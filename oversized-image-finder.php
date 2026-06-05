<?php
/**
 * Plugin Name: Oversized Image Finder
 * Plugin URI: https://github.com/
 * Description: Find oversized images that slow down your site. Scan the Media Library, uploads folder, and theme/plugin directories. View filename, size, dimensions, and more.
 * Version: 1.0.0
 * Author: DP
 * Text Domain: oversized-image-finder
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OIF_VERSION', '1.0.0' );
define( 'OIF_PLUGIN_FILE', __FILE__ );
define( 'OIF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OIF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OIF_OPTION_KEY', 'oif_settings' );
define( 'OIF_TRANSIENT_RESULTS', 'oif_scan_results' );
define( 'OIF_TRANSIENT_STATE', 'oif_scan_state' );

require_once OIF_PLUGIN_DIR . 'includes/helpers.php';
require_once OIF_PLUGIN_DIR . 'includes/class-scanner.php';
require_once OIF_PLUGIN_DIR . 'includes/class-ajax.php';
require_once OIF_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Default plugin settings.
 *
 * @return array<string, int>
 */
function oif_get_default_settings() {
	return array(
		'max_file_size_kb' => 500,
		'max_width'        => 2000,
		'max_height'       => 2000,
		'batch_size'       => 50,
		'cache_ttl_hours'  => 24,
	);
}

/**
 * Get plugin settings merged with defaults.
 *
 * @return array<string, int>
 */
function oif_get_settings() {
	$settings = get_option( OIF_OPTION_KEY, array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	return wp_parse_args( $settings, oif_get_default_settings() );
}

/**
 * Plugin activation.
 */
function oif_activate() {
	if ( false === get_option( OIF_OPTION_KEY ) ) {
		add_option( OIF_OPTION_KEY, oif_get_default_settings() );
	}
}
register_activation_hook( __FILE__, 'oif_activate' );

/**
 * Initialize plugin components.
 */
function oif_init() {
	OIF_Admin_Page::init();
	OIF_Ajax::init();
}
add_action( 'plugins_loaded', 'oif_init' );
