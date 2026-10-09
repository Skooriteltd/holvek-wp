<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Admin\SettingsTransfer;
use PHPUnit\Framework\TestCase;

class SettingsTransferTest extends TestCase {

	private AdminModule $admin;

	protected function setUp(): void {
		parent::setUp();
		$this->admin = new AdminModule( new EventManager(), new AdminMenu() );
		foreach ( $this->watchedOptions() as $option ) {
			delete_option( $option );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mock_user_caps'] );
		$GLOBALS['mock_rest_routes'] = array();
		parent::tearDown();
	}

	public function testExportOmitsSecretsAndKeepsPublicSettings(): void {
		update_option( 'holvek_google_indexing_key', '{"private_key":"INDEXING-SECRET-VALUE"}' );
		update_option( 'holvek_indexnow_key', 'indexnow-secret-value' );
		update_option( 'holvek_openai_key', 'openai-secret-value' );
		update_option( 'holvek_anthropic_key', 'anthropic-secret-value' );
		update_option( 'holvek_api_key', 'api-secret-value' );
		update_option( 'holvek_license_key', 'license-secret-value' );
		update_option( 'holvek_ga4_credentials', '{"private_key":"GA4-SECRET-VALUE"}' );
		update_option( 'holvek_encryption_salt', 'salt-secret-value' );
		update_option(
			'holvek_social_apps',
			array(
				'facebook' => array( 'app_secret' => 'oauth-app-secret-value' ),
			)
		);
		update_option(
			'holvek_social_accounts',
			array(
				array( 'access_token' => 'oauth-token-value' ),
			)
		);
		update_option( 'holvek_google_verify', 'google-verify-token' );
		update_option( 'holvek_rss_before_content', '<p>Hello</p><script>alert(1)</script>' );
		update_option( 'holvek_breadcrumb_home_label', 'Start' );

		$payload = $this->admin->exportSettings( new \WP_REST_Request() )->get_data()['data'];
		$encoded = (string) wp_json_encode( $payload );

		$this->assertSame( 'google-verify-token', $payload['settings']['holvek_google_verify'] );
		$this->assertSame( 'Start', $payload['settings']['holvek_breadcrumb_home_label'] );
		$this->assertStringContainsString( '<p>Hello</p>', $payload['settings']['holvek_rss_before_content'] );
		$this->assertStringNotContainsString( '<script', $payload['settings']['holvek_rss_before_content'] );
		$this->assertArrayNotHasKey( 'holvek_google_indexing_key', $payload['settings'] );
		$this->assertArrayNotHasKey( 'holvek_indexnow_key', $payload['settings'] );
		$this->assertArrayNotHasKey( 'holvek_encryption_salt', $payload['settings'] );
		$this->assertContains( 'holvek_indexnow_key', $payload['_redacted'] );
		$this->assertContains( 'holvek_encryption_salt', $payload['_redacted'] );
		$this->assertContains( 'holvek_social_apps', $payload['_redacted'] );

		foreach (
			array(
				'INDEXING-SECRET-VALUE',
				'indexnow-secret-value',
				'openai-secret-value',
				'anthropic-secret-value',
				'api-secret-value',
				'license-secret-value',
				'GA4-SECRET-VALUE',
				'salt-secret-value',
				'oauth-app-secret-value',
				'oauth-token-value',
				'<script',
			) as $secret
		) {
			$this->assertStringNotContainsString( $secret, $encoded );
		}
	}

	public function testImportSanitizesEachAllowedField(): void {
		$result = $this->import(
			array(
				'holvek_auto_index'                 => true,
				'holvek_enable_google_indexing_api' => 'no',
				'holvek_google_verify'              => 'abcDEF_123-token',
				'holvek_bing_verify'                => '',
				'holvek_rss_before_content'         => '<p>Read <a href="https://example.com">this</a></p><script>alert(1)</script>',
				'holvek_rss_after_content'          => '<strong>End</strong>',
				'holvek_breadcrumb_separator'       => '›',
				'holvek_breadcrumb_home_label'      => 'Home',
				'holvek_breadcrumb_auto_insert'     => 'before_content',
				'holvek_sitemap_exclude_post_types' => 'Post, Page',
				'holvek_sitemap_exclude_posts'      => '12, 34',
				'holvek_ai_provider'                => 'Anthropic',
				'holvek_ollama_url'                 => 'http://localhost:11434/',
				'holvek_default_share_image'        => 'https://cdn.example.com/share.png',
				'holvek_ga4_measurement_id'         => 'g-abc12345',
				'holvek_ga4_property_id'            => '123456',
				'holvek_html_sitemap_post_types'    => 'post,page',
				'holvek_html_sitemap_exclude_ids'   => '7',
				'holvek_html_sitemap_depth'         => '3',
				'holvek_html_sitemap_order'         => 'title',
				'holvek_html_sitemap_show_count'    => 'yes',
			)
		);

		$this->assertSame( 21, $result->get_data()['imported'] );
		$this->assertSame( 'yes', get_option( 'holvek_auto_index' ) );
		$this->assertSame( 'no', get_option( 'holvek_enable_google_indexing_api' ) );
		$this->assertSame( 'abcDEF_123-token', get_option( 'holvek_google_verify' ) );
		$this->assertSame( '', get_option( 'holvek_bing_verify' ) );
		$rss = (string) get_option( 'holvek_rss_before_content' );
		$this->assertStringContainsString( '<p>Read', $rss );
		$this->assertStringContainsString( 'https://example.com', $rss );
		$this->assertStringNotContainsString( '<script', $rss );
		$this->assertStringNotContainsString( 'alert(1)', $rss );
		$this->assertSame( '<strong>End</strong>', get_option( 'holvek_rss_after_content' ) );
		$this->assertSame( '›', get_option( 'holvek_breadcrumb_separator' ) );
		$this->assertSame( 'before_content', get_option( 'holvek_breadcrumb_auto_insert' ) );
		$this->assertSame( 'post,page', get_option( 'holvek_sitemap_exclude_post_types' ) );
		$this->assertSame( '12,34', get_option( 'holvek_sitemap_exclude_posts' ) );
		$this->assertSame( 'anthropic', get_option( 'holvek_ai_provider' ) );
		$this->assertSame( 'http://localhost:11434', get_option( 'holvek_ollama_url' ) );
		$this->assertSame( 'https://cdn.example.com/share.png', get_option( 'holvek_default_share_image' ) );
		$this->assertSame( 'G-ABC12345', get_option( 'holvek_ga4_measurement_id' ) );
		$this->assertSame( '123456', get_option( 'holvek_ga4_property_id' ) );
		$this->assertSame( 3, get_option( 'holvek_html_sitemap_depth' ) );
		$this->assertSame( 'title', get_option( 'holvek_html_sitemap_order' ) );
	}

	public function testInvalidValueWritesNothing(): void {
		update_option( 'holvek_breadcrumb_home_label', 'Start' );
		update_option( 'holvek_default_share_image', 'https://example.com/old.png' );
		update_option( 'holvek_indexnow_key', 'original-indexnow' );

		$cases = array(
			array( 'holvek_default_share_image', 'javascript:alert(1)' ),
			array( 'holvek_google_verify', 'token with spaces' ),
			array( 'holvek_ga4_measurement_id', 'UA-123' ),
			array( 'holvek_sitemap_exclude_posts', '12,foo' ),
			array( 'holvek_sitemap_exclude_post_types', 'post,<script>' ),
			array( 'holvek_ai_provider', 'gpt' ),
			array( 'holvek_breadcrumb_auto_insert', 'footer' ),
			array( 'holvek_html_sitemap_depth', '11' ),
			array( 'holvek_auto_index', 'maybe' ),
		);

		foreach ( $cases as $case ) {
			$result = $this->import(
				array(
					$case[0]                       => $case[1],
					'holvek_breadcrumb_home_label' => 'Changed',
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'invalid_setting', $result->get_error_code() );
			$this->assertSame( 'Start', get_option( 'holvek_breadcrumb_home_label' ) );
			$this->assertSame( 'https://example.com/old.png', get_option( 'holvek_default_share_image' ) );
			$this->assertSame( 'original-indexnow', get_option( 'holvek_indexnow_key' ) );
		}
	}

	public function testUnknownKeyWritesNothingAndKnownSecretsAreIgnored(): void {
		update_option( 'holvek_breadcrumb_home_label', 'Start' );
		update_option( 'holvek_encryption_salt', 'salt-must-stay-on-the-site' );
		update_option( 'holvek_indexnow_key', 'original-indexnow' );
		update_option( 'holvek_google_indexing_key', 'original-indexing-json' );

		$unknown = $this->import(
			array(
				'holvek_not_a_setting'         => 'x',
				'holvek_breadcrumb_home_label' => 'Changed',
				'holvek_indexnow_key'          => 'replacement-key',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $unknown );
		$this->assertSame( 'unknown_setting', $unknown->get_error_code() );
		$this->assertSame( 'Start', get_option( 'holvek_breadcrumb_home_label' ) );
		$this->assertSame( 'original-indexnow', get_option( 'holvek_indexnow_key' ) );
		$this->assertSame( 'salt-must-stay-on-the-site', get_option( 'holvek_encryption_salt' ) );

		$secrets = $this->import(
			array(
				'holvek_indexnow_key'          => 'replacement-key',
				'holvek_google_indexing_key'   => 'replacement-json',
				'holvek_encryption_salt'       => 'replacement-salt',
				'holvek_openai_key'            => 'replacement-openai',
				'holvek_social_apps'           => array( 'facebook' => array( 'app_secret' => 'new-secret' ) ),
				'holvek_breadcrumb_home_label' => 'Home',
			)
		);
		$this->assertSame( 1, $secrets->get_data()['imported'] );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );
		$this->assertSame( 'original-indexnow', get_option( 'holvek_indexnow_key' ) );
		$this->assertSame( 'original-indexing-json', get_option( 'holvek_google_indexing_key' ) );
		$this->assertSame( 'salt-must-stay-on-the-site', get_option( 'holvek_encryption_salt' ) );
		$this->assertFalse( get_option( 'holvek_openai_key', false ) );
		$this->assertContains( 'holvek_indexnow_key', SettingsTransfer::secretOptions() );
	}

	public function testTransferRoutesRequireManageOptions(): void {
		$GLOBALS['mock_rest_routes'] = array();
		$this->admin->registerRestRoutes();
		$routes = $GLOBALS['mock_rest_routes'];

		$export = $routes['holvek/v1/settings/export']['permission_callback'];
		$import = $routes['holvek/v1/settings/import']['permission_callback'];
		$settings = $routes['holvek/v1/settings'][0]['permission_callback'];

		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => true,
			'manage_seo'     => false,
		);
		$this->assertTrue( $export() );
		$this->assertTrue( $import() );
		$this->assertTrue( $settings() );

		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => false,
			'manage_seo'     => true,
		);
		$this->assertFalse( $export() );
		$this->assertFalse( $import() );
		$this->assertTrue( $settings() );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function import( array $settings ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'_plugin'  => 'holvek',
				'settings' => $settings,
			)
		);

