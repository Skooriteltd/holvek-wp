<?php

namespace Holvek\WordPress\Modules\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UsageMeteringManager
 *
 * Tracks AI token usage per user, per provider, and per feature.
 * Enforces configurable soft/hard caps and provides usage dashboards.
 *
 * BL-011
 */
class UsageMeteringManager {

	private const TABLE_NAME   = 'holvek_ai_usage';
	private const LIMIT_OPTION = 'holvek_ai_token_limits';
	private const CRON_HOOK    = 'holvek_weekly_usage_email';

	private static ?self $instance = null;

	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Installation ──────────────────────────────────────────────────────────

	public static function createTable(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            provider     VARCHAR(64)     NOT NULL DEFAULT '',
            feature      VARCHAR(128)    NOT NULL DEFAULT '',
            tokens_used  INT UNSIGNED    NOT NULL DEFAULT 0,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( self::CRON_HOOK, array( $this, 'sendWeeklySummary' ) );
	}

	/**
	 * Schedule the weekly summary. Activation calls this. The worker calls it again when the event is missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
	}

	// ── Core API ──────────────────────────────────────────────────────────────

	/**
	 * Record a token usage event.
	 */
	public function record( int $userId, string $provider, string $feature, int $tokensUsed ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			array(
				'user_id'     => $userId,
				'provider'    => sanitize_text_field( $provider ),
				'feature'     => sanitize_text_field( $feature ),
				'tokens_used' => max( 0, $tokensUsed ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get usage totals for a specific user for a period.
	 *
	 * @param string $period 'month'|'week'|'day'|'all'
	 */
	public function getUsage( int $userId, string $period = 'month' ): array {
		$period = $this->normalizePeriod( $period );
		$limit  = $this->getLimit();
		if ( $userId < 1 ) {
			return array(
				'user_id'    => 0,
				'period'     => $period,
				'total'      => 0,
				'by_feature' => array(),
				'limit'      => $limit,
				'percent'    => null,
			);
		}

		$rows  = $this->queryUserUsage( $userId, $period );
		$total = 0;
		foreach ( $rows as $row ) {
			$total += (int) $row->total;
		}

		return array(
			'user_id'    => $userId,
			'period'     => $period,
			'total'      => $total,
			'by_feature' => $rows,
			'limit'      => $limit,
			'percent'    => $limit > 0 ? round( ( $total / $limit ) * 100, 1 ) : null,
		);
	}

	/**
	 * Get site-wide usage for a period.
	 */
	public function getSiteUsage( string $period = 'month' ): array {
		$period = $this->normalizePeriod( $period );
		$rows   = $this->querySiteUsage( $period );
		$total  = array_sum( array_map( 'intval', array_column( (array) $rows, 'total' ) ) );

		return array(
			'period'     => $period,
			'total'      => (int) $total,
			'by_feature' => $rows,
		);
	}

	/**
	 * Check whether a user is within their token limit.
	 */
	public function checkLimit( int $userId, string $provider = '' ): bool {
		$limit = $this->getLimit();
		if ( $limit <= 0 ) {
			return true; // No limit configured
		}

		$usage = $this->getUsage( $userId, 'month' );
		return $usage['total'] < $limit;
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/ai/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getMyUsage' ),
				'permission_callback' => fn() => is_user_logged_in(),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/ai/usage/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getSiteUsageEndpoint' ),
				'permission_callback' => fn() => $this->canViewSiteUsage(),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/ai/usage/limits',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( get_option( self::LIMIT_OPTION, $this->defaultLimits() ) ),
					'permission_callback' => fn() => $this->canViewSiteUsage(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveLimits' ),
					'permission_callback' => fn() => $this->canViewSiteUsage(),
				),
			)
		);
	}

	/**
	 * Usage for the logged-in user.
	 *
	 * A requested user ID is ignored. Counters always belong to the current user.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getMyUsage( \WP_REST_Request $request ) {
		$userId = get_current_user_id();
		if ( ! is_user_logged_in() || $userId < 1 ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view AI usage.', 'holvek' ),
				array( 'status' => 401 )
			);
		}

		$period = $this->normalizePeriod( (string) ( $request->get_param( 'period' ) ?? 'month' ) );
		return rest_ensure_response( $this->getUsage( $userId, $period ) );
	}

	/**
	 * Site-wide usage for an SEO manager or administrator.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getSiteUsageEndpoint( \WP_REST_Request $request ) {
		if ( ! $this->canViewSiteUsage() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view site-wide AI usage.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		$period = $this->normalizePeriod( (string) ( $request->get_param( 'period' ) ?? 'month' ) );
		return rest_ensure_response( $this->getSiteUsage( $period ) );
	}

	public function saveLimits( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$limits = $this->defaultLimits();

		foreach ( array( 'free', 'pro', 'enterprise' ) as $tier ) {
			if ( isset( $params[ $tier ] ) ) {
				$limits[ $tier ] = max( 0, (int) $params[ $tier ] );
			}
		}

		update_option( self::LIMIT_OPTION, $limits );
		return rest_ensure_response(
			array(
				'success' => true,
				'limits'  => $limits,
			)
		);
	}

	// ── Weekly email ──────────────────────────────────────────────────────────

	public function sendWeeklySummary(): void {
		self::schedule();

		$usage   = $this->getSiteUsage( 'week' );
		$admin   = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: %s: number of AI tokens used this week. */
			__( '[Holvek] Weekly AI Usage Summary — %s tokens used', 'holvek' ),
			number_format( $usage['total'] )
		);

