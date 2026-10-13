<?php
namespace Mors\Db;
class Tracks_Repo extends Repo {
    public function find( $id ) {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row(
            $db->prepare( "SELECT * FROM {$t['tracks']} WHERE id = %s", $id ), ARRAY_A );
        return $row ?: null;
    }
    public function all( array $args = [] ) {
        $db = $this->wpdb(); $t = $this->t();
        $where = ''; $params = [];
        if ( ! empty( $args['status'] ) ) { $where = 'WHERE status = %s'; $params[] = $args['status']; }
        $sql = "SELECT * FROM {$t['tracks']} $where ORDER BY created_at DESC";
        if ( $params ) { $sql = $db->prepare( $sql, $params ); }
        return $db->get_results( $sql, ARRAY_A ) ?: [];
    }
    public function create( array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $data = array_merge( [
            'id' => $this->new_id(), 'status' => 'WAITING_ROOM',
            'duration_seconds' => 210, 'total_weeks_on_chart' => 0,
            'audio_key' => 'synth_chill',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ], $data );
        $db->insert( $t['tracks'], $data );
        return $data;
    }
    public function update( $id, array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $data['updated_at'] = $this->now();
        $db->update( $t['tracks'], $data, [ 'id' => $id ] );
    }
    public function delete( $id ) {
        $db = $this->wpdb(); $t = $this->t();
        // Kaskada logiczna: usuń wpisy i głosy tego utworu w jednej transakcji,
        // żeby awaria w połowie sekwencji nie zostawiła osieroconych wierszy.
        $this->tx( function () use ( $db, $t, $id ) {
            $db->delete( $t['entries'], [ 'track_id' => $id ] );
            $db->delete( $t['votes'], [ 'track_id' => $id ] );
            $db->delete( $t['tracks'], [ 'id' => $id ] );
        } );
    }
}
