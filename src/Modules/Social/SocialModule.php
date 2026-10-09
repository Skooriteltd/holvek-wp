<?php

namespace Holvek\WordPress\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Queue\QueueManager;
use Holvek\WordPress\Core\Security\KeyVault;

class SocialModule {

	private EventManager $eventManager;

	public function __construct( EventManager $eventManager ) {
		$this->eventManager = $eventManager;
	}

	public function boot(): void {
		$this->eventManager->addAction( 'rest_api_init', array( $this, 'registerRestRoutes' ) );
		$this->eventManager->addAction( 'transition_post_status', array( $this, 'handlePostTransition' ), 10, 3 );
	}

	public function handlePostTransition( $newStatus, $oldStatus, $post ): void {
		// Only trigger on first publish
		if ( $newStatus === 'publish' && $oldStatus !== 'publish' ) {

			// Check global auto-share toggle setting
			$globalAutoShare = get_option( 'holvek_global_auto_share', 'yes' );
			if ( $globalAutoShare !== 'yes' ) {
				return;
			}

			// Dispatch job
			$queueManager = new QueueManager();
			$queueManager->push(
				AutoShareJob::class,
				array(
					'post_id' => $post->ID,
					'retries' => 0,
					'manual'  => false,
				)
			);
		}
	}

