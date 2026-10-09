<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\ImageSeo\ImageAltAudit;
use Holvek\WordPress\Modules\ImageSeo\ImageCompressor;
use Holvek\WordPress\Modules\ImageSeo\ImageFilenameEnforcer;
use PHPUnit\Framework\TestCase;

class ImageSeoBehaviorTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/holvek-image-seo-' . uniqid( '', true );
		mkdir( $this->dir );
		$GLOBALS['mock_user_caps']             = array(
			'edit_post'      => true,
			'manage_seo'     => true,
			'manage_options' => true,
		);
		$GLOBALS['mock_posts']                 = array();
		$GLOBALS['mock_post_meta']             = array();
		$GLOBALS['mock_attached_files']        = array();
		$GLOBALS['mock_post_mime']             = array();
		$GLOBALS['mock_inserted_attachments']  = array();
		$GLOBALS['mock_next_attachment_id']    = 900;
		unset( $GLOBALS['mock_wp_query'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['mock_user_caps'],
			$GLOBALS['mock_posts'],
			$GLOBALS['mock_post_meta'],
			$GLOBALS['mock_attached_files'],
			$GLOBALS['mock_post_mime'],
			$GLOBALS['mock_inserted_attachments'],
			$GLOBALS['mock_next_attachment_id'],
			$GLOBALS['mock_wp_query']
		);
		unset(
			$GLOBALS['mock_wp_options']['holvek_filename_enforce'],
			$GLOBALS['mock_wp_options']['holvek_image_compression']
		);
		if ( is_dir( $this->dir ) ) {
			foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	public function testUploadRenamesAndExistingFilesStayPut(): void {
		update_option( 'holvek_filename_enforce', 'yes' );
		$enforcer = new ImageFilenameEnforcer();
		$file     = array(
			'name' => 'IMG_9999_summer_holiday_beach.JPG',
			'type' => 'image/jpeg',
			'size' => 100,
		);

		$this->assertSame( 'summer-holiday-beach.jpg', $enforcer->enforceFilename( $file )['name'] );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'ids' => array( 15 ) ) );
		$result = $enforcer->bulkRename( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'existing_files_not_renamed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function testMissingAltPageIsCappedAtTwenty(): void {
		$seen                        = array();
		$GLOBALS['mock_wp_query']    = function ( array $args ) use ( &$seen ): array {
			$seen = $args;
			return array(
				'posts'         => array(),
				'found_posts'   => 0,
				'max_num_pages' => 0,
			);
		};
		$request = new \WP_REST_Request();
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 500 );

		$data = ( new ImageAltAudit() )->getMissingAlt( $request )->get_data();

		$this->assertSame( 20, $data['per_page'] );
		$this->assertSame( 20, $seen['posts_per_page'] );
	}

	public function testCompressionKeepsTheOriginalInTheMediaLibrary(): void {
		$path = $this->dir . '/beach.jpg';
		file_put_contents( $path, 'original-bytes' );
		$post = new \WP_Post(
			array(
				'ID'          => 44,
				'post_title'  => 'Beach',
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
		$GLOBALS['mock_posts'][44]          = $post;
		$GLOBALS['mock_attached_files'][44] = $path;
		$GLOBALS['mock_post_mime'][44]      = 'image/jpeg';
		update_option(
			'holvek_image_compression',
			array(
				'enabled'  => true,
				'quality'  => 82,
				'preserve' => true,
			)
		);

		$calls                    = 0;
		$GLOBALS['mock_wp_query'] = function () use ( &$calls, $post ): array {
			++$calls;
			if ( 1 === $calls ) {
				return array(
					'posts'         => array( $post ),
					'found_posts'   => 1,
					'max_num_pages' => 1,
				);
			}
			return array(
				'posts'         => array(),
				'found_posts'   => 0,
				'max_num_pages' => 0,
			);
		};

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'batch_size' => 20 ) );
		$result = ( new ImageCompressor() )->bulkCompress( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$originalId = (int) get_post_meta( 44, '_holvek_original_attachment_id', true );
		$this->assertGreaterThan( 0, $originalId );
		$this->assertNotNull( get_post( $originalId ) );
		$kept = get_attached_file( $originalId );
		$this->assertIsString( $kept );
		$this->assertFileExists( $kept );
		$this->assertSame( 'original-bytes', file_get_contents( $kept ) );
		$this->assertFileDoesNotExist( $path . '.holvek-original' );
		$this->assertSame( 'original-bytes', file_get_contents( $path ) );
	}
}
