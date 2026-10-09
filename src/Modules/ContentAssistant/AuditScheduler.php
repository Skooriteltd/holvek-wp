<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AuditScheduler
 *
 * Schedules recurring lightweight SEO audits and emails results to the admin.
 * Stores the last 12 audit runs for trend tracking.
 *
 * BL-023
 */
class AuditScheduler {

	private const RESULTS_OPTION  = 'holvek_last_audit_results';
	private const HISTORY_OPTION  = 'holvek_audit_history';
	private const SCHEDULE_OPTION = 'holvek_audit_schedule';
	private const CRON_HOOK       = 'holvek_scheduled_audit';
	private const HISTORY_MAX     = 12;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( self::CRON_HOOK, array( $this, 'runScheduledAudit' ) );
		add_filter( 'cron_schedules', array( $this, 'registerSchedules' ) );
	}

	/**
	 * Schedule the stored frequency. Activation calls this. The worker calls it again when the event is missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$config     = ( new self() )->getConfig();
		$frequency  = ( $config['frequency'] ?? 'weekly' ) === 'monthly' ? 'monthly' : 'weekly';
		$recurrence = ( $frequency === 'monthly' ) ? 'holvek_monthly' : 'weekly';
		wp_schedule_event( time() + 60, $recurrence, self::CRON_HOOK );
	}

	public function registerSchedules( array $schedules ): array {
		$schedules['holvek_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly', 'holvek' ),
		);
		return $schedules;
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/audit/schedule',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSchedule' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveSchedule' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/audit/results',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getResults' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/audit/run-now',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'runNow' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	public function getSchedule( \WP_REST_Request $request ): \WP_REST_Response {
		$config  = $this->getConfig();
		$nextRun = wp_next_scheduled( self::CRON_HOOK );
		$lastRun = get_option( self::RESULTS_OPTION, array() );

		return rest_ensure_response(
			array(
				'frequency'     => $config['frequency'],
				'email_enabled' => $config['email_enabled'],
				'email_address' => $config['email_address'],
				'next_run'      => $nextRun ? gmdate( 'Y-m-d H:i:s', $nextRun ) : null,
				'last_run'      => $lastRun['timestamp'] ?? null,
			)
		);
	}

	public function saveSchedule( \WP_REST_Request $request ): \WP_REST_Response {
		$params       = $request->get_json_params();
		$frequency    = in_array( $params['frequency'] ?? '', array( 'weekly', 'monthly' ), true )
			? $params['frequency'] : 'weekly';
		$emailEnabled = (bool) ( $params['email_enabled'] ?? true );
		$emailAddress = sanitize_email( $params['email_address'] ?? get_option( 'admin_email' ) );

		$config                  = compact( 'frequency', 'emailEnabled', 'emailAddress' );
		$config['email_enabled'] = $emailEnabled;
		$config['email_address'] = $emailAddress;
		unset( $config['emailEnabled'], $config['emailAddress'] );

		update_option( self::SCHEDULE_OPTION, $config );
		$this->setupSchedule( $frequency );

		return rest_ensure_response(
			array(
				'success' => true,
				'config'  => $config,
			)
		);
	}

	public function getResults( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'last_results' => get_option( self::RESULTS_OPTION, null ),
				'history'      => get_option( self::HISTORY_OPTION, array() ),
			)
		);
	}

	public function runNow( \WP_REST_Request $request ): \WP_REST_Response {
		wp_schedule_single_event( time() + 2, self::CRON_HOOK );
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Audit will run momentarily.', 'holvek' ),
			)
		);
	}

	// ── Audit logic ───────────────────────────────────────────────────────────

	public function runScheduledAudit(): void {
		self::schedule();

		$issues   = 0;
		$warnings = 0;
		$passed   = 0;
		$details  = array();

		// 1. Missing meta descriptions
		$missingMeta = ( new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => '_holvek_meta_description',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		) )->found_posts;
		if ( $missingMeta > 0 ) {
			++$warnings;
			$details['missing_meta_descriptions'] = $missingMeta; } else {
			++$passed; }

			// 2. Missing alt text
			$missingAlt = ( new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
					'posts_per_page' => 1,
					'meta_query'     => array(
						array(
							'key'     => '_wp_attachment_image_alt',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			) )->found_posts;
		if ( $missingAlt > 0 ) {
			++$warnings;
			$details['missing_alt_text'] = $missingAlt; } else {
			++$passed; }

			// 3. 404 count
			global $wpdb;
			$logTable    = $wpdb->prefix . 'holvek_404_logs';
			$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logTable ) ) );
			$count404    = $tableExists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_404_logs" ) : 0;
			if ( $count404 > 20 ) {
				++$issues;
				$details['404_count'] = $count404; } elseif ( $count404 > 5 ) {
						++$warnings;
						$details['404_count'] = $count404; } else {
					++$passed; }

						// 4. Noindex on front page
						$frontPageId  = (int) get_option( 'page_on_front' );
						$frontNoindex = $frontPageId > 0 ? get_post_meta( $frontPageId, '_holvek_noindex', true ) : '';
						if ( $frontNoindex === 'yes' ) {
							++$issues;
							$details['front_page_noindex'] = true; } else {
							++$passed; }

							// 5. SSL
							if ( strpos( site_url(), 'https://' ) !== 0 ) {
								++$issues;
								$details['no_ssl'] = true; } else {
								++$passed; }

								$results = array(
									'timestamp' => current_time( 'mysql' ),
									'summary'   => array(
										'issues'   => $issues,
										'warnings' => $warnings,
										'passed'   => $passed,
									),
									'details'   => $details,
								);

								update_option( self::RESULTS_OPTION, $results, false );

								// Append to history (keep last 12)
								$history   = (array) get_option( self::HISTORY_OPTION, array() );
								$history[] = array(
									'timestamp' => $results['timestamp'],
									'summary'   => $results['summary'],
								);
								update_option( self::HISTORY_OPTION, array_slice( $history, -self::HISTORY_MAX ), false );

								// Email if enabled
								$config = $this->getConfig();
								if ( $config['email_enabled'] ) {
									wp_mail(
										$config['email_address'],
										'[Holvek] Scheduled SEO Audit Results',
										$this->formatEmailBody( $results ),
										array( 'Content-Type: text/html; charset=UTF-8' )
									);
								}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public function setupSchedule( string $frequency ): void {
		// Clear existing
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}

		$recurrence = ( $frequency === 'monthly' ) ? 'holvek_monthly' : 'weekly';
		wp_schedule_event( time() + 60, $recurrence, self::CRON_HOOK );
	}

	public function formatEmailBody( array $results ): string {
		$s     = $results['summary'];
		$body  = '<h2>Holvek SEO Audit Results</h2>';
		$body .= "<p>Run at: <strong>{$results['timestamp']}</strong></p>";
		$body .= '<table border="1" cellpadding="6" style="border-collapse:collapse">';
		$body .= '<tr><th>Metric</th><th>Count</th></tr>';
		$body .= '<tr><td>🔴 Critical Issues</td><td>' . $s['issues'] . '</td></tr>';
		$body .= '<tr><td>⚠️ Warnings</td><td>' . $s['warnings'] . '</td></tr>';
		$body .= '<tr><td>✅ Passed</td><td>' . $s['passed'] . '</td></tr>';
		$body .= '</table>';

		if ( ! empty( $results['details'] ) ) {
			$body .= '<h3>Details</h3><ul>';
			foreach ( $results['details'] as $k => $v ) {
				$body .= '<li>' . esc_html( str_replace( '_', ' ', $k ) ) . ': <strong>' . esc_html( (string) $v ) . '</strong></li>';
			}
			$body .= '</ul>';
		}

		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=holvek' ) ) . '">View full audit in Holvek →</a></p>';
		return $body;
	}

	private function getConfig(): array {
		return array_merge(
			array(
				'frequency'     => 'weekly',
				'email_enabled' => true,
				'email_address' => get_option( 'admin_email' ),
			),
			(array) get_option( self::SCHEDULE_OPTION, array() )
		);
	}
}