		$body  = '<h2>' . esc_html__( 'Holvek Weekly AI Usage', 'holvek' ) . '</h2>';
		$body .= '<p>' . esc_html__( 'Total tokens used this week:', 'holvek' ) . ' <strong>' . number_format( $usage['total'] ) . '</strong></p>';
		$body .= "<table border='1' cellpadding='5'><tr><th>" . esc_html__( 'Provider', 'holvek' ) . '</th><th>' . esc_html__( 'Feature', 'holvek' ) . '</th><th>' . esc_html__( 'Tokens', 'holvek' ) . '</th></tr>';

		foreach ( (array) $usage['by_feature'] as $row ) {
			$body .= '<tr><td>' . esc_html( $row->provider ) . '</td><td>' . esc_html( $row->feature ) . '</td><td>' . number_format( (int) $row->total ) . '</td></tr>';
		}

		$body .= '</table>';

		wp_mail( $admin, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Cloud usage reporting. This does not run on a page view.
	 *
	 * A site that is not connected receives a local error. Usage totals stay
	 * in the local table either way. This release does not phone home.
	 */
	public function reportToCloud(): \WP_Error {
		if ( ! \Holvek\WordPress\Core\Mode\OperatingMode::isCloudConfigured() ) {
			return \Holvek\WordPress\Core\Mode\OperatingMode::disconnected( __( 'Cloud usage reporting', 'holvek' ) );
		}

		return new \WP_Error(
			'holvek_usage_local',
			__( 'AI usage is stored on this site.', 'holvek' ),
			array( 'status' => 400 )
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getLimit(): int {
		$tier   = \Holvek\WordPress\Core\Api\CloudCredential::cachedTier();
		$limits = get_option( self::LIMIT_OPTION, $this->defaultLimits() );
		return (int) ( $limits[ $tier ] ?? 50000 );
	}

	private function defaultLimits(): array {
		return array(
			'free'       => 50000,
			'pro'        => 500000,
			'enterprise' => 5000000,
		);
	}

	private function canViewSiteUsage(): bool {
		return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Limit a usage window to the three periods the dashboard offers.
	 */
	private function normalizePeriod( string $period ): string {
		$period = sanitize_key( $period );
		if ( in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
			return $period;
		}

		return 'month';
	}

	/**
	 * Rows for one user. The user ID is the only request value in the query.
	 *
	 * @return array<int, object>
	 */
	private function queryUserUsage( int $userId, string $period ): array {
		global $wpdb;

		if ( 'day' === $period ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY provider, feature ORDER BY total DESC",
					$userId
				)
			);
		} elseif ( 'week' === $period ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY provider, feature ORDER BY total DESC",
					$userId
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY provider, feature ORDER BY total DESC",
					$userId
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows for the whole site. There is no user-supplied SQL.
	 *
	 * @return array<int, object>
	 */
	private function querySiteUsage( string $period ): array {
		global $wpdb;

		if ( 'day' === $period ) {
			$sql = "SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY provider, feature ORDER BY total DESC";
		} elseif ( 'week' === $period ) {
			$sql = "SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY provider, feature ORDER BY total DESC";
		} else {
			$sql = "SELECT provider, feature, SUM(tokens_used) AS total FROM {$wpdb->prefix}holvek_ai_usage WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY provider, feature ORDER BY total DESC";
		}

		// The period selects one of the three literal queries above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}
}
