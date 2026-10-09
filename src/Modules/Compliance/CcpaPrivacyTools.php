<?php

namespace Holvek\WordPress\Modules\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CcpaPrivacyTools
 *
 * CCPA/GDPR compliance toolkit:
 * - Data inventory endpoint
 * - User data export & deletion
 * - Configurable retention policy with auto-purge cron
 * - Integrates with WordPress built-in privacy exporters/erasers
 * - Honours GPC (Global Privacy Control) header
 *
 * BL-008
 */
class CcpaPrivacyTools {

	private const RETENTION_OPTION  = 'holvek_privacy_retention';
	private const HONOUR_GPC_OPTION = 'holvek_honour_gpc';
	private const CRON_HOOK         = 'holvek_apply_retention';

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( self::CRON_HOOK, array( $this, 'applyRetentionPolicy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'registerEraser' ) );
		add_action( 'admin_init', array( $this, 'addPrivacyPolicyText' ) );
		add_action( 'init', array( $this, 'handleGpcSignal' ) );
	}

	/**
	 * Schedule the daily retention purge. Activation calls this. The worker calls it again when the event is missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::CRON_HOOK );
	}

	// ── GPC signal ────────────────────────────────────────────────────────────

	public function handleGpcSignal(): void {
		if ( get_option( self::HONOUR_GPC_OPTION, 'yes' ) !== 'yes' ) {
			return;
		}

		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_SEC_GPC'] ) )
			: '';
		if ( '1' === $gpc ) {
			// Signal to other modules that AI/tracking features should be suppressed
			do_action( 'holvek_gpc_opt_out' );
			setcookie(
				'holvek_gpc',
				'1',
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/privacy/data-inventory',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getDataInventory' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/privacy/export',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'exportUserData' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/privacy/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deleteUserData' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/privacy/retention-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getRetentionSettings' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveRetentionSettings' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/privacy/apply-retention',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'triggerRetention' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/privacy/gpc-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( array( 'honour_gpc' => get_option( self::HONOUR_GPC_OPTION, 'yes' ) === 'yes' ) ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => function ( \WP_REST_Request $req ) {
						$p = $req->get_json_params();
						update_option( self::HONOUR_GPC_OPTION, ! empty( $p['honour_gpc'] ) ? 'yes' : 'no', true );
						return rest_ensure_response( array( 'success' => true ) );
					},
					'permission_callback' => $adminCap,
				),
			)
		);
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	public function getDataInventory( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'data_types' => array(
					array(
						'store'       => 'holvek_404_logs (DB table)',
						'data_type'   => 'URL request logs, referrer URLs, User-Agent strings',
						'sensitivity' => 'low',
						'retention'   => 'Configurable (default: 90 days)',
					),
					array(
						'store'       => 'holvek_redirects (DB table)',
						'data_type'   => 'Redirect rules (URLs only, no PII)',
						'sensitivity' => 'none',
						'retention'   => 'Indefinite',
					),
					array(
						'store'       => 'holvek_ai_usage (DB table)',
						'data_type'   => 'AI token usage per WordPress user ID',
						'sensitivity' => 'low',
						'retention'   => 'Configurable (default: 365 days)',
					),
					array(
						'store'       => 'wp_options (holvek_* keys)',
						'data_type'   => 'Plugin settings, API keys (encrypted)',
						'sensitivity' => 'medium',
						'retention'   => 'Until plugin uninstalled',
					),
					array(
						'store'       => 'wp_postmeta (_holvek_* keys)',
						'data_type'   => 'Per-post SEO metadata (titles, descriptions, schema overrides)',
						'sensitivity' => 'none',
						'retention'   => 'Until post deleted',
					),
				),
			)
		);
	}

	public function exportUserData( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		$email  = sanitize_email( $params['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'A valid email address is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $this->getUserData( $email ),
			)
		);
	}

	public function deleteUserData( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		$email  = sanitize_email( $params['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'A valid email address is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$deleted = $this->eraseUserData( $email );

		return rest_ensure_response(
			array(
				'success'         => true,
				'records_deleted' => $deleted,
			)
		);
	}

	public function getRetentionSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$defaults = array(
			'keep_404_logs_days'   => 90,
			'keep_usage_logs_days' => 365,
		);
		return rest_ensure_response( array_merge( $defaults, get_option( self::RETENTION_OPTION, array() ) ) );
	}

	public function saveRetentionSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$config = array(
			'keep_404_logs_days'   => max( 1, (int) ( $params['keep_404_logs_days'] ?? 90 ) ),
			'keep_usage_logs_days' => max( 1, (int) ( $params['keep_usage_logs_days'] ?? 365 ) ),
		);
		update_option( self::RETENTION_OPTION, $config );
		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $config,
			)
		);
	}

	public function triggerRetention( \WP_REST_Request $request ): \WP_REST_Response {
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Retention purge scheduled.', 'holvek' ),
			)
		);
	}

	// ── Cron: retention purge ─────────────────────────────────────────────────

	public function applyRetentionPolicy(): void {
		self::schedule();

		global $wpdb;

		$config = array_merge(
			array(
				'keep_404_logs_days'   => 90,
				'keep_usage_logs_days' => 365,
			),
			get_option( self::RETENTION_OPTION, array() )
		);

		// Purge old 404 logs
		$logsTable   = $wpdb->prefix . 'holvek_404_logs';
		$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logsTable ) ) );
		if ( $tableExists ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE last_hit < DATE_SUB(NOW(), INTERVAL %d DAY)",
					$config['keep_404_logs_days']
				)
			);
		}

		// Purge old AI usage logs
		$usageTable  = $wpdb->prefix . 'holvek_ai_usage';
		$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $usageTable ) ) );
		if ( $tableExists ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}holvek_ai_usage WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
					$config['keep_usage_logs_days']
				)
			);
		}
	}

	// ── WordPress privacy framework integration ───────────────────────────────

	public function addPrivacyPolicyText(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$items   = array(
			__( '<strong>AI features:</strong> If an editor runs AI-assisted writing, an excerpt of the post may be sent to the provider configured on this site, such as OpenAI, Anthropic, or a confirmed Ollama host.', 'holvek' ),
			__( '<strong>External services:</strong> Indexing features send published URLs only after an administrator turns them on.', 'holvek' ),
			__( '<strong>404 logs:</strong> Holvek stores the missing path, the referring address without its query string, and a shortened browser name. Rows are kept for 90 days and then deleted. They are not linked to a WordPress account unless the stored path or referrer contains that account email or login.', 'holvek' ),
			__( '<strong>AI usage:</strong> Token counts are stored with the WordPress user ID of the person who ran the feature. A personal-data export and erase include those rows. They are kept for 365 days by default.', 'holvek' ),
			__( '<strong>Audit log:</strong> Changes to titles, descriptions, and settings are stored with the WordPress user ID of the person who made the change. A personal-data export and erase include that person’s rows.', 'holvek' ),
			__( '<strong>Search Console and Analytics:</strong> Google Search Console and Google Analytics 4 credentials are site settings. They are not included in a visitor or account export.', 'holvek' ),
			__( '<strong>Cookie notice:</strong> The visitor’s choice is stored in the holvek_consent cookie in the browser. It does not store an email address or a WordPress user ID, so it is not part of an account export.', 'holvek' ),
		);
		$content = '<p>' . __( 'Holvek stores these records on this site. The site owner decides the legal basis. This plugin does not make a site compliant with a privacy law.', 'holvek' ) . '</p><ul>';
		foreach ( $items as $item ) {
			$content .= '<li>' . $item . '</li>';
		}
		$content .= '</ul>';

		wp_add_privacy_policy_content( __( 'Holvek SEO', 'holvek' ), $content );
	}

	public function registerExporter( array $exporters ): array {
		$exporters['holvek'] = array(
			'exporter_friendly_name' => __( 'Holvek SEO Data', 'holvek' ),
			'callback'               => array( $this, 'wpPrivacyExporter' ),
		);
		return $exporters;
	}

	public function registerEraser( array $erasers ): array {
		$erasers['holvek'] = array(
			'eraser_friendly_name' => __( 'Holvek SEO Data', 'holvek' ),
			'callback'             => array( $this, 'wpPrivacyEraser' ),
		);
		return $erasers;
	}

	public function wpPrivacyExporter( string $email, int $page = 1 ): array {
		$items = array();
		$data  = $this->getUserData( $email, $page );

		foreach ( $data['records']['ai_usage'] as $row ) {
			$items[] = array(
				'group_id'    => 'holvek_ai_usage',
				'group_label' => __( 'Holvek AI Usage', 'holvek' ),
				'item_id'     => 'ai-usage-' . $row->id,
				'data'        => array(
					array(
						'name'  => 'Provider',
						'value' => $row->provider,
					),
					array(
						'name'  => 'Feature',
						'value' => $row->feature,
					),
					array(
						'name'  => 'Tokens',
						'value' => $row->tokens_used,
					),
					array(
						'name'  => 'Date',
						'value' => $row->created_at,
					),
				),
			);
		}

		foreach ( $data['records']['logs_404'] as $row ) {
			$items[] = array(
				'group_id'    => 'holvek_404_logs',
				'group_label' => __( 'Holvek 404 logs', 'holvek' ),
				'item_id'     => '404-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Missing path', 'holvek' ),
						'value' => (string) $row->uri,
					),
					array(
						'name'  => __( 'Referrer', 'holvek' ),
						'value' => (string) $row->referer,
					),
					array(
						'name'  => __( 'Browser', 'holvek' ),
						'value' => (string) $row->user_agent,
					),
					array(
						'name'  => __( 'Last seen', 'holvek' ),
						'value' => (string) $row->last_hit,
					),
				),
			);
		}

		foreach ( $data['records']['audit_log'] as $row ) {
			$items[] = array(
				'group_id'    => 'holvek_audit_log',
				'group_label' => __( 'Holvek audit log', 'holvek' ),
				'item_id'     => 'audit-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Action', 'holvek' ),
						'value' => (string) $row->action,
					),
					array(
						'name'  => __( 'Field', 'holvek' ),
						'value' => (string) $row->field,
					),
					array(
						'name'  => __( 'Post ID', 'holvek' ),
						'value' => (string) $row->post_id,
					),
					array(
						'name'  => __( 'Date', 'holvek' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}

		if ( $page === 1 ) {
			foreach ( $data['records']['user_meta'] as $key => $values ) {
				$items[] = array(
					'group_id'    => 'holvek_user_meta',
					'group_label' => 'Holvek User Metadata',
					'item_id'     => 'user-meta-' . $key,
					'data'        => array(
						array(
							'name'  => $key,
							'value' => implode( ', ', array_map( 'strval', (array) $values ) ),
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => ! $data['has_more'],
		);
	}

	public function wpPrivacyEraser( string $email, int $page = 1 ): array {
		$deleted = $this->eraseUserData( $email );
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Read the plugin data tied to one email address. This private operation is
	 * shared by REST and WordPress privacy callbacks so their behaviour cannot
	 * diverge.
	 *
	 * @return array{email:string,user_id:int|null,records:array{ai_usage:array,user_meta:array,logs_404:array,audit_log:array},has_more:bool}
	 */
	private function getUserData( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array(
			'email'    => $email,
			'user_id'  => $user ? (int) $user->ID : null,
			'records'  => array(
				'ai_usage'  => array(),
				'user_meta' => array(),
				'logs_404'  => array(),
				'audit_log' => array(),
			),
			'has_more' => false,
		);

		if ( ! $user ) {
			return $data;
		}

		global $wpdb;
		$usageTable  = $wpdb->prefix . 'holvek_ai_usage';
		$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $usageTable ) ) );
		$limit       = 100;
		$offset      = max( 0, $page - 1 ) * $limit;

		if ( $tableExists ) {
			$rows                        = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id, provider, feature, tokens_used, created_at FROM {$wpdb->prefix}holvek_ai_usage WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", $user->ID, $limit + 1, $offset )
			);
			$data['has_more']            = count( $rows ) > $limit;
			$data['records']['ai_usage'] = array_slice( $rows, 0, $limit );
		}

		if ( $page === 1 ) {
			$allMeta                      = get_user_meta( $user->ID );
			$data['records']['user_meta'] = array_filter(
				$allMeta,
				static fn( $key ) => str_starts_with( (string) $key, 'holvek' ),
				ARRAY_FILTER_USE_KEY
			);
		}

		$logs                        = $this->matching404Logs( $user, $page );
		$data['records']['logs_404'] = $logs['rows'];
		$data['has_more']            = $data['has_more'] || $logs['has_more'];

		$audit                        = $this->matchingAuditLogs( $user, $page );
		$data['records']['audit_log'] = $audit['rows'];
		$data['has_more']             = $data['has_more'] || $audit['has_more'];

		return $data;
	}

	/**
	 * 404 rows are site logs. A personal-data export includes a row only when the stored path or referrer contains this account email or login.
	 *
	 * @param object $user User from get_user_by().
	 * @return array{rows:array<int, object>,has_more:bool}
	 */
	private function matching404Logs( object $user, int $page ): array {
		global $wpdb;

		$empty   = array(
			'rows'     => array(),
			'has_more' => false,
		);
		$needles = $this->logNeedles( $user );
		if ( array() === $needles ) {
			return $empty;
		}

		$table = $wpdb->prefix . 'holvek_404_logs';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return $empty;
		}

		$limit  = 50;
		$offset = max( 0, $page - 1 ) * $limit;
		$likes  = array();
		foreach ( $needles as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		if ( count( $likes ) === 1 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, uri, referer, user_agent, last_hit FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s ORDER BY last_hit DESC LIMIT %d OFFSET %d",
					$likes[0],
					$likes[0],
					$limit + 1,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, uri, referer, user_agent, last_hit FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s OR uri LIKE %s OR referer LIKE %s ORDER BY last_hit DESC LIMIT %d OFFSET %d",
					$likes[0],
					$likes[0],
					$likes[1],
					$likes[1],
					$limit + 1,
					$offset
				)
			);
		}
		if ( ! is_array( $rows ) ) {
			return $empty;
		}

		return array(
			'rows'     => array_slice( $rows, 0, $limit ),
			'has_more' => count( $rows ) > $limit,
		);
	}

	/**
	 * @param object $user User from get_user_by().
	 * @return array<int, string>
	 */
	private function logNeedles( object $user ): array {
		$needles = array();
		$email   = (string) ( $user->user_email ?? '' );
		if ( is_email( $email ) ) {
			$needles[] = $email;
		}
		$login = (string) ( $user->user_login ?? '' );
		if ( strlen( $login ) >= 4 ) {
			$needles[] = $login;
		}
		return $needles;
	}

	/**
	 * Erase data for one verified WordPress user and return the exact number of
	 * records removed. It deliberately does not report success for an empty or
	 * unmatched address.
	 */
	private function eraseUserData( string $email ): int {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return 0;
		}

		global $wpdb;
		$deleted     = 0;
		$usageTable  = $wpdb->prefix . 'holvek_ai_usage';
		$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $usageTable ) ) );
		if ( $tableExists ) {
			$deleted += (int) $wpdb->delete( $usageTable, array( 'user_id' => $user->ID ), array( '%d' ) );
		}

		$deleted += $this->eraseMatching404Logs( $user );
		$deleted += $this->eraseAuditLogs( $user );

		foreach ( array_keys( get_user_meta( $user->ID ) ) as $key ) {
			if ( str_starts_with( (string) $key, 'holvek' ) && delete_user_meta( $user->ID, $key ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Remove 404 rows whose path or referrer contains this account email or login.
	 *
	 * @param object $user User from get_user_by().
	 */
	private function eraseMatching404Logs( object $user ): int {
		global $wpdb;

		$needles = $this->logNeedles( $user );
		if ( array() === $needles ) {
			return 0;
		}

		$table = $wpdb->prefix . 'holvek_404_logs';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return 0;
		}

		$likes = array();
		foreach ( $needles as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		if ( count( $likes ) === 1 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s",
					$likes[0],
					$likes[0]
				)
			);
			if ( $count < 1 ) {
				return 0;
			}
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s",
					$likes[0],
					$likes[0]
				)
			);
			return $count;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s OR uri LIKE %s OR referer LIKE %s",
				$likes[0],
				$likes[0],
				$likes[1],
				$likes[1]
			)
		);
		if ( $count < 1 ) {
			return 0;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}holvek_404_logs WHERE uri LIKE %s OR referer LIKE %s OR uri LIKE %s OR referer LIKE %s",
				$likes[0],
				$likes[0],
				$likes[1],
				$likes[1]
			)
		);
		return $count;
	}

	/**
	 * Audit rows belong to the WordPress user who made the change.
	 *
	 * @param object $user User from get_user_by().
	 * @return array{rows:array<int, object>,has_more:bool}
	 */
	private function matchingAuditLogs( object $user, int $page ): array {
		global $wpdb;

		$empty  = array(
			'rows'     => array(),
			'has_more' => false,
		);
		$userId = (int) ( $user->ID ?? 0 );
		if ( $userId < 1 ) {
			return $empty;
		}

		$table = $wpdb->prefix . 'holvek_audit_log';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return $empty;
		}

		$limit  = 50;
		$offset = max( 0, $page - 1 ) * $limit;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, post_id, action, field, created_at FROM {$wpdb->prefix}holvek_audit_log WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$userId,
				$limit + 1,
				$offset
			)
		);
		if ( ! is_array( $rows ) ) {
			return $empty;
		}

		return array(
			'rows'     => array_slice( $rows, 0, $limit ),
			'has_more' => count( $rows ) > $limit,
		);
	}

	/**
	 * Remove audit rows stored for this WordPress user.
	 *
	 * @param object $user User from get_user_by().
	 */
	private function eraseAuditLogs( object $user ): int {
		global $wpdb;

		$userId = (int) ( $user->ID ?? 0 );
		if ( $userId < 1 ) {
			return 0;
		}

		$table = $wpdb->prefix . 'holvek_audit_log';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return 0;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_audit_log WHERE user_id = %d",
				$userId
			)
		);
		if ( $count < 1 ) {
			return 0;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}holvek_audit_log WHERE user_id = %d",
				$userId
			)
		);

		return $count;
	}
}
