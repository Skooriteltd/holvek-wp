<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Admin\SeoAuditHistoryLog;
use Holvek\WordPress\Modules\Ai\UsageMeteringManager;
use Holvek\WordPress\Modules\Analytics\BingWebmasterIntegration;
use Holvek\WordPress\Modules\Analytics\KeywordRankTracker;
use Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools;
use Holvek\WordPress\Modules\Compliance\CookieBannerModule;
use Holvek\WordPress\Modules\ContentAssistant\AuditScheduler;
use Holvek\WordPress\Modules\ContentAssistant\StaleCornerStoneDetector;
use Holvek\WordPress\Modules\ImageSeo\ImageFilenameEnforcer;
use Holvek\WordPress\Modules\Sitemap\HtmlSitemapShortcode;
use Holvek\WordPress\Modules\Social\SocialAccountManager;
use Holvek\WordPress\Modules\Social\SocialModule;
use Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor;
use Holvek\WordPress\Modules\TechnicalSeo\RedirectManager;
use Holvek\WordPress\Modules\TechnicalSeo\RobotsTxtEditor;
use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WP_REST_Request;

class AssetQueryTest extends TestCase {

	private \MockWpdb $db;

	private $previousWpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previousWpdb = $GLOBALS['wpdb'] ?? null;
		$this->db           = new \MockWpdb();
		$GLOBALS['wpdb']    = $this->db;
		$GLOBALS['mock_recurring_events'] = array();
		$GLOBALS['mock_shortcodes']       = array();
		$GLOBALS['mock_option_autoload']  = array();
		$GLOBALS['mock_mail']             = array();
		$GLOBALS['mock_transients']       = array();
		$GLOBALS['mock_object_cache']     = array();
		unset(
			$GLOBALS['mock_wpdb_var'],
			$GLOBALS['mock_wpdb_row'],
			$GLOBALS['mock_wpdb_results'],
			$GLOBALS['mock_wpdb_col'],
			$GLOBALS['mock_get_posts'],
			$GLOBALS['mock_get_posts_args'],
			$GLOBALS['mock_wp_options']['holvek_redirect_counts'],
			$GLOBALS['mock_wp_options']['holvek_redirects'],
			$GLOBALS['mock_wp_options']['holvek_404_logs'],
			$GLOBALS['mock_wp_options']['holvek_404_table_exists']
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mock_recurring_events'] = array();
		$GLOBALS['mock_option_autoload']  = array();
		unset(
			$GLOBALS['mock_wpdb_var'],
			$GLOBALS['mock_wpdb_row'],
			$GLOBALS['mock_wpdb_results'],
			$GLOBALS['mock_wpdb_col'],
			$GLOBALS['mock_get_posts'],
			$GLOBALS['mock_get_posts_args']
		);
		if ( $this->previousWpdb !== null ) {
			$GLOBALS['wpdb'] = $this->previousWpdb;
		}
		$this->resetPlugin();
		parent::tearDown();
	}

	public function testSitemapStylesPrintWithTheShortcode(): void {
		$before = array_column( $GLOBALS['mock_hooks'] ?? array(), 1 );
		$sitemap = new HtmlSitemapShortcode();
		$sitemap->register();

		$this->assertSame( $before, array_column( $GLOBALS['mock_hooks'] ?? array(), 1 ) );
		$this->assertSame( array( 'holvek_sitemap' ), $GLOBALS['mock_shortcodes'] );

		$first = $sitemap->render();
		$this->assertStringContainsString( 'id="holvek-sitemap-css"', $first );
		$this->assertStringContainsString( '<nav class="holvek-sitemap"', $first );

		$second = $sitemap->render();
		$this->assertStringNotContainsString( 'id="holvek-sitemap-css"', $second );
		$this->assertStringContainsString( '<nav class="holvek-sitemap"', $second );
	}

