<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Ai\UsageMeteringManager;
use PHPUnit\Framework\TestCase;

class AiUsageScopeTest extends TestCase {

	private UsageMeteringManager $usage;

	protected function setUp(): void {
		parent::setUp();

		$this->usage = new UsageMeteringManager();

		$GLOBALS['mock_wp_options']       = array();
		$GLOBALS['mock_rest_routes']      = array();
		$GLOBALS['mock_current_user_id']  = 0;
		$GLOBALS['mock_is_user_logged_in'] = false;
		$GLOBALS['mock_user_caps']        = array(
			'read'            => false,
			'manage_seo'      => false,
			'manage_options'  => false,
		);
		$GLOBALS['wpdb']->queries         = array();
		unset( $GLOBALS['mock_wpdb_results'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mock_wpdb_results'], $GLOBALS['mock_is_user_logged_in'], $GLOBALS['mock_user_caps'] );
		parent::tearDown();
	}

	public function testSubscriberTokenSeesOnlyTheirOwnCounters(): void {
		$this->actAsSubscriber();
		$this->usage->registerRoutes();

		$route = $GLOBALS['mock_rest_routes']['holvek/v1/ai/usage'];
		$this->assertTrue( ( $route['permission_callback'] )() );

		$this->seedUsageRows();
		$request = new \WP_REST_Request( 'GET', '/holvek/v1/ai/usage' );
		$request->set_param( 'user_id', 1 );
		$request->set_param( 'period', 'month' );

		$response = $this->usage->getMyUsage( $request );
		$data     = $response->get_data();
		$encoded  = (string) wp_json_encode( $data );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'month', $data['period'] );
		$this->assertSame( 12, $data['total'] );
		$this->assertSame( 'title', $data['by_feature'][0]->feature );
		$this->assertStringNotContainsString( 'admin-secret', $encoded );
		$this->assertStringNotContainsString( '9999', $encoded );

		$query = (string) end( $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'user_id = 42', $query );
		$this->assertStringNotContainsString( 'user_id = 1', $query );
		$this->assertStringContainsString( 'INTERVAL 30 DAY', $query );
	}

	public function testSubscriberCannotReadSiteUsageOrLimits(): void {
		$this->actAsSubscriber();
		$this->usage->registerRoutes();
		$this->seedUsageRows();

		$site   = $GLOBALS['mock_rest_routes']['holvek/v1/ai/usage/site'];
		$limits = $GLOBALS['mock_rest_routes']['holvek/v1/ai/usage/limits'];

		$this->assertFalse( ( $site['permission_callback'] )() );
		$this->assertFalse( ( $limits[0]['permission_callback'] )() );
		$this->assertFalse( ( $limits[1]['permission_callback'] )() );

		$before = $GLOBALS['wpdb']->queries;
		$result = $this->usage->getSiteUsageEndpoint( new \WP_REST_Request( 'GET', '/holvek/v1/ai/usage/site' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertSame( $before, $GLOBALS['wpdb']->queries );
	}

	public function testSeoManagerAndAdministratorCanReadSiteUsage(): void {
		$this->usage->registerRoutes();
		$this->seedUsageRows();
		$site = $GLOBALS['mock_rest_routes']['holvek/v1/ai/usage/site']['permission_callback'];

		$this->actAs( 7, array( 'manage_seo' => true, 'manage_options' => false ) );
		$this->assertTrue( $site() );
		$manager = $this->usage->getSiteUsageEndpoint( new \WP_REST_Request() );
		$this->assertSame( 9999, $manager->get_data()['total'] );
		$this->assertSame( 'admin-secret', $manager->get_data()['by_feature'][0]->feature );
		$this->assertStringNotContainsString( 'user_id = 42', (string) end( $GLOBALS['wpdb']->queries ) );

		$this->actAs( 1, array( 'manage_seo' => false, 'manage_options' => true ) );
		$this->assertTrue( $site() );
	}

	public function testLoggedOutUserCannotReadUsage(): void {
		$this->usage->registerRoutes();
		$route = $GLOBALS['mock_rest_routes']['holvek/v1/ai/usage']['permission_callback'];
		$this->assertFalse( $route() );

		$result = $this->usage->getMyUsage( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->data['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	private function actAsSubscriber(): void {
		$this->actAs(
			42,
			array(
				'read'           => true,
				'manage_seo'     => false,
				'manage_options' => false,
				'edit_posts'     => false,
			)
		);
	}

	/**
	 * @param array<string, bool> $caps
	 */
	private function actAs( int $userId, array $caps ): void {
		$GLOBALS['mock_current_user_id']   = $userId;
		$GLOBALS['mock_is_user_logged_in'] = $userId > 0;
		$GLOBALS['mock_user_caps']         = $caps;
	}

	private function seedUsageRows(): void {
		$GLOBALS['mock_wpdb_results'] = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, 'user_id = 42' ) ) {
				return array(
					(object) array(
						'provider' => 'openai',
						'feature'  => 'title',
						'total'    => 12,
					),
				);
			}

			return array(
				(object) array(
					'provider' => 'openai',
					'feature'  => 'admin-secret',
					'total'    => 9999,
				),
			);
		};
	}
}
