<?php
namespace Mors\Db;
class Editions_Repo extends Repo {
    public function current() {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row(
            "SELECT * FROM {$t['editions']} WHERE is_current = 1 LIMIT 1", ARRAY_A );
        return $row ?: null;
    }
    public function find_by_number( $number ) {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row(
            $db->prepare( "SELECT * FROM {$t['editions']} WHERE edition_number = %d LIMIT 1", (int) $number ),
            ARRAY_A
        );
        return $row ?: null;
    }
    public function create( array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $data = array_merge( [
            'id' => $this->new_id(), 'status' => 'ACTIVE',
            'is_current' => 0, 'created_at' => $this->now(),
        ], $data );
        $db->insert( $t['editions'], $data );
        return $data;
    }
    public function update( $id, array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $db->update( $t['editions'], $data, [ 'id' => $id ] );
    }
}