	public function testBootDoesNotScheduleAndWorkersRestoreAMissingEvent(): void {
		update_option( 'holvek_db_version', HOLVEK_VERSION );
		$this->bootPlugin( false, false );
		$this->assertSame( array(), $GLOBALS['mock_recurring_events'] );

		update_option( 'holvek_caps_version', HOLVEK_VERSION );
		$this->bootPlugin( true, true );
		$this->assertSame( array(), $GLOBALS['mock_recurring_events'] );

		( new ErrorMonitor() )->boot();
		( new KeywordRankTracker() )->register();
		( new CcpaPrivacyTools() )->boot();
		( new UsageMeteringManager() )->boot();
		( new StaleCornerStoneDetector() )->register();
		( new SeoAuditHistoryLog() )->register();
		( new AuditScheduler() )->register();
		$this->assertSame( array(), $GLOBALS['mock_recurring_events'] );

		$method = ( new ReflectionClass( Plugin::class ) )->getMethod( 'scheduleBackgroundEvents' );
		$method->setAccessible( true );
		$method->invoke( null );
		$method->invoke( null );

		$this->assertSame(
			array(
				'holvek_flush_404_buffer'            => 'hourly',
				'holvek_404_cleanup'                 => 'daily',
				'holvek_rank_check'                  => 'daily',
				'holvek_apply_retention'            => 'daily',
				'holvek_weekly_usage_email'         => 'weekly',
				'holvek_cornerstone_staleness_check' => 'weekly',
				'holvek_prune_audit_log'            => 'daily',
				'holvek_scheduled_audit'            => 'weekly',
			),
			$this->recurrences()
		);

		$this->dropHook( 'holvek_rank_check' );
		( new KeywordRankTracker() )->checkAllKeywords();
		$this->assertCount( 1, $this->eventsFor( 'holvek_rank_check' ) );

		$this->dropHook( 'holvek_flush_404_buffer' );
		( new ErrorMonitor() )->flushBufferToDatabase();
		$this->assertCount( 1, $this->eventsFor( 'holvek_flush_404_buffer' ) );

		$this->dropHook( 'holvek_404_cleanup' );
		$queriesBefore = count( $this->db->queries );
		( new ErrorMonitor() )->purgeExpiredLogs();
		$this->assertCount( 1, $this->eventsFor( 'holvek_404_cleanup' ) );
		$this->assertSame( array(), array_slice( $this->db->queries, $queriesBefore ) );

		update_option( 'holvek_404_table_exists', 'yes', false );
		$this->db->queries = array();
		( new ErrorMonitor() )->purgeExpiredLogs();
		$purge = $this->sqlContaining( 'holvek_404_logs' );
		$this->assertStringContainsString( 'INTERVAL 90 DAY', $purge );

		$this->dropHook( 'holvek_prune_audit_log' );
		( new SeoAuditHistoryLog() )->pruneOldEntries();
		$this->assertCount( 1, $this->eventsFor( 'holvek_prune_audit_log' ) );

		$this->dropHook( 'holvek_apply_retention' );
		( new CcpaPrivacyTools() )->applyRetentionPolicy();
		$this->assertCount( 1, $this->eventsFor( 'holvek_apply_retention' ) );

		$this->dropHook( 'holvek_weekly_usage_email' );
		( new UsageMeteringManager() )->sendWeeklySummary();
		$this->assertCount( 1, $this->eventsFor( 'holvek_weekly_usage_email' ) );
		$this->assertNotEmpty( $GLOBALS['mock_mail'] );

		$this->dropHook( 'holvek_cornerstone_staleness_check' );
		( new StaleCornerStoneDetector() )->checkStaleness();
		$this->assertCount( 1, $this->eventsFor( 'holvek_cornerstone_staleness_check' ) );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_stale_cornerstone_ids'] );

		$this->dropHook( 'holvek_scheduled_audit' );
		( new AuditScheduler() )->runScheduledAudit();
		$this->assertCount( 1, $this->eventsFor( 'holvek_scheduled_audit' ) );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_last_audit_results'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_audit_history'] );
	}

	public function testAdminReadsArePaged(): void {
		$redirects = new RedirectManager();
		$request   = new WP_REST_Request();
		$request->set_param( 'per_page', 5000 );
		$redirects->getRedirectsEndpoint( $request );

		$pageSql = $this->sqlContaining( 'SELECT id, source, target, code, is_regex' );
		$this->assertStringContainsString( 'LIMIT 100', $pageSql );
		$this->assertStringNotContainsString( 'LIMIT 5000', $pageSql );
		$this->assertStringNotContainsString( 'SELECT *', $pageSql );

		$this->db->queries = array();
		$redirects->exportCsvEndpoint( new WP_REST_Request() );
		$exportSql = $this->sqlContaining( 'SELECT id, source, target, code, is_regex' );
		$this->assertStringContainsString( 'LIMIT 100', $exportSql );
		$this->assertStringNotContainsString( 'SELECT *', $exportSql );
		$this->assertStringNotContainsString( 'LIMIT 5000', $exportSql );

		update_option( 'holvek_404_table_exists', 'yes' );
		$errors = new ErrorMonitor();
		$logs   = new WP_REST_Request();
		$logs->set_param( 'per_page', 5000 );
		$errors->get404LogsEndpoint( $logs );
		$logSql = $this->sqlContaining( 'SELECT id, uri, hits, referer, user_agent, last_hit, resolved' );
		$this->assertStringContainsString( 'LIMIT 100', $logSql );
		$this->assertStringNotContainsString( 'SELECT *', $logSql );

		$clear = new WP_REST_Request();
		$clear->set_json_params( array() );
		$cleared = $errors->clear404LogsEndpoint( $clear );
		$this->assertSame( array(), $cleared->get_data()['logs'] );

		$this->db->queries = array();
		$audit = ( new SeoAuditHistoryLog() )->exportCsv( new WP_REST_Request() );
		$this->assertIsString( $audit->get_data() );
		$auditSql = $this->sqlContaining( 'FROM wp_holvek_audit_log' );
		$this->assertStringContainsString( 'SELECT id, user_id, post_id, action, field, old_value, new_value, context, created_at', $auditSql );
		$this->assertStringContainsString( 'LIMIT 200', $auditSql );
		$this->assertStringNotContainsString( 'SELECT *', $auditSql );
		$this->assertStringNotContainsString( 'LIMIT 5000', $auditSql );

		$admin = new AdminModule( new EventManager(), new AdminMenu() );
		$GLOBALS['mock_wpdb_var'] = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, 'SHOW TABLES' ) ) {
				return 'wp_holvek_links';
			}
			if ( is_string( $query ) && str_contains( $query, 'COUNT' ) ) {
				return 3;
			}
			return null;
		};
		$GLOBALS['mock_get_posts'] = static function () {
			return array( 7 );
		};

		$this->db->queries = array();
		$paged = new WP_REST_Request();
		$paged->set_param( 'page', 1 );
		$paged->set_param( 'per_page', 500 );
		$page = $admin->getOrphanPages( $paged )->get_data();
		$this->assertSame( 100, $page['per_page'] );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( 100, $GLOBALS['mock_get_posts_args']['posts_per_page'] );
		$linked = $this->sqlContaining( 'SELECT DISTINCT target_url' );
		$this->assertStringContainsString( 'WHERE target_url IN', $linked );
		$this->assertStringNotContainsString( 'LIMIT 5000', $linked );

		$GLOBALS['mock_transients'] = array();
		$GLOBALS['mock_get_posts']  = static function () {
			return array();
		};
		$this->db->queries = array();
		$empty = new WP_REST_Request();
		$empty->set_param( 'page', 2 );
		$empty->set_param( 'per_page', 50 );
		$admin->getOrphanPages( $empty );
		$this->assertFalse( $this->hasSql( 'SELECT DISTINCT target_url' ) );

		$GLOBALS['mock_transients'] = array();
		unset( $GLOBALS['mock_wpdb_var'] );
		$this->db->queries = array();
		$missing = $admin->getOrphanPages( new WP_REST_Request() )->get_data();
		$this->assertSame( array(), $missing['orphans'] );
		$this->assertSame( 0, $missing['total_pages'] );
		$this->assertFalse( $this->hasSql( 'SELECT DISTINCT' ) );
	}

	public function testFrontEndOptionsAutoloadAndSecretsDoNot(): void {
		$robots = new RobotsTxtEditor();
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'content'           => "User-agent: *\nAllow: /\n",
				'replace_generated' => true,
				'block_ai_bots'     => true,
			)
		);
		$robots->updateRobotsTxt( $request );
		$robots->updateAiBots( $this->jsonRequest( array( 'custom_bots' => array( 'GPTBot' ) ) ) );
		$robots->registerRoutes();
		$this->invokeRoute( 'holvek/v1/ai-training-optout', 1, array( 'enabled' => true ) );

		( new BingWebmasterIntegration() )->saveSettings( $this->paramRequest( 'api_key', 'bing-key' ) );

		$admin = new AdminModule( new EventManager(), new AdminMenu() );
		$admin->updateSettings(
			$this->jsonRequest(
				array(
					'auto_index'    => true,
					'indexnow_key'  => 'abcd1234',
				)
			)
		);
		$store = ( new ReflectionClass( AdminModule::class ) )->getMethod( 'storeSecret' );
		$store->setAccessible( true );
		$this->assertTrue( $store->invoke( $admin, 'holvek_openai_key', '' ) );
		KeyVault::ensureSaltExists();
		$this->assertTrue( $store->invoke( $admin, 'holvek_ga4_credentials', 'secret-value' ) );
		$this->assertStringStartsWith( 'holvek_enc::', (string) get_option( 'holvek_ga4_credentials' ) );

		( new CookieBannerModule() )->saveSettings( $this->jsonRequest( array( 'enabled' => true ) ) );
		( new ImageFilenameEnforcer() )->saveSettings( $this->jsonRequest( array( 'enforce' => true ) ) );
		( new CcpaPrivacyTools() )->registerRoutes();
		$this->invokeRoute( 'holvek/v1/privacy/gpc-settings', 1, array( 'honour_gpc' => true ) );

		$saved = ( new SocialAccountManager() )->saveAccount(
			array(
				'id'           => 'facebook_1',
				'network'      => 'facebook',
				'name'         => 'Page',
				'access_token' => 'token-value',
			)
		);
		$this->assertTrue( $saved );
		$this->assertStringStartsWith( 'holvek_enc::', (string) get_option( 'holvek_social_accounts' )[0]['access_token'] );

		$social = ( new SocialModule( new EventManager() ) )->saveSocialSettings(
			$this->jsonRequest(
				array(
					'facebook' => array(
						'app_id'     => '12345678',
						'app_secret' => '',
					),
				)
			)
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $social );

		$redirects = new RedirectManager();
		$created   = $redirects->addRedirectEndpoint(
			$this->jsonRequest(
				array(
					'source' => '/old-page',
					'target' => '/new-page',
					'code'   => 301,
				)
			)
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $created );

		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_robots_txt_replaces_generated'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_block_ai_bots'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_custom_ai_bots'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_ai_training_optout'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_auto_index'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_cookie_banner'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_filename_enforce'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_honour_gpc'] );
		$this->assertTrue( $GLOBALS['mock_option_autoload']['holvek_redirect_counts'] );

		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_robots_txt'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_bing_api_key'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_indexnow_key'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_openai_key'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_ga4_credentials'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_social_accounts'] );
		$this->assertFalse( $GLOBALS['mock_option_autoload']['holvek_social_apps'] );

		$this->db->queries = array();
		$enforce = ( new ReflectionClass( Plugin::class ) )->getMethod( 'enforceOptionAutoload' );
		$enforce->setAccessible( true );
		$enforce->invoke( null );
		$quiet = $this->sqlContaining( 'holvek_license_key' );
		$this->assertStringContainsString( "autoload = 'no'", $quiet );
		$counts = $this->sqlContaining( 'holvek_redirect_counts' );
		$this->assertStringContainsString( "autoload = 'yes'", $counts );
		$bots = $this->sqlContaining( 'holvek_block_ai_bots' );
		$this->assertStringContainsString( "autoload = 'yes'", $bots );
	}

	private function bootPlugin( bool $admin, bool $loggedIn ): void {
		$this->resetPlugin();
		$_SERVER['REQUEST_URI']            = $admin ? '/wp-admin/index.php' : '/hello';
		$GLOBALS['mock_is_admin']          = $admin;
		$GLOBALS['mock_is_user_logged_in'] = $loggedIn;
		$GLOBALS['mock_hooks']             = array();
		Plugin::getInstance()->boot();
	}

	private function resetPlugin(): void {
		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * @return array<string, string>
	 */
	private function recurrences(): array {
		$map = array();
		foreach ( $GLOBALS['mock_recurring_events'] as $event ) {
			$map[ $event['hook'] ] = $event['recurrence'];
		}
		return $map;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function eventsFor( string $hook ): array {
		return array_values(
			array_filter(
				$GLOBALS['mock_recurring_events'],
				static function ( $event ) use ( $hook ): bool {
					return is_array( $event ) && ( $event['hook'] ?? '' ) === $hook;
				}
			)
		);
	}

	private function dropHook( string $hook ): void {
		$GLOBALS['mock_recurring_events'] = array_values(
			array_filter(
				$GLOBALS['mock_recurring_events'],
				static function ( $event ) use ( $hook ): bool {
					return ! is_array( $event ) || ( $event['hook'] ?? '' ) !== $hook;
				}
			)
		);
	}

	private function sqlContaining( string $needle ): string {
		foreach ( $this->db->queries as $sql ) {
			if ( is_string( $sql ) && str_contains( $sql, $needle ) ) {
				return $sql;
			}
		}
		$this->fail( 'No query contained ' . $needle );
	}

	private function hasSql( string $needle ): bool {
		foreach ( $this->db->queries as $sql ) {
			if ( is_string( $sql ) && str_contains( $sql, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function jsonRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_json_params( $params );
		return $request;
	}

	private function paramRequest( string $key, string $value ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( $key, $value );
		return $request;
	}

	private function invokeRoute( string $route, int $index, array $json ): void {
		$callback = $GLOBALS['mock_rest_routes'][ $route ][ $index ]['callback'];
		$callback( $this->jsonRequest( $json ) );
	}
}
