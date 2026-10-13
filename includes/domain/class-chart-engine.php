<?php
namespace Mors\Domain;

use Mors\Db\Editions_Repo;
use Mors\Db\Entries_Repo;
use Mors\Db\Tracks_Repo;
use Mors\Db\Votes_Repo;

/**
 * Silnik notowania: freeze() i reset_and_publish().
 *
 * Port 1:1 algorytmu z app/src/routes/admin.js (sekcja „Chart lifecycle”,
 * trasy POST /chart/freeze i POST /chart/reset-and-publish) — admin.js jest
 * źródłem prawdy dla granic array_slice, reguł trend i dopełnienia poczekalni.
 *
 * Jedyna świadoma różnica względem admin.js: tam `newEdition` jest tworzone
 * przez `prisma.chartEdition.create()` PRZED wejściem w `$transaction(...)`,
 * więc w razie błędu w dalszej części nie zostałoby wycofane. Tutaj — zgodnie
 * z wymaganiem taska („ALL inside one tx()”) — tworzenie nowej edycji też
 * jest wewnątrz transakcji, więc cały reset jest atomowy.
 */
class Chart_Engine {

    /** Zamraża bieżące notowanie (status -> FROZEN). */
    public function freeze( $adminId ) {
        $edRepo = new Editions_Repo();
        $ed = $edRepo->current();
        if ( ! $ed ) {
            throw new \RuntimeException( 'Brak aktywnego notowania.' );
        }
        $edRepo->update( $ed['id'], [ 'status' => 'FROZEN' ] );
        ( new Votes_Repo() )->log( $adminId, 'CHART_FREEZE', [ 'editionId' => $ed['id'] ] );

        return [
            'success' => true,
            'edition' => [ 'id' => $ed['id'], 'status' => 'FROZEN' ],
        ];
    }

    /**
     * Zapisuje nową kolejność notowania (drag & drop w panelu).
     * $trackIds — track_id wpisów notowania (is_waiting=0) w docelowej kolejności;
     * pozycja = indeks+1. Całość w transakcji.
     */
    public function reorder_chart( $adminId, array $trackIds, $waiting = false ) {
        $ed = ( new Editions_Repo() )->current();
        if ( ! $ed ) {
            throw new \RuntimeException( 'Brak aktywnego notowania.' );
        }
        $entriesRepo = new Entries_Repo();
        $votesRepo   = new Votes_Repo();

        $votesRepo->tx( function () use ( $entriesRepo, $ed, $trackIds, $waiting ) {
            $entriesRepo->reorder_chart( $ed['id'], $trackIds, $waiting );
        } );

        $votesRepo->log( $adminId, $waiting ? 'WAITING_REORDER' : 'CHART_REORDER', [ 'editionId' => $ed['id'], 'count' => count( $trackIds ) ] );

        return [ 'success' => true ];
    }

