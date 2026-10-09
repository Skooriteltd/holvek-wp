<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GoogleSearchConsoleIntegration
 *
 * BL-030: OAuth2 Google Search Console connection.
 * Fetches impressions, clicks, CTR, position per URL and
 * "Top Declining Keywords" trend data.
 */
class GoogleSearchConsoleIntegration {

	private const TOKEN_OPTION  = 'holvek_gsc_token';
	private const CONFIG_OPTION = 'holvek_gsc_config';
	private const CACHE_TTL     = 6 * HOUR_IN_SECONDS;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$adminCap  = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
		$reportCap = fn() => current_user_can( 'view_seo_reports' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/gsc/auth-url',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getAuthUrl' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/gsc/callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handleCallback' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/gsc/disconnect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/gsc/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getStatus' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/gsc/performance',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getPerformance' ),
				'permission_callback' => $reportCap,
				'args'                => array(
					'days'    => array(
						'default'           => 28,
						'sanitize_callback' => 'absint',
					),
					'page'    => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_url',
					),
					'keyword' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/gsc/declining-keywords',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getDecliningKeywords' ),
				'permission_callback' => $reportCap,
			)
		);
	}

	public function getAuthUrl( \WP_REST_Request $request ): \WP_REST_Response {
		$config = $this->getConfig();

		if ( empty( $config['client_id'] ) ) {
			return new \WP_Error( 'no_config', __( 'Set your Google OAuth client_id and client_secret in Holvek → Settings → Integrations.', 'holvek' ), array( 'status' => 400 ) );
		}

		$state       = wp_create_nonce( 'holvek_gsc_oauth' );
		$redirectUri = rest_url( 'holvek/v1/gsc/callback' );

		$params = http_build_query(
			array(
				'client_id'     => $config['client_id'],
				'redirect_uri'  => $redirectUri,
				'response_type' => 'code',
				'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			)
		);

		return rest_ensure_response(
			array(
				'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params,
			)
		);
	}

	public function handleCallback( \WP_REST_Request $request ): \WP_REST_Response {
		$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
		$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

		if ( ! wp_verify_nonce( $state, 'holvek_gsc_oauth' ) ) {
			return new \WP_Error( 'invalid_state', __( 'OAuth state mismatch. Possible CSRF.', 'holvek' ), array( 'status' => 403 ) );
		}

		if ( empty( $code ) ) {
			return new \WP_Error( 'no_code', __( 'No authorization code received.', 'holvek' ), array( 'status' => 400 ) );
		}

		$config = $this->getConfig();
		$token  = $this->exchangeCode( $code, $config );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		update_option( self::TOKEN_OPTION, $token, false );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Google Search Console connected successfully.', 'holvek' ),
			)
		);
	}

	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		delete_option( self::TOKEN_OPTION );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function getStatus( \WP_REST_Request $request ): \WP_REST_Response {
		$token = get_option( self::TOKEN_OPTION );
		return rest_ensure_response(
			array(
				'connected'  => ! empty( $token['access_token'] ),
				'expires_at' => $token['expires_at'] ?? null,
				'site_url'   => home_url(),
			)
		);
	}

	public function getPerformance( \WP_REST_Request $request ): \WP_REST_Response {
		$days    = min( 90, max( 1, (int) $request->get_param( 'days' ) ) );
		$page    = $request->get_param( 'page' );
		$keyword = $request->get_param( 'keyword' );

		$cacheKey = 'gsc_perf_' . md5( $days . $page . $keyword );
		$cached   = get_transient( $cacheKey );
		if ( $cached !== false ) {
			return rest_ensure_response( array_merge( $cached, array( 'cached' => true ) ) );
		}

		$token = $this->getValidToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endDate   = gmdate( 'Y-m-d' );
		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$body = array(
			'startDate'  => $startDate,
			'endDate'    => $endDate,
			'dimensions' => array( 'query', 'page' ),
			'rowLimit'   => 100,
		);

		if ( $page ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page,
						),
					),
				),
			);
		}

		if ( $keyword ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'query',
							'operator'   => 'contains',
							'expression' => $keyword,
						),
					),
				),
			);
		}

		$siteUrl  = rawurlencode( home_url( '/' ) );
		$response = wp_safe_remote_post(
			"https://www.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query",
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'gsc_error', $data['error']['message'] ?? __( 'GSC error.', 'holvek' ), array( 'status' => 502 ) );
		}

		$result = array(
			'rows'        => $data['rows'] ?? array(),
			'period_days' => $days,
		);
		set_transient( $cacheKey, $result, self::CACHE_TTL );

		return rest_ensure_response( array_merge( $result, array( 'cached' => false ) ) );
	}

	public function getDecliningKeywords( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $this->getValidToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$siteUrl = rawurlencode( home_url( '/' ) );
		$today   = gmdate( 'Y-m-d' );
		$periods = array(
			'recent' => array( gmdate( 'Y-m-d', strtotime( '-28 days' ) ), $today ),
			'prior'  => array( gmdate( 'Y-m-d', strtotime( '-56 days' ) ), gmdate( 'Y-m-d', strtotime( '-29 days' ) ) ),
		);

		$data = array();
		foreach ( $periods as $label => [$start, $end] ) {
			$response = wp_safe_remote_post(
				"https://www.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query",
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'startDate'  => $start,
							'endDate'    => $end,
							'dimensions' => array( 'query' ),
							'rowLimit'   => 200,
						)
					),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$body           = json_decode( wp_remote_retrieve_body( $response ), true );
				$data[ $label ] = array_column( $body['rows'] ?? array(), null, 'keys' );
			}
		}

		// Compare: find keywords where position worsened or clicks dropped
		$declining = array();
		foreach ( $data['recent'] ?? array() as $keyArr => $row ) {
			$kw    = is_array( $row['keys'] ) ? $row['keys'][0] : $keyArr;
			$prior = $data['prior'][ $keyArr ] ?? null;
			if ( $prior && ( $row['clicks'] < ( $prior['clicks'] * 0.8 ) || $row['position'] > ( $prior['position'] + 3 ) ) ) {
				$declining[] = array(
					'keyword'          => $kw,
					'recent_clicks'    => $row['clicks'],
					'prior_clicks'     => $prior['clicks'],
					'recent_position'  => round( $row['position'], 1 ),
					'prior_position'   => round( $prior['position'], 1 ),
					'click_change_pct' => round( ( ( $row['clicks'] - $prior['clicks'] ) / max( 1, $prior['clicks'] ) ) * 100, 1 ),
				);
			}
		}

		usort( $declining, fn( $a, $b ) => $a['click_change_pct'] - $b['click_change_pct'] );

		return rest_ensure_response( array( 'declining_keywords' => array_slice( $declining, 0, 20 ) ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getValidToken(): string|\WP_Error {
		$token = get_option( self::TOKEN_OPTION );
		if ( empty( $token['access_token'] ) ) {
			return new \WP_Error( 'not_connected', __( 'Google Search Console not connected.', 'holvek' ), array( 'status' => 401 ) );
		}

		// Refresh if expired
		if ( ! empty( $token['expires_at'] ) && time() > (int) $token['expires_at'] - 60 ) {
			if ( ! empty( $token['refresh_token'] ) ) {
				$refreshed = $this->refreshToken( $token['refresh_token'] );
				if ( ! is_wp_error( $refreshed ) ) {
					$token = $refreshed;
					update_option( self::TOKEN_OPTION, $token, false );
				}
			}
		}

		return $token['access_token'];
	}

	private function exchangeCode( string $code, array $config ): array|\WP_Error {
		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $config['client_id'],
					'client_secret' => $config['client_secret'],
					'redirect_uri'  => rest_url( 'holvek/v1/gsc/callback' ),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'oauth_error', $data['error_description'] ?? __( 'OAuth error.', 'holvek' ), array( 'status' => 400 ) );
		}

		return array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
		);
	}

	private function refreshToken( string $refreshToken ): array|\WP_Error {
		$config   = $this->getConfig();
		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'refresh_token' => $refreshToken,
					'client_id'     => $config['client_id'],
					'client_secret' => $config['client_secret'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'refresh_error', $data['error_description'] ?? __( 'Token refresh error.', 'holvek' ), array( 'status' => 401 ) );
		}

		$existing = get_option( self::TOKEN_OPTION, array() );
		return array_merge(
			$existing,
			array(
				'access_token' => $data['access_token'],
				'expires_at'   => time() + (int) ( $data['expires_in'] ?? 3600 ),
			)
		);
	}

	private function getConfig(): array {
		return (array) get_option(
			self::CONFIG_OPTION,
			array(
				'client_id'     => '',
				'client_secret' => '',
			)
		);
	}
}
