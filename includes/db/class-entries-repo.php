<?php
namespace Mors\Db;
class Entries_Repo extends Repo {
    public function for_edition( $editionId, $waiting ) {
        $db = $this->wpdb(); $t = $this->t();
        $order = $waiting
            ? 'e.votes_count DESC, e.weeks_on_chart DESC, tr.title ASC'
            : 'e.votes_count DESC, COALESCE(e.position, 999) ASC, e.weeks_on_chart DESC, tr.title ASC';
        $sql = $db->prepare(
            "SELECT e.*, tr.title, tr.artist, tr.album, tr.genre, tr.cover_image_url,
                    tr.audio_url, tr.audio_key, tr.bpm, tr.duration_seconds, tr.peak_position,
                    tr.total_weeks_on_chart
             FROM {$t['entries']} e
             JOIN {$t['tracks']} tr ON tr.id = e.track_id
             WHERE e.edition_id = %s AND e.is_waiting = %d
             ORDER BY {$order}",
            $editionId, $waiting ? 1 : 0 );
        return $db->get_results( $sql, ARRAY_A ) ?: [];
    }
    /** Wpisy notowania (is_waiting=0), posortowane wg pozycji rosnąco — do GET /chart/current. */
    public function chart_by_position( $editionId ) {
        $db = $this->wpdb(); $t = $this->t();
        $sql = $db->prepare(
            "SELECT e.*, tr.title, tr.artist, tr.album, tr.genre, tr.cover_image_url,
                    tr.audio_url, tr.audio_key, tr.bpm, tr.duration_seconds, tr.peak_position,
                    tr.total_weeks_on_chart
             FROM {$t['entries']} e
             JOIN {$t['tracks']} tr ON tr.id = e.track_id
             WHERE e.edition_id = %s AND e.is_waiting = 0
             ORDER BY e.position ASC",
            $editionId );
        return $db->get_results( $sql, ARRAY_A ) ?: [];
    }
    /** Suma votes_count po wszystkich wpisach edycji (chart + poczekalnia). */
    public function total_votes( $editionId ) {
        $db = $this->wpdb(); $t = $this->t();
        $sum = $db->get_var( $db->prepare(
            "SELECT COALESCE(SUM(votes_count),0) FROM {$t['entries']} WHERE edition_id = %s",
            $editionId ) );
        return (int) $sum;
    }
    public function by_ids( array $ids, $editionId ) {
        if ( ! $ids ) { return []; }
        $db = $this->wpdb(); $t = $this->t();
        $ph = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $sql = $db->prepare(
            "SELECT * FROM {$t['entries']} WHERE id IN ($ph) AND edition_id = %s",
            array_merge( $ids, [ $editionId ] ) );
        return $db->get_results( $sql, ARRAY_A ) ?: [];
    }
    public function create( array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $data = array_merge( [ 'id' => $this->new_id(), 'trend' => 'NEW',
            'votes_count' => 0, 'weeks_on_chart' => 1, 'is_waiting' => 0 ], $data );
        $db->insert( $t['entries'], $data );
        return $data;
    }
    public function increment_votes( $entryId ) {
        $db = $this->wpdb(); $t = $this->t();
        $db->query( $db->prepare(
            "UPDATE {$t['entries']} SET votes_count = votes_count + 1 WHERE id = %s", $entryId ) );
    }
    /** Liczba wpisów w edycji dla danej sekcji (is_waiting=0 -> notowanie, 1 -> poczekalnia). */
    public function count_for_edition( string $editionId, bool $waiting ): int {
        $db = $this->wpdb(); $t = $this->t();
        return (int) $db->get_var( $db->prepare(
            "SELECT COUNT(*) FROM {$t['entries']} WHERE edition_id = %s AND is_waiting = %d",
            $editionId, $waiting ? 1 : 0 ) );
    }

    public function find_for_track( string $editionId, string $trackId ) {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row( $db->prepare(
            "SELECT * FROM {$t['entries']} WHERE edition_id = %s AND track_id = %s", $editionId, $trackId ), ARRAY_A );
        return $row ?: null;
    }

    public function update( string $id, array $data ) {
        $this->wpdb()->update( $this->t()['entries'], $data, [ 'id' => $id ] );
    }

    /** Zagęszcza pozycje notowania do 1..N zachowując bieżącą kolejność. */
    public function compact_chart_positions( string $editionId ) {
        $this->reorder_chart( $editionId, array_column( $this->chart_by_position( $editionId ), 'track_id' ) );
    }

    /** Najwyższa pozycja na notowaniu (is_waiting=0) w danej edycji; 0, jeśli brak wpisów. */
    public function max_chart_position( string $editionId ): int {
        $db = $this->wpdb(); $t = $this->t();
        $max = $db->get_var( $db->prepare(
            "SELECT COALESCE(MAX(position),0) FROM {$t['entries']} WHERE edition_id = %s AND is_waiting = 0",
            $editionId ) );
        return (int) $max;
    }

    /**
     * Ustawia pozycje wpisów notowania (is_waiting=0) wg podanej kolejności track_id.
     * Pozycja = indeks+1. Wywoływać w transakcji (Chart_Engine::reorder_chart).
     */
    public function reorder_chart( $editionId, array $trackIds ) {
        $db = $this->wpdb(); $t = $this->t();
        $pos = 1;
        foreach ( $trackIds as $trackId ) {
            $db->query( $db->prepare(
                "UPDATE {$t['entries']} SET position = %d WHERE edition_id = %s AND track_id = %s AND is_waiting = 0",
                $pos, $editionId, $trackId ) );
            $pos++;
        }
    }
}
