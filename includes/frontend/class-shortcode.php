<?php
namespace Mors\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode [lista_przebojow_mors]:
 *  - renderuje pełny statyczny shell SPA (templates/public-app.php),
 *  - rejestruje i kolejkuje style/JS aplikacji oraz lokalną kopię ikon Lucide,
 *  - przekazuje do JS dane WP (morsData: restUrl, nonce, isEditor, isAdminPanel).
 */
class Shortcode {

    const HANDLE = 'mors-app';

    public static function register() {
        add_shortcode( 'lista_przebojow_mors', [ self::class, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
    }

    /**
     * Rejestracja (bez kolejkowania) — faktyczny enqueue następuje w render(),
     * dzięki czemu assety ładują się tylko na stronach z shortcodem.
     */
    public static function register_assets() {
        wp_register_style(
            self::HANDLE,
            MORS_PLUGIN_URL . 'assets/css/styles.css',
            [],
            MORS_VERSION
        );

        // Lokalnie zwendorowana biblioteka ikon Lucide (UMD, global `lucide`).
        wp_register_script(
            'mors-lucide',
            MORS_PLUGIN_URL . 'assets/js/lucide.min.js',
            [],
            MORS_VERSION,
            true
        );

        // Aplikacja SPA — w stopce, zależna od Lucide (ikony dostępne przy init).
        wp_register_script(
            self::HANDLE,
            MORS_PLUGIN_URL . 'assets/js/app.js',
            [ 'mors-lucide' ],
            MORS_VERSION,
            true
        );

        wp_localize_script( self::HANDLE, 'morsData', [
            'restUrl'      => esc_url_raw( rest_url( 'mors/v1' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'isEditor'     => current_user_can( \Mors_Enum::CAP_EDIT_MUSIC ),
            'isAdminPanel' => false,
            'config'       => \mors_get_config(),
        ] );
    }

    public static function render() {
        // Enqueue tylko gdy shortcode faktycznie występuje na stronie.
        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( 'mors-lucide' );
        wp_enqueue_script( self::HANDLE );

        ob_start();
        include MORS_PLUGIN_DIR . 'templates/public-app.php';
        return ob_get_clean();
    }
}
