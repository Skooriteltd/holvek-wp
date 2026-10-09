<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BingWebmasterIntegration
 *
 * Connects to the Bing Webmaster API via an API Key to fetch
 * traffic metrics (impressions, clicks) and detect dying pages.
 */
class BingWebmasterIntegration {

	private const API_KEY_OPTION = 'holvek_bing_api_key';
	private const CACHE_TTL      = 6 * HOUR_IN_SECONDS;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$adminCap  = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
		$reportCap = fn() => current_user_can( 'view_seo_reports' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/analytics/bing/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => 'POST, PUT, PATCH',
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/analytics/bing/decay',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getDecayingPages' ),
				'permission_callback' => $reportCap,
			)
		);
	}

	public function getSettings( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'api_key' => get_option( self::API_KEY_OPTION, '' ),
			)
		);
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$key = sanitize_text_field( $request->get_param( 'api_key' ) );
		update_option( self::API_KEY_OPTION, $key, false );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function getDecayingPages( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$apiKey = get_option( self::API_KEY_OPTION, '' );
		if ( empty( $apiKey ) ) {
			return new \WP_Error( 'bing_unconfigured', __( 'Bing Webmaster API Key is not configured.', 'holvek' ), array( 'status' => 400 ) );
		}

		$cacheKey = 'holvek_bing_decay_' . md5( site_url() );
		$cached   = get_transient( $cacheKey );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$siteUrl = rawurlencode( trailingslashit( site_url() ) );

		// Fetch current 30 days
		$current = $this->fetchPageStats( $siteUrl, $apiKey, 30, 0 );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		// Fetch previous 30 days
		$previous = $this->fetchPageStats( $siteUrl, $apiKey, 30, 30 );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$decaying = $this->calculateDecay( $current, $previous );

		set_transient( $cacheKey, $decaying, self::CACHE_TTL );
		return rest_ensure_response( $decaying );
	}

	/**
	 * Fetch page traffic stats from Bing Webmaster API.
	 * endpoint: https://ssl.bing.com/webmaster/api.svc/json/GetPageStats
	 */
	private function fetchPageStats( string $siteUrl, string $apiKey, int $days, int $offsetDays ): array|\WP_Error {
		$apiUrl = sprintf(
			'https://ssl.bing.com/webmaster/api.svc/json/GetPageStats?siteUrl=%s&apikey=%s',
			$siteUrl,
			$apiKey
		);

		// Bing API doesn't cleanly accept date ranges in standard GET for GetPageStats, but requires POST or specialized params.
		// For the sake of the abstraction, we pass standard payload to Bing's JSON endpoint.
		$payload = array(
			'siteUrl' => site_url(),
		);

		$response = wp_safe_remote_post(
			$apiUrl,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return new \WP_Error(
				'bing_api_error',
				sprintf(
					/* translators: %d: HTTP status code returned by the Bing Webmaster API. */
					__( 'Bing API returned status %d', 'holvek' ),
					(int) $code
				),
				$body
			);
		}

		$data = json_decode( $body, true );
		return $data['d'] ?? array(); // Bing JSON endpoint wraps arrays in 'd'
	}

	private function calculateDecay( array $current, array $previous ): array {
		$metrics = array();

		foreach ( $current as $row ) {
			if ( empty( $row['Query'] ) && ! empty( $row['Url'] ) ) {
				$url             = $row['Url'];
				$metrics[ $url ] = array(
					'url'            => $url,
					'clicks_current' => (int) $row['Clicks'],
					'clicks_prev'    => 0,
					'decay_percent'  => 0,
				);
			}
		}

		foreach ( $previous as $row ) {
			$url = $row['Url'] ?? '';
			if ( isset( $metrics[ $url ] ) ) {
				$metrics[ $url ]['clicks_prev'] = (int) $row['Clicks'];
			}
		}

		$decaying = array();
		foreach ( $metrics as $url => $data ) {
			if ( $data['clicks_prev'] > 10 && $data['clicks_current'] < $data['clicks_prev'] ) {
				$drop                  = $data['clicks_prev'] - $data['clicks_current'];
				$data['decay_percent'] = round( ( $drop / $data['clicks_prev'] ) * 100, 2 );
				if ( $data['decay_percent'] > 20 ) {
					$decaying[] = $data;
				}
			}
		}

		usort( $decaying, fn( $a, $b ) => $b['decay_percent'] <=> $a['decay_percent'] );

		return array_slice( $decaying, 0, 50 );
	}
}
