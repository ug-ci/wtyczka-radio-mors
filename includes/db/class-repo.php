<?php
namespace Mors\Db;
abstract class Repo {
    protected function wpdb() { global $wpdb; return $wpdb; }
    protected function t() { return Schema::table_names(); }
    public function new_id() { return wp_generate_uuid4(); }
    public function now() { return gmdate( 'Y-m-d H:i:s' ); }
    /** Wykonuje $fn w transakcji; COMMIT po sukcesie, ROLLBACK i re-throw na wyjątku. */
    public function tx( callable $fn ) {
        $db = $this->wpdb();
        $db->query( 'START TRANSACTION' );
        try {
            $result = $fn();
            $db->query( 'COMMIT' );
            return $result;
        } catch ( \Throwable $e ) {
            $db->query( 'ROLLBACK' );
            throw $e;
        }
    }
}