	public function registerRestRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/social/accounts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getAccounts' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveAccount' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/social/accounts/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteAccount' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE, // PUT/PATCH
					'callback'            => array( $this, 'updateAccount' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/social/share',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'manualShare' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		// Mock OAuth callback endpoints for testing UI
		register_rest_route(
			'holvek/v1',
			'/social/oauth-init',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'initOAuth' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/social/oauth-callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'oauthCallback' ),
				'permission_callback' => '__return_true', // Public for redirect
			)
		);

		register_rest_route(
			'holvek/v1',
			'/social/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSocialSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveSocialSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);
	}

	/**
	 * Social tokens and OAuth app secrets are site credentials.
	 * manage_seo is granted to administrators only, not to authors or editors.
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_seo' );
	}

	/**
	 * @return \WP_Error|null
	 */
	private function permissionError(): ?\WP_Error {
		if ( $this->checkPermission() ) {
			return null;
		}

		return new \WP_Error(
			'forbidden',
			__( 'You do not have permission to manage social accounts.', 'holvek' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $accounts
	 * @return array<int, array<string, mixed>>
	 */
	private function maskAccounts( array $accounts ): array {
		foreach ( $accounts as &$account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			foreach ( $account as $key => $value ) {
				if ( ! is_string( $key ) || ! is_string( $value ) || $value === '' ) {
					continue;
				}
				$normalized = strtolower( $key );
				if ( str_contains( $normalized, 'token' ) || str_contains( $normalized, 'secret' ) ) {
					$account[ $key ] = '********';
				}
			}
		}
		unset( $account );

		return $accounts;
	}

	public function getAccounts( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$manager  = new SocialAccountManager();
		$accounts = $this->maskAccounts( $manager->getAccounts() );

		$cats       = get_categories( array( 'hide_empty' => false ) );
		$categories = array_map(
			function ( $cat ) {
				return array(
					'id'   => $cat->term_id,
					'name' => $cat->name,
				);
			},
			$cats
		);

		return rest_ensure_response(
			array(
				'accounts'          => $accounts,
				'global_auto_share' => get_option( 'holvek_global_auto_share', 'yes' ) === 'yes',
				'categories'        => $categories,
			)
		);
	}

	public function saveAccount( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$params = $request->get_json_params();

		if ( isset( $params['global_auto_share'] ) ) {
			update_option( 'holvek_global_auto_share', $params['global_auto_share'] ? 'yes' : 'no' );
			return rest_ensure_response( array( 'success' => true ) );
		}

		if ( empty( $params['network'] ) || empty( $params['access_token'] ) ) {
			return new \WP_Error( 'missing_params', __( 'Network and Access Token are required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$manager = new SocialAccountManager();
		$saved   = $manager->saveAccount(
			array(
				'network'      => sanitize_text_field( $params['network'] ),
				'access_token' => sanitize_text_field( $params['access_token'] ),
				'profile_name' => sanitize_text_field( $params['profile_name'] ?? 'Unknown Profile' ),
				'auto_share'   => isset( $params['auto_share'] ) ? (bool) $params['auto_share'] : true,
				'created_at'   => time(),
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'accounts' => $this->maskAccounts( $manager->getAccounts() ),
			)
		);
	}

	public function deleteAccount( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$id      = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$manager = new SocialAccountManager();

		if ( $manager->deleteAccount( $id ) ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'accounts' => $this->maskAccounts( $manager->getAccounts() ),
				)
			);
		}

		return new \WP_Error( 'not_found', __( 'Account not found.', 'holvek' ), array( 'status' => 404 ) );
	}

	public function updateAccount( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$id     = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$manager = new SocialAccountManager();
		if ( $manager->updateAccount( $id, $params ) ) {
			return rest_ensure_response(
				array(
					'success'  => true,
					'accounts' => $this->maskAccounts( $manager->getAccounts() ),
				)
			);
		}

		return new \WP_Error( 'not_found', __( 'Account not found.', 'holvek' ), array( 'status' => 404 ) );
	}

	public function manualShare( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$postId = $request->get_param( 'post_id' );
		if ( ! $postId ) {
			return new \WP_Error( 'missing_param', __( 'Post ID is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$queueManager = new QueueManager();
		$queueManager->push(
			AutoShareJob::class,
			array(
				'post_id' => $postId,
				'retries' => 0,
				'manual'  => true,
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'queued'  => true,
				'message' => __( 'Share queued. Holvek will send it in the background.', 'holvek' ),
			)
		);
	}

	public function initOAuth( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$params  = $request->get_json_params();
		$network = sanitize_text_field( $params['network'] ?? '' );

		if ( empty( $network ) ) {
			return new \WP_Error( 'missing_network', __( 'Network parameter is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$apps = get_option( 'holvek_social_apps', array() );
		if ( empty( $apps[ $network ]['app_id'] ) || empty( $apps[ $network ]['app_secret'] ) ) {
			return new \WP_Error(
				'not_configured',
				sprintf(
					/* translators: %s: social network name. */
					__( 'Please configure your %s App ID and Secret in the Platform API Settings first.', 'holvek' ),
					ucfirst( $network )
				),
				array( 'status' => 400 )
			);
		}

		$clientId     = $apps[ $network ]['app_id'];
		$state        = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false ) : bin2hex( random_bytes( 16 ) );
		$codeVerifier = $this->generateCodeVerifier();

		// Store transient for 10 minutes tied to the initiating administrator.
		// The verifier stays server-side and is never returned to the browser.
		set_transient(
			'holvek_oauth_state_' . $state,
			array(
				'user_id'       => get_current_user_id(),
				'network'       => $network,
				'code_verifier' => $codeVerifier,
			),
			10 * MINUTE_IN_SECONDS
		);

		$callbackUri = $this->oauthCallbackUrl( $network );
		$authUrl     = $this->buildAuthorizeUrl( $network, (string) $clientId, $callbackUri, $state, $codeVerifier );

		return rest_ensure_response(
			array(
				'success'  => true,
				'auth_url' => $authUrl,
				'state'    => $state,
			)
		);
	}

	public function oauthCallback( \WP_REST_Request $request ): \WP_REST_Response {
		$network = sanitize_text_field( $request->get_param( 'network' ) ?? '' );
		$code    = $request->get_param( 'code' );
		$error   = $request->get_param( 'error' );
		$state   = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

		$redirectUrl = admin_url( 'admin.php?page=holvek#/social' );

		if ( $error || ! $code || empty( $state ) ) {
			$errParam = $error ? sanitize_key( (string) $error ) : 'missing_code_or_state';
			if ( $errParam === '' ) {
				$errParam = 'oauth_error';
			}
			$this->redirectTo( add_query_arg( 'oauth_error', $errParam, $redirectUrl ) );
		}

		// Verify state against transient
		$transientKey = 'holvek_oauth_state_' . $state;
		$stateData    = get_transient( $transientKey );

		if ( empty( $stateData ) || ! is_array( $stateData ) ) {
			$this->redirectTo( add_query_arg( 'oauth_error', 'invalid_or_expired_state', $redirectUrl ) );
		}

		// Delete transient immediately to prevent replay
		delete_transient( $transientKey );

		// Verify network matches initiation
		if ( ( $stateData['network'] ?? '' ) !== $network ) {
			$this->redirectTo( add_query_arg( 'oauth_error', 'network_mismatch', $redirectUrl ) );
		}

		// The person who started the flow must still be allowed to hold site credentials.
		$userId = (int) ( $stateData['user_id'] ?? 0 );
		$user   = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user || ! ( user_can( $user, 'manage_seo' ) || user_can( $user, 'manage_options' ) ) ) {
			$this->redirectTo( add_query_arg( 'oauth_error', 'unauthorized', $redirectUrl ) );
		}

		$apps = get_option( 'holvek_social_apps', array() );
		if ( empty( $apps[ $network ]['app_id'] ) || empty( $apps[ $network ]['app_secret'] ) ) {
			$this->redirectTo( add_query_arg( 'oauth_error', 'not_configured', $redirectUrl ) );
		}

		$appId       = $apps[ $network ]['app_id'];
		$appSecret   = KeyVault::decrypt( $apps[ $network ]['app_secret'] );
		$callbackUri = $this->oauthCallbackUrl( $network );

		$accessToken = '';
		$profileName = ucfirst( $network ) . ' Profile';

		if ( $network === 'facebook' ) {
			$response = wp_safe_remote_post(
				'https://graph.facebook.com/v19.0/oauth/access_token',
				array(
					'timeout' => 15,
					'body'    => array(
						'client_id'     => $appId,
						'redirect_uri'  => $callbackUri,
						'client_secret' => $appSecret,
						'code'          => $code,
					),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$body        = json_decode( wp_remote_retrieve_body( $response ), true );
				$accessToken = $body['access_token'] ?? '';

				if ( $accessToken ) {
					$pagesRes = wp_safe_remote_get(
						'https://graph.facebook.com/v19.0/me/accounts',
						array(
							'timeout' => 15,
							'headers' => array(
								'Authorization' => 'Bearer ' . $accessToken,
							),
						)
					);
					if ( ! is_wp_error( $pagesRes ) ) {
						$pagesBody = json_decode( wp_remote_retrieve_body( $pagesRes ), true );
						if ( ! empty( $pagesBody['data'][0] ) ) {
							$page        = $pagesBody['data'][0];
							$accessToken = $page['access_token'];
							$profileName = $page['name'];
						}
					}
				}
			}
		} else {
			$tokenEndpoints = array(
				'linkedin'  => 'https://www.linkedin.com/oauth/v2/accessToken',
				'pinterest' => 'https://api.pinterest.com/v5/oauth/token',
				'twitter'   => 'https://api.twitter.com/2/oauth2/token',
			);

			if ( isset( $tokenEndpoints[ $network ] ) ) {
				$args = array(
					'body' => array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'redirect_uri'  => $callbackUri,
						'client_id'     => $appId,
						'client_secret' => $appSecret,
					),
				);

				if ( $network === 'twitter' ) {
					$args['body']['code_verifier'] = (string) ( $stateData['code_verifier'] ?? '' );
					$args['headers']               = array(
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth 2 client authentication requires standard Base64.
						'Authorization' => 'Basic ' . base64_encode( $appId . ':' . $appSecret ),
					);
				}

				$response = wp_safe_remote_post( $tokenEndpoints[ $network ], $args );
				if ( ! is_wp_error( $response ) ) {
					$body        = json_decode( wp_remote_retrieve_body( $response ), true );
					$accessToken = $body['access_token'] ?? '';
				}
			}
		}

		if ( $accessToken ) {
			$manager = new SocialAccountManager();
			$saved   = $manager->saveAccount(
				array(
					'network'      => sanitize_text_field( $network ),
					'access_token' => $accessToken,
					'profile_name' => $profileName,
					'auto_share'   => true,
					'created_at'   => time(),
				)
			);
			if ( is_wp_error( $saved ) ) {
				$this->redirectTo( add_query_arg( 'oauth_error', 'encryption_unavailable', $redirectUrl ) );
			}
		}

		$this->redirectTo( $redirectUrl );
	}

	/**
	 * Authorize and token exchange must send this exact redirect URI, including the network.
	 */
	private function oauthCallbackUrl( string $network ): string {
		return add_query_arg(
			'network',
			$network,
			rest_url( 'holvek/v1/social/oauth-callback' )
		);
	}

	/**
	 * @return string Authorization URL, or an empty string for an unknown network.
	 */
	private function buildAuthorizeUrl( string $network, string $clientId, string $callbackUri, string $state, string $codeVerifier ): string {
		if ( $network === 'facebook' ) {
			$base  = 'https://www.facebook.com/v19.0/dialog/oauth';
			$query = array(
				'client_id'     => $clientId,
				'redirect_uri'  => $callbackUri,
				'state'         => $state,
				'scope'         => 'pages_manage_posts,pages_read_engagement',
				'response_type' => 'code',
			);
		} elseif ( $network === 'twitter' ) {
			$base  = 'https://twitter.com/i/oauth2/authorize';
			$query = array(
				'response_type'         => 'code',
				'client_id'             => $clientId,
				'redirect_uri'          => $callbackUri,
				'state'                 => $state,
				'scope'                 => 'tweet.read tweet.write users.read offline.access',
				'code_challenge'        => $this->codeChallenge( $codeVerifier ),
				'code_challenge_method' => 'S256',
			);
		} elseif ( $network === 'linkedin' ) {
			$base  = 'https://www.linkedin.com/oauth/v2/authorization';
			$query = array(
				'response_type' => 'code',
				'client_id'     => $clientId,
				'redirect_uri'  => $callbackUri,
				'state'         => $state,
				'scope'         => 'w_member_social',
			);
		} elseif ( $network === 'pinterest' ) {
			$base  = 'https://www.pinterest.com/oauth/';
			$query = array(
				'client_id'     => $clientId,
				'redirect_uri'  => $callbackUri,
				'response_type' => 'code',
				'state'         => $state,
				'scope'         => 'boards:read,pins:read,pins:write',
			);
		} else {
			return '';
		}

		return $base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	private function generateCodeVerifier(): string {
		return $this->base64Url( random_bytes( 32 ) );
	}

	private function codeChallenge( string $verifier ): string {
		return $this->base64Url( hash( 'sha256', $verifier, true ) );
	}

	private function base64Url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 uses RFC 4648 base64url, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Redirect and stop. wp_safe_redirect refuses off-site targets.
	 */
	private function redirectTo( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	public function getSocialSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$apps = get_option( 'holvek_social_apps', array() );

		// Mask secrets before sending to frontend
		foreach ( $apps as $network => $creds ) {
			if ( ! empty( $creds['app_secret'] ) ) {
				$apps[ $network ]['app_secret'] = '********';
			}
		}

		return rest_ensure_response(
			array(
				'apps' => $apps,
			)
		);
	}

	public function saveSocialSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		$params = $request->get_json_params();
		$apps   = get_option( 'holvek_social_apps', array() );

		$networks = array( 'facebook', 'twitter', 'linkedin', 'pinterest' );

		foreach ( $networks as $net ) {
			if ( isset( $params[ $net ] ) ) {
				$creds      = $params[ $net ];
				$app_id     = sanitize_text_field( $creds['app_id'] ?? '' );
				$app_secret = sanitize_text_field( $creds['app_secret'] ?? '' );

				// Only update secret if it's not the masked value
				if ( $app_secret === '********' ) {
					$app_secret = $apps[ $net ]['app_secret'] ?? '';
				} elseif ( ! empty( $app_secret ) ) {
					$encrypted = KeyVault::encrypt( $app_secret );
					if ( is_wp_error( $encrypted ) || ! is_string( $encrypted ) || ! KeyVault::isCiphertext( $encrypted ) ) {
						if ( is_wp_error( $encrypted ) ) {
							return $encrypted;
						}
						return new \WP_Error(
							'holvek_encryption_failed',
							__( 'Holvek could not encrypt this secret. The value was not saved.', 'holvek' ),
							array( 'status' => 500 )
						);
					}
					$app_secret = $encrypted;
				}

				$apps[ $net ] = array(
					'app_id'     => $app_id,
					'app_secret' => $app_secret,
				);
			}
		}

		update_option( 'holvek_social_apps', $apps, false );

		// Mask before returning
		foreach ( $apps as $network => $creds ) {
			if ( ! empty( $creds['app_secret'] ) ) {
				$apps[ $network ]['app_secret'] = '********';
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'apps'    => $apps,
			)
		);
	}
}
