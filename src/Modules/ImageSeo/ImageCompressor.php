<?php

namespace Holvek\WordPress\Modules\ImageSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageCompressor: Reversibly compresses administrator-selected media.
 *
 * Provides a REST endpoint for bulk-processing the existing library.
 * Uses Imagick when available, falls back to GD.
 */
class ImageCompressor {

	private const OPTION_KEY         = 'holvek_image_compression';
	private const PROCESSED_META_KEY = '_holvek_compressed';
	private const ORIGINAL_META_KEY  = '_holvek_compression_original';

	public function boot(): void {
		$config = $this->getConfig();
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );

		// Upload-time mutation is intentionally unsupported. Media can be
		// optimized only through the explicit, reversible bulk workflow after
		// WordPress has created an attachment record and backup metadata.
		if ( ! $config['enabled'] ) {
			return;
		}
	}

	/**
	 * Compress a single backed-up file.
	 * Returns true on success, false on failure.
	 */
	public function processFile( string $filePath, string $mime, array $config ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local derivative path is checked before GD writes it.
		if ( ! file_exists( $filePath ) || ! is_writable( $filePath ) ) {
			return false;
		}

		$quality = (int) ( $config['quality'] ?? 82 );
		// Prefer Imagick for better compression quality
		if ( extension_loaded( 'imagick' ) ) {
			return $this->processWithImagick( $filePath, $mime, $quality );
		}

		if ( extension_loaded( 'gd' ) ) {
			return $this->processWithGd( $filePath, $mime, $quality );
		}

		return false;
	}

	// ── Imagick implementation ────────────────────────────────────────────────

	private function processWithImagick( string $path, string $mime, int $quality ): bool {
		try {
			$imagick = new \Imagick( $path );
			// Preserve metadata and orientation. The unmodified source is also
			// backed up before every bulk operation.

			if ( $mime === 'image/jpeg' ) {
				$imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
				$imagick->setImageCompressionQuality( $quality );
				$imagick->setInterlaceScheme( \Imagick::INTERLACE_JPEG ); // Progressive
			} elseif ( $mime === 'image/png' ) {
				$imagick->setImageFormat( 'png' );
				// PNG compression 0-9, quality 0-100 mapped → 0-9
				$imagick->setImageCompressionQuality( min( 9, (int) floor( ( 100 - $quality ) / 11 ) ) );
			}

			$imagick->writeImage( $path );

			$imagick->destroy();
			return true;

		} catch ( \Exception $e ) {
			return false;
		}
	}

	// ── GD fallback ───────────────────────────────────────────────────────────

	private function processWithGd( string $path, string $mime, int $quality ): bool {
		$image = match ( $mime ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD warns on corrupt image bytes. The false return is checked.
			'image/jpeg' => @imagecreatefromjpeg( $path ),
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD warns on corrupt image bytes. The false return is checked.
			'image/png'  => @imagecreatefrompng( $path ),
			default      => false,
		};

		if ( ! $image ) {
			return false;
		}

		$result = match ( $mime ) {
			'image/jpeg' => imagejpeg( $image, $path, $quality ),
			'image/png'  => imagepng( $image, $path, min( 9, (int) floor( ( 100 - $quality ) / 11 ) ) ),
			default      => false,
		};

		imagedestroy( $image );
		return (bool) $result;
	}

	// ── REST API ─────────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		// Settings CRUD
		register_rest_route(
			'holvek/v1',
			'/settings/image-compression',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
				),
			)
		);

		// Bulk compress endpoint — processes library in batches
		register_rest_route(
			'holvek/v1',
			'/images/compress-bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulkCompress' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/images/(?P<attachment_id>\d+)/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restoreAttachment' ),
				'permission_callback' => fn( \WP_REST_Request $request ) => current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $request->get_param( 'attachment_id' ) ),
				'args'                => array(
					'attachment_id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => fn( $value ) => (int) $value > 0,
					),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/images/compression-backups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getBackups' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Stats endpoint — total images, compressed count, saved bytes estimate
		register_rest_route(
			'holvek/v1',
			'/images/compression-stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getStats' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);
	}

	public function getSettings(): \WP_REST_Response {
		return rest_ensure_response( $this->getConfig() );
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_json_params();
		$enabled = ! empty( $params['enabled'] );

		if ( $enabled && empty( $params['acknowledge_reversible_processing'] ) ) {
			return new \WP_Error(
				'compression_confirmation_required',
				__( 'Confirm that originals will be retained before enabling image compression.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$config = array(
			'enabled'  => $enabled,
			'quality'  => min( 100, max( 40, (int) ( $params['quality'] ?? 82 ) ) ),
			// WebP delivery needs server/content-negotiation support and is not
			// shipped until that path has end-to-end coverage.
			'webp'     => false,
			// This invariant makes every shipped compression reversible.
			'preserve' => true,
		);
		update_option( self::OPTION_KEY, $config );
		return rest_ensure_response(
			array(
				'success' => true,
				'config'  => $config,
			)
		);
	}

	/**
	 * Bulk compress unprocessed images. Processes up to $batchSize per request
	 * so the admin UI can call this repeatedly (with progress tracking).
	 */
	public function bulkCompress( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params    = $request->get_json_params();
		$batchSize = min( 50, max( 1, (int) ( $params['batch_size'] ?? 20 ) ) );
		$config    = $this->getConfig();

		if ( ! $config['enabled'] ) {
			return new \WP_Error( 'compression_disabled', __( 'Enable and confirm reversible image compression before starting a bulk run.', 'holvek' ), array( 'status' => 400 ) );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => $batchSize,
				'meta_query'     => array(
					array(
						'key'     => self::PROCESSED_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$processed = 0;
		$failed    = 0;

		foreach ( $query->posts as $post ) {
			$file = get_attached_file( $post->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$mime = get_post_mime_type( $post->ID );
			if ( ! $mime ) {
				continue;
			}

			$ok = $this->processAttachment( $post->ID, $file, $mime, $config );
			update_post_meta( $post->ID, self::PROCESSED_META_KEY, $ok ? 'yes' : 'failed' );

			if ( $ok ) {
				++$processed;
			} else {
				++$failed;
			}
		}

		// Count remaining unprocessed
		$remaining = ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => self::PROCESSED_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		) )->found_posts;

		return rest_ensure_response(
			array(
				'success'   => true,
				'processed' => $processed,
				'failed'    => $failed,
				'remaining' => $remaining,
				'done'      => $remaining === 0,
			)
		);
	}

	public function getStats(): \WP_REST_Response {
		$total = ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 1,
			)
		) )->found_posts;

		$compressed = ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => self::PROCESSED_META_KEY,
						'value' => 'yes',
					),
				),
			)
		) )->found_posts;

		return rest_ensure_response(
			array(
				'total'          => $total,
				'compressed'     => $compressed,
				'uncompressed'   => max( 0, $total - $compressed ),
				'engine'         => extension_loaded( 'imagick' ) ? 'imagick' : ( extension_loaded( 'gd' ) ? 'gd' : 'none' ),
				'webp_supported' => extension_loaded( 'imagick' ) || function_exists( 'imagewebp' ),
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getConfig(): array {
		$defaults           = array(
			'enabled'  => false,
			'quality'  => 82,
			'webp'     => false,
			'preserve' => true,
		);
		$saved              = get_option( self::OPTION_KEY, array() );
		$config             = array_merge( $defaults, is_array( $saved ) ? $saved : array() );
		$config['enabled']  = ! empty( $config['enabled'] );
		$config['webp']     = false;
		$config['preserve'] = true;
		return $config;
	}

	private function processAttachment( int $attachmentId, string $file, string $mime, array $config ): bool {
		if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
			return false;
		}

		$originalId = $this->retainOriginalAttachment( $attachmentId, $file, $mime );
		if ( $originalId <= 0 ) {
			return false;
		}

		if ( $this->processFile( $file, $mime, $config ) ) {
			return true;
		}

		$originalFile = get_attached_file( $originalId );
		if ( is_string( $originalFile ) && is_readable( $originalFile ) ) {
			copy( $originalFile, $file );
		}
		return false;
	}

	/**
	 * Keep the uncompressed bytes as their own media-library attachment.
	 *
	 * A sidecar path is not enough. Uninstall of a backup directory must
	 * not be the only copy of the original.
	 */
	public function retainOriginalAttachment( int $attachmentId, string $file, string $mime ): int {
		$existing = (int) get_post_meta( $attachmentId, '_holvek_original_attachment_id', true );
		if ( $existing > 0 ) {
			$kept = get_attached_file( $existing );
			if ( get_post( $existing ) && is_string( $kept ) && is_readable( $kept ) ) {
				return $existing;
			}
		}

		if ( ! is_readable( $file ) ) {
			return 0;
		}

		$info = pathinfo( $file );
		$dir  = (string) ( $info['dirname'] ?? '' );
		$base = (string) ( $info['filename'] ?? 'image' );
		$ext  = strtolower( (string) ( $info['extension'] ?? 'jpg' ) );
		if ( '' === $dir ) {
			return 0;
		}

		$dest = $dir . '/' . $base . '-holvek-original.' . $ext;
		$try  = 2;
		while ( file_exists( $dest ) ) {
			$dest = $dir . '/' . $base . '-holvek-original-' . $try . '.' . $ext;
			++$try;
			if ( $try > 50 ) {
				return 0;
			}
		}

		if ( ! copy( $file, $dest ) ) {
			return 0;
		}

		$inserted = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( basename( $dest ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$dest,
			0,
			true
		);

		if ( is_wp_error( $inserted ) || (int) $inserted <= 0 ) {
			wp_delete_file( $dest );
			return 0;
		}

		$originalId = (int) $inserted;
		update_attached_file( $originalId, $dest );
		update_post_meta( $attachmentId, '_holvek_original_attachment_id', $originalId );
		update_post_meta( $originalId, '_holvek_original_of', $attachmentId );
		update_post_meta( $attachmentId, self::ORIGINAL_META_KEY, (string) $originalId );

		return $originalId;
	}

	public function restoreAttachment( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$attachmentId = (int) $request->get_param( 'attachment_id' );

		if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
			return new \WP_Error( 'unauthorized', __( 'You do not have permission to edit this attachment.', 'holvek' ), array( 'status' => 403 ) );
		}

		$file       = get_attached_file( $attachmentId );
		$originalId = (int) get_post_meta( $attachmentId, '_holvek_original_attachment_id', true );
		$backup     = $originalId > 0 ? get_attached_file( $originalId ) : '';
		if ( ! is_string( $backup ) || '' === $backup || ! is_readable( $backup ) ) {
			$legacy = (string) get_post_meta( $attachmentId, self::ORIGINAL_META_KEY, true );
			if ( '' !== $legacy && ! ctype_digit( $legacy ) && is_readable( $legacy ) ) {
				$backup = $legacy;
			}
		}

		if ( ! is_string( $file ) || '' === $file || ! is_string( $backup ) || '' === $backup || ! is_readable( $backup ) || ! copy( $backup, $file ) ) {
			return new \WP_Error( 'restore_failed', __( 'No restorable image backup is available for this attachment.', 'holvek' ), array( 'status' => 404 ) );
		}

		delete_post_meta( $attachmentId, self::PROCESSED_META_KEY );

		return rest_ensure_response(
			array(
				'success'       => true,
				'attachment_id' => $attachmentId,
				'original_id'   => $originalId,
			)
		);
	}

	public function getBackups( \WP_REST_Request $request ): \WP_REST_Response {
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$query   = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $perPage,
				'paged'          => $page,
				'meta_query'     => array(
					array(
						'key'     => self::ORIGINAL_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$items = array_map(
			static function ( \WP_Post $attachment ): array {
				return array(
					'attachment_id' => $attachment->ID,
					'title'         => get_the_title( $attachment ),
					'url'           => wp_get_attachment_url( $attachment->ID ),
				);
			},
			$query->posts
		);

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}
}
