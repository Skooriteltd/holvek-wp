<?php
/**
 * Holvek uninstall routine.
 *
 * Plugin data is retained by default. An administrator must explicitly enable
 * `holvek_delete_data_on_uninstall` before deletion to be permitted.
 *
 * @package Holvek
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove the legacy public backup directory. It lives under wp-content, so one
 * successful delete covers every site. Paths outside that directory are ignored.
 */
$holvekRemoveBackupDirectory = static function (): void {
	if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
		return;
	}

	$content = rtrim( (string) WP_CONTENT_DIR, '/\\' );
	$root    = $content . '/holvek-backups';
	if ( ! is_dir( $root ) ) {
		return;
	}

	$realContent = realpath( $content );
	$realRoot    = realpath( $root );
	if ( false === $realContent || false === $realRoot ) {
		return;
	}

	$prefix = rtrim( $realContent, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	if ( ! str_starts_with( $realRoot, $prefix ) || 'holvek-backups' !== basename( $realRoot ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $realRoot, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			continue;
		}
		wp_delete_file( $item->getPathname() );
	}

	rmdir( $realRoot ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
};

/**
 * Clear runtime work for one site and, when explicitly requested, remove only
 * data owned by this plugin. Table names are calculated after switch_to_blog().
 * Capabilities are removed even when the site owner keeps the data.
 */
$holvekUninstallSite = static function ( bool $deleteData ) use ( $holvekRemoveBackupDirectory ): void {
	global $wpdb;

	$cronHooks = array(
		'holvek_process_job',
		'holvek_scan_orphaned',
		'holvek_stale_cornerstone_check',
		'holvek_weekly_audit',
		'holvek_monthly_audit',
		'holvek_scheduled_audit',
		'holvek_rank_check',
		'holvek_404_cleanup',
		'holvek_apply_retention',
		'holvek_run_technical_audit',
		'holvek_blc_scan_batch',
		'holvek_usage_metering_weekly',
		'holvek_flush_404_buffer',
		'holvek_cornerstone_staleness_check',
		'holvek_weekly_usage_email',
		'holvek_prune_audit_log',
		'holvek_indexnow_flush',
	);

	foreach ( $cronHooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
			as_unschedule_all_actions( $hook, array(), 'holvek' );
		}
	}

	foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $roleName ) {
		$role = get_role( $roleName );
		if ( $role ) {
			foreach ( array( 'manage_seo', 'view_seo_reports', 'manage_redirects', 'edit_seo_meta' ) as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	if ( ! $deleteData ) {
		return;
	}

	$uploads     = wp_upload_dir();
	$uploadsBase = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );
	$backups     = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_holvek_compression_original'
		)
	);
	foreach ( $backups as $backup ) {
		$backup = (string) $backup;
		if ( $uploadsBase !== '' && str_starts_with( $backup, $uploadsBase ) && is_file( $backup ) ) {
			wp_delete_file( $backup );
		}
	}

	$likeOptions          = $wpdb->esc_like( 'holvek_' ) . '%';
	$likeMeta             = $wpdb->esc_like( '_holvek_' ) . '%';
	$likeUserMeta         = $wpdb->esc_like( 'holvek_' ) . '%';
	$likeTransient        = $wpdb->esc_like( '_transient_holvek_' ) . '%';
	$likeTransientTimeout = $wpdb->esc_like( '_transient_timeout_holvek_' ) . '%';

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $likeOptions ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $likeTransient ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $likeTransientTimeout ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $likeMeta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $likeUserMeta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$tables = array(
		$wpdb->prefix . 'holvek_redirects',
		$wpdb->prefix . 'holvek_404_logs',
		$wpdb->prefix . 'holvek_sitemap_settings',
		$wpdb->prefix . 'holvek_links',
		$wpdb->prefix . 'holvek_ai_usage',
		$wpdb->prefix . 'holvek_usage_metering',
		$wpdb->prefix . 'holvek_audit_log',
		$wpdb->prefix . 'holvek_rank_history',
		$wpdb->prefix . 'holvek_broken_links',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	$holvekRemoveBackupDirectory();
};

$holvek_delete_data = get_option( 'holvek_delete_data_on_uninstall', 'no' ) === 'yes';
$holvekUninstallSite( $holvek_delete_data );

if ( is_multisite() ) {
	$holvek_site_ids        = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	$holvek_current_blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
	foreach ( $holvek_site_ids as $holvek_site_id ) {
		if ( (int) $holvek_site_id === (int) $holvek_current_blog_id ) {
			continue;
		}
		switch_to_blog( (int) $holvek_site_id );
		$holvekUninstallSite( $holvek_delete_data );
		restore_current_blog();
	}

	if ( $holvek_delete_data ) {
		$holvek_like_network_options = $wpdb->esc_like( 'holvek_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $holvek_like_network_options ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
