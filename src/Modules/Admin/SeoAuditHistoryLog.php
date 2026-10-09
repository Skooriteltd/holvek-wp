<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SeoAuditHistoryLog
 *
 * BL-042: Maintains a tamper-evident audit trail of all Holvek SEO setting
 * changes. Records who changed what, when, and what the previous value was.
 */
class SeoAuditHistoryLog {

	private const TABLE_NAME      = 'holvek_audit_log';
	private const LOG_MAX_ROWS    = 10000;
	private const PRUNE_CRON_HOOK = 'holvek_prune_audit_log';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		$this->hookMetaChanges();
		add_action( self::PRUNE_CRON_HOOK, array( $this, 'pruneOldEntries' ) );
	}

	/**
	 * Schedule the daily prune. Activation calls this. The worker calls it again when the event is missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::PRUNE_CRON_HOOK );
	}

	/**
	 * Prune audit log entries exceeding MAX_ROWS. Called by daily cron.
	 */
	public function pruneOldEntries(): void {
		self::schedule();

		global $wpdb;

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_audit_log" );
		if ( $count <= self::LOG_MAX_ROWS ) {
			return;
		}

		$deleteLimit = $count - self::LOG_MAX_ROWS;
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}holvek_audit_log ORDER BY id ASC LIMIT %d", $deleteLimit )
		);
	}

	/**
	 * Cleanup on plugin deactivation: clear the pruning cron.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::PRUNE_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::PRUNE_CRON_HOOK );
		}
	}

	public static function createTable(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action      VARCHAR(64) NOT NULL,
            field       VARCHAR(128) NOT NULL,
            old_value   TEXT,
            new_value   TEXT,
            context     VARCHAR(255),
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function hookMetaChanges(): void {
		// Track changes to all _holvek_* post meta
		add_action( 'updated_post_meta', array( $this, 'onMetaUpdated' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'onMetaAdded' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'onMetaDeleted' ), 10, 4 );

		// Track changes to holvek_ options
		add_action( 'updated_option', array( $this, 'onOptionUpdated' ), 10, 3 );
	}

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public function onMetaUpdated( $metaId, int $postId, string $metaKey, $newValue ): void {
		if ( strpos( $metaKey, '_holvek_' ) !== 0 ) {
			return;
		}

		$oldValue = get_post_meta( $postId, $metaKey, true );
		$this->log( $postId, 'meta_updated', $metaKey, (string) $oldValue, (string) $newValue );
	}

	public function onMetaAdded( $metaId, int $postId, string $metaKey, $metaValue ): void {
		if ( strpos( $metaKey, '_holvek_' ) !== 0 ) {
			return;
		}

		$this->log( $postId, 'meta_added', $metaKey, '', (string) $metaValue );
	}

	public function onMetaDeleted( $metaIds, int $postId, string $metaKey, $metaValue ): void {
		if ( strpos( $metaKey, '_holvek_' ) !== 0 ) {
			return;
		}

		$this->log( $postId, 'meta_deleted', $metaKey, (string) $metaValue, '' );
	}

	public function onOptionUpdated( string $option, $oldValue, $newValue ): void {
		if ( strpos( $option, 'holvek_' ) !== 0 ) {
			return;
		}

		// Skip high-frequency transients
		if ( strpos( $option, 'holvek_audit_log' ) !== false ) {
			return;
		}

		$this->log( 0, 'option_updated', $option, maybe_serialize( $oldValue ), maybe_serialize( $newValue ), 'site_option' );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/audit-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getLogs' ),
				'permission_callback' => $adminCap,
				'args'                => array(
					'post_id'  => array(
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'page'     => array(
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'per_page' => array(
						'sanitize_callback' => 'absint',
						'default'           => 50,
					),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/audit-log/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'exportCsv' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	public function getLogs( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$postId  = (int) $request->get_param( 'post_id' );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset  = ( $page - 1 ) * $perPage;

		if ( $postId > 0 ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_audit_log WHERE post_id = %d", $postId )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}holvek_audit_log WHERE post_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$postId,
					$perPage,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_audit_log" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}holvek_audit_log ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$perPage,
					$offset
				),
				ARRAY_A
			);
		}

		// Enrich with user display name
		foreach ( $rows as &$row ) {
			$row['user_name'] = $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] )?->display_name ?? '' : 'System';
		}

		return rest_ensure_response(
			array(
				'items'       => $rows,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $perPage ),
				'page'        => $page,
			)
		);
	}

	public function exportCsv( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$perPage = (int) $request->get_param( 'per_page' );
		if ( $perPage < 1 ) {
			$perPage = 200;
		}
		$perPage       = min( 200, $perPage );
		$requestedPage = (int) $request->get_param( 'page' );
		$lines         = array( 'id,user_id,post_id,action,field,old_value,new_value,context,created_at' );
		$page          = $requestedPage > 0 ? $requestedPage : 1;
		$lastPage      = $requestedPage > 0 ? $requestedPage : 50;
		$fetched       = 0;

		do {
			$offset = ( $page - 1 ) * $perPage;
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, post_id, action, field, old_value, new_value, context, created_at FROM {$wpdb->prefix}holvek_audit_log ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
					$perPage,
					$offset
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			foreach ( $rows as $r ) {
				$lines[] = implode( ',', array_map( fn( $v ) => '"' . str_replace( '"', '""', (string) $v ) . '"', $r ) );
			}
			$fetched = count( $rows );
			++$page;
		} while ( $requestedPage < 1 && $fetched === $perPage && $page <= $lastPage );

		$response = rest_ensure_response( implode( "\n", $lines ) );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename=holvek-audit-log.csv' );
		return $response;
	}

	// ── Core log writer ───────────────────────────────────────────────────────

	public function log( int $postId, string $action, string $field, string $oldValue, string $newValue, string $context = '' ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->insert(
			$table,
			array(
				'user_id'    => (int) get_current_user_id(),
				'post_id'    => $postId,
				'action'     => $action,
				'field'      => $field,
				'old_value'  => mb_substr( $oldValue, 0, 1000 ),
				'new_value'  => mb_substr( $newValue, 0, 1000 ),
				'context'    => $context,
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Pruning is handled by the daily cron job (holvek_prune_audit_log)
		// to avoid expensive COUNT+DELETE queries on every meta write.
	}
}
