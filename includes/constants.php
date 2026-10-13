<?php
// Statusy i trendy (VARCHAR w DB, stałe w kodzie).
final class Mors_Enum {
    const TRACK_STATUSES   = [ 'WAITING_ROOM', 'CHART', 'ARCHIVED', 'REJECTED' ];
    const EDITION_STATUSES = [ 'DRAFT', 'ACTIVE', 'FROZEN', 'BROADCASTING', 'ARCHIVED' ];
    const TRENDS           = [ 'NEW', 'UP', 'DOWN', 'SAME', 'REENTRY' ];
    const CAP_EDIT_MUSIC   = 'mors_edit_music';
    const CAP_PRESENT      = 'mors_present';
    const CAP_MANAGE       = 'mors_manage_editors';
}

if ( ! function_exists( 'mors_to_iso8601' ) ) {
    /**
     * Konwertuje przechowywany naiwny datetime UTC ('Y-m-d H:i:s') na ISO-8601
     * Zulu do serializacji dla klienta. SPA parsuje wartości przez `new Date(...)`,
     * które oczekuje ISO-8601 — format ze spacją jest odczytywany jako czas
     * lokalny w Chrome/Firefox i jako Invalid Date w Safari/iOS. Wartości
     * używane do porównań po stronie serwera (cooldown) mają zostać w formacie
     * naiwnym — ta funkcja dotyczy wyłącznie tego, co trafia do JSON.
     */
    function mors_to_iso8601( $mysql_utc ) {
        if ( empty( $mysql_utc ) ) { return null; }
        $ts = strtotime( $mysql_utc . ' UTC' );
        if ( $ts === false ) { return null; }
        return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
    }
}

if ( ! function_exists( 'mors_parse_duration' ) ) {
    /**
     * Parsuje czas trwania w formacie "m:ss" na sekundy. Port parseDuration()
     * z app/src/routes/admin.js — przy braku wartości lub złym formacie zwraca
     * $fallback zamiast rzucać wyjątek (upload nie powinien się wywalać na
     * niepoprawnym polu czasu trwania).
     */
    function mors_parse_duration( $str, $fallback = 210 ) {
        if ( ! $str ) { return $fallback; }
        $parts = explode( ':', (string) $str );
        if ( count( $parts ) !== 2 ) { return $fallback; }
        list( $m, $s ) = $parts;
        if ( ! is_numeric( $m ) || ! is_numeric( $s ) ) { return $fallback; }
        return ( (int) $m ) * 60 + (int) $s;
    }
}

if ( ! function_exists( 'mors_get_config' ) ) {
    /**
     * Zwraca aktualną konfigurację rozmiarów notowania i poczekalni.
     */
    function mors_get_config() {
        $chart_size       = (int) get_option( 'mors_chart_size', 25 );
        $waiting_size     = (int) get_option( 'mors_waiting_size', 35 );
        $promoted_count   = (int) get_option( 'mors_promoted_count', 2 );
        $max_votes        = (int) get_option( 'mors_max_votes_per_voter', 15 );
        $voting_suspended = (bool) get_option( 'mors_voting_suspended', false );

        if ( $chart_size < 1 ) { $chart_size = 25; }
        if ( $waiting_size < 1 ) { $waiting_size = 35; }
        if ( $promoted_count < 0 || $promoted_count > $chart_size ) { $promoted_count = 2; }
        if ( $max_votes < 1 ) { $max_votes = 15; }

        return [
            'chartSize'       => $chart_size,
            'waitingSize'     => $waiting_size,
            'promotedCount'   => $promoted_count,
            'maxVotes'        => $max_votes,
            'votingSuspended' => $voting_suspended,
        ];
    }
}

