<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Api\CloudCredential;
use Holvek\WordPress\Core\Api\HolvekApiGateway;
use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Mode\OperatingMode;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Admin\SettingsTransfer;
use Holvek\WordPress\Modules\Admin\UpgradePromptManager;
use Holvek\WordPress\Modules\Ai\UsageMeteringManager;
use PHPUnit\Framework\TestCase;

class CloudCredentialTest extends TestCase {

	private const SITE     = 'site-token-value-ok-1';
	private const CODE     = 'auth-code-value-ok-001';
	private const ACCESS   = 'access-token-value-ok-1';
	private const ROTATED  = 'rotated-token-value-ok';

	private bool $touchBreadcrumb = false;

	private mixed $breadcrumbWas = null;

	protected function setUp(): void {
		parent::setUp();
		$this->clearCloud();
		$this->clearHttp();
		$this->actAsAdmin();
		if ( isset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] ) ) {
			unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		}
	}

	protected function tearDown(): void {
		if ( $this->touchBreadcrumb ) {
			if ( $this->breadcrumbWas === null ) {
				delete_option( 'holvek_breadcrumb_home_label' );
			} else {
				update_option( 'holvek_breadcrumb_home_label', $this->breadcrumbWas );
			}
			$this->touchBreadcrumb = false;
			$this->breadcrumbWas   = null;
		}
		$this->clearCloud();
		$this->clearHttp();
		unset( $GLOBALS['mock_user_caps'] );
		if ( isset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] ) ) {
			unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		}
		parent::tearDown();
	}

	public function testAnEditorCannotExchangeOrReadTheConnection(): void {
		$this->actAsEditor();
		$this->storeVerified( self::ACCESS, 'pro' );
		$client  = new CloudCredential();
		$request = $this->jsonRequest( array( 'site_token' => self::SITE, 'code' => self::CODE ) );

		$results = array(
			$client->status( $request ),
			$client->connectSiteToken( $request ),
			$client->connectAuthorizationCode( $request ),
			$client->recheck( $request ),
			$client->rotate( $request ),
			$client->revoke( $request ),
		);

		foreach ( $results as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'rest_forbidden', $result->get_error_code() );
			$this->assertSame( 403, $result->data['status'] );
		}
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertTrue( CloudCredential::isActive() );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
	}

	public function testAShortTokenIsRejectedBeforeAnyRequest(): void {
		$client = new CloudCredential();
		$result = $client->connectSiteToken( $this->jsonRequest( array( 'site_token' => 'short token' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_invalid_credential', $result->get_error_code() );
		$this->assertSame( 400, $result->data['status'] );
		$this->assertStringContainsString( 'The settings screen cannot change the Holvek API host.', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertCredentialWasNotSaved();

		$colon = $client->connectAuthorizationCode( $this->jsonRequest( array( 'code' => 'https://evil.example/token-value' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $colon );
		$this->assertSame( 'holvek_invalid_credential', $colon->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testASiteTokenIsExchangedVerifiedAndStoredWithoutThePaste(): void {
		update_option( CloudCredential::LICENSE_OPTION, 'old-license-value' );
		update_option( CloudCredential::TIER_OPTION, 'enterprise' );
		update_option( OperatingMode::REVOKED_OPTION, 'yes' );
		$this->queueExchange( self::ACCESS, 'pro' );

		$response = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => '  ' . self::SITE . '  ' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['connected'] );
		$this->assertTrue( $data['credential_saved'] );
		$this->assertSame( 'pro', $data['tier'] );
		$this->assertSame( 'https://api.holvek.com', $data['host'] );
		$this->assertSame( 'https://api.holvek.com/v1', $data['api_base'] );
		$this->assertStringNotContainsString( self::ACCESS, (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( self::SITE, (string) wp_json_encode( $data ) );

		$this->assertCount( 2, $GLOBALS['mock_http_log'] );
		$post = $GLOBALS['mock_http_log'][0];
		$this->assertSame( 'POST', $post['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/site-token', $post['url'] );
		$this->assertSame( 10, $post['args']['timeout'] );
		$this->assertSame( 'Holvek-WP/1.0.0', $post['args']['headers']['User-Agent'] );
		$this->assertSame( 'application/json', $post['args']['headers']['Accept'] );
		$this->assertSame( 'application/json', $post['args']['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Authorization', $post['args']['headers'] );
		$payload = json_decode( (string) $post['args']['body'], true );
		$this->assertSame(
			array(
				'grant_type' => 'site_token',
				'site_token' => self::SITE,
				'site_url'   => 'https://example.com/',
			),
			$payload
		);

		$verify = $GLOBALS['mock_http_log'][1];
		$this->assertSame( 'GET', $verify['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/verify', $verify['url'] );
		$this->assertSame( 'Bearer ' . self::ACCESS, $verify['args']['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'Content-Type', $verify['args']['headers'] );
		$this->assertArrayNotHasKey( 'body', $verify['args'] );

		$stored = (string) get_option( CloudCredential::API_KEY_OPTION, '' );
		$this->assertStringStartsWith( 'holvek_enc::', $stored );
		$this->assertStringNotContainsString( self::SITE, $stored );
		$this->assertStringNotContainsString( self::ACCESS, $stored );
		$this->assertFalse( $GLOBALS['mock_option_autoload'][ CloudCredential::API_KEY_OPTION ] );
		$this->assertFalse( $GLOBALS['mock_option_autoload'][ CloudCredential::VERIFICATION_OPTION ] );
		$this->assertSame( '', (string) get_option( CloudCredential::LICENSE_OPTION, '' ) );
		$this->assertFalse( get_option( CloudCredential::TIER_OPTION, false ) );
		$this->assertSame( '', (string) get_option( OperatingMode::REVOKED_OPTION, '' ) );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertTrue( CloudCredential::isActive() );
		$this->assertTrue( HolvekApiGateway::hasActiveLicense() );
		$this->assertSame( 'pro', CloudCredential::cachedTier() );

		$record = get_option( CloudCredential::VERIFICATION_OPTION, array() );
		$this->assertSame( hash( 'sha256', self::ACCESS ), $record['token_hash'] );
		$this->assertSame( 'pro', $record['tier'] );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 30, (int) $record['expires_at'] );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, (int) $record['expires_at'] );
		$this->assertSame( 500000, ( new UsageMeteringManager() )->getUsage( 0 )['limit'] );
	}

	public function testAnAuthorizationCodeUsesTheTokenEndpointAndIgnoresAnUnknownTier(): void {
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => self::ACCESS ) ),
				$this->jsonResponse( 200, array( 'active' => true, 'tier' => 'gold' ) ),
			)
		);

		$response = ( new CloudCredential() )->connectAuthorizationCode( $this->jsonRequest( array( 'code' => self::CODE ) ) );

		$this->assertTrue( $response->get_data()['connected'] );
		$this->assertSame( 'free', $response->get_data()['tier'] );
		$this->assertSame( 'POST', $GLOBALS['mock_http_log'][0]['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/token', $GLOBALS['mock_http_log'][0]['url'] );
		$payload = json_decode( (string) $GLOBALS['mock_http_log'][0]['args']['body'], true );
		$this->assertSame(
			array(
				'grant_type' => 'authorization_code',
				'code'       => self::CODE,
				'site_url'   => 'https://example.com/',
			),
			$payload
		);
		$this->assertStringNotContainsString( self::CODE, (string) get_option( CloudCredential::API_KEY_OPTION, '' ) );
		$record = get_option( CloudCredential::VERIFICATION_OPTION, array() );
		$this->assertSame( '', $record['tier'] );
		$this->assertSame( 'free', CloudCredential::cachedTier() );
		$this->assertTrue( CloudCredential::isActive() );
	}

	public function testAFailedVerificationStoresNothing(): void {
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => self::ACCESS ) ),
				$this->jsonResponse( 200, array( 'active' => 'true', 'tier' => 'pro' ) ),
			)
		);

		$result = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => self::SITE ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_not_verified', $result->get_error_code() );
		$this->assertSame( 400, $result->data['status'] );
		$this->assertStringNotContainsString( self::SITE, $result->get_error_message() );
		$this->assertStringNotContainsString( self::ACCESS, $result->get_error_message() );
		$this->assertCount( 2, $GLOBALS['mock_http_log'] );
		$this->assertCredentialWasNotSaved();
		$this->assertFalse( CloudCredential::isActive() );
	}

	public function testATransportErrorAndA429AreTriedOnce(): void {
		$this->queue(
			array(
				new \WP_Error( 'http_request_failed', 'timed out' ),
				$this->jsonResponse( 200, array( 'access_token' => self::ACCESS ) ),
				$this->jsonResponse( 200, array( 'active' => true, 'tier' => 'pro' ) ),
			)
		);

		$response = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => self::SITE ) ) );

		$this->assertTrue( $response->get_data()['connected'] );
		$this->assertCount( 3, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/site-token', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/site-token', $GLOBALS['mock_http_log'][1]['url'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/verify', $GLOBALS['mock_http_log'][2]['url'] );

		$this->clearCloud();
		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 429, array( 'message' => 'Slow down' ) ),
				$this->jsonResponse( 200, array( 'access_token' => self::ACCESS ) ),
				$this->jsonResponse( 200, array( 'active' => true, 'tier' => 'free' ) ),
			)
		);
		$retried = ( new CloudCredential() )->connectAuthorizationCode( $this->jsonRequest( array( 'code' => self::CODE ) ) );
		$this->assertTrue( $retried->get_data()['connected'] );
		$this->assertCount( 3, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/token', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/token', $GLOBALS['mock_http_log'][1]['url'] );
	}

	public function testARejectedCredentialIsNotRetriedAndNothingIsSaved(): void {
		$this->queue(
			array(
				$this->jsonResponse( 401, array( 'message' => 'Rejected credential' ) ),
				$this->jsonResponse( 200, array( 'access_token' => self::ACCESS, 'active' => true ) ),
			)
		);

		$result = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => self::SITE ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_connection_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->data['status'] );
		$this->assertSame( 'Rejected credential', $result->get_error_message() );
		$this->assertStringNotContainsString( self::SITE, $result->get_error_message() );
		$this->assertCount( 1, $GLOBALS['mock_http_log'] );
		$this->assertCredentialWasNotSaved();
	}

	public function testTwoServerErrorsLeaveTheSiteStandalone(): void {
		$this->queue(
			array(
				$this->jsonResponse( 503, array() ),
				$this->jsonResponse( 500, array() ),
			)
		);

		$result = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => self::SITE ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_connection_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->data['status'] );
		$this->assertStringContainsString( 'This site keeps working without an account.', $result->get_error_message() );
		$this->assertCount( 2, $GLOBALS['mock_http_log'] );
		$this->assertCredentialWasNotSaved();
		$this->assertFalse( OperatingMode::isCloudConfigured() );
	}

	public function testRotateReplacesTheCredentialOnlyAfterTheNewOneVerifies(): void {
		$this->storeVerified( self::ACCESS, 'pro' );
		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 500, array() ),
				$this->jsonResponse( 500, array() ),
			)
		);

		$failed = ( new CloudCredential() )->rotate( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $failed );
		$this->assertSame( 502, $failed->data['status'] );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertTrue( CloudCredential::isActive() );
		$this->assertSame( 'pro', CloudCredential::cachedTier() );

		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => 'short' ) ),
			)
		);
		$malformed = ( new CloudCredential() )->rotate( new \WP_REST_Request() );
		$this->assertSame( 'holvek_not_verified', $malformed->get_error_code() );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertCount( 1, $GLOBALS['mock_http_log'] );

		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => self::ROTATED ) ),
				$this->jsonResponse( 200, array( 'active' => false ) ),
			)
		);
		$unconfirmed = ( new CloudCredential() )->rotate( new \WP_REST_Request() );
		$this->assertSame( 'holvek_not_verified', $unconfirmed->get_error_code() );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertTrue( CloudCredential::isActive() );

		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => self::ROTATED ) ),
				$this->jsonResponse( 200, array( 'active' => true, 'tier' => 'enterprise' ) ),
			)
		);
		$rotated = ( new CloudCredential() )->rotate( new \WP_REST_Request() );
		$this->assertTrue( $rotated->get_data()['connected'] );
		$this->assertSame( 'enterprise', $rotated->get_data()['tier'] );
		$this->assertSame( 'POST', $GLOBALS['mock_http_log'][0]['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/rotate', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 'Bearer ' . self::ACCESS, $GLOBALS['mock_http_log'][0]['args']['headers']['Authorization'] );
		$payload = json_decode( (string) $GLOBALS['mock_http_log'][0]['args']['body'], true );
		$this->assertSame( array( 'site_url' => 'https://example.com/' ), $payload );
		$this->assertSame( self::ROTATED, OperatingMode::credential() );
		$this->assertSame( 'enterprise', CloudCredential::cachedTier() );
		$this->assertSame( hash( 'sha256', self::ROTATED ), get_option( CloudCredential::VERIFICATION_OPTION, array() )['token_hash'] );
		$this->assertStringNotContainsString( self::ACCESS, (string) get_option( CloudCredential::API_KEY_OPTION, '' ) );
	}

	public function testARejectedRecheckStopsCountingTheCredentialAndKeepsSeoData(): void {
		$this->keepBreadcrumb();
		$this->storeVerified( self::ACCESS, 'pro' );
		$this->clearHttp();
		$this->queue( array( $this->jsonResponse( 401, array( 'message' => self::ACCESS ) ) ) );

		$result = ( new CloudCredential() )->recheck( new \WP_REST_Request() );

		$this->assertSame( 'holvek_not_verified', $result->get_error_code() );
		$this->assertStringNotContainsString( self::ACCESS, $result->get_error_message() );
		$this->assertStringContainsString( 'SEO data was kept.', $result->get_error_message() );
		$this->assertCount( 1, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'GET', $GLOBALS['mock_http_log'][0]['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/verify', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertFalse( get_option( CloudCredential::VERIFICATION_OPTION, false ) );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertFalse( CloudCredential::isActive() );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );

		$this->storeVerified( self::ACCESS, 'pro' );
		$this->clearHttp();
		$this->queue( array( $this->jsonResponse( 200, array( 'active' => false ) ) ) );
		$inactive = ( new CloudCredential() )->recheck( new \WP_REST_Request() );
		$this->assertSame( 'holvek_not_verified', $inactive->get_error_code() );
		$this->assertFalse( get_option( CloudCredential::VERIFICATION_OPTION, false ) );
		$this->assertSame( self::ACCESS, OperatingMode::credential() );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );
	}

	public function testANetworkFailureDuringRecheckLeavesTheSavedCredential(): void {
		$this->storeVerified( self::ACCESS, 'enterprise' );
		$before = get_option( CloudCredential::VERIFICATION_OPTION, array() );
		$this->clearHttp();
		$this->queue(
			array(
				new \WP_Error( 'http_request_failed', 'down' ),
				new \WP_Error( 'http_request_failed', 'down' ),
			)
		);

		$result = ( new CloudCredential() )->recheck( new \WP_REST_Request() );

		$this->assertSame( 'holvek_connection_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->data['status'] );
		$this->assertSame( $before, get_option( CloudCredential::VERIFICATION_OPTION, array() ) );
		$this->assertTrue( CloudCredential::isActive() );
		$this->assertSame( 'enterprise', CloudCredential::cachedTier() );
		$this->assertCount( 2, $GLOBALS['mock_http_log'] );
		$this->assertSame( array(), $this->requestsAfter( 2 ) );
	}

	public function testASuccessfulRecheckRefreshesTheCacheWithoutCallingHolvekAgain(): void {
		$this->storeVerified( self::ACCESS, 'free', time() + 60 );
		$this->clearHttp();
		$this->queue( array( $this->jsonResponse( 200, array( 'active' => true, 'tier' => 'enterprise' ) ) ) );

		$response = ( new CloudCredential() )->recheck( new \WP_REST_Request() );

		$this->assertSame( 'enterprise', $response->get_data()['tier'] );
		$this->assertGreaterThan( time() + 60, (int) get_option( CloudCredential::VERIFICATION_OPTION, array() )['expires_at'] );
		$this->assertCount( 1, $GLOBALS['mock_http_log'] );

		$GLOBALS['mock_http_log'] = array();
		$this->assertTrue( CloudCredential::isActive() );
		$this->assertSame( 'enterprise', CloudCredential::cachedTier() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testRevokeRemovesTheLocalCredentialWhenHolvekIsDown(): void {
		$this->keepBreadcrumb();
		$this->storeVerified( self::ACCESS, 'pro' );
		update_option( CloudCredential::LICENSE_OPTION, 'old-license-value' );
		update_option( CloudCredential::TIER_OPTION, 'enterprise' );
		$this->clearHttp();
		$this->queue(
			array(
				$this->jsonResponse( 503, array() ),
				$this->jsonResponse( 503, array() ),
			)
		);

		$response = ( new CloudCredential() )->revoke( new \WP_REST_Request() );
		$data     = $response->get_data();

		$this->assertTrue( $data['disconnected'] );
		$this->assertFalse( $data['remote_revoked'] );
		$this->assertStringContainsString( 'SEO data was kept.', $data['message'] );
		$this->assertStringContainsString( 'could not be reached', $data['message'] );
		$this->assertStringNotContainsString( self::ACCESS, $data['message'] );
		$this->assertCount( 2, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'POST', $GLOBALS['mock_http_log'][0]['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/auth/revoke', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 'Bearer ' . self::ACCESS, $GLOBALS['mock_http_log'][0]['args']['headers']['Authorization'] );
		$this->assertSame( '', (string) get_option( CloudCredential::API_KEY_OPTION, '' ) );
		$this->assertSame( '', (string) get_option( CloudCredential::LICENSE_OPTION, '' ) );
		$this->assertFalse( get_option( CloudCredential::VERIFICATION_OPTION, false ) );
		$this->assertFalse( get_option( CloudCredential::TIER_OPTION, false ) );
		$this->assertSame( '', (string) get_option( OperatingMode::REVOKED_OPTION, '' ) );
		$this->assertFalse( CloudCredential::isActive() );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );
		$this->assertSame( 'free', CloudCredential::cachedTier() );
	}

	public function testRevokeWithNoCredentialDoesNotCallHolvek(): void {
		$response = ( new CloudCredential() )->revoke( new \WP_REST_Request() );

		$this->assertTrue( $response->get_data()['disconnected'] );
		$this->assertFalse( $response->get_data()['remote_revoked'] );
		$this->assertStringNotContainsString( 'could not be reached', $response->get_data()['message'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testAnExpiredOrMismatchedVerificationIsNotActiveAndDoesNotCallHolvek(): void {
		$this->storeVerified( self::ACCESS, 'enterprise', time() - 5 );
		$this->assertFalse( CloudCredential::isActive() );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );
		$this->assertFalse( OperatingMode::isCloudConfigured() );
		$this->assertSame( 'free', CloudCredential::cachedTier() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		$this->storeVerified( self::ACCESS, 'enterprise' );
		$record               = get_option( CloudCredential::VERIFICATION_OPTION, array() );
		$record['token_hash'] = hash( 'sha256', self::ROTATED );
		update_option( CloudCredential::VERIFICATION_OPTION, $record, false );
		$this->assertFalse( CloudCredential::isActive() );
		$this->assertSame( 'free', CloudCredential::cachedTier() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testStatusReadsTheCacheAndTheFilterChangesOnlyTheBaseUrl(): void {
		update_option( CloudCredential::LICENSE_OPTION, self::ACCESS );
		$status = ( new CloudCredential() )->status( new \WP_REST_Request() );
		$this->assertFalse( $status->get_data()['connected'] );
		$this->assertTrue( $status->get_data()['credential_saved'] );
		$this->assertSame( '', $status->get_data()['tier'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		$this->storeVerified( self::ACCESS, 'pro' );
		$GLOBALS['mock_http_log'] = array();
		$saved = ( new CloudCredential() )->status( new \WP_REST_Request() );
		$this->assertTrue( $saved->get_data()['connected'] );
		$this->assertSame( 'pro', $saved->get_data()['tier'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		add_filter(
			'holvek_backend_api_url',
			static function () {
				return 'https://staging.example/v1';
			}
		);
		$this->clearCloud();
		$this->clearHttp();
		$this->queueExchange( self::ACCESS, 'free' );
		$connected = ( new CloudCredential() )->connectSiteToken( $this->jsonRequest( array( 'site_token' => self::SITE ) ) );
		$this->assertSame( 'https://api.holvek.com', $connected->get_data()['host'] );
		$this->assertSame( 'https://staging.example/v1', $connected->get_data()['api_base'] );
		$this->assertSame( 'https://staging.example/v1/auth/site-token', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 'https://staging.example/v1/auth/verify', $GLOBALS['mock_http_log'][1]['url'] );
	}

	public function testSettingsAndImportCannotWriteTheCredentialTierOrHost(): void {
		update_option( CloudCredential::API_KEY_OPTION, 'keep-api' );
		update_option( CloudCredential::LICENSE_OPTION, 'keep-license' );
		update_option( CloudCredential::TIER_OPTION, 'free' );
		update_option( 'holvek_backend_api_url', 'https://api.holvek.com/v1' );
		update_option( CloudCredential::VERIFICATION_OPTION, array( 'tier' => 'free' ) );
		update_option( OperatingMode::REVOKED_OPTION, '' );

		$module  = new AdminModule( new EventManager(), new AdminMenu() );
		$request = $this->jsonRequest(
			array(
				'holvek_api_key'             => 'stolen-api-key-value',
				'holvek_license_key'         => 'stolen-license-value',
				'holvek_user_tier'           => 'enterprise',
				'holvek_backend_api_url'     => 'https://evil.example/v1',
				'holvek_cloud_verification'  => array(
					'tier'       => 'enterprise',
					'expires_at' => time() + DAY_IN_SECONDS,
					'token_hash' => hash( 'sha256', self::ACCESS ),
				),
				'user_tier'                  => 'enterprise',
				'backend_api_url'            => 'https://evil.example/v1',
			)
		);
		$saved = $module->updateSettings( $request );

		$this->assertSame( true, $saved->get_data()['success'] );
		$this->assertSame( 'keep-api', get_option( CloudCredential::API_KEY_OPTION ) );
		$this->assertSame( 'keep-license', get_option( CloudCredential::LICENSE_OPTION ) );
		$this->assertSame( 'free', get_option( CloudCredential::TIER_OPTION ) );
		$this->assertSame( 'https://api.holvek.com/v1', get_option( 'holvek_backend_api_url' ) );
		$this->assertSame( array( 'tier' => 'free' ), get_option( CloudCredential::VERIFICATION_OPTION ) );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertSame( 'free', CloudCredential::cachedTier() );

		update_option( 'holvek_breadcrumb_home_label', 'Home' );
		$imported = SettingsTransfer::importPayload(
			array(
				'_plugin'  => 'holvek',
				'settings' => array(
					'holvek_breadcrumb_home_label' => 'Start',
					'holvek_api_key'               => 'stolen-api-key-value',
					'holvek_license_key'           => 'stolen-license-value',
					'holvek_user_tier'             => 'enterprise',
					'holvek_backend_api_url'       => 'https://evil.example/v1',
					'holvek_cloud_verification'    => array( 'tier' => 'enterprise' ),
					'holvek_cloud_revoked'         => 'yes',
				),
			)
		);
		$this->assertIsArray( $imported );
		$this->assertSame( 'Start', get_option( 'holvek_breadcrumb_home_label' ) );
		$this->assertSame( 'keep-api', get_option( CloudCredential::API_KEY_OPTION ) );
		$this->assertSame( 'keep-license', get_option( CloudCredential::LICENSE_OPTION ) );
		$this->assertSame( 'free', get_option( CloudCredential::TIER_OPTION ) );
		$this->assertSame( 'https://api.holvek.com/v1', get_option( 'holvek_backend_api_url' ) );
		$this->assertSame( array( 'tier' => 'free' ), get_option( CloudCredential::VERIFICATION_OPTION ) );
		$this->assertSame( '', (string) get_option( OperatingMode::REVOKED_OPTION, '' ) );
		foreach ( array( 'holvek_api_key', 'holvek_license_key', 'holvek_user_tier', 'holvek_backend_api_url', 'holvek_cloud_verification', 'holvek_cloud_revoked' ) as $secret ) {
			$this->assertContains( $secret, SettingsTransfer::secretOptions() );
		}
	}

	public function testADirectTierOptionDoesNotUnlockFeatures(): void {
		update_option( CloudCredential::TIER_OPTION, 'enterprise' );
		$this->assertSame( 'free', CloudCredential::cachedTier() );
		$this->assertSame( 50000, ( new UsageMeteringManager() )->getUsage( 0 )['limit'] );

		$listed = ( new UpgradePromptManager() )->listFeatures( new \WP_REST_Request() )->get_data();
		$this->assertSame( 'free', $listed['current_tier'] );
		$keys = array();
		foreach ( $listed['features'] as $feature ) {
			$keys[] = $feature['key'];
			$this->assertTrue( $feature['available'] );
			$this->assertSame( '', $feature['upgrade_url'] );
		}
		$this->assertNotContains( 'ai_visibility_tracker', $keys );
		$this->assertNotContains( 'agency_workspace', $keys );
		$this->assertNotContains( 'client_reports', $keys );

		$this->storeVerified( self::ACCESS, 'pro' );
		update_option( CloudCredential::TIER_OPTION, 'enterprise' );
		$this->assertSame( 'pro', CloudCredential::cachedTier() );
		$this->assertSame( 500000, ( new UsageMeteringManager() )->getUsage( 0 )['limit'] );
		$this->assertSame( 'pro', ( new UpgradePromptManager() )->listFeatures( new \WP_REST_Request() )->get_data()['current_tier'] );
	}

	public function testRegisteringRoutesDoesNotCallHolvek(): void {
		$client = new CloudCredential();
		$client->registerRoutes();

		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		foreach ( array( '/connection', '/connection/site-token', '/connection/authorization-code', '/connection/verify', '/connection/rotate', '/connection/revoke' ) as $route ) {
			$this->assertArrayHasKey( 'holvek/v1' . $route, $GLOBALS['mock_rest_routes'] );
		}
		$this->actAsEditor();
		$permission = $GLOBALS['mock_rest_routes']['holvek/v1/connection']['permission_callback'];
		$this->assertFalse( $permission() );

		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$html   = $this->between( $plugin, 'function registerRestControllers', 'function registerAdminScopedRestControllers' );
		$admin  = $this->between( $plugin, 'function registerAdminScopedRestControllers', 'function isFrontendHtmlRequest' );
		$this->assertStringContainsString( 'CloudCredential() )->registerRoutes();', $html );
		$this->assertStringContainsString( 'CloudCredential() )->registerRoutes();', $admin );
	}

	public function testTheConnectionPanelHasNoHostFieldAndLoadsWhenOpened(): void {
		$app = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/assets/js/App.js' );

		$this->assertStringContainsString( 'if (!showHolvekNote || !canConnectHolvek)', $app );
		$this->assertStringContainsString( 'manage_options === true', $app );
		$this->assertStringContainsString( 'Connecting Holvek is optional.', $app );
		$this->assertStringContainsString( 'The settings screen cannot change the Holvek API host.', $app );
		$this->assertStringContainsString( 'Connect with site token', $app );
		$this->assertStringContainsString( 'Connect with authorization code', $app );
		$this->assertStringContainsString( 'Check saved credential', $app );
		$this->assertStringContainsString( 'Rotate credential', $app );
		$this->assertStringContainsString( '{__(\'Disconnect\', \'holvek\')}', $app );
		$this->assertStringContainsString( 'type="password"', $app );
		$this->assertStringContainsString( 'role="alert"', $app );
		$this->assertStringNotContainsString( 'name="host"', $app );
		$this->assertStringNotContainsString( 'holvek-api-host', $app );

		$guard = strpos( $app, 'if (!showHolvekNote || !canConnectHolvek)' );
		$fetch = strpos( $app, 'fetch(`${holvekAdminConfig.apiUrl}/connection`' );
		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $fetch );
		$this->assertLessThan( $fetch, $guard );
	}

	public function testRotateAndRecheckWithoutACredentialDoNotCallHolvek(): void {
		$client = new CloudCredential();
		$rotate = $client->rotate( new \WP_REST_Request() );
		$check  = $client->recheck( new \WP_REST_Request() );

		$this->assertSame( 'holvek_not_connected', $rotate->get_error_code() );
		$this->assertSame( 'holvek_not_connected', $check->get_error_code() );
		$this->assertSame( 400, $rotate->data['status'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	/**
	 * @param array<int, array<string, mixed>|\WP_Error> $responses
	 */
	private function queue( array $responses ): void {
		$GLOBALS['mock_http_queue'] = $responses;
	}

	private function queueExchange( string $access, string $tier ): void {
		$this->queue(
			array(
				$this->jsonResponse( 200, array( 'access_token' => $access ) ),
				$this->jsonResponse( 200, array( 'active' => true, 'tier' => $tier ) ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function jsonResponse( int $code, array $body ): array {
		return array(
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code ),
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function jsonRequest( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_json_params( $params );
		return $request;
	}

	private function storeVerified( string $token, string $tier, int $expires = 0 ): void {
		$encrypted = KeyVault::encrypt( $token );
		$this->assertIsString( $encrypted );
		update_option( CloudCredential::API_KEY_OPTION, $encrypted, false );
		delete_option( CloudCredential::LICENSE_OPTION );
		delete_option( OperatingMode::REVOKED_OPTION );
		update_option(
			CloudCredential::VERIFICATION_OPTION,
			array(
				'token_hash' => hash( 'sha256', $token ),
				'expires_at' => $expires > 0 ? $expires : time() + DAY_IN_SECONDS,
				'tier'       => $tier,
			),
			false
		);
	}

	private function assertCredentialWasNotSaved(): void {
		$this->assertSame( '', (string) get_option( CloudCredential::API_KEY_OPTION, '' ) );
		$this->assertSame( '', (string) get_option( CloudCredential::LICENSE_OPTION, '' ) );
		$this->assertFalse( get_option( CloudCredential::VERIFICATION_OPTION, false ) );
	}

	private function keepBreadcrumb(): void {
		global $mock_wp_options;
		$this->touchBreadcrumb = true;
		if ( is_array( $mock_wp_options ) && array_key_exists( 'holvek_breadcrumb_home_label', $mock_wp_options ) ) {
			$this->breadcrumbWas = $mock_wp_options['holvek_breadcrumb_home_label'];
		} else {
			$this->breadcrumbWas = null;
		}
		update_option( 'holvek_breadcrumb_home_label', 'Home' );
	}

	private function clearCloud(): void {
		delete_option( CloudCredential::API_KEY_OPTION );
		delete_option( CloudCredential::LICENSE_OPTION );
		delete_option( CloudCredential::VERIFICATION_OPTION );
		delete_option( CloudCredential::TIER_OPTION );
		delete_option( 'holvek_backend_api_url' );
		delete_option( OperatingMode::REVOKED_OPTION );
	}

	private function clearHttp(): void {
		$GLOBALS['mock_http_log'] = array();
		unset( $GLOBALS['mock_http_queue'], $GLOBALS['mock_http_response'], $GLOBALS['mock_http_responses'] );
	}

	/**
	 * @return array<int, mixed>
	 */
	private function requestsAfter( int $count ): array {
		return array_slice( $GLOBALS['mock_http_log'], $count );
	}

	private function actAsAdmin(): void {
		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => true,
			'manage_seo'     => true,
			'edit_seo_meta'  => true,
		);
	}

	private function actAsEditor(): void {
		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => false,
			'manage_seo'     => true,
			'edit_seo_meta'  => true,
		);
	}

	private function between( string $source, string $start, string $end ): string {
		$from = strpos( $source, $start );
		$to   = strpos( $source, $end );
		$this->assertNotFalse( $from );
		$this->assertNotFalse( $to );
		$this->assertLessThan( $to, $from );

		return substr( $source, $from, $to - $from );
	}
}
