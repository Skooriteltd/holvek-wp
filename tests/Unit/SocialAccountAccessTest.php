<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Social\SocialAccountManager;
use Holvek\WordPress\Modules\Social\SocialModule;
use PHPUnit\Framework\TestCase;

class SocialAccountAccessTest extends TestCase {

	private const PLAINTEXT_TOKEN = 'super-secret-access-token';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mock_transients']         = array();
		$GLOBALS['mock_http_log']           = array();
		$GLOBALS['mock_http_responses']     = array();
		$GLOBALS['mock_redirects']          = array();
		$GLOBALS['mock_redirect_throw']     = true;
		$GLOBALS['mock_users']              = array();
		$GLOBALS['mock_categories']         = array();
		$GLOBALS['mock_current_user_id']    = 4;
		$GLOBALS['mock_generated_password'] = 'fixed-oauth-state-value';

		update_option( 'holvek_encryption_salt', 'social-test-salt-0123456789abcdef' );
		update_option( 'holvek_social_accounts', array() );
		update_option( 'holvek_social_apps', array() );
		update_option( 'holvek_global_auto_share', 'yes' );
		$this->grant( false, false );
	}

	protected function tearDown(): void {
		$GLOBALS['mock_redirect_throw'] = false;
		parent::tearDown();
	}

	public function testAuthorAndEditorCannotReadOrStoreSocialCredentials(): void {
		$module = $this->module();
		$roles  = array(
			'author' => array(
				'edit_posts'       => true,
				'edit_seo_meta'    => true,
				'view_seo_reports' => false,
				'manage_redirects' => false,
				'manage_options'   => false,
				'manage_seo'       => false,
			),
			'editor' => array(
				'edit_posts'       => true,
				'edit_seo_meta'    => true,
				'view_seo_reports' => true,
				'manage_redirects' => true,
				'manage_options'   => false,
				'manage_seo'       => false,
			),
		);

		foreach ( $roles as $role => $caps ) {
			$GLOBALS['mock_user_caps']      = $caps;
			$GLOBALS['mock_transients']     = array();
			$secret                         = $role . '-token-must-not-leak';
			update_option( 'holvek_social_accounts', array() );
			update_option( 'holvek_social_apps', array() );
			update_option( 'holvek_global_auto_share', 'yes' );

			$this->assertFalse( $module->checkPermission(), $role );

			$toggle = new \WP_REST_Request();
			$toggle->set_json_params( array( 'global_auto_share' => false ) );
			$toggled = $module->saveAccount( $toggle );
			$this->assertForbidden( $toggled, $secret );
			$this->assertSame( 'yes', get_option( 'holvek_global_auto_share' ) );

			$save = new \WP_REST_Request();
			$save->set_json_params(
				array(
					'network'      => 'twitter',
					'access_token' => $secret,
					'profile_name' => 'Hidden',
				)
			);
			$this->assertForbidden( $module->saveAccount( $save ), $secret );
			$this->assertSame( array(), ( new SocialAccountManager() )->getAccounts() );

			$this->assertForbidden( $module->getAccounts( new \WP_REST_Request() ), $secret );

			$delete = new \WP_REST_Request();
			$delete->set_param( 'id', 'twitter_1' );
			$this->assertForbidden( $module->deleteAccount( $delete ), $secret );

			$update = new \WP_REST_Request();
			$update->set_param( 'id', 'twitter_1' );
			$update->set_json_params(
				array(
					'profile_name' => 'Nope',
					'access_token' => $secret,
				)
			);
			$this->assertForbidden( $module->updateAccount( $update ), $secret );

			$share = new \WP_REST_Request();
			$share->set_param( 'post_id', 9 );
			$this->assertForbidden( $module->manualShare( $share ), $secret );

			$init = new \WP_REST_Request();
			$init->set_json_params( array( 'network' => 'twitter' ) );
			$this->assertForbidden( $module->initOAuth( $init ), $secret );
			$this->assertSame( array(), $GLOBALS['mock_transients'] );

			update_option(
				'holvek_social_apps',
				array(
					'twitter' => array(
						'app_id'     => 'app',
						'app_secret' => $secret,
					),
				)
			);
			$this->assertForbidden( $module->getSocialSettings( new \WP_REST_Request() ), $secret );

			$saveSettings = new \WP_REST_Request();
			$saveSettings->set_json_params(
				array(
					'twitter' => array(
						'app_id'     => 'changed',
						'app_secret' => 'replacement-secret',
					),
				)
			);
			$this->assertForbidden( $module->saveSocialSettings( $saveSettings ), $secret );
			$this->assertSame( $secret, get_option( 'holvek_social_apps' )['twitter']['app_secret'] );
		}
	}

	public function testSiteManagersPassTheSocialCapabilityCheck(): void {
		$module = $this->module();

		$this->grant( true, false );
		$this->assertTrue( $module->checkPermission() );

		$this->grant( false, true );
		$this->assertTrue( $module->checkPermission() );
	}

	public function testAccountResponsesMaskTokensWhileStorageStaysEncrypted(): void {
		$this->grant( true, false );
		$module = $this->module();

		$save = new \WP_REST_Request();
		$save->set_json_params(
			array(
				'network'      => 'twitter',
				'access_token' => self::PLAINTEXT_TOKEN,
				'profile_name' => 'Newsroom',
			)
		);
		$saved = $module->saveAccount( $save );
		$this->assertInstanceOf( \WP_REST_Response::class, $saved );
		$this->assertTokenMasked( $saved->get_data()['accounts'] );

		$stored = ( new SocialAccountManager() )->getAccounts();
		$this->assertCount( 1, $stored );
		$this->assertStringStartsWith( 'holvek_enc::', $stored[0]['access_token'] );
		$this->assertSame( self::PLAINTEXT_TOKEN, KeyVault::decrypt( $stored[0]['access_token'] ) );

		$listed = $module->getAccounts( new \WP_REST_Request() );
		$this->assertTokenMasked( $listed->get_data()['accounts'] );

		$second = new \WP_REST_Request();
		$second->set_json_params(
			array(
				'network'      => 'linkedin',
				'access_token' => 'second-secret-token',
				'profile_name' => 'Company',
			)
		);
		$module->saveAccount( $second );
		$accounts = ( new SocialAccountManager() )->getAccounts();
		$firstId  = $accounts[0]['id'];

		$update = new \WP_REST_Request();
		$update->set_param( 'id', $firstId );
		$update->set_json_params(
			array(
				'profile_name' => 'Renamed',
				'access_token' => 'replacement-token-must-not-stick',
			)
		);
		$updated = $module->updateAccount( $update );
		$this->assertInstanceOf( \WP_REST_Response::class, $updated );
		$this->assertTokenMasked( $updated->get_data()['accounts'] );
		$this->assertSame( 'Renamed', $updated->get_data()['accounts'][0]['profile_name'] );

		$afterUpdate = ( new SocialAccountManager() )->getAccounts();
		$this->assertSame( self::PLAINTEXT_TOKEN, KeyVault::decrypt( $afterUpdate[0]['access_token'] ) );

		$delete = new \WP_REST_Request();
		$delete->set_param( 'id', $firstId );
		$deleted = $module->deleteAccount( $delete );
		$this->assertInstanceOf( \WP_REST_Response::class, $deleted );
		$this->assertTokenMasked( $deleted->get_data()['accounts'] );
		$this->assertStringNotContainsString( 'second-secret-token', wp_json_encode( $deleted->get_data() ) );
		$this->assertCount( 1, ( new SocialAccountManager() )->getAccounts() );
	}

	public function testSocialSettingsMaskSecretsAndKeepTheStoredCiphertext(): void {
		$this->grant( false, true );
		$module = $this->module();

		$save = new \WP_REST_Request();
		$save->set_json_params(
			array(
				'facebook' => array(
					'app_id'     => 'fb-app',
					'app_secret' => 'fb-app-secret',
				),
			)
		);
		$saved = $module->saveSocialSettings( $save );
		$this->assertSame( '********', $saved->get_data()['apps']['facebook']['app_secret'] );
		$this->assertStringNotContainsString( 'fb-app-secret', wp_json_encode( $saved->get_data() ) );

		$storedSecret = get_option( 'holvek_social_apps' )['facebook']['app_secret'];
		$this->assertStringStartsWith( 'holvek_enc::', $storedSecret );
		$this->assertSame( 'fb-app-secret', KeyVault::decrypt( $storedSecret ) );

		$listed = $module->getSocialSettings( new \WP_REST_Request() );
		$this->assertSame( '********', $listed->get_data()['apps']['facebook']['app_secret'] );
		$this->assertStringNotContainsString( 'fb-app-secret', wp_json_encode( $listed->get_data() ) );
		$this->assertStringNotContainsString( 'aew_enc::', wp_json_encode( $listed->get_data() ) );
		$this->assertStringNotContainsString( 'holvek_enc::', wp_json_encode( $listed->get_data() ) );

		$keep = new \WP_REST_Request();
		$keep->set_json_params(
			array(
				'facebook' => array(
					'app_id'     => 'fb-app',
					'app_secret' => '********',
				),
			)
		);
		$module->saveSocialSettings( $keep );
		$this->assertSame( 'fb-app-secret', KeyVault::decrypt( get_option( 'holvek_social_apps' )['facebook']['app_secret'] ) );
	}

	public function testRegisteredRoutesKeepThePublicCallbackAndManagerPermissions(): void {
		$GLOBALS['mock_rest_routes'] = array();
		$module                      = $this->module();
		$module->registerRestRoutes();

		$accounts = $GLOBALS['mock_rest_routes']['holvek/v1/social/accounts'];
		foreach ( $accounts as $endpoint ) {
			$this->assertSame( array( $module, 'checkPermission' ), $endpoint['permission_callback'] );
		}

		$callback = $GLOBALS['mock_rest_routes']['holvek/v1/social/oauth-callback'];
		$this->assertSame( '__return_true', $callback['permission_callback'] );

		$init = $GLOBALS['mock_rest_routes']['holvek/v1/social/oauth-init'];
		$this->assertSame( array( $module, 'checkPermission' ), $init['permission_callback'] );

		$settings = $GLOBALS['mock_rest_routes']['holvek/v1/social/settings'];
		foreach ( $settings as $endpoint ) {
			$this->assertSame( array( $module, 'checkPermission' ), $endpoint['permission_callback'] );
		}
	}

	public function testTwitterAuthorizeUsesS256AndHidesTheVerifier(): void {
		$this->grant( true, false );
		$this->rememberUser( 4, true, false );
		$this->configureApp( 'twitter', 'twitter-client', 'twitter-secret' );

		$result = $this->module()->initOAuth( $this->networkRequest( 'twitter' ) );
		$data   = $result->get_data();
		$state  = $data['state'];
		$stored = get_transient( 'holvek_oauth_state_' . $state );

		$this->assertIsArray( $stored );
		$verifier = (string) $stored['code_verifier'];
		$this->assertNotSame( '', $verifier );
		$this->assertStringNotContainsString( $verifier, wp_json_encode( $data ) );
		$this->assertStringNotContainsString( $verifier, $data['auth_url'] );
		$this->assertArrayNotHasKey( 'code_verifier', $data );

		$query = $this->query( $data['auth_url'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertNotSame( 'plain', $query['code_challenge_method'] );
		$this->assertNotSame( 'challenge', $query['code_challenge'] );
		$this->assertSame( $this->s256( $verifier ), $query['code_challenge'] );
		$this->assertSame(
			'https://example.com/wp-json/holvek/v1/social/oauth-callback?network=twitter',
			$query['redirect_uri']
		);
	}

	public function testTwitterTokenExchangePostsTheVerifierAndMatchingRedirectUri(): void {
		$this->grant( true, false );
		$this->rememberUser( 4, true, false );
		$this->configureApp( 'twitter', 'twitter-client', 'twitter-secret' );
		$this->respondWith( 'https://api.twitter.com/2/oauth2/token', array( 'access_token' => 'twitter-user-token' ) );

		$module = $this->module();
		$init   = $module->initOAuth( $this->networkRequest( 'twitter' ) )->get_data();
		$state  = get_transient( 'holvek_oauth_state_' . $init['state'] );
		$query  = $this->query( $init['auth_url'] );

		$location = $this->captureRedirect(
			function () use ( $module, $init ) {
				$module->oauthCallback( $this->callbackRequest( 'twitter', 'auth-code', $init['state'] ) );
			}
		);

		$this->assertStringStartsWith( 'https://example.com/wp-admin/admin.php?', $location );
		$this->assertStringContainsString( '#/social', $location );
		$this->assertStringNotContainsString( 'oauth_error', $location );
		$this->assertFalse( get_transient( 'holvek_oauth_state_' . $init['state'] ) );

		$calls = $this->httpCalls( 'POST', 'https://api.twitter.com/2/oauth2/token' );
		$this->assertCount( 1, $calls );
		$body = $calls[0]['args']['body'];
		$this->assertSame( $state['code_verifier'], $body['code_verifier'] );
		$this->assertSame( $query['redirect_uri'], $body['redirect_uri'] );
		$this->assertStringNotContainsString( 'code_verifier', $calls[0]['url'] );
		$this->assertStringNotContainsString( 'twitter-secret', $calls[0]['url'] );
		$this->assertSame(
			'Basic ' . base64_encode( 'twitter-client:twitter-secret' ),
			$calls[0]['args']['headers']['Authorization']
		);

		$stored = ( new SocialAccountManager() )->getAccounts();
		$this->assertSame( 'twitter-user-token', KeyVault::decrypt( $stored[0]['access_token'] ) );
	}

	public function testFacebookExchangesTheSecretByPostAndLoadsPagesWithBearer(): void {
		$this->grant( true, false );
		$this->rememberUser( 4, true, false );
		$this->configureApp( 'facebook', 'fb-client', 'fb-app-secret' );
		$this->respondWith( 'https://graph.facebook.com/v19.0/oauth/access_token', array( 'access_token' => 'user-token-secret' ) );
		$this->respondWith(
			'https://graph.facebook.com/v19.0/me/accounts',
			array(
				'data' => array(
					array(
						'access_token' => 'page-token-secret',
						'name'         => 'Holvek Page',
					),
				),
			)
		);

		$module = $this->module();
		$init   = $module->initOAuth( $this->networkRequest( 'facebook' ) )->get_data();
		$query  = $this->query( $init['auth_url'] );

		$location = $this->captureRedirect(
			function () use ( $module, $init ) {
				$module->oauthCallback( $this->callbackRequest( 'facebook', 'fb-code', $init['state'] ) );
			}
		);

		$this->assertStringNotContainsString( 'oauth_error', $location );
		$this->assertStringNotContainsString( 'page-token-secret', $location );
		$this->assertStringNotContainsString( 'fb-app-secret', $location );

		$tokenCalls = $this->httpCalls( 'POST', 'https://graph.facebook.com/v19.0/oauth/access_token' );
		$this->assertCount( 1, $tokenCalls );
		$this->assertSame( 15, $tokenCalls[0]['args']['timeout'] );
		$this->assertSame( 'fb-app-secret', $tokenCalls[0]['args']['body']['client_secret'] );
		$this->assertSame( $query['redirect_uri'], $tokenCalls[0]['args']['body']['redirect_uri'] );
		$this->assertStringContainsString( 'network=facebook', $tokenCalls[0]['args']['body']['redirect_uri'] );
		$this->assertStringNotContainsString( 'client_secret', $tokenCalls[0]['url'] );
		$this->assertStringNotContainsString( 'fb-app-secret', $tokenCalls[0]['url'] );

		$pageCalls = $this->httpCalls( 'GET', 'https://graph.facebook.com/v19.0/me/accounts' );
		$this->assertCount( 1, $pageCalls );
		$this->assertSame( 'Bearer user-token-secret', $pageCalls[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'access_token', $pageCalls[0]['url'] );
		$this->assertStringNotContainsString( 'user-token-secret', $pageCalls[0]['url'] );

		$stored = ( new SocialAccountManager() )->getAccounts();
		$this->assertSame( 'Holvek Page', $stored[0]['profile_name'] );
		$this->assertSame( 'page-token-secret', KeyVault::decrypt( $stored[0]['access_token'] ) );
	}

	public function testInvalidOrHostileCallbackStateDoesNotCallTheProvider(): void {
		$this->configureApp( 'facebook', 'fb-client', 'fb-app-secret' );
		$module = $this->module();

		$missing = $this->captureRedirect(
			function () use ( $module ) {
				$module->oauthCallback( $this->callbackRequest( 'facebook', 'abc', 'missing-state' ) );
			}
		);
		$this->assertStringContainsString( 'oauth_error=invalid_or_expired_state#/social', $missing );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		$hostile = $this->captureRedirect(
			function () use ( $module ) {
				$request = $this->callbackRequest( 'facebook', '', 'unused-state' );
				$request->set_param( 'error', 'https://evil.example/phish' );
				$module->oauthCallback( $request );
			}
		);
		$this->assertStringStartsWith( 'https://example.com/wp-admin/admin.php?', $hostile );
		$this->assertStringContainsString( '#/social', $hostile );
		$this->assertStringNotContainsString( 'https://evil.example', $hostile );
		$this->assertStringNotContainsString( 'evil.example', $hostile );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testCallbackRejectsAnInitiatorWhoCanNoLongerManageSocial(): void {
		$this->grant( true, false );
		$this->rememberUser( 4, true, false );
		$this->configureApp( 'twitter', 'twitter-client', 'twitter-secret' );
		$this->respondWith( 'https://api.twitter.com/2/oauth2/token', array( 'access_token' => 'should-not-save' ) );

		$module = $this->module();
		$init   = $module->initOAuth( $this->networkRequest( 'twitter' ) )->get_data();
		$GLOBALS['mock_users'][4]->caps = array(
			'manage_options' => false,
			'manage_seo'     => false,
		);

		$location = $this->captureRedirect(
			function () use ( $module, $init ) {
				$module->oauthCallback( $this->callbackRequest( 'twitter', 'auth-code', $init['state'] ) );
			}
		);

		$this->assertStringContainsString( 'oauth_error=unauthorized', $location );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( array(), ( new SocialAccountManager() )->getAccounts() );
	}

	public function testOauthStateCannotBeReplayed(): void {
		$this->grant( true, false );
		$this->rememberUser( 4, true, false );
		$this->configureApp( 'twitter', 'twitter-client', 'twitter-secret' );
		$this->respondWith( 'https://api.twitter.com/2/oauth2/token', array( 'access_token' => 'twitter-user-token' ) );

		$module = $this->module();
		$init   = $module->initOAuth( $this->networkRequest( 'twitter' ) )->get_data();
		$request = $this->callbackRequest( 'twitter', 'auth-code', $init['state'] );

		$this->captureRedirect(
			function () use ( $module, $request ) {
				$module->oauthCallback( $request );
			}
		);
		$replay = $this->captureRedirect(
			function () use ( $module, $request ) {
				$module->oauthCallback( $request );
			}
		);

		$this->assertStringContainsString( 'oauth_error=invalid_or_expired_state', $replay );
		$this->assertCount( 1, $this->httpCalls( 'POST', 'https://api.twitter.com/2/oauth2/token' ) );
	}

	private function module(): SocialModule {
		return new SocialModule( new EventManager() );
	}

	private function grant( bool $manageOptions, bool $manageSeo ): void {
		$GLOBALS['mock_user_caps'] = array(
			'edit_posts'      => true,
			'manage_options'  => $manageOptions,
			'manage_seo'      => $manageSeo,
		);
	}

	private function rememberUser( int $id, bool $manageOptions, bool $manageSeo ): void {
		$user       = new \stdClass();
		$user->ID   = $id;
		$user->caps = array(
			'manage_options' => $manageOptions,
			'manage_seo'     => $manageSeo,
		);
		$GLOBALS['mock_users'][ $id ]    = $user;
		$GLOBALS['mock_current_user_id'] = $id;
	}

	private function configureApp( string $network, string $appId, string $secret ): void {
		$apps              = get_option( 'holvek_social_apps', array() );
		$apps[ $network ]  = array(
			'app_id'     => $appId,
			'app_secret' => $secret,
		);
		update_option( 'holvek_social_apps', $apps );
	}

	private function networkRequest( string $network ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'network' => $network ) );
		return $request;
	}

	private function callbackRequest( string $network, string $code, string $state ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'network', $network );
		$request->set_param( 'code', $code );
		$request->set_param( 'state', $state );
		return $request;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function respondWith( string $url, array $body ): void {
		$GLOBALS['mock_http_responses'][ $url ] = array(
			'body' => wp_json_encode( $body ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function query( string $url ): array {
		$parsed = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );
		return $parsed;
	}

	private function s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function captureRedirect( callable $callback ): string {
		$GLOBALS['mock_redirect_throw'] = true;
		try {
			$callback();
		} catch ( \RuntimeException $exception ) {
			$prefix = 'redirect:';
			$this->assertStringStartsWith( $prefix, $exception->getMessage() );
			return substr( $exception->getMessage(), strlen( $prefix ) );
		}

		$this->fail( 'Expected a safe redirect.' );
		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $accounts
	 */
	private function assertTokenMasked( array $accounts ): void {
		$this->assertNotEmpty( $accounts );
		$encoded = wp_json_encode( $accounts );
		$this->assertStringNotContainsString( self::PLAINTEXT_TOKEN, $encoded );
		$this->assertStringNotContainsString( 'aew_enc::', $encoded );
		$this->assertStringNotContainsString( 'holvek_enc::', $encoded );
		$this->assertStringNotContainsString( 'second-secret-token', $encoded );
		$this->assertStringNotContainsString( 'replacement-token-must-not-stick', $encoded );
		foreach ( $accounts as $account ) {
			$this->assertSame( '********', $account['access_token'] );
		}
	}

	private function assertForbidden( $result, string $secret ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $result ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function httpCalls( string $method, string $url ): array {
		$found = array();
		foreach ( $GLOBALS['mock_http_log'] as $call ) {
			if ( $call['method'] === $method && $call['url'] === $url ) {
				$found[] = $call;
			}
		}
		return $found;
	}
}
