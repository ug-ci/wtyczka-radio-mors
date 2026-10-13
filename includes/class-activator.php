<?php
namespace Mors;
use Mors\Db\Schema;
use Mors\Auth\Capabilities;
class Activator {
    public static function activate() {
        Schema::create_tables();
        Capabilities::add();
        self::seed_first_edition();
        update_option( 'mors_db_version', MORS_VERSION );
    }
    private static function seed_first_edition() {
        global $wpdb;
        $t = Schema::table_names();
        $exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['editions']}" );
        if ( $exists > 0 ) { return; }
        $now = current_time( 'mysql', true );
        $ends = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
        $wpdb->insert( $t['editions'], [
            'id' => wp_generate_uuid4(),
            'edition_number' => 1,
            'title' => 'Notowanie 1 • Wydanie Główne',
            'voting_starts_at' => $now,
            'voting_ends_at' => $ends,
            'status' => 'ACTIVE',
            'is_current' => 1,
            'created_at' => $now,
        ] );
    }
}