		return $this->admin->importSettings( $request );
	}

	/**
	 * @return array<int, string>
	 */
	private function watchedOptions(): array {
		return array(
			'holvek_auto_index',
			'holvek_enable_google_indexing_api',
			'holvek_google_verify',
			'holvek_bing_verify',
			'holvek_yandex_verify',
			'holvek_pinterest_verify',
			'holvek_rss_before_content',
			'holvek_rss_after_content',
			'holvek_breadcrumb_separator',
			'holvek_breadcrumb_home_label',
			'holvek_breadcrumb_auto_insert',
			'holvek_sitemap_exclude_post_types',
			'holvek_sitemap_exclude_posts',
			'holvek_ai_provider',
			'holvek_ollama_url',
			'holvek_ollama_remote_ack',
			'holvek_default_share_image',
			'holvek_ga4_measurement_id',
			'holvek_ga4_property_id',
			'holvek_html_sitemap_post_types',
			'holvek_html_sitemap_exclude_ids',
			'holvek_html_sitemap_depth',
			'holvek_html_sitemap_order',
			'holvek_html_sitemap_show_count',
			'holvek_google_indexing_key',
			'holvek_indexnow_key',
			'holvek_openai_key',
			'holvek_anthropic_key',
			'holvek_api_key',
			'holvek_license_key',
			'holvek_ga4_credentials',
			'holvek_encryption_salt',
			'holvek_social_apps',
			'holvek_social_accounts',
		);
	}
}
