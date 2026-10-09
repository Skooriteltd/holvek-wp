<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Modules\Api\HeadlessSeoEndpoints;
use Holvek\WordPress\Modules\Schema\SchemaGenerator;
use Holvek\WordPress\Modules\Schema\SchemaModule;
use PHPUnit\Framework\TestCase;

class PublicSeoPayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		global $mock_posts, $mock_post_meta, $mock_user_caps, $mock_transients;
		$mock_posts       = array();
		$mock_post_meta   = array();
		$mock_user_caps   = null;
		$mock_transients  = array();
	}

	public function testAnonymousPostSeoOmitsEditorialFields(): void {
		global $mock_posts, $mock_post_meta;

		$mock_posts[42] = new \WP_Post(
			array(
				'ID'            => 42,
				'post_title'    => 'Public title',
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_content'  => '<p>Visible copy.</p>',
				'post_modified' => '2026-02-01 00:00:00',
			)
		);
		$mock_post_meta[42] = array(
			'_holvek_meta_title'       => 'Search title',
			'_holvek_meta_description' => 'Search description',
			'_holvek_focus_keyword'    => 'secret keyword',
			'_holvek_search_intent'    => 'transactional',
			'_holvek_is_cornerstone'   => 'yes',
			'_holvek_ai_generated'     => 'yes',
			'_holvek_schema_type'      => 'faq',
			'_holvek_canonical_url'    => 'https://example.com/public/',
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );

		$response = ( new HeadlessSeoEndpoints() )->getPostSeo( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$data = $response->get_data();
		$this->assertSame( 'Search title', $data['meta_title'] );
		$this->assertSame( 'Search description', $data['meta_description'] );
		$this->assertSame( 'https://example.com/public/', $data['canonical_url'] );
		$this->assertArrayHasKey( 'robots', $data );
		$this->assertArrayHasKey( 'open_graph', $data );
		$this->assertArrayHasKey( 'structured_data', $data );

		foreach ( array( 'focus_keyword', 'search_intent', 'is_cornerstone', 'ai_generated', 'schema_type' ) as $hidden ) {
			$this->assertArrayNotHasKey( $hidden, $data );
		}
		$this->assertStringNotContainsString( 'secret keyword', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'transactional', wp_json_encode( $data ) );
	}

	public function testAnonymousPostSeoHidesUnpublishedPosts(): void {
		global $mock_posts;

		$mock_posts[7] = new \WP_Post(
			array(
				'ID'          => 7,
				'post_status' => 'draft',
				'post_title'  => 'Draft',
			)
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 7 );
		$result = ( new HeadlessSeoEndpoints() )->getPostSeo( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->data['status'] );
	}

	public function testPutStillRequiresEditPost(): void {
		global $mock_posts, $mock_user_caps;

		$mock_posts[9] = new \WP_Post(
			array(
				'ID'          => 9,
				'post_status' => 'draft',
				'post_title'  => 'Draft',
			)
		);
		$mock_user_caps = array( 'edit_post' => array() );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 9 );
		$request->set_json_params( array( 'meta_title' => 'Changed' ) );

		$result = ( new HeadlessSeoEndpoints() )->updatePostSeo( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function testSchemaMapRejectsInheritedAndDraftPosts(): void {
		global $mock_posts;

		$module = new SchemaModule( new EventManager(), new SchemaGenerator() );

		foreach ( array( 'inherit', 'draft', 'private' ) as $index => $status ) {
			$postId                 = 100 + $index;
			$mock_posts[ $postId ]  = new \WP_Post(
				array(
					'ID'          => $postId,
					'post_status' => $status,
					'post_title'  => 'Hidden',
				)
			);
			$request = new \WP_REST_Request();
			$request->set_param( 'post_id', $postId );
			$result = $module->handleSchemaMap( $request );
			$this->assertInstanceOf( \WP_Error::class, $result, $status );
			$this->assertSame( 404, $result->data['status'] );
		}
	}

	public function testSchemaMapCacheVersionBumpsAfterARealSave(): void {
		$module = new SchemaModule( new EventManager(), new SchemaGenerator() );
		update_option( 'holvek_schemamap_version', 3 );

		$module->bumpSchemaMapCache( 15 );

		$this->assertSame( 4, get_option( 'holvek_schemamap_version' ) );
	}
}
