<?php
/**
 * Uninstall — Lista Przebojów Radia MORS.
 *
 * Uruchamiane przez WordPressa przy "Delete" wtyczki z ekranu Wtyczki (NIE
 * przy zwykłej deaktywacji). Ten plik działa BEZ zbootowanej wtyczki — tylko
 * ten skrypt jest ładowany, więc dociągamy tylko potrzebne pliki jawnie.
 *
 * Domyślnie NIC nie usuwamy — dane (tabele, opcje) zostają, żeby przypadkowe
 * odinstalowanie nie skasowało historii głosowań/notowań. Usuwanie danych
 * jest opt-in przez opcję `mors_delete_data_on_uninstall` (patrz readme.txt
 * FAQ) — ustawia ją panel administracyjny/operator świadomie przed usunięciem.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! get_option( 'mors_delete_data_on_uninstall' ) ) {
    return;
}

global $wpdb;

$mors_constants = __DIR__ . '/includes/constants.php';
$mors_schema    = __DIR__ . '/includes/db/class-schema.php';
$mors_caps      = __DIR__ . '/includes/auth/class-capabilities.php';

if ( file_exists( $mors_constants ) ) {
    require_once $mors_constants;
}
if ( file_exists( $mors_schema ) ) {
    require_once $mors_schema;
}

if ( class_exists( '\\Mors\\Db\\Schema' ) ) {
    foreach ( \Mors\Db\Schema::table_names() as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS $table" ); // nazwy tabel z prefix $wpdb->prefix, nie z wejścia użytkownika.
    }
}

if ( file_exists( $mors_caps ) ) {
    require_once $mors_caps;
}

if ( class_exists( '\\Mors\\Auth\\Capabilities' ) ) {
    \Mors\Auth\Capabilities::remove();
} else {
    // Awaryjnie, gdyby klasa Capabilities nie dała się załadować: usuń
    // capability wprost z roli administratora.
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->remove_cap( 'mors_edit_music' );
        $admin->remove_cap( 'mors_present' );
        $admin->remove_cap( 'mors_manage_editors' );
    }
}

delete_option( 'mors_delete_data_on_uninstall' );
delete_option( 'mors_db_version' );
