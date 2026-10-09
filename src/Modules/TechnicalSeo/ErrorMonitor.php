<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors and logs front-end 404 errors safely and efficiently.
 *
 * Architecture:
 *   - Frontend: Pushes 404 details into a lightweight transient buffer (zero DB writes).
 *   - Background: A WP-Cron job flushes the buffer into the database every hour.
 *   - Limits: Caps stored logs at MAX_LOGS (100) to prevent database ballooning.
 *   - No SHOW TABLES: Table existence is checked once on activation and cached.
 */
class ErrorMonitor {

	private const OPTION_KEY          = 'holvek_404_logs';
	private const BUFFER_TRANSIENT    = 'holvek_404_buffer';
	private const TABLE_EXISTS_OPTION = 'holvek_404_table_exists';
	private const MAX_LOGS            = 100;
	private const MAX_BUFFER          = 50; // Cap buffer size to prevent transient bloat
	private const PAGE_SIZE           = 50;
	private const MAX_STORED_URI      = 200;
	private const MAX_STORED_REFERRER = 200;
	private const MAX_STORED_AGENT    = 160;
	private const RETENTION_DAYS      = 90;
	private const FLUSH_HOOK          = 'holvek_flush_404_buffer';
	private const CLEANUP_HOOK        = 'holvek_404_cleanup';

	/**
	 * Boot the error monitor — register the background flush cron.
	 */
	public function boot(): void {
		add_action( self::FLUSH_HOOK, array( $this, 'flushBufferToDatabase' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'purgeExpiredLogs' ) );
	}

	/**
	 * Schedule the hourly flush and the daily 90-day purge.
	 * Activation calls this. Each worker calls its own schedule again when that event is missing.
	 */
	public static function schedule(): void {
		self::scheduleFlush();
		self::scheduleCleanup();
	}

