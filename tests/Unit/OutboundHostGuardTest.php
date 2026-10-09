<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Ai\AiGateway;
use Holvek\WordPress\Core\Ai\OllamaEndpoint;
use Holvek\WordPress\Core\Api\BackendApiClient;
use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Indexing\BingIndexNowApi;
use Holvek\WordPress\Modules\Schema\CompetitorScraper;
use Holvek\WordPress\Modules\Schema\SchemaGenerator;
use Holvek\WordPress\Modules\Schema\SchemaModule;
use PHPUnit\Framework\TestCase;

class OutboundHostGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		$GLOBALS['mock_http_log']       = array();
		$GLOBALS['mock_http_responses'] = array();
		unset( $GLOBALS['mock_http_response'] );
		$GLOBALS['mock_user_caps']      = array(
			'manage_options' => true,
			'manage_seo'     => false,
			'edit_posts'     => true,
		);
		$GLOBALS['mock_current_user_id'] = 4;
		delete_transient( 'holvek_schema_scrape_4' );
		delete_option( 'holvek_backend_api_url' );
		delete_option( 'holvek_api_key' );
		delete_option( 'holvek_indexnow_endpoint' );
		delete_option( 'holvek_indexnow_key' );
		delete_option( 'holvek_auto_index' );
		delete_option( OllamaEndpoint::URL_OPTION );
		delete_option( OllamaEndpoint::ACK_OPTION );
		update_option( 'holvek_ai_provider', 'ollama' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		parent::tearDown();
	}

	public function testApiHostIgnoresTheSettingsOptionAndHonorsADeveloperFilter(): void {
		update_option( 'holvek_backend_api_url', 'https://evil.example/v1' );
		$client = new BackendApiClient();

		$this->assertSame( 'https://api.holvek.com/v1', $client->getApiUrl() );

		add_filter(
			'holvek_backend_api_url',
			static function (): string {
				return 'https://staging.example.test/v1/';
			}
		);
		$this->assertSame( 'https://staging.example.test/v1', $client->getApiUrl() );

		unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		add_filter(
			'holvek_backend_api_url',
			static function (): string {
				return 'http://staging.example.test/v1';
			}
		);
		$this->assertSame( 'https://api.holvek.com/v1', $client->getApiUrl() );

		unset( $GLOBALS['mock_filter_callbacks']['holvek_backend_api_url'] );
		add_filter(
			'holvek_backend_api_url',
			static function (): string {
				return 'https://user:secret@api.holvek.com/v1';
			}
		);
		$this->assertSame( 'https://api.holvek.com/v1', $client->getApiUrl() );
	}

	public function testIndexNowEndpointIsAnAllowList(): void {
		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->pingEndpoint( 'https://evil.example/indexnow' ) );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->pingEndpoint( 'https://api.indexnow.org.evil.example/indexnow' ) );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->pingEndpoint( 'http://api.indexnow.org/indexnow' ) );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->pingEndpoint( 'https://user:secret@api.indexnow.org/indexnow' ) );
		$this->assertSame( 'https://www.bing.com/indexnow', $this->pingEndpoint( 'https://www.bing.com/indexnow/' ) );
		$this->assertSame( 'https://yandex.com/indexnow', $this->pingEndpoint( 'https://Yandex.com/indexnow' ) );
		$this->assertSame( 'https://search.seznam.cz/indexnow', $this->pingEndpoint( 'https://search.seznam.cz/indexnow' ) );
	}

	public function testCompetitorImportRequiresManageOptionsAndIsRateLimited(): void {
		$module = new SchemaModule( new EventManager(), new SchemaGenerator() );
		$GLOBALS['mock_rest_routes'] = array();
		$module->registerRoutes();
		$this->assertSame(
			array( $module, 'checkScrapePermission' ),
			$GLOBALS['mock_rest_routes']['holvek/v1/schema/scrape']['permission_callback']
		);

		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => false,
			'manage_seo'     => true,
			'edit_posts'     => true,
		);
		$denied = $module->handleScrapeCompetitor( $this->scrapeRequest( 'http://127.0.0.1/' ) );
		$this->assertSame( 'forbidden', $denied->get_error_code() );
		$this->assertSame( 403, $denied->data['status'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
		$this->assertFalse( get_transient( 'holvek_schema_scrape_4' ) );

		$GLOBALS['mock_user_caps']['manage_options'] = true;
		$request = $this->scrapeRequest( 'http://127.0.0.1/' );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$result = $module->handleScrapeCompetitor( $request );
			$this->assertSame( 'scrape_failed', $result->get_error_code() );
		}

		$limited = $module->handleScrapeCompetitor( $request );
		$this->assertSame( 'rate_limited', $limited->get_error_code() );
		$this->assertSame( 429, $limited->data['status'] );
	}

	public function testCompetitorFetchRejectsPrivateHostsAndRedirects(): void {
		$scraper = $this->scraper(
			array(
				'example.com' => array( '8.8.8.8' ),
				'cgnat.test'  => array( '100.64.0.1' ),
				'mixed.test'  => array( '8.8.8.8', '10.0.0.1' ),
			)
		);

		foreach (
			array(
				'http://127.0.0.1/',
				'http://10.1.2.3/latest',
				'http://169.254.169.254/latest/meta-data',
				'http://[::1]/',
				'http://[::ffff:127.0.0.1]/',
				'http://localhost/secret',
				'https://user:pass@example.com/',
				'http://2130706433/',
				'http://cgnat.test/',
				'https://mixed.test/page',
			) as $url
		) {
			$GLOBALS['mock_http_log'] = array();
			$result                   = $scraper->fetch( $url );
			$this->assertFalse( $result['success'], $url );
			$this->assertSame( array(), $GLOBALS['mock_http_log'], $url );
		}

		$GLOBALS['mock_http_responses']['https://example.com/start'] = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'http://127.0.0.1/secret' ),
			'body'     => '',
		);
		$redirected = $scraper->fetch( 'https://example.com/start' );
		$this->assertFalse( $redirected['success'] );
		$this->assertCount( 1, $GLOBALS['mock_http_log'] );
		$this->assertSame( 'https://example.com/start', $GLOBALS['mock_http_log'][0]['url'] );
		$this->assertSame( 0, $GLOBALS['mock_http_log'][0]['args']['redirection'] );
		$this->assertStringContainsString( 'Holvek-WP/', $GLOBALS['mock_http_log'][0]['args']['user-agent'] );
		$this->assertStringNotContainsString( 'Mozilla', $GLOBALS['mock_http_log'][0]['args']['user-agent'] );

		$GLOBALS['mock_http_log'] = array();
		$GLOBALS['mock_http_responses']['https://example.com/product'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html><script type="application/ld+json">{"@type":"Product","name":"Widget","offers":{"price":"5","priceCurrency":"USD"}}</script></html>',
		);
		$fetched = $scraper->fetch( 'https://example.com/product' );
		$this->assertTrue( $fetched['success'] );
		$this->assertSame( 'Widget', $fetched['schemas']['product']['name'] );
	}

	public function testOllamaDefaultsToLoopbackAndConfirmsRemoteHosts(): void {
		$saved = OllamaEndpoint::save( 'HTTP://127.0.0.1:11434/', false );
		$this->assertTrue( $saved );
		$this->assertSame( 'http://127.0.0.1:11434', get_option( OllamaEndpoint::URL_OPTION ) );
		$this->assertSame( 'no', get_option( OllamaEndpoint::ACK_OPTION ) );

		$remote = OllamaEndpoint::save( 'https://ollama.example:11434', false );
		$this->assertSame( 'ollama_remote_unconfirmed', $remote->get_error_code() );
		$this->assertSame( 'http://127.0.0.1:11434', get_option( OllamaEndpoint::URL_OPTION ) );

		$confirmed = OllamaEndpoint::save( 'https://ollama.example:11434/', true );
		$this->assertTrue( $confirmed );
		$this->assertSame( 'https://ollama.example:11434', get_option( OllamaEndpoint::ACK_OPTION ) );

		update_option( OllamaEndpoint::URL_OPTION, 'https://other.example:11434' );
		$GLOBALS['mock_http_log'] = array();
		$blocked = ( new AiGateway() )->queryModel( 'Write a title', 'System' );
		$this->assertFalse( $blocked['success'] );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		update_option( OllamaEndpoint::URL_OPTION, 'https://ollama.example:11434' );
		( new AiGateway() )->queryModel( 'Write a title', 'System' );
		$this->assertSame( 'https://ollama.example:11434/api/chat', $GLOBALS['mock_http_log'][0]['url'] );

		$metadata = OllamaEndpoint::save( 'http://metadata.google.internal', true );
		$this->assertSame( 'invalid_ollama_url', $metadata->get_error_code() );
		$linkLocal = OllamaEndpoint::save( 'http://169.254.169.254', true );
		$this->assertSame( 'invalid_ollama_url', $linkLocal->get_error_code() );

		update_option( OllamaEndpoint::URL_OPTION, 'http://localhost:11434' );
		$this->assertFalse( OllamaEndpoint::import( 'https://evil.example:11434' ) );
		$this->assertSame( 'http://localhost:11434', get_option( OllamaEndpoint::URL_OPTION ) );
		$this->assertTrue( OllamaEndpoint::import( 'http://localhost:11434/' ) );
		$this->assertSame( 'no', get_option( OllamaEndpoint::ACK_OPTION ) );
	}

	public function testSettingsImportCannotPointOllamaAtARemoteHost(): void {
		update_option( OllamaEndpoint::URL_OPTION, 'http://localhost:11434' );
		$admin   = new AdminModule( new EventManager(), new AdminMenu() );
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'_plugin'  => 'holvek',
				'settings' => array(
					'holvek_ollama_url'         => 'https://evil.example:11434',
					'holvek_breadcrumb_home_label' => 'Home',
				),
			)
		);

		$result = $admin->importSettings( $request );
		$this->assertSame( 1, $result->get_data()['imported'] );
		$this->assertSame( 'http://localhost:11434', get_option( OllamaEndpoint::URL_OPTION ) );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );

		$save = new \WP_REST_Request();
		$save->set_json_params( array( 'ollama_url' => 'https://ollama.example' ) );
		$rejected = $admin->updateSettings( $save );
		$this->assertSame( 'ollama_remote_unconfirmed', $rejected->get_error_code() );
		$this->assertSame( 'http://localhost:11434', get_option( OllamaEndpoint::URL_OPTION ) );
	}

	private function pingEndpoint( string $configured ): string {
		update_option( 'holvek_indexnow_key', 'indexnow-test-key' );
		update_option( 'holvek_auto_index', 'yes' );
		update_option( 'holvek_indexnow_endpoint', $configured );
		$GLOBALS['mock_http_log'] = array();

		( new BingIndexNowApi() )->ping( 'https://example.com/post/' );

		$this->assertNotEmpty( $GLOBALS['mock_http_log'] );
		return (string) $GLOBALS['mock_http_log'][0]['url'];
	}

	private function scrapeRequest( string $url ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'url' => $url ) );
		return $request;
	}

	/**
	 * @param array<string, array<int, string>> $dns
	 */
	private function scraper( array $dns ): CompetitorScraper {
		return new class( $dns ) extends CompetitorScraper {
			/**
			 * @param array<string, array<int, string>> $dns
			 */
			public function __construct( private array $dns ) {}

			protected function resolveHost( string $host ): array {
				$host = strtolower( trim( $host, '[]' ) );
				if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
					return array( $host );
				}
				return $this->dns[ $host ] ?? array();
			}
		};
	}
}
