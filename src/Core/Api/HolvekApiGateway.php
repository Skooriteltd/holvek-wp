<?php

namespace Holvek\WordPress\Core\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Mode\OperatingMode;

/**
 * Provides a fallback proxy for heavy data computation and analytics API calls
 * when the user has not provided their own native API keys.
 *
 * E.g., PageSpeed Insights, Indexing limits, GSC aggregate queries.
 */
class HolvekApiGateway {

	private const PROXY_ENDPOINT = 'https://api.holvek.com/v1';

	/**
	 * Whether this site has a verified Holvek credential.
	 *
	 * A stored string is not enough. Verification is cached by the connection
	 * screen and this method does not call api.holvek.com.
	 */
	public static function hasActiveLicense(): bool {
		return OperatingMode::isCloudConfigured();
	}

	public static function proxyRequest( string $service, array $payload ): array|\WP_Error {
		if ( ! OperatingMode::isCloudConfigured() ) {
			return OperatingMode::disconnected( __( 'PageSpeed through Holvek', 'holvek' ) );
		}

		$response = wp_safe_remote_post(
			self::PROXY_ENDPOINT . '/' . $service,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . OperatingMode::credential(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			return new \WP_Error( 'api_error', $body['message'] ?? __( 'Holvek API error.', 'holvek' ) );
		}

		return $body;
	}
}