	/**
	 * Schedule the hourly buffer flush when it is missing.
	 */
	public static function scheduleFlush(): void {
		if ( wp_next_scheduled( self::FLUSH_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'hourly', self::FLUSH_HOOK );
	}

	/**
	 * Schedule the daily purge. docs/third-party-services.md says 404 logs are removed after 90 days.
	 */
	public static function scheduleCleanup(): void {
		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
	}

	/**
	 * Intercept front-end queries and buffer the 404 — zero database writes.
	 */
	public function log404Errors(): void {
		if ( ! is_404() ) {
			return;
		}

		// Avoid logging common static assets and hack attempts. The check uses the raw request so a query string cannot hide a probe.
		$rawUri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( $rawUri === '' || preg_match( '/\.(jpg|jpeg|png|gif|ico|css|js|map|xml|txt|woff2|woff|ttf)$/i', $rawUri ) ) {
			return;
		}

		if ( strpos( $rawUri, 'wp-admin' ) !== false || strpos( $rawUri, 'wp-login' ) !== false || strpos( $rawUri, '.env' ) !== false ) {
			return;
		}

		$uri = $this->storedUri( $rawUri );
		if ( $uri === '' ) {
			return;
		}

		$referer   = $this->storedReferrer( isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' );
		$userAgent = $this->storedUserAgent( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' );

		// Push into the in-memory transient buffer (no DB write during HTTP request)
		$buffer = get_transient( self::BUFFER_TRANSIENT );
		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		// Deduplicate in the buffer: if the URI already exists, increment hits
		$found = false;
		foreach ( $buffer as &$entry ) {
			if ( $entry['uri'] === $uri ) {
				++$entry['hits'];
				$entry['last_hit']   = current_time( 'mysql' );
				$entry['referer']    = $referer ?: $entry['referer'];
				$entry['user_agent'] = $userAgent;
				$found               = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found && count( $buffer ) < self::MAX_BUFFER ) {
			$buffer[] = array(
				'uri'        => $uri,
				'hits'       => 1,
				'referer'    => $referer,
				'user_agent' => $userAgent,
				'last_hit'   => current_time( 'mysql' ),
			);
		}

		// Store back — short TTL, flushed by cron within the hour
		set_transient( self::BUFFER_TRANSIENT, $buffer, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Background cron callback: flush the transient buffer into the database.
	 * Runs outside the HTTP request lifecycle — safe for batch DB writes.
	 */
	public function flushBufferToDatabase(): void {
		self::scheduleFlush();

		$buffer = get_transient( self::BUFFER_TRANSIENT );
		if ( empty( $buffer ) || ! is_array( $buffer ) ) {
			return;
		}

		// Check table existence from cached option (set during activation)
		if ( get_option( self::TABLE_EXISTS_OPTION ) !== 'yes' ) {
			// Re-verify once and cache the result
			global $wpdb;
			$table = $wpdb->prefix . 'holvek_404_logs';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				update_option( self::TABLE_EXISTS_OPTION, 'yes', false );
			} else {
				return; // Table doesn't exist, skip
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'holvek_404_logs';

		foreach ( $buffer as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$entry = $this->normalizeStoredEntry( $entry );
			if ( $entry['uri'] === '' ) {
				continue;
			}
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}holvek_404_logs WHERE uri = %s", $entry['uri'] ) );

			if ( $existing ) {
				$wpdb->update(
					$table,
					array(
						'hits'       => intval( $existing->hits ) + intval( $entry['hits'] ),
						'last_hit'   => $entry['last_hit'],
						'referer'    => $entry['referer'] ?: $existing->referer,
						'user_agent' => $entry['user_agent'],
					),
					array( 'id' => $existing->id )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'id'         => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'err_404_', true ),
						'uri'        => $entry['uri'],
						'hits'       => intval( $entry['hits'] ),
						'referer'    => $entry['referer'],
						'user_agent' => $entry['user_agent'],
						'last_hit'   => $entry['last_hit'],
					)
				);
			}
		}

		// Trim oldest logs if total exceeds MAX_LOGS
		$totalLogs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs" );
		if ( $totalLogs > self::MAX_LOGS ) {
			$keepIds = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}holvek_404_logs ORDER BY last_hit DESC LIMIT %d", self::MAX_LOGS ) );
			if ( ! empty( $keepIds ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $keepIds ), '%s' ) );
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated %d or %s tokens. Values are passed to prepare().
						"DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE id NOT IN ($placeholders)",
						...$keepIds
					)
				);
			} else {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}holvek_404_logs" );
			}
		}

		// Clear the buffer after successful flush
		delete_transient( self::BUFFER_TRANSIENT );
	}

	/**
	 * Mark the 404 table as existing (called from Installer on activation).
	 */
	public static function markTableExists(): void {
		update_option( self::TABLE_EXISTS_OPTION, 'yes', false );
	}

	/**
	 * Delete 404 rows older than the retention window. The default is 90 days.
	 */
	public function purgeExpiredLogs(): void {
		self::scheduleCleanup();

		$days   = self::RETENTION_DAYS;
		$config = get_option( 'holvek_privacy_retention', array() );
		if ( is_array( $config ) && isset( $config['keep_404_logs_days'] ) ) {
			$days = max( 1, (int) $config['keep_404_logs_days'] );
		}

		if ( get_option( self::TABLE_EXISTS_OPTION ) !== 'yes' ) {
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE last_hit < DATE_SUB( NOW(), INTERVAL %d DAY )",
				$days
			)
		);
	}

	/**
	 * Path only, no query string, bounded length.
	 */
	private function storedUri( string $uri ): string {
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			$path = strtok( $uri, '?' );
			$path = is_string( $path ) ? $path : '';
		}

		return substr( sanitize_text_field( $path ), 0, self::MAX_STORED_URI );
	}

	/**
	 * Referrer without userinfo, query, or fragment. Anything other than http or https is dropped.
	 */
	private function storedReferrer( string $referrer ): string {
		$referrer = trim( $referrer );
		if ( $referrer === '' ) {
			return '';
		}

		$parts = wp_parse_url( $referrer );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}

		$path    = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$rebuilt = $scheme . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$rebuilt .= ':' . (int) $parts['port'];
		}
		$rebuilt .= $path;

		return substr( esc_url_raw( $rebuilt ), 0, self::MAX_STORED_REFERRER );
	}

	/**
	 * Browser name only, shortened so a unique token in the agent string is not kept in full.
	 */
	private function storedUserAgent( string $agent ): string {
		$agent = sanitize_text_field( $agent );
		return substr( $agent, 0, self::MAX_STORED_AGENT );
	}

	/**
	 * @param array<string, mixed> $entry Buffer row.
	 * @return array{uri:string,hits:int,referer:string,user_agent:string,last_hit:string}
	 */
	private function normalizeStoredEntry( array $entry ): array {
		return array(
			'uri'        => $this->storedUri( (string) ( $entry['uri'] ?? '' ) ),
			'hits'       => (int) ( $entry['hits'] ?? 1 ),
			'referer'    => $this->storedReferrer( (string) ( $entry['referer'] ?? '' ) ),
			'user_agent' => $this->storedUserAgent( (string) ( $entry['user_agent'] ?? '' ) ),
			'last_hit'   => sanitize_text_field( (string) ( $entry['last_hit'] ?? '' ) ),
		);
	}

	/**
	 * Expose REST routes for 404 monitoring.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/errors/404',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get404LogsEndpoint' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clear404LogsEndpoint' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		// BL-001: one-click redirect creation from a 404 log entry
		register_rest_route(
			'holvek/v1',
			'/errors/404/create-redirect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createRedirectFromLog' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);
	}

	/**
	 * BL-001: Create a redirect rule from an existing 404 log entry.
	 *
	 * Payload: { id: string, target: string, code?: int }
	 */
	public function createRedirectFromLog( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$id     = sanitize_text_field( $params['id'] ?? '' );
		$target = sanitize_text_field( $params['target'] ?? '' );
		$code   = in_array( (int) ( $params['code'] ?? 301 ), array( 301, 302, 307, 410, 451 ), true )
			? (int) $params['code']
			: 301;

		if ( empty( $id ) || empty( $target ) ) {
			return new \WP_Error( 'missing_params', __( 'id and target are required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$isSafe = false;
		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			$isSafe = true;
		} else {
			$parts    = wp_parse_url( $target );
			$siteHost = wp_parse_url( home_url(), PHP_URL_HOST );
			$isSafe   = is_array( $parts )
				&& in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
				&& ! empty( $parts['host'] )
				&& strtolower( $parts['host'] ) === strtolower( (string) $siteHost );
		}
		if ( ! $isSafe ) {
			return new \WP_Error( 'unsafe_target', __( 'Redirect target must be a local path or URL on this site.', 'holvek' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$logTable = $wpdb->prefix . 'holvek_404_logs';

		// Fetch the log entry to get the source URI
		$entry = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}holvek_404_logs WHERE id = %s", $id ),
			ARRAY_A
		);

		if ( empty( $entry ) ) {
			return new \WP_Error( 'not_found', __( '404 log entry not found.', 'holvek' ), array( 'status' => 404 ) );
		}

		$source = $entry['uri'];

		// Insert into redirect table
		$redirectTable = $wpdb->prefix . 'holvek_redirects';
		$redirectId    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'redir_', true );

		// Check for duplicate
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects WHERE source = %s", $source )
		);
		if ( (int) $exists > 0 ) {
			return new \WP_Error( 'duplicate', __( 'A redirect for this source already exists.', 'holvek' ), array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert(
			$redirectTable,
			array(
				'id'       => $redirectId,
				'source'   => $source,
				'target'   => $target,
				'code'     => $code,
				'is_regex' => 0,
			)
		);

		if ( $inserted === false ) {
			return new \WP_Error( 'db_error', __( 'Failed to create redirect.', 'holvek' ), array( 'status' => 500 ) );
		}

		// Mark 404 log entry as resolved
		$wpdb->update( $logTable, array( 'resolved' => 1 ), array( 'id' => $id ) );

		// Flush regex cache so new rule is live
		wp_cache_delete( 'holvek_regex_redirects', 'holvek' );

		return rest_ensure_response(
			array(
				'success'  => true,
				'redirect' => array(
					'source' => $source,
					'target' => $target,
					'code'   => $code,
				),
			)
		);
	}

	public function checkPermission(): bool {
		return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
	}

	public function get404LogsEndpoint( \WP_REST_Request $request ): \WP_REST_Response {
		// Force-flush any pending buffer so admin sees the latest data
		$this->flushBufferToDatabase();
		$this->migrateLegacyLogs();

		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = (int) $request->get_param( 'per_page' );
		if ( $perPage < 1 ) {
			$perPage = self::PAGE_SIZE;
		}

		return rest_ensure_response( $this->logPage( $page, $perPage ) );
	}

	/**
	 * Move legacy option rows into the table without selecting them all back.
	 */
	private function migrateLegacyLogs(): void {
		if ( get_option( self::TABLE_EXISTS_OPTION ) !== 'yes' ) {
			return;
		}

		$legacyLogs = get_option( self::OPTION_KEY );
		if ( empty( $legacyLogs ) || ! is_array( $legacyLogs ) ) {
			return;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'holvek_404_logs';
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs" );
		if ( $existing > 0 ) {
			delete_option( self::OPTION_KEY );
			return;
		}

		foreach ( $legacyLogs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'id'         => $log['id'] ?? ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'err_404_', true ) ),
					'uri'        => $log['uri'] ?? '',
					'hits'       => intval( $log['hits'] ?? 1 ),
					'referer'    => $log['referer'] ?? '',
					'user_agent' => $log['user_agent'] ?? '',
					'last_hit'   => $log['last_hit'] ?? current_time( 'mysql' ),
				)
			);
		}

		delete_option( self::OPTION_KEY );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function logPage( int $page, int $perPage ): array {
		$page    = max( 1, $page );
		$perPage = min( self::MAX_LOGS, max( 1, $perPage ) );

		if ( get_option( self::TABLE_EXISTS_OPTION ) !== 'yes' ) {
			return array(
				'items'       => array(),
				'logs'        => array(),
				'total'       => 0,
				'page'        => $page,
				'total_pages' => 0,
			);
		}

		global $wpdb;
		$offset = ( $page - 1 ) * $perPage;
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs" );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, uri, hits, referer, user_agent, last_hit, resolved FROM {$wpdb->prefix}holvek_404_logs ORDER BY last_hit DESC, id DESC LIMIT %d OFFSET %d",
				$perPage,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		foreach ( $rows as &$row ) {
			$row['hits'] = intval( $row['hits'] );
		}
		unset( $row );

		return array(
			'items'       => $rows,
			'logs'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => $perPage > 0 ? (int) ceil( $total / $perPage ) : 0,
		);
	}

	public function clear404LogsEndpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$id     = sanitize_text_field( $params['id'] ?? '' );

		global $wpdb;
		$table = $wpdb->prefix . 'holvek_404_logs';

		if ( empty( $id ) ) {
			// Clear all logs
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE 1 = %d", 1 ) );
			return rest_ensure_response(
				array(
					'success' => true,
					'logs'    => array(),
				)
			);
		}

		// Clear specific log item
		$wpdb->delete( $table, array( 'id' => $id ) );

		$page = $this->logPage( 1, self::PAGE_SIZE );

		return rest_ensure_response(
			array(
				'success' => true,
				'logs'    => $page['logs'],
				'items'   => $page['items'],
				'total'   => $page['total'],
				'page'    => $page['page'],
			)
		);
	}

	/**
	 * Cleanup on plugin deactivation: clear the scheduled flush event.
	 */
	public static function deactivate(): void {
		foreach ( array( self::FLUSH_HOOK, self::CLEANUP_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
