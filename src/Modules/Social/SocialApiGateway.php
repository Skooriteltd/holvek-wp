<?php

namespace Holvek\WordPress\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Security\KeyVault;

class SocialApiGateway {

	/**
	 * Dispatch the post to the specified network using the account details.
	 */
	public function share( array $account, array $payload ): array {
		$network        = $account['network'] ?? 'unknown';
		$encryptedToken = $account['access_token'] ?? '';

		if ( empty( $encryptedToken ) ) {
			return array(
				'success' => false,
				'message' => __( 'No access token available for this account.', 'holvek' ),
				'code'    => 401,
			);
		}

		// Decrypt the token before making the call
		$token = KeyVault::decrypt( $encryptedToken );
		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to decrypt access token.', 'holvek' ),
				'code'    => 401,
			);
		}

		// Validate payload content
		if ( empty( $payload['url'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Cannot share without a URL.', 'holvek' ),
				'code'    => 400,
			);
		}

		$message = sanitize_textarea_field( $payload['message'] ?? '' );
		$url     = esc_url_raw( $payload['url'] );

		switch ( $network ) {
			case 'facebook':
				return $this->shareFacebook( $token, $message, $url );
			case 'linkedin':
				return $this->shareLinkedIn( $token, $message, $url );
			case 'twitter':
				return $this->shareTwitter( $token, $message, $url );
			case 'pinterest':
				return $this->sharePinterest( $token, $message, $url );
			default:
				return array(
					'success' => false,
					'message' => __( 'Unsupported network.', 'holvek' ),
					'code'    => 400,
				);
		}
	}

	private function shareFacebook( string $token, string $message, string $url ): array {
		// Facebook Graph API v19.0 endpoint for Page Feed
		$apiUrl = 'https://graph.facebook.com/v19.0/me/feed';

		$body = array(
			'message'      => $message,
			'link'         => $url,
			'access_token' => $token,
		);

		$response = wp_safe_remote_post(
			$apiUrl,
			array(
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'code'    => 500,
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $responseCode >= 200 && $responseCode < 300 ) {
			return array(
				'success' => true,
				'post_id' => $responseBody['id'] ?? '',
				'code'    => $responseCode,
			);
		}

		return array(
			'success' => false,
			'message' => $responseBody['error']['message'] ?? 'Unknown Facebook error',
			'code'    => $responseCode,
		);
	}

	private function shareTwitter( string $token, string $message, string $url ): array {
		// Twitter API v2
		$apiUrl = 'https://api.twitter.com/2/tweets';

		$body = array(
			'text' => $message . "\n" . $url,
		);

		$response = wp_safe_remote_post(
			$apiUrl,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'code'    => 500,
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $responseCode >= 200 && $responseCode < 300 ) {
			return array(
				'success' => true,
				'post_id' => $responseBody['data']['id'] ?? '',
				'code'    => $responseCode,
			);
		}

		return array(
			'success' => false,
			'message' => $responseBody['detail'] ?? 'Unknown Twitter error',
			'code'    => $responseCode,
		);
	}

	private function shareLinkedIn( string $token, string $message, string $url ): array {
		$meResponse = wp_safe_remote_get(
			'https://api.linkedin.com/v2/userinfo',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $meResponse ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to fetch LinkedIn profile.', 'holvek' ),
				'code'    => 500,
			);
		}
		$meBody = json_decode( wp_remote_retrieve_body( $meResponse ), true );
		$urn    = $meBody['sub'] ?? '';

		if ( ! $urn ) {
			return array(
				'success' => false,
				'message' => __( 'Could not determine LinkedIn author URN.', 'holvek' ),
				'code'    => 400,
			);
		}

		// Try modern LinkedIn /v2/posts API first
		$apiUrl = 'https://api.linkedin.com/v2/posts';
		$body   = array(
			'author'                    => 'urn:li:person:' . $urn,
			'commentary'                => $message,
			'visibility'                => 'PUBLIC',
			'distribution'              => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                 => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'content'                   => array(
				'article' => array(
					'source' => $url,
					'title'  => substr( $message, 0, 100 ),
				),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);

		$response = wp_safe_remote_post(
			$apiUrl,
			array(
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'Content-Type'              => 'application/json',
					'LinkedIn-Version'          => '202401',
					'X-Restli-Protocol-Version' => '2.0.0',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 ) {
			$headers = wp_remote_retrieve_headers( $response );
			$postId  = $headers['x-restli-id'] ?? $headers['x-linkedin-id'] ?? '';
			return array(
				'success' => true,
				'post_id' => $postId,
				'code'    => wp_remote_retrieve_response_code( $response ),
			);
		}

		// Fallback to legacy ugcPosts API
		$legacyApiUrl = 'https://api.linkedin.com/v2/ugcPosts';
		$legacyBody   = array(
			'author'          => 'urn:li:person:' . $urn,
			'lifecycleState'  => 'PUBLISHED',
			'specificContent' => array(
				'com.linkedin.ugc.ShareContent' => array(
					'shareCommentary'    => array( 'text' => $message ),
					'shareMediaCategory' => 'ARTICLE',
					'media'              => array(
						array(
							'status'      => 'READY',
							'originalUrl' => $url,
						),
					),
				),
			),
			'visibility'      => array( 'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC' ),
		);

		$legacyResponse = wp_safe_remote_post(
			$legacyApiUrl,
			array(
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'Content-Type'              => 'application/json',
					'X-Restli-Protocol-Version' => '2.0.0',
				),
				'body'    => wp_json_encode( $legacyBody ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $legacyResponse ) ) {
			return array(
				'success' => false,
				'message' => $legacyResponse->get_error_message(),
				'code'    => 500,
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $legacyResponse );
		if ( $responseCode >= 200 && $responseCode < 300 ) {
			$headers = wp_remote_retrieve_headers( $legacyResponse );
			$postId  = $headers['x-linkedin-id'] ?? '';
			return array(
				'success' => true,
				'post_id' => $postId,
				'code'    => $responseCode,
			);
		}

		$responseBody = json_decode( wp_remote_retrieve_body( $legacyResponse ), true );
		return array(
			'success' => false,
			'message' => $responseBody['message'] ?? 'Unknown LinkedIn error',
			'code'    => $responseCode,
		);
	}

	private function sharePinterest( string $token, string $message, string $url ): array {
		$boardsRes = wp_safe_remote_get(
			'https://api.pinterest.com/v5/boards',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $boardsRes ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to fetch Pinterest boards.', 'holvek' ),
				'code'    => 500,
			);
		}

		$boardsBody = json_decode( wp_remote_retrieve_body( $boardsRes ), true );
		$boardId    = $boardsBody['items'][0]['id'] ?? '';

		if ( ! $boardId ) {
			return array(
				'success' => false,
				'message' => __( 'No Pinterest boards found to pin to.', 'holvek' ),
				'code'    => 400,
			);
		}

		$apiUrl = 'https://api.pinterest.com/v5/pins';
		$body   = array(
			'board_id'    => $boardId,
			'title'       => substr( $message, 0, 100 ),
			'description' => $message,
			'link'        => $url,
		);

		$response = wp_safe_remote_post(
			$apiUrl,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'code'    => 500,
			);
		}

		$responseCode = wp_remote_retrieve_response_code( $response );
		$responseBody = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $responseCode >= 200 && $responseCode < 300 ) {
			return array(
				'success' => true,
				'post_id' => $responseBody['id'] ?? '',
				'code'    => $responseCode,
			);
		}

		return array(
			'success' => false,
			'message' => $responseBody['message'] ?? 'Unknown Pinterest error',
			'code'    => $responseCode,
		);
	}
}
