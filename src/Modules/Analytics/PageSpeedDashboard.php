<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Mode\OperatingMode;

/**
 * PageSpeedDashboard
 *
 * BL-037: Fetches Core Web Vitals and PageSpeed scores via Google PageSpeed
 * Insights API. Caches results per URL for 24 hours.
 */
class PageSpeedDashboard {

	private const API_BASE    = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	private const CACHE_GROUP = 'holvek_psi';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$reportCap = fn() => current_user_can( 'view_seo_reports' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/analytics/pagespeed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getPageSpeed' ),
				'permission_callback' => $reportCap,
				'args'                => array(
					'url'      => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_url',
					),
					'strategy' => array(
						'default'           => 'mobile',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'force'    => array( 'default' => false ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/analytics/pagespeed/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulkCheck' ),
				'permission_callback' => $reportCap,
			)
		);
	}

	public function getPageSpeed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url      = $request->get_param( 'url' );
		$strategy = in_array( $request->get_param( 'strategy' ), array( 'mobile', 'desktop' ), true )
			? $request->get_param( 'strategy' ) : 'mobile';
		$force    = (bool) $request->get_param( 'force' );

		$apiKey = (string) get_option( 'holvek_pagespeed_api_key', '' );
		if ( $apiKey === '' ) {
			if ( ! OperatingMode::isCloudConfigured() ) {
				return OperatingMode::disconnected( __( 'PageSpeed through Holvek', 'holvek' ) );
			}

			$proxied = $this->fetchViaProxy( $url, $strategy );
			if ( is_wp_error( $proxied ) ) {
				return $proxied;
			}

			return rest_ensure_response( $proxied );
		}

		$cacheKey = 'psi_' . md5( $url . $strategy );
		if ( ! $force ) {
			$cached = get_transient( $cacheKey );
			if ( $cached !== false ) {
				return rest_ensure_response( array_merge( $cached, array( 'cached' => true ) ) );
			}
		}

		$result = $this->fetchPageSpeed( $url, $strategy, $apiKey );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $cacheKey, $result, DAY_IN_SECONDS );
		return rest_ensure_response( array_merge( $result, array( 'cached' => false ) ) );
	}

	public function bulkCheck( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$rawUrls = $params['urls'] ?? array();
		if ( ! is_array( $rawUrls ) ) {
			$rawUrls = array();
		}

		$urls = array();
		foreach ( array_slice( $rawUrls, 0, 10 ) as $rawUrl ) {
			if ( ! is_string( $rawUrl ) || $rawUrl === '' ) {
				continue;
			}
			$urls[] = esc_url_raw( $rawUrl );
		}

		$requested = isset( $params['strategy'] ) && is_string( $params['strategy'] ) ? $params['strategy'] : 'mobile';
		$strategy  = in_array( $requested, array( 'mobile', 'desktop' ), true ) ? $requested : 'mobile';
		$apiKey    = (string) get_option( 'holvek_pagespeed_api_key', '' );

		if ( $apiKey === '' ) {
			if ( ! OperatingMode::isCloudConfigured() ) {
				return OperatingMode::disconnected( __( 'PageSpeed through Holvek', 'holvek' ) );
			}

			if ( $urls === array() ) {
				return new \WP_Error( 'missing_urls', __( 'Add at least one URL to check.', 'holvek' ), array( 'status' => 400 ) );
			}

			$proxied = array();
			foreach ( $urls as $url ) {
				$result = $this->fetchViaProxy( $url, $strategy );
				if ( is_wp_error( $result ) ) {
					$proxied[ $url ] = array( 'error' => $result->get_error_message() );
					continue;
				}
				$proxied[ $url ] = $result;
			}

			return rest_ensure_response( array( 'results' => $proxied ) );
		}

		if ( $urls === array() ) {
			return new \WP_Error( 'missing_urls', __( 'Add at least one URL to check.', 'holvek' ), array( 'status' => 400 ) );
		}

		$results = array();
		foreach ( $urls as $url ) {
			$cacheKey = 'psi_' . md5( $url . $strategy );
			$cached   = get_transient( $cacheKey );
			if ( $cached !== false ) {
				$results[ $url ] = array_merge( $cached, array( 'cached' => true ) );
				continue;
			}
			$result = $this->fetchPageSpeed( $url, $strategy, $apiKey );
			if ( ! is_wp_error( $result ) ) {
				set_transient( $cacheKey, $result, DAY_IN_SECONDS );
				$results[ $url ] = $result;
			} else {
				$results[ $url ] = array( 'error' => $result->get_error_message() );
			}
		}

		return rest_ensure_response( array( 'results' => $results ) );
	}

	private function fetchPageSpeed( string $url, string $strategy, string $apiKey ): array|\WP_Error {
		$endpoint = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'key'      => $apiKey,
				'category' => array( 'performance', 'accessibility', 'seo', 'best-practices' ),
			),
			self::API_BASE
		);

		$response = wp_safe_remote_get( $endpoint, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'PageSpeed API error.';
			return new \WP_Error( 'psi_error', $msg, array( 'status' => 502 ) );
		}

		$cats   = $body['lighthouseResult']['categories'] ?? array();
		$audits = $body['lighthouseResult']['audits'] ?? array();

		return array(
			'url'             => $url,
			'strategy'        => $strategy,
			'scores'          => array(
				'performance'    => $this->score( $cats, 'performance' ),
				'accessibility'  => $this->score( $cats, 'accessibility' ),
				'seo'            => $this->score( $cats, 'seo' ),
				'best_practices' => $this->score( $cats, 'best-practices' ),
			),
			'core_web_vitals' => array(
				'lcp'  => $this->auditValue( $audits, 'largest-contentful-paint' ),
				'fid'  => $this->auditValue( $audits, 'max-potential-fid' ),
				'cls'  => $this->auditValue( $audits, 'cumulative-layout-shift' ),
				'fcp'  => $this->auditValue( $audits, 'first-contentful-paint' ),
				'ttfb' => $this->auditValue( $audits, 'server-response-time' ),
			),
			'opportunities'   => $this->extractOpportunities( $audits ),
			'fetched_at'      => current_time( 'mysql' ),
		);
	}

	private function score( array $cats, string $key ): ?int {
		$val = $cats[ $key ]['score'] ?? null;
		return $val !== null ? (int) round( $val * 100 ) : null;
	}

	private function auditValue( array $audits, string $id ): ?string {
		return $audits[ $id ]['displayValue'] ?? null;
	}

	private function extractOpportunities( array $audits ): array {
		$opps = array();
		foreach ( $audits as $id => $audit ) {
			if ( ( $audit['score'] ?? 1 ) < 0.9 && isset( $audit['details']['type'] ) && $audit['details']['type'] === 'opportunity' ) {
				$opps[] = array(
					'id'          => $id,
					'title'       => $audit['title'],
					'description' => $audit['description'],
					'savings'     => $audit['details']['overallSavingsMs'] ?? null,
				);
			}
		}
		return array_slice( $opps, 0, 10 );
	}
	private function fetchViaProxy( string $url, string $strategy ): array|\WP_Error {
		$result = \Holvek\WordPress\Core\Api\HolvekApiGateway::proxyRequest(
			'pagespeed',
			array(
				'url'      => $url,
				'strategy' => $strategy,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Assuming the proxy returns the exact same schema structure
		return $result;
	}
}
