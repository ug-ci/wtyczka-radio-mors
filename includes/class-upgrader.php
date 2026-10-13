<?php
namespace Mors;

use Mors\Db\Schema;

/**
 * Lekka rutyna aktualizacji DB. Tabele/kolumny obecnie powstają tylko przy
 * aktywacji — jeśli wtyczka jest aktualizowana bez deaktywacji/reaktywacji
 * (typowy scenariusz auto-update), nowa kolumna/tabela dodana w kolejnej
 * wersji nigdy by nie powstała. Porównujemy zapisaną wersję DB z MORS_VERSION
 * przy każdym starcie (plugins_loaded) i, gdy się różnią, ponownie wołamy
 * Schema::create_tables() — dbDelta() jest idempotentny (dodaje wyłącznie
 * brakujące tabele/kolumny/indeksy, nic nie usuwa).
 */
class Upgrader {
    public static function maybe_upgrade() {
        $installed = get_option( 'mors_db_version' );
        if ( $installed === MORS_VERSION ) {
            return;
        }
        Schema::create_tables();
        update_option( 'mors_db_version', MORS_VERSION );
    }
}
