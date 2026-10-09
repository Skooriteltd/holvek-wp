<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database operations for Holvek sitemap settings using the custom table.
 * Fallbacks to options and handles transparent on-demand migrations.
 */
class SitemapSettings {

	private static string $table = 'holvek_sitemap_settings';

	/**
	 * Retrieve a sitemap configuration setting.
	 */
	public static function get( string $key, $fallback = null ) {
		global $wpdb;
		$tableName = $wpdb->prefix . self::$table;

		// Check if custom table exists; if not, fallback to options
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tableName ) ) ) !== $tableName ) {
			return get_option( 'holvek_' . $key, $fallback );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT setting_value FROM {$wpdb->prefix}holvek_sitemap_settings WHERE setting_key = %s", $key ) );
		if ( $row !== null ) {
			return maybe_unserialize( $row->setting_value );
		}

		// Check fallback to option table for backwards compatibility
		$optVal = get_option( 'holvek_' . $key, null );
		if ( $optVal !== null ) {
			// One-time self-healing migration
			self::set( $key, $optVal );
			delete_option( 'holvek_' . $key );
			return $optVal;
		}

		return $fallback;
	}

	/**
	 * Save a sitemap configuration setting.
	 */
	public static function set( string $key, $value ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . self::$table;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tableName ) ) ) !== $tableName ) {
			update_option( 'holvek_' . $key, $value );
			return;
		}

		$serializedValue = maybe_serialize( $value );

		$wpdb->replace(
			$tableName,
			array(
				'setting_key'   => $key,
				'setting_value' => $serializedValue,
			)
		);
	}
}
