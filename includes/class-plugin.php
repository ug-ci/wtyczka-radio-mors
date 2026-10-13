<?php
namespace Mors;
class Plugin {
    private static $instance;
    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    public function boot() {
        add_action( 'plugins_loaded', [ '\\Mors\\Upgrader', 'maybe_upgrade' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ] );
        \Mors\Frontend\Shortcode::register();
        if ( is_admin() ) {
            \Mors\Admin\Admin_Page::register();
        }
    }

    public function filter_upload_mimes( $mimes ) {
        $mimes['flac'] = 'audio/flac';
        $mimes['wav']  = 'audio/wav';
        $mimes['mp3']  = 'audio/mpeg';
        return $mimes;
    }

    public function register_rest_routes() {
        ( new \Mors\Rest\Chart() )->register();
        ( new \Mors\Rest\Votes() )->register();
        ( new \Mors\Rest\Admin() )->register();
    }
}
