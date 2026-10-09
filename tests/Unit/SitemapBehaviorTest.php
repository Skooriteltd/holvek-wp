<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Sitemap\NewsSitemapGenerator;
use Holvek\WordPress\Modules\Sitemap\SitemapGenerator;
use Holvek\WordPress\Modules\Sitemap\SitemapModule;
use Holvek\WordPress\Modules\Sitemap\SitemapRouteManager;
use Holvek\WordPress\Modules\Sitemap\VideoSitemapGenerator;
use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SitemapBehaviorTest extends TestCase {

	private array $options;

	private $wpdbResults;

	private $wpdbVar;

	private $getPosts;

	protected function setUp(): void {
		parent::setUp();
		$this->options     = $GLOBALS['mock_wp_options'];
		$this->wpdbResults = $GLOBALS['mock_wpdb_results'] ?? null;
		$this->wpdbVar     = $GLOBALS['mock_wpdb_var'] ?? null;
		$this->getPosts    = $GLOBALS['mock_get_posts'] ?? null;
		$GLOBALS['mock_wpdb_var'] = static function () {
			return null;
		};
		$GLOBALS['mock_post_types']  = array( 'post', 'page' );
		$GLOBALS['mock_flush_count'] = 0;
		$GLOBALS['mock_rewrite_rules'] = array();
		$GLOBALS['wp_rewrite']         = new \stdClass();
		$GLOBALS['wp_rewrite']->extra_rules_top = array();
		$GLOBALS['mock_transients']    = array();
		$GLOBALS['mock_posts']         = array();
		$GLOBALS['mock_post_meta']     = array();
		unset(
			$GLOBALS['mock_wpdb_results'],
			$GLOBALS['mock_get_posts'],
			$GLOBALS['mock_current_screen'],
			$GLOBALS['mock_user_caps'],
			$GLOBALS['mock_filter_callbacks']['holvek_sitemap_urls_per_file'],
			$GLOBALS['mock_filter_callbacks']['holvek_sitemap_conflicting_plugins']
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mock_wp_options'] = $this->options;
		if ( $this->wpdbResults ) {
			$GLOBALS['mock_wpdb_results'] = $this->wpdbResults;
		} else {
			unset( $GLOBALS['mock_wpdb_results'] );
		}
		if ( $this->getPosts ) {
			$GLOBALS['mock_get_posts'] = $this->getPosts;
		} else {
			unset( $GLOBALS['mock_get_posts'] );
		}
		if ( $this->wpdbVar ) {
			$GLOBALS['mock_wpdb_var'] = $this->wpdbVar;
		} else {
			unset( $GLOBALS['mock_wpdb_var'] );
		}
		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		$GLOBALS['mock_is_admin'] = false;
		$_SERVER['REQUEST_URI']   = '/';
		unset(
			$GLOBALS['mock_post_types'],
			$GLOBALS['mock_current_screen'],
			$GLOBALS['mock_user_caps'],
			$GLOBALS['mock_filter_callbacks']['holvek_sitemap_urls_per_file'],
			$GLOBALS['mock_filter_callbacks']['holvek_sitemap_conflicting_plugins'],
			$GLOBALS['wp_rewrite']
		);
		parent::tearDown();
	}

	public function testIndexSplitsWhenAPeriodExceedsTheUrlLimit(): void {
		add_filter(
			'holvek_sitemap_urls_per_file',
			static function () {
				return 2;
			}
		);
		$GLOBALS['mock_wpdb_results'] = static function ( string $query ): array {
			if ( ! str_contains( $query, 'COUNT' ) ) {
				return array();
			}
			return array(
				(object) array(
					'period'    => '2026-10',
					'url_count' => 5,
				),
			);
		};

		$xml = ( new SitemapGenerator() )->indexXml();

		$this->assertStringContainsString( '<sitemapindex ', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );
		$this->assertStringContainsString( 'https://example.com/sitemap-posts-2026-10.xml', $xml );
		$this->assertStringContainsString( 'https://example.com/sitemap-posts-2026-10-2.xml', $xml );
		$this->assertStringContainsString( 'https://example.com/sitemap-posts-2026-10-3.xml', $xml );
		$this->assertStringNotContainsString( 'sitemap-posts-2026-10-1.xml', $xml );
		$this->assertStringNotContainsString( 'sitemap-posts-2026-10-4.xml', $xml );
		$this->assertSame( 2, SitemapGenerator::urlLimit() );
	}

	public function testNoindexPostsAndExcludedTypesStayOut(): void {
		update_option( 'holvek_sitemap_exclude_types', array( 'product' ) );
		update_option( 'holvek_sitemap_exclude_posts', '4' );
		$GLOBALS['mock_post_types'] = array( 'post', 'page', 'product' );
		$GLOBALS['mock_post_meta']  = array(
			3 => array( '_holvek_noindex' => 'yes' ),
		);
		$GLOBALS['mock_wpdb_results'] = static function ( string $query ): array {
			if ( str_contains( $query, 'COUNT' ) ) {
				if ( str_contains( $query, "'product'" ) ) {
					return array(
						(object) array(
							'period'    => '2026-10',
							'url_count' => 9,
						),
					);
				}
				return array();
			}

			return array(
				(object) array(
					'ID'                => 2,
					'post_title'        => 'Public',
					'post_type'         => 'post',
					'post_modified_gmt' => '2026-10-01 12:00:00',
					'thumbnail_id'      => '',
				),
				(object) array(
					'ID'                => 3,
					'post_title'        => 'Hidden',
					'post_type'         => 'post',
					'post_modified_gmt' => '2026-10-01 12:00:00',
					'thumbnail_id'      => '',
				),
				(object) array(
					'ID'                => 4,
					'post_title'        => 'Skipped',
					'post_type'         => 'post',
					'post_modified_gmt' => '2026-10-01 12:00:00',
					'thumbnail_id'      => '',
				),
				(object) array(
					'ID'                => 5,
					'post_title'        => 'Product',
					'post_type'         => 'product',
					'post_modified_gmt' => '2026-10-01 12:00:00',
					'thumbnail_id'      => '',
				),
			);
		};

		$generator = new SitemapGenerator();
		$index     = $generator->indexXml();
		$chunk     = $generator->chunkXml( '2026-10', 1 );

		$this->assertStringNotContainsString( 'sitemap-posts-', $index );
		$this->assertStringContainsString( 'https://example.com/post-2/', $chunk );
		$this->assertStringNotContainsString( 'https://example.com/post-3/', $chunk );
		$this->assertStringNotContainsString( 'https://example.com/post-4/', $chunk );
		$this->assertStringNotContainsString( 'https://example.com/post-5/', $chunk );
		$sawNoindex = false;
		$sawProduct = false;
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			if ( ! is_string( $query ) || ! str_contains( $query, 'COUNT' ) ) {
				continue;
			}
			$sawNoindex = str_contains( $query, '_holvek_noindex' );
			$sawProduct = str_contains( $query, "'product'" );
		}
		$this->assertTrue( $sawNoindex );
		$this->assertFalse( $sawProduct );
	}

	public function testChunkQueryUsesTheConfiguredLimitAndPage(): void {
		add_filter(
			'holvek_sitemap_urls_per_file',
			static function () {
				return 2;
			}
		);
		$GLOBALS['mock_wpdb_results'] = static function (): array {
			return array();
		};

		( new SitemapGenerator() )->chunkXml( '2026-10', 2 );

		$chunkQuery = '';
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			if ( is_string( $query ) && str_contains( $query, 'LIMIT' ) ) {
				$chunkQuery = $query;
			}
		}
		$this->assertStringContainsString( 'LIMIT 2 OFFSET 2', $chunkQuery );
	}

	public function testNewsSitemapKeepsTheLast48Hours(): void {
		$GLOBALS['mock_posts'] = array(
			1 => new \WP_Post(
				array(
					'ID'         => 1,
					'post_title' => 'Fresh',
					'post_date'  => '2026-10-02 11:00:00',
				)
			),
			2 => new \WP_Post(
				array(
					'ID'         => 2,
					'post_title' => 'Old',
					'post_date'  => '2026-09-01 12:00:00',
				)
			),
			3 => new \WP_Post(
				array(
					'ID'         => 3,
					'post_title' => 'Edge',
					'post_date'  => '2026-09-30 12:00:00',
				)
			),
			4 => new \WP_Post(
				array(
					'ID'         => 4,
					'post_title' => 'Just out',
					'post_date'  => '2026-09-30 11:59:59',
				)
			),
			5 => new \WP_Post(
				array(
					'ID'         => 5,
					'post_title' => 'Hidden fresh',
					'post_date'  => '2026-10-02 10:00:00',
				)
			),
		);
		$GLOBALS['mock_post_meta'][5] = array( '_holvek_noindex' => 'yes' );
		$GLOBALS['mock_get_posts']    = static function (): array {
			return array_values( $GLOBALS['mock_posts'] );
		};

		$xml = ( new NewsSitemapGenerator() )->generateXml();

		$this->assertStringContainsString( 'https://example.com/post-1/', $xml );
		$this->assertStringContainsString( 'Fresh', $xml );
		$this->assertStringContainsString( 'https://example.com/post-3/', $xml );
		$this->assertStringContainsString( 'Edge', $xml );
		$this->assertStringNotContainsString( 'https://example.com/post-2/', $xml );
		$this->assertStringNotContainsString( 'Old', $xml );
		$this->assertStringNotContainsString( 'https://example.com/post-4/', $xml );
		$this->assertStringNotContainsString( 'Just out', $xml );
		$this->assertStringNotContainsString( 'https://example.com/post-5/', $xml );
		$this->assertStringNotContainsString( 'Hidden fresh', $xml );
		$this->assertSame( '48 hours ago', $GLOBALS['mock_get_posts_args']['date_query'][0]['after'] );
	}

	public function testVideoSitemapListsOnlyPagesWithAVideo(): void {
		$GLOBALS['mock_posts'] = array(
			8 => new \WP_Post(
				array(
					'ID'           => 8,
					'post_title'   => 'Watch',
					'post_content' => 'See https://www.youtube.com/watch?v=abcdefghijk today.',
					'post_excerpt' => 'A clip',
				)
			),
			9 => new \WP_Post(
				array(
					'ID'           => 9,
					'post_title'   => 'Plain',
					'post_content' => 'No video on this page.',
					'post_excerpt' => 'Text',
				)
			),
		);
		$GLOBALS['mock_get_posts'] = static function (): array {
			return array_values( $GLOBALS['mock_posts'] );
		};

		$xml = ( new VideoSitemapGenerator() )->generateXml();

		$this->assertStringContainsString( 'https://example.com/post-8/', $xml );
		$this->assertStringContainsString( '<video:video>', $xml );
		$this->assertStringNotContainsString( 'https://example.com/post-9/', $xml );
		$this->assertStringNotContainsString( 'https://example.com/</loc>', $xml );
		$this->assertSame( 1, substr_count( $xml, '<loc>' ) );
	}

	public function testActivationRegistersRulesAndFlushesOnce(): void {
		update_option( 'holvek_enable_index_sitemap', 'yes' );
		update_option( 'holvek_enable_news_sitemap', 'yes' );
		update_option( 'holvek_enable_video_sitemap', 'yes' );
		$GLOBALS['wp_rewrite']->extra_rules_top['^shop$'] = 'index.php?post_type=product';

		$routes = new SitemapRouteManager(
			new SitemapGenerator(),
			new NewsSitemapGenerator(),
			new VideoSitemapGenerator()
		);
		$routes->flushRules();

		$this->assertSame( 1, $GLOBALS['mock_flush_count'] );
		$rules = $GLOBALS['wp_rewrite']->extra_rules_top;
		$this->assertArrayHasKey( '^sitemap\.xml$', $rules );
		$this->assertArrayHasKey( '^sitemap-posts-(\d{4}-\d{2})(?:-([2-9]|[1-9]\d+))?\.xml$', $rules );
		$this->assertStringContainsString( 'holvek_sitemap_page=$matches[2]', $rules['^sitemap-posts-(\d{4}-\d{2})(?:-([2-9]|[1-9]\d+))?\.xml$'] );
		$this->assertArrayHasKey( '^sitemap-news\.xml$', $rules );
		$this->assertArrayHasKey( '^video-sitemap\.xml$', $rules );
		$this->assertArrayHasKey( '^shop$', $rules );

		$routes->removeRulesAndFlush();

		$this->assertSame( 2, $GLOBALS['mock_flush_count'] );
		$this->assertArrayNotHasKey( '^sitemap\.xml$', $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertArrayNotHasKey( '^sitemap-posts-(\d{4}-\d{2})(?:-([2-9]|[1-9]\d+))?\.xml$', $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertArrayNotHasKey( '^sitemap-news\.xml$', $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertArrayNotHasKey( '^video-sitemap\.xml$', $GLOBALS['wp_rewrite']->extra_rules_top );
		$this->assertSame( 'index.php?post_type=product', $GLOBALS['wp_rewrite']->extra_rules_top['^shop$'] );

		$plugin     = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$activate   = $this->methodBody( $plugin, 'activateSite' );
		$deactivate = $this->methodBody( $plugin, 'deactivateSite' );
		$this->assertSame( 1, substr_count( $activate, 'flushRules()' ) );
		$this->assertSame( 0, substr_count( $activate, 'removeRulesAndFlush()' ) );
		$this->assertSame( 1, substr_count( $deactivate, 'removeRulesAndFlush()' ) );
		$this->assertSame( 0, substr_count( $deactivate, 'flushRules()' ) );
	}

	public function testConflictingSeoPluginAsksTheUserToDisableOne(): void {
		update_option( 'active_plugins', array( 'wordpress-seo/wp-seo.php' ) );
		update_option( 'holvek_enable_index_sitemap', 'yes' );

		$module = new SitemapModule(
			new \Holvek\WordPress\Core\Event\EventManager(),
			new SitemapRouteManager(
				new SitemapGenerator(),
				new NewsSitemapGenerator(),
				new VideoSitemapGenerator()
			)
		);
		$text = $module->conflictNoticeText();
		$this->assertStringContainsString( 'Yoast SEO', $text );
		$this->assertStringContainsString( 'Disable the Holvek XML sitemap or disable the sitemap in the other plugin', $text );

		update_option( 'holvek_enable_index_sitemap', 'no' );
		$this->assertSame( '', $module->conflictNoticeText() );

		update_option( 'holvek_enable_index_sitemap', 'yes' );
		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'dashboard' );
		ob_start();
		$module->renderConflictNotice();
		$dashboard = ob_get_clean();
		$this->assertSame( '', $dashboard );

		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'toplevel_page_holvek' );
		ob_start();
		$module->renderConflictNotice();
		$holvek = ob_get_clean();
		$this->assertStringContainsString( 'Yoast SEO', $holvek );
		$this->assertStringContainsString( 'notice-warning', $holvek );

		$GLOBALS['mock_user_caps'] = array(
			'manage_seo'     => false,
			'manage_options' => false,
		);
		ob_start();
		$module->renderConflictNotice();
		$denied = ob_get_clean();
		$this->assertSame( '', $denied );

		add_filter(
			'holvek_sitemap_conflicting_plugins',
			static function (): array {
				return array( 'Custom SEO' );
			}
		);
		$GLOBALS['mock_user_caps'] = array(
			'manage_seo'     => true,
			'manage_options' => true,
		);
		$this->assertStringContainsString( 'Custom SEO', $module->conflictNoticeText() );
		$this->assertStringNotContainsString( 'Yoast SEO', $module->conflictNoticeText() );
	}

	public function testPagedSitemapPathDoesNotConstructTheGenerator(): void {
		update_option( 'holvek_db_version', HOLVEK_VERSION );
		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		$_SERVER['REQUEST_URI']   = '/sitemap-posts-2026-10-2.xml';
		$GLOBALS['mock_is_admin'] = false;
		$GLOBALS['mock_hooks']    = array();

		$plugin = Plugin::getInstance();
		$plugin->boot();

		$names = array();
		foreach ( $GLOBALS['mock_hooks'] as $hook ) {
			$names[] = $hook[1];
		}
		$this->assertContains( 'template_redirect', $names );
		$property = ( new ReflectionClass( $plugin->getContainer() ) )->getProperty( 'instances' );
		$property->setAccessible( true );
		$instances = $property->getValue( $plugin->getContainer() );
		$this->assertArrayNotHasKey( 'sitemap_generator', $instances );

		$instance->setValue( null, null );
		$_SERVER['REQUEST_URI'] = '/';
	}

	private function methodBody( string $source, string $name ): string {
		$start = strpos( $source, 'function ' . $name . '(' );
		$this->assertNotFalse( $start );
		$end = strpos( $source, "\n\tprivate static function", $start + 10 );
		$this->assertNotFalse( $end );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}
}
