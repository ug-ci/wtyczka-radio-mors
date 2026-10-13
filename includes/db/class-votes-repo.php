<?php
namespace Mors\Db;
class Votes_Repo extends Repo {
    public function find_voter( $hash ) {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row( $db->prepare(
            "SELECT * FROM {$t['voters']} WHERE voter_hash = %s", $hash ), ARRAY_A );
        return $row ?: null;
    }
    /** Blokada wiersza wewnątrz transakcji (FOR UPDATE) — używane przez Vote_Service. */
    public function find_voter_for_update( $hash ) {
        $db = $this->wpdb(); $t = $this->t();
        $row = $db->get_row( $db->prepare(
            "SELECT * FROM {$t['voters']} WHERE voter_hash = %s FOR UPDATE", $hash ), ARRAY_A );
        return $row ?: null;
    }
    public function upsert_voter( $hash, $now, $next ) {
        $db = $this->wpdb(); $t = $this->t();
        $existing = $this->find_voter( $hash );
        if ( $existing ) {
            $db->update( $t['voters'],
                [ 'last_voted_at' => $now, 'next_eligible_vote_at' => $next ],
                [ 'id' => $existing['id'] ] );
            return $this->find_voter( $hash );
        }
        $row = [ 'id' => $this->new_id(), 'voter_hash' => $hash,
            'last_voted_at' => $now, 'next_eligible_vote_at' => $next,
            'trust_score' => 1.0, 'created_at' => $now ];
        $db->insert( $t['voters'], $row );
        return $row;
    }
    public function insert_vote( array $data ) {
        $db = $this->wpdb(); $t = $this->t();
        $data = array_merge( [ 'id' => $this->new_id(), 'created_at' => $this->now() ], $data );
        $db->insert( $t['votes'], $data );
    }
    public function log( $adminId, $action, array $meta = [] ) {
        $db = $this->wpdb(); $t = $this->t();
        $db->insert( $t['audit_log'], [
            'id' => $this->new_id(), 'admin_id' => $adminId, 'action' => $action,
            'metadata' => wp_json_encode( $meta ), 'created_at' => $this->now() ] );
    }
}
