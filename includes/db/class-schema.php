<?php
namespace Mors\Db;
class Schema {
    public static function table_names() {
        global $wpdb;
        $p = $wpdb->prefix . 'mors_';
        return [
            'editions'  => $p . 'editions',
            'tracks'    => $p . 'tracks',
            'entries'   => $p . 'entries',
            'voters'    => $p . 'voters',
            'votes'     => $p . 'votes',
            'audit_log' => $p . 'audit_log',
        ];
    }
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = self::table_names();
        $sql = [];
        $sql[] = "CREATE TABLE {$t['editions']} (
            id CHAR(36) NOT NULL,
            edition_number INT NOT NULL,
            title VARCHAR(191) NOT NULL,
            voting_starts_at DATETIME NOT NULL,
            voting_ends_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY edition_number (edition_number),
            KEY is_current (is_current)
        ) ENGINE=InnoDB $charset;";
        $sql[] = "CREATE TABLE {$t['tracks']} (
            id CHAR(36) NOT NULL,
            title VARCHAR(191) NOT NULL,
            artist VARCHAR(191) NOT NULL,
            album VARCHAR(191) NULL,
            genre VARCHAR(191) NULL,
            cover_image_url TEXT NULL,
            audio_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'WAITING_ROOM',
            duration_seconds INT NOT NULL DEFAULT 210,
            total_weeks_on_chart INT NOT NULL DEFAULT 0,
            peak_position INT NULL,
            bpm INT NULL,
            audio_key VARCHAR(50) NULL DEFAULT 'synth_chill',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status)
        ) ENGINE=InnoDB $charset;";
        $sql[] = "CREATE TABLE {$t['entries']} (
            id CHAR(36) NOT NULL,
            edition_id CHAR(36) NOT NULL,
            track_id CHAR(36) NOT NULL,
            position INT NULL,
            previous_position INT NULL,
            trend VARCHAR(10) NOT NULL DEFAULT 'NEW',
            votes_count INT NOT NULL DEFAULT 0,
            weeks_on_chart INT NOT NULL DEFAULT 1,
            is_waiting TINYINT(1) NOT NULL DEFAULT 0,
            tag VARCHAR(50) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY edition_track (edition_id, track_id),
            KEY edition_waiting (edition_id, is_waiting)
        ) ENGINE=InnoDB $charset;";
        $sql[] = "CREATE TABLE {$t['voters']} (
            id CHAR(36) NOT NULL,
            voter_hash VARCHAR(64) NOT NULL,
            email VARCHAR(191) NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            last_voted_at DATETIME NOT NULL,
            next_eligible_vote_at DATETIME NOT NULL,
            trust_score FLOAT NOT NULL DEFAULT 1.0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY voter_hash (voter_hash)
        ) ENGINE=InnoDB $charset;";
        $sql[] = "CREATE TABLE {$t['votes']} (
            id CHAR(36) NOT NULL,
            edition_id CHAR(36) NOT NULL,
            track_id CHAR(36) NOT NULL,
            voter_id CHAR(36) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            user_agent TEXT NULL,
            fingerprint_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY edition_id (edition_id),
            KEY voter_id (voter_id)
        ) ENGINE=InnoDB $charset;";
        $sql[] = "CREATE TABLE {$t['audit_log']} (
            id CHAR(36) NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY admin_id (admin_id)
        ) ENGINE=InnoDB $charset;";
        foreach ( $sql as $s ) { dbDelta( $s ); }
    }
}