    /**
     * Resetuje notowanie i publikuje nowe wydanie:
     *  - top 18 z listy -> pozycje 1..18 (trend wg starej/nowej pozycji, peak/weeks na Track).
     *  - top 2 z poczekalni -> pozycje 19..20 (trend NEW).
     *  - reszta poczekalni przechodzi dalej (weeks_on_chart += 1).
     *  - poczekalnia dopełniana placeholderami do 25.
     * Całość w jednej transakcji.
     */
    public function reset_and_publish( $adminId ) {
        $edRepo      = new Editions_Repo();
        $entriesRepo = new Entries_Repo();
        $tracksRepo  = new Tracks_Repo();
        $votesRepo   = new Votes_Repo();

        $ed = $edRepo->current();
        if ( ! $ed ) {
            throw new \RuntimeException( 'Brak aktywnego notowania.' );
        }

        // for_edition() sortuje już malejąco po votes_count — to jest kolejność rankingowa.
        $chart   = $entriesRepo->for_edition( $ed['id'], false );
        $waiting = $entriesRepo->for_edition( $ed['id'], true );

        $config        = \mors_get_config();
        $chartSize     = $config['chartSize'];
        $waitingSize   = $config['waitingSize'];
        $promotedCount = min( $config['promotedCount'], $chartSize );
        $retainedCount = max( 0, $chartSize - $promotedCount );

        $promoted         = array_slice( $waiting, 0, $promotedCount );
        $remainingWaiting = array_slice( $waiting, $promotedCount );

        $now       = gmdate( 'Y-m-d H:i:s' );
        $ends      = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
        $newNumber = (int) $ed['edition_number'] + 1;

        return $votesRepo->tx( function () use (
            $edRepo, $entriesRepo, $tracksRepo, $votesRepo,
            $ed, $chart, $promoted, $remainingWaiting, $now, $ends, $newNumber, $adminId,
            $chartSize, $waitingSize, $promotedCount, $retainedCount
        ) {
            $newEd = $edRepo->create( [
                'edition_number'   => $newNumber,
                'title'            => "Notowanie {$newNumber} • Wydanie Główne",
                'voting_starts_at' => $now,
                'voting_ends_at'   => $ends,
                'status'           => 'ACTIVE',
                'is_current'       => 1,
                'created_at'       => $now,
            ] );
            $edRepo->update( $ed['id'], [ 'is_current' => 0, 'status' => 'ARCHIVED' ] );

            // Zachowane z notowania -> pozycje 1..$retainedCount, trend wg porównania starej/nowej pozycji.
            $retainedChart = array_slice( $chart, 0, $retainedCount );
            foreach ( $retainedChart as $i => $entry ) {
                $newPos = $i + 1;
                $oldPos = $entry['position'] !== null ? (int) $entry['position'] : null;

                $trend = 'SAME';
                if ( $oldPos !== null && $oldPos > $newPos ) {
                    $trend = 'UP';
                } elseif ( $oldPos !== null && $oldPos < $newPos ) {
                    $trend = 'DOWN';
                }

                $entriesRepo->create( [
                    'edition_id'        => $newEd['id'],
                    'track_id'          => $entry['track_id'],
                    'position'          => $newPos,
                    'previous_position' => $oldPos,
                    'trend'             => $trend,
                    'votes_count'       => 0,
                    'weeks_on_chart'    => (int) $entry['weeks_on_chart'] + 1,
                    'is_waiting'        => 0,
                ] );

                $peak = $entry['peak_position'] !== null
                    ? min( (int) $entry['peak_position'], $newPos )
                    : $newPos;
                $tracksRepo->update( $entry['track_id'], [
                    'peak_position'         => $peak,
                    'total_weeks_on_chart'  => (int) $entry['weeks_on_chart'] + 1,
                    'status'                => 'CHART',
                ] );
            }

            // Promocja $promotedCount z poczekalni -> pozycje ($retainedCount + 1)..$chartSize.
            foreach ( $promoted as $i => $entry ) {
                $newPos = $retainedCount + 1 + $i;
                $entriesRepo->create( [
                    'edition_id'        => $newEd['id'],
                    'track_id'          => $entry['track_id'],
                    'position'          => $newPos,
                    'previous_position' => null,
                    'trend'             => 'NEW',
                    'votes_count'       => 0,
                    'weeks_on_chart'    => 1,
                    'is_waiting'        => 0,
                ] );
                $tracksRepo->update( $entry['track_id'], [
                    'peak_position'        => $newPos,
                    'total_weeks_on_chart' => 1,
                    'status'               => 'CHART',
                ] );
            }

            // Reszta poczekalni przechodzi dalej (nie promowana, nie na liście).
            foreach ( $remainingWaiting as $entry ) {
                $entriesRepo->create( [
                    'edition_id'     => $newEd['id'],
                    'track_id'       => $entry['track_id'],
                    'position'       => null,
                    'trend'          => 'NEW',
                    'votes_count'    => 0,
                    'weeks_on_chart' => (int) $entry['weeks_on_chart'] + 1,
                    'is_waiting'     => 1,
                    'tag'            => isset( $entry['tag'] ) ? $entry['tag'] : null,
                ] );
            }

            // Dopełnienie poczekalni placeholderami do $waitingSize.
            $totalWaiting = count( $remainingWaiting );
            $toPad = max( 0, $waitingSize - $totalWaiting );
            for ( $i = 0; $i < $toPad; $i++ ) {
                $idNum = $totalWaiting + $i + 1;
                $newTrack = $tracksRepo->create( [
                    'title'            => "Nowa Propozycja #{$idNum}",
                    'artist'           => 'Młoda Fala UG',
                    'status'           => 'WAITING_ROOM',
                    'duration_seconds' => 195,
                ] );
                $entriesRepo->create( [
                    'edition_id'     => $newEd['id'],
                    'track_id'       => $newTrack['id'],
                    'position'       => null,
                    'trend'          => 'NEW',
                    'votes_count'    => 0,
                    'weeks_on_chart' => 1,
                    'is_waiting'     => 1,
                    'tag'            => 'Nowość redakcji',
                ] );
            }

            $votesRepo->log( $adminId, 'CHART_RESET_AND_PUBLISH', [
                'previousEditionId' => $ed['id'],
                'newEditionId'      => $newEd['id'],
                'newEditionNumber'  => $newNumber,
            ] );

            return [
                'success' => true,
                'edition' => [
                    'id'            => $newEd['id'],
                    'editionNumber' => $newNumber,
                    'status'        => 'ACTIVE',
                ],
            ];
        } );
    }
}
