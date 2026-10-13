<?php
namespace Mors\Domain;
use Mors\Db\Editions_Repo;
use Mors\Db\Entries_Repo;
use Mors\Db\Votes_Repo;

class Vote_Exception extends \Exception {
    public $code; public $http; public $nextEligibleVoteAt;
    public function __construct( $code, $message, $http = 400, $next = null ) {
        parent::__construct( $message );
        $this->code = $code; $this->http = $http; $this->nextEligibleVoteAt = $next;
    }
}

class Vote_Service {
    public function cast( array $trackIds, $hash, $ip, $ua ) {
        $config   = \mors_get_config();
        $maxVotes = isset( $config['maxVotes'] ) ? (int) $config['maxVotes'] : 15;

        if ( ! empty( $config['votingSuspended'] ) ) {
            throw new Vote_Exception( 'SUSPENDED', 'Głosowanie jest obecnie czasowo zawieszone. Zapraszamy wkrótce!', 403 );
        }

        // Walidacja wejścia (1–maxVotes, bez duplikatów).
        $trackIds = array_values( $trackIds );
        if ( count( $trackIds ) < 1 || count( $trackIds ) > $maxVotes ) {
            throw new Vote_Exception( 'INVALID', sprintf( 'Wybierz od 1 do %d utworów, aby oddać głos.', $maxVotes ) );
        }
        if ( count( array_unique( $trackIds ) ) !== count( $trackIds ) ) {
            throw new Vote_Exception( 'INVALID', 'Wykryto zduplikowane utwory w głosowaniu.' );
        }
        $edRepo = new Editions_Repo();
        $edition = $edRepo->current();
        if ( ! $edition || $edition['status'] !== 'ACTIVE' ) {
            throw new Vote_Exception( 'CLOSED', 'Głosowanie w tym notowaniu jest obecnie zamknięte.', 409 );
        }
        $entriesRepo = new Entries_Repo();
        $entries = $entriesRepo->by_ids( $trackIds, $edition['id'] );
        if ( count( $entries ) !== count( $trackIds ) ) {
            throw new Vote_Exception( 'NOT_IN_EDITION',
                'Jeden lub więcej wybranych utworów nie należy do bieżącego notowania.' );
        }
        $votesRepo = new Votes_Repo();
        $now  = gmdate( 'Y-m-d H:i:s' );
        $next = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

        // Cooldown + zapis w jednej transakcji (FOR UPDATE eliminuje wyścig).
        $updated = $votesRepo->tx( function () use ( $votesRepo, $entriesRepo, $entries, $edition, $hash, $ip, $ua, $now, $next ) {
            $existing = $votesRepo->find_voter_for_update( $hash );
            if ( $existing && strtotime( $existing['next_eligible_vote_at'] . ' UTC' ) > time() ) {
                throw new Vote_Exception( 'COOLDOWN',
                    'Twój limit głosów na 24h jest obecnie aktywny.', 429,
                    \mors_to_iso8601( $existing['next_eligible_vote_at'] ) );
            }
            $voter = $votesRepo->upsert_voter( $hash, $now, $next );
            foreach ( $entries as $e ) {
                $entriesRepo->increment_votes( $e['id'] );
                $votesRepo->insert_vote( [
                    'edition_id' => $edition['id'], 'track_id' => $e['track_id'],
                    'voter_id' => $voter['id'], 'ip_address' => $ip,
                    'user_agent' => $ua, 'fingerprint_hash' => $hash,
                ] );
            }
            $ids = array_map( function ( $e ) { return $e['id']; }, $entries );
            return $entriesRepo->by_ids( $ids, $edition['id'] );
        } );

        return [
            'success' => true,
            'message' => 'Głosy zostały pomyślnie zarejestrowane. Dziękujemy!',
            'nextEligibleVoteAt' => \mors_to_iso8601( $next ),
            'updatedEntries' => array_map( function ( $e ) {
                return [ 'id' => $e['id'], 'votes' => (int) $e['votes_count'] ];
            }, $updated ),
        ];
    }
}
