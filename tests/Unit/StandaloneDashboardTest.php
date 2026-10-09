<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Admin\StandaloneDashboard;
use Holvek\WordPress\Modules\Admin\UpgradePromptManager;
use PHPUnit\Framework\TestCase;

class StandaloneDashboardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->resetState();
	}

	protected function tearDown(): void {
		$this->resetState();
		parent::tearDown();
	}

	public function testAUserWithoutTheScreenCapabilityGetsNothing(): void {
		$this->actAs(
			array(
				'edit_seo_meta'  => false,
				'manage_options' => false,
			)
		);
		$dashboard = new StandaloneDashboard();
		$dashboard->registerRoutes();

		$permission = $GLOBALS['mock_rest_routes']['holvek/v1/dashboard/standalone']['permission_callback'];
		$this->assertFalse( $permission() );

		$before = $GLOBALS['wpdb']->queries;
		$result = $dashboard->snapshot( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertSame( $before, $GLOBALS['wpdb']->queries );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testTheFirstScreenUsesLocalCountsAndDoesNotCallOut(): void {
		$this->actAs(
			array(
				'edit_seo_meta'  => true,
				'manage_options' => false,
			)
		);
		update_option( 'blog_public', '0' );
		update_option( 'holvek_redirect_counts', array( 'exact' => 2, 'regex' => 3 ) );
		$this->mockCounts();

		$dashboard = new StandaloneDashboard();
		$result    = $dashboard->snapshot( new \WP_REST_Request() );
		$data      = $result->get_data();
		$sql       = implode( "\n", $GLOBALS['wpdb']->queries );

		$this->assertFalse( $data['indexable'] );
		$this->assertSame( 'Search engines are discouraged from indexing this site.', $data['indexability'] );
		$this->assertSame( 'https://example.com/sitemap.xml', $data['sitemap_url'] );
		$this->assertSame( 4, $data['missing_titles'] );
		$this->assertSame( 7, $data['missing_alt'] );
		$this->assertSame( 5, $data['redirects'] );
		$this->assertSame( 0, $data['not_found'] );
		$this->assertSame( 'setup', $data['next_step'] );
		$this->assertSame( 'Set up Holvek to choose a site type. Titles and the sitemap already work.', $data['next_step_message'] );
		$this->assertStringContainsString( 'A Holvek account is optional.', $data['summary'] );
		$this->assertStringContainsString( '4 published posts or pages have an empty title.', $data['missing_titles_label'] );
		$this->assertStringContainsString( '7 images have no alternative text.', $data['missing_alt_label'] );
		$this->assertStringNotContainsString( 'holvek_404_logs', $sql );
		$this->assertStringNotContainsString( 'holvek_redirects', $sql );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		delete_transient( 'holvek_standalone_counts' );
		update_option( 'holvek_404_table_exists', 'yes' );
		$GLOBALS['wpdb']->queries = array();
		$withLogs                 = $dashboard->snapshot( new \WP_REST_Request() )->get_data();
		$loggedSql                = implode( "\n", $GLOBALS['wpdb']->queries );

		$this->assertSame( 11, $withLogs['not_found'] );
		$this->assertStringContainsString( 'holvek_404_logs', $loggedSql );
		$this->assertStringNotContainsString( 'holvek_redirects', $loggedSql );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );
	}

	public function testAFinishedSetupSaysTitlesAndTheSitemapAreActive(): void {
		$this->actAs(
			array(
				'edit_seo_meta'  => false,
				'manage_options' => true,
			)
		);
		update_option( 'holvek_setup_complete', true );
		update_option( 'blog_public', '1' );
		$this->mockCounts();

		$data = ( new StandaloneDashboard() )->snapshot( new \WP_REST_Request() )->get_data();

		$this->assertTrue( $data['indexable'] );
		$this->assertSame( 'Search engines may index this site.', $data['indexability'] );
		$this->assertSame( 'active', $data['next_step'] );
		$this->assertSame( 'Your titles and sitemap are already active.', $data['next_step_message'] );
	}

	public function testRepeatReadsUseTheCountCacheAndDoNotScanRedirects(): void {
		$this->actAs(
			array(
				'edit_seo_meta'  => true,
				'manage_options' => true,
			)
		);
		$this->mockCounts();
		$dashboard = new StandaloneDashboard();
		$first     = $dashboard->snapshot( new \WP_REST_Request() )->get_data();

		$GLOBALS['mock_wpdb_var'] = static fn() => '100';
		$GLOBALS['wpdb']->queries = array();
		$second                   = $dashboard->snapshot( new \WP_REST_Request() )->get_data();

		$this->assertSame( 4, $first['missing_titles'] );
		$this->assertSame( 4, $second['missing_titles'] );
		$this->assertSame( 7, $second['missing_alt'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertSame( 0, $second['redirects'] );
	}

	public function testTheUpgradeBannerDoesNotLockImplementedFeatures(): void {
		$manager = new UpgradePromptManager();

		ob_start();
		$manager->showUpgradeBannerNotice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( 'Unlock Holvek Pro', $html );

		update_option( 'holvek_user_tier', 'free' );
		$listed = $manager->listFeatures( new \WP_REST_Request() )->get_data();
		$byKey  = array();
		foreach ( $listed['features'] as $feature ) {
			$byKey[ $feature['key'] ] = $feature;
		}

		foreach ( array( 'gsc_integration', 'technical_audit', 'bulk_csv_redirects', 'rank_tracker', 'writing_assistant' ) as $key ) {
			$this->assertTrue( $byKey[ $key ]['available'] );
			$this->assertSame( '', $byKey[ $key ]['upgrade_url'] );
		}

		$encoded = (string) wp_json_encode( $listed );
		$this->assertStringNotContainsString( 'feature_lock', $encoded );
		$this->assertStringNotContainsString( 'ai_visibility_tracker', $encoded );
		$this->assertStringNotContainsString( 'Multi-Brand', $encoded );
		$this->assertStringNotContainsString( 'Client Reporting', $encoded );
		$this->assertStringNotContainsString( 'AI Visibility', $encoded );

		update_option( 'holvek_user_tier', 'enterprise' );
		$this->assertFalse( $manager->isFeatureAvailable( 'ai_visibility_tracker' ) );
		$this->assertTrue( $manager->isFeatureAvailable( 'gsc_integration' ) );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'feature_key' => 'gsc_integration' ) );
		$check = $manager->checkFeature( $request );
		$this->assertSame( true, $check->get_data()['available'] );

		$unknown = new \WP_REST_Request();
		$unknown->set_json_params( array( 'feature_key' => 'ai_visibility_tracker' ) );
		$missing = $manager->checkFeature( $unknown );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'unknown_feature', $missing->get_error_code() );
		$this->assertSame( 404, $missing->data['status'] );
	}

	public function testTheAdminScreenKeepsTheLocalAnswersAboveSetup(): void {
		$app    = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/assets/js/App.js' );
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$start  = strpos( $plugin, 'private function bootFrontendHtml' );
		$end    = strpos( $plugin, 'public function filterCoreSitemapsEnabled' );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$htmlBoot = substr( $plugin, $start, $end - $start );

		$this->assertStringNotContainsString( '{!showSetup &&', $app );
		$this->assertStringContainsString( '/dashboard/standalone', $app );
		$this->assertStringContainsString( 'Your titles and sitemap are already active.', $app );
		$this->assertStringContainsString( 'Connect Google Search Console', $app );
		$this->assertStringContainsString( 'Connecting Holvek is optional.', $app );
		$this->assertStringContainsString( 'gscOpen', $app );
		$this->assertStringNotContainsString( 'StandaloneDashboard', $htmlBoot );
		$this->assertStringNotContainsString( 'dashboard/standalone', $htmlBoot );
	}

	/**
	 * @param array<string, bool> $caps Capability map. Missing keys must be set or the stub allows them.
	 */
	private function actAs( array $caps ): void {
		$GLOBALS['mock_user_caps'] = $caps;
	}

	private function mockCounts(): void {
		$GLOBALS['mock_wpdb_var'] = static function ( $query ) {
			$query = (string) $query;
			if ( str_contains( $query, 'holvek_404_logs' ) ) {
				return '11';
			}
			if ( str_contains( $query, '_wp_attachment_image_alt' ) ) {
				return '7';
			}
			if ( str_contains( $query, 'post_title' ) ) {
				return '4';
			}
			return '0';
		};
	}

	private function resetState(): void {
		delete_option( 'blog_public' );
		delete_option( 'holvek_setup_complete' );
		delete_option( 'holvek_redirect_counts' );
		delete_option( 'holvek_404_table_exists' );
		delete_option( 'holvek_user_tier' );
		delete_transient( 'holvek_standalone_counts' );
		$GLOBALS['mock_http_log']    = array();
		$GLOBALS['mock_rest_routes'] = array();
		$GLOBALS['wpdb']->queries    = array();
		unset( $GLOBALS['mock_user_caps'], $GLOBALS['mock_wpdb_var'] );
	}
}
