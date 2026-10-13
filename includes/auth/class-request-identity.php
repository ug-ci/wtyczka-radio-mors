<?php
namespace Mors\Auth;

/**
 * Tożsamość żądania publicznego (głosowanie, cooldown). Celowo NIE uwzględnia
 * User-Agent w hashu — tylko adres IP.
 */
class Request_Identity {

    /** Realny IP klienta. Domyślnie REMOTE_ADDR; za zaufanym proxy można włączyć nagłówek. */
    public static function client_ip() {
        $trust_header = apply_filters( 'mors_trusted_ip_header', '' ); // np. 'HTTP_CF_CONNECTING_IP'
        if ( $trust_header && ! empty( $_SERVER[ $trust_header ] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER[ $trust_header ] ) );
        }
        return isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    public static function voter_hash() {
        return hash( 'sha256', 'ip:' . self::client_ip() );
    }
}
