<?php

namespace Holvek\WordPress\Core\Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Api\BackendApiClient;
use Holvek\WordPress\Core\Security\KeyVault;

/**
 * Standalone versus connected mode.
 *
 * Connected means a verified site credential is stored, it has not been
 * revoked, and the verification cache has not expired. That check reads
 * options only. It does not contact api.holvek.com.
 */
class OperatingMode {

	public const REVOKED_OPTION = 'holvek_cloud_revoked';

	/**
	 * A verified site credential is stored and has not been revoked.
	 */
	public static function isCloudConfigured(): bool {
		return \Holvek\WordPress\Core\Api\CloudCredential::isActive();
	}

	/**
	 * Ask api.holvek.com whether it answers.
	 *
	 * Call this only from an explicit connection check. A normal page view
	 * must not call it. Metadata, schema, sitemaps, redirects, and robots
	 * do not depend on the answer.
	 */
	public static function isCloudReachable(): bool {
		$response = wp_safe_remote_get(
			( new BackendApiClient() )->getApiUrl() . '/health',
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Holvek-WP/' . ( defined( 'HOLVEK_VERSION' ) ? HOLVEK_VERSION : '1.0.1' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Plain error for a cloud feature on a site that is not connected.
	 */
	public static function disconnected( string $feature ): \WP_Error {
		return new \WP_Error(
			'holvek_not_connected',
			sprintf(
				/* translators: %s: cloud feature that was requested. */
				__( 'This site is not connected to Holvek, so %s is unavailable. You can keep using Holvek on this site.', 'holvek' ),
				$feature
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Decrypted site credential. The API key wins when both are stored.
	 */
	public static function credential(): string {
		$apiKey = self::readSecret( 'holvek_api_key' );
		if ( $apiKey !== '' ) {
			return $apiKey;
		}

		return self::readSecret( 'holvek_license_key' );
	}

	private static function readSecret( string $option ): string {
		$stored = get_option( $option, '' );
		if ( ! is_string( $stored ) || $stored === '' ) {
			return '';
		}

		return KeyVault::decrypt( $stored );
	}
}
