<?php
/**
 * Plugin Name: Lista Przebojów Radia MORS
 * Description: Lista przebojów z głosowaniem słuchaczy i panelem redakcji.
 * Version: 1.1.0.1
 * Requires PHP: 7.4
 * Text Domain: radio-mors
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MORS_VERSION', '1.1.0.1' );
define( 'MORS_PLUGIN_FILE', __FILE__ );
define( 'MORS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MORS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MORS_PLUGIN_DIR . 'includes/constants.php';
$mors_autoload = MORS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $mors_autoload ) ) { require_once $mors_autoload; }

register_activation_hook( __FILE__, [ '\\Mors\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\\Mors\\Deactivator', 'deactivate' ] );

\Mors\Plugin::instance()->boot();
