<?php
/**
 * Plugin Name: Holvek
 * Plugin URI: https://holvek.com
 * Description: Technical SEO, structured data, sitemaps, redirects, and indexing tools for WordPress.
 * Version: 1.0.1
 * Author: Skoorite Limited
 * Author URI: https://holvek.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: holvek
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 *
 * @package Holvek
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
if ( ! defined( 'HOLVEK_VERSION' ) ) {
	define( 'HOLVEK_VERSION', '1.0.1' );
}
if ( ! defined( 'HOLVEK_PLUGIN_FILE' ) ) {
	define( 'HOLVEK_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'HOLVEK_PLUGIN_DIR' ) ) {
	define( 'HOLVEK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'HOLVEK_PLUGIN_URL' ) ) {
	define( 'HOLVEK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}


// Defensive PHP version guard.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Holvek', 'holvek' ),
				sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__( 'Holvek requires PHP version %1$s or higher. Your server is running PHP %2$s. Please upgrade your PHP version.', 'holvek' ),
					'8.2',
					esc_html( PHP_VERSION )
				)
			);
		}
	);
	return;
}

// Require the Composer autoloader.
if ( file_exists( HOLVEK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once HOLVEK_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Show an admin notice if composer is not installed.
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Holvek', 'holvek' ),
				wp_kses(
					__( 'Please run <code>composer install</code> in the plugin directory.', 'holvek' ),
					array( 'code' => array() )
				)
			);
		}
	);
	return;
}

// Initialize the plugin.
function holvek_init() {
	$plugin = \Holvek\WordPress\Plugin::getInstance();
	$plugin->boot();
}

add_action( 'plugins_loaded', 'holvek_init' );

// Global template helper for themes to render breadcrumbs.
if ( ! function_exists( 'holvek_breadcrumbs' ) ) {
	function holvek_breadcrumbs(): void {
		$renderer = new \Holvek\WordPress\Modules\Breadcrumbs\BreadcrumbRenderer();
		echo wp_kses_post( $renderer->render() );
	}
}

// Activation and Deactivation hooks.
register_activation_hook( __FILE__, array( \Holvek\WordPress\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Holvek\WordPress\Plugin::class, 'deactivate' ) );
