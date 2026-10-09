<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Ai\AiGateway;
use Holvek\WordPress\Core\Api\BackendApiClient;
use Holvek\WordPress\Core\Api\CloudCredential;
use Holvek\WordPress\Core\Api\HolvekApiGateway;
use Holvek\WordPress\Core\Mode\OperatingMode;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Ai\UsageMeteringManager;
use Holvek\WordPress\Modules\Analytics\KeywordRankTracker;
use Holvek\WordPress\Modules\Analytics\PageSpeedDashboard;
use Holvek\WordPress\Modules\ContentAssistant\ContentGapAnalyser;
use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OperatingModeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->clearCloudState();
		$GLOBALS['mock_http_log']       = array();
		$GLOBALS['mock_http_responses'] = array();
		unset( $GLOBALS['mock_http_response'], $GLOBALS['mock_get_posts_args'] );
	}

	protected function tearDown(): void {
		$this->clearCloudState();
		$GLOBALS['mock_is_admin']    = false;
		$GLOBALS['mock_cron_events'] = array();
		unset( $GLOBALS['mock_is_user_logged_in'] );
		$_SERVER['REQUEST_URI'] = '/';
		$this->resetPlugin();
		parent::tearDown();
	}

	public function testAStoredCredentialIsConfiguredUntilItIsRevoked(): void {
		$this->assertFalse( OperatingMode::isCloudConfigured() );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );

		update_option( 'holvek_license_key', 'lic-plain-value' );
		$this->assertFalse( OperatingMode::isCloudConfigured() );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		$this->verifyCredential( 'lic-plain-value' );
		$this->assertTrue( OperatingMode::isCloudConfigured() );
		$this->assertTrue( HolvekApiGateway::hasActiveLicense() );
		$this->assertSame( 'lic-plain-value', OperatingMode::credential() );

		$encrypted = KeyVault::encrypt( 'api-secret' );
		$this->assertIsString( $encrypted );
		update_option( 'holvek_api_key', $encrypted );
		$this->verifyCredential( 'api-secret' );
		$this->assertSame( 'api-secret', OperatingMode::credential() );
		$this->assertTrue( OperatingMode::isCloudConfigured() );

		update_option( OperatingMode::REVOKED_OPTION, 'yes' );
		$this->assertFalse( OperatingMode::isCloudConfigured() );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testReachabilityIsAnExplicitRequest(): void {
		$GLOBALS['mock_http_response'] = array(
			'body'     => '{}',
			'response' => array( 'code' => 200 ),
		);

		$this->assertTrue( OperatingMode::isCloudReachable() );
		$this->assertSame( 'GET', $GLOBALS['mock_http_log'][0]['method'] );
		$this->assertSame( 'https://api.holvek.com/v1/health', $GLOBALS['mock_http_log'][0]['url'] );

		$GLOBALS['mock_http_response'] = array(
			'body'     => '',
			'response' => array( 'code' => 503 ),
		);
		$GLOBALS['mock_http_log'] = array();
		$this->assertFalse( OperatingMode::isCloudReachable() );
	}

	public function testProductionCodeDoesNotCallReachability(): void {
		$src  = dirname( __DIR__, 2 ) . '/src';
		$hits = array();
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}
			if ( $file->getBasename() === 'OperatingMode.php' ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			if ( is_string( $contents ) && str_contains( $contents, 'isCloudReachable(' ) ) {
				$hits[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame( array(), $hits );
	}

	public function testAnonymousFrontEndWithEmptyCloudOptionsMakesNoRemoteCall(): void {
		$this->clearCloudState();
		delete_option( 'holvek_anthropic_key' );
		delete_option( 'holvek_google_indexing_key' );
		delete_option( 'holvek_bing_api_key' );
		delete_option( 'holvek_gsc_token' );
		delete_option( 'holvek_indexnow_key' );
		update_option( 'holvek_db_version', HOLVEK_VERSION );

		$this->bootAt( '/hello', false, false );

		$this->assertContains( 'wp_head', $this->hookNames() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testANormalPageViewDoesNotCallHolvek(): void {
		update_option( 'holvek_db_version', HOLVEK_VERSION );
		update_option( 'holvek_license_key', 'lic-plain-value' );
		$this->verifyCredential( 'lic-plain-value' );
		$GLOBALS['mock_http_log'] = array();

		$this->bootAt( '/hello', false, false );

		$this->assertContains( 'wp_head', $this->hookNames() );
		$this->assertContains( 'robots_txt', $this->hookNames() );
		$this->assertContains( 'template_redirect', $this->hookNames() );
		$this->assertSame( array(), $this->holvekRequests() );
	}

	public function testCloudCallsFailClosedWhenTheSiteIsNotConnected(): void {
		$client = new BackendApiClient();

		$generated = $client->generateAi( array( 'prompt' => 'test' ) );
		$this->assertSame( 'holvek_not_connected', $generated->get_error_code() );

		$gap = $client->analyzeContentGap( 'widgets', array( 'existing' ) );
		$this->assertSame( 'holvek_not_connected', $gap->get_error_code() );

		$ranks = $client->trackRanks( array( 'widgets' ) );
		$this->assertSame( 'holvek_not_connected', $ranks->get_error_code() );
		$this->assertStringContainsString( 'not connected to Holvek', $ranks->get_error_message() );

		$posted = $client->post( '/ai/generate', array() );
		$this->assertSame( 'holvek_not_connected', $posted->get_error_code() );

		$proxy = HolvekApiGateway::proxyRequest( 'pagespeed', array( 'url' => 'https://example.com/' ) );
		$this->assertSame( 'holvek_not_connected', $proxy->get_error_code() );

		$usage = UsageMeteringManager::getInstance()->reportToCloud();
		$this->assertSame( 'holvek_not_connected', $usage->get_error_code() );

		$request = new \WP_REST_Request();
		$request->set_param( 'url', 'https://example.com/' );
		$request->set_param( 'strategy', 'mobile' );
		$pageSpeed = ( new PageSpeedDashboard() )->getPageSpeed( $request );
		$this->assertSame( 'holvek_not_connected', $pageSpeed->get_error_code() );

		$bulk = new \WP_REST_Request();
		$bulk->set_json_params( array( 'urls' => array( 'https://example.com/' ) ) );
		$bulkResult = ( new PageSpeedDashboard() )->bulkCheck( $bulk );
		$this->assertSame( 'holvek_not_connected', $bulkResult->get_error_code() );

		update_option( 'holvek_tracked_keywords', array( 'widgets' ) );
		delete_option( 'holvek_serpapi_key' );
		$rankRequest = new \WP_REST_Request();
		$rankRequest->set_json_params( array() );
		$rank = ( new KeywordRankTracker() )->checkNow( $rankRequest );
		$this->assertSame( 'holvek_not_connected', $rank->get_error_code() );

		$gapRequest = new \WP_REST_Request();
		$gapRequest->set_json_params( array( 'keyword' => 'widgets' ) );
		$contentGap = ( new ContentGapAnalyser() )->analyseGap( $gapRequest );
		$this->assertSame( 'holvek_not_connected', $contentGap->get_error_code() );
		$this->assertArrayNotHasKey( 'mock_get_posts_args', $GLOBALS );

		update_option( 'holvek_ai_provider', 'not-a-provider' );
		$model = ( new AiGateway() )->queryModel( 'Write a title' );
		$this->assertFalse( $model['success'] );

		$this->assertSame( array(), $this->holvekRequests() );
	}

	public function testARevokedCredentialDoesNotProxyAndALocalKeyStillCan(): void {
		update_option( 'holvek_license_key', 'lic-plain-value' );
		$this->verifyCredential( 'lic-plain-value' );
		update_option( OperatingMode::REVOKED_OPTION, 'yes' );

		$proxy = HolvekApiGateway::proxyRequest( 'pagespeed', array() );
		$this->assertSame( 'holvek_not_connected', $proxy->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		delete_option( OperatingMode::REVOKED_OPTION );
		$GLOBALS['mock_http_response'] = array(
			'body'     => '{"ok":true}',
			'response' => array( 'code' => 200 ),
		);
		$proxied = HolvekApiGateway::proxyRequest( 'pagespeed', array( 'url' => 'https://example.com/' ) );
		$this->assertIsArray( $proxied );
		$this->assertSame( 'Bearer lic-plain-value', $GLOBALS['mock_http_log'][0]['args']['headers']['Authorization'] );

		update_option( 'holvek_serpapi_key', 'serp-key' );
		update_option( 'holvek_tracked_keywords', array( 'widgets' ) );
		$GLOBALS['mock_http_log'] = array();
		$queued = ( new KeywordRankTracker() )->checkNow( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $queued );
		$this->assertTrue( $queued->get_data()['queued'] );
		$this->assertSame( array(), $this->holvekRequests() );
	}

	private function clearCloudState(): void {
		delete_option( 'holvek_api_key' );
		delete_option( 'holvek_license_key' );
		delete_option( CloudCredential::VERIFICATION_OPTION );
		delete_option( 'holvek_serpapi_key' );
		delete_option( 'holvek_pagespeed_api_key' );
		delete_option( 'holvek_openai_key' );
		delete_option( 'holvek_openai_api_key' );
		delete_option( OperatingMode::REVOKED_OPTION );
	}

	/**
	 * @return array<int, string>
	 */
	private function verifyCredential( string $token ): void {
		update_option(
			CloudCredential::VERIFICATION_OPTION,
			array(
				'token_hash' => hash( 'sha256', $token ),
				'expires_at' => time() + HOUR_IN_SECONDS,
				'tier'       => 'free',
			),
			false
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function holvekRequests(): array {
		$urls = array();
		foreach ( $GLOBALS['mock_http_log'] as $entry ) {
			$url = (string) ( $entry['url'] ?? '' );
			if ( str_contains( $url, 'api.holvek.com' ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	private function bootAt( string $path, bool $admin, bool $loggedIn ): void {
		$this->resetPlugin();
		$_SERVER['REQUEST_URI']            = $path;
		$GLOBALS['mock_is_admin']          = $admin;
		$GLOBALS['mock_is_user_logged_in'] = $loggedIn;
		$GLOBALS['mock_hooks']             = array();
		$GLOBALS['mock_http_log']          = array();
		Plugin::getInstance()->boot();
	}

	private function resetPlugin(): void {
		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * @return array<int, string>
	 */
	private function hookNames(): array {
		$names = array();
		foreach ( $GLOBALS['mock_hooks'] as $hook ) {
			$names[] = $hook[1];
		}
		return $names;
	}
}
