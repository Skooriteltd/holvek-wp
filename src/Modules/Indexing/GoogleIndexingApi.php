<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Core\Support\ValueList;

class GoogleIndexingApi {

	private function getStoredCredentials(): string {
		$raw = (string) get_option( 'holvek_google_indexing_key', '' );
		return KeyVault::decrypt( $raw );
	}

	/**
	 * Submit only a Google Indexing API eligible URL. The API is not a generic
	 * crawl/index request mechanism, so eligibility is enforced again in the
	 * client rather than trusting a queued job payload.
	 */
	public function ping( string $url, string $action, int $postId ): bool {
		$post = get_post( $postId );
		if ( ! $post || ! self::isEligiblePost( $post ) || get_permalink( $postId ) !== $url ) {
			return false;
		}

		$apiKeyJsonStr = $this->getStoredCredentials();
		if ( empty( $apiKeyJsonStr ) ) {
			return false;
		}

		$jsonKey = json_decode( $apiKeyJsonStr, true );
		if ( ! $jsonKey || ! isset( $jsonKey['private_key'] ) || ! isset( $jsonKey['client_email'] ) ) {
			return false;
		}

		$accessToken = $this->getAccessToken( $jsonKey );
		if ( ! $accessToken ) {
			return false;
		}

		$endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $accessToken,
				),
				'body'    => wp_json_encode(
					array(
						'url'  => $url,
						'type' => $action,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code === 200 || $code === 202;
	}

	/**
	 * Google limits the Indexing API to JobPosting pages and livestream pages
	 * with a BroadcastEvent embedded in a VideoObject. This verifier is kept
	 * deliberately conservative: ambiguous or malformed markup is rejected.
	 */
	public static function isEligiblePost( \WP_Post $post ): bool {
		if ( $post->post_status !== 'publish' ) {
			return false;
		}

		$schemas   = array();
		$generated = ( new \Holvek\WordPress\Modules\Schema\SchemaGenerator() )->getSchemaForPost( $post->ID );
		if ( ! empty( $generated['@graph'] ) && is_array( $generated['@graph'] ) ) {
			$schemas = $generated['@graph'];
		}

		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$decoded = json_decode( trim( $json ), true );
				if ( is_array( $decoded ) ) {
					$schemas[] = $decoded;
				}
			}
		}

		foreach ( $schemas as $schema ) {
			if ( self::containsJobPosting( $schema ) || self::containsVideoBroadcastEvent( $schema ) ) {
				return true;
			}
		}

		return false;
	}

	private static function containsJobPosting( array $schema ): bool {
		$type  = $schema['@type'] ?? array();
		$types = is_array( $type ) ? $type : array( $type );
		if ( in_array( 'JobPosting', $types, true ) ) {
			return true;
		}

		foreach ( $schema as $value ) {
			if ( is_array( $value ) ) {
				if ( ValueList::isList( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) && self::containsJobPosting( $item ) ) {
							return true;
						}
					}
				} elseif ( self::containsJobPosting( $value ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function containsVideoBroadcastEvent( array $schema ): bool {
		$type  = $schema['@type'] ?? array();
		$types = is_array( $type ) ? $type : array( $type );
		if ( in_array( 'VideoObject', $types, true ) ) {
			foreach ( array( 'publication', 'broadcastOfEvent' ) as $key ) {
				$event = $schema[ $key ] ?? null;
				if ( is_array( $event ) ) {
					$eventType  = $event['@type'] ?? array();
					$eventTypes = is_array( $eventType ) ? $eventType : array( $eventType );
					if ( in_array( 'BroadcastEvent', $eventTypes, true ) ) {
						return true;
					}
				}
			}
		}

		foreach ( $schema as $value ) {
			if ( is_array( $value ) ) {
				if ( ValueList::isList( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) && self::containsVideoBroadcastEvent( $item ) ) {
							return true;
						}
					}
				} elseif ( self::containsVideoBroadcastEvent( $value ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Retrieve a valid Google OAuth2 access token.
	 *
	 * When called with a $jsonKey array the key is used directly (internal use).
	 * When called with no arguments the key is read from the stored option, which
	 * allows external callers (e.g. AdminModule) to obtain a token without
	 * duplicating the credential-loading logic.
	 *
	 * @param array|null $jsonKey Service-account credentials array, or null to auto-load.
	 * @return string|null        Bearer token string, or null on failure.
	 */
	public function getAccessToken( ?array $jsonKey = null ): ?string {
		if ( $jsonKey === null ) {
			$apiKeyJsonStr = $this->getStoredCredentials();
			if ( empty( $apiKeyJsonStr ) ) {
				return null;
			}
			$jsonKey = json_decode( $apiKeyJsonStr, true );
			if ( ! $jsonKey || ! isset( $jsonKey['private_key'], $jsonKey['client_email'] ) ) {
				return null;
			}
		}

		$transientKey = 'holvek_gsc_token';
		$token        = get_transient( $transientKey );
		if ( $token ) {
			return $token;
		}

		$header = wp_json_encode(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
			)
		);
		$now    = time();
		$claim  = wp_json_encode(
			array(
				'iss'   => $jsonKey['client_email'],
				'scope' => 'https://www.googleapis.com/auth/indexing',
				'aud'   => 'https://oauth2.googleapis.com/token',
				'exp'   => $now + 3600,
				'iat'   => $now,
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is the JWT wire encoding, not code obfuscation.
		$base64UrlHeader = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $header ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is the JWT wire encoding, not code obfuscation.
		$base64UrlClaim = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $claim ) );

		$signature = '';
		if ( ! openssl_sign( $base64UrlHeader . '.' . $base64UrlClaim, $signature, $jsonKey['private_key'], 'SHA256' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is the JWT wire encoding, not code obfuscation.
		$base64UrlSignature = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $signature ) );
		$jwt                = $base64UrlHeader . '.' . $base64UrlClaim . '.' . $base64UrlSignature;

		$response = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['access_token'] ) ) {
			set_transient( $transientKey, $body['access_token'], 3500 ); // Cache for slightly less than 1 hour
			return $body['access_token'];
		}

		return null;
	}
}
