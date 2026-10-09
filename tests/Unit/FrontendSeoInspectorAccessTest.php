<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Admin\FrontendSeoInspector;
use PHPUnit\Framework\TestCase;

class FrontendSeoInspectorAccessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		global $mock_posts, $mock_post_meta, $mock_user_caps;
		$mock_posts     = array();
		$mock_post_meta = array();
		$mock_user_caps = array(
			'edit_posts' => true,
			'edit_post'  => array(),
		);
	}

	public function testMissingPostIdIsRejectedBeforeLookup(): void {
		$request = new \WP_REST_Request();
		$request->set_json_params( array() );

		$result = ( new FrontendSeoInspector() )->runAudit( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_id', $result->get_error_code() );
		$this->assertSame( 400, $result->data['status'] );
	}

	public function testMissingPostReturns404AndDoesNotReadMeta(): void {
		global $mock_post_meta;
		$mock_post_meta[88] = array(
			'_holvek_focus_keyword' => 'hidden keyword',
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'post_id' => 88 ) );
		$result = ( new FrontendSeoInspector() )->runAudit( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->data['status'] );
		$this->assertStringNotContainsString( 'hidden keyword', wp_json_encode( $result ) );
	}

	public function testContributorCannotReadAnotherPostsEditorialMeta(): void {
		$this->seedPost( 15, 'private' );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'post_id' => 15 ) );
		$inspector = new FrontendSeoInspector();

		$permission = $inspector->canAuditRequest( $request );
		$this->assertInstanceOf( \WP_Error::class, $permission );
		$this->assertSame( 403, $permission->data['status'] );

		$result = $inspector->runAudit( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
		$this->assertStringNotContainsString( 'secret keyword', wp_json_encode( $result ) );
	}

	public function testUserWithoutEditPostsCannotProbeIds(): void {
		global $mock_user_caps;
		$mock_user_caps = array(
			'edit_posts' => false,
			'edit_post'  => array(),
		);
		$this->seedPost(21, 'publish');

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'post_id' => 99999 ) );
		$result = ( new FrontendSeoInspector() )->runAudit( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] );
	}

	public function testEditorReceivesTheAuditForAnEditablePost(): void {
		global $mock_user_caps;
		$mock_user_caps['edit_post'] = array( 15 );
		$this->seedPost( 15, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'post_id' => 15 ) );
		$inspector = new FrontendSeoInspector();

		$this->assertTrue( $inspector->canAuditRequest( $request ) );

		$result = $inspector->runAudit( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertSame( 15, $data['post_id'] );
		$this->assertSame( 'secret keyword', $data['checks']['focus_keyword']['value'] );
	}

	private function seedPost( int $postId, string $status ): void {
		global $mock_posts, $mock_post_meta;

		$mock_posts[ $postId ] = new \WP_Post(
			array(
				'ID'           => $postId,
				'post_status'  => $status,
				'post_title'   => 'Inspected',
				'post_content' => 'A long enough sentence for the word counter to see real content. ',
				'post_type'    => 'post',
			)
		);
		$mock_post_meta[ $postId ] = array(
			'_holvek_meta_title'       => 'A proper search title',
			'_holvek_meta_description' => 'A proper search description that is long enough to pass the length check for this audit.',
			'_holvek_focus_keyword'    => 'secret keyword',
		);
	}
}
