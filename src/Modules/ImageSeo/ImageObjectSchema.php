<?php

namespace Holvek\WordPress\Modules\ImageSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Modules\Schema\JsonLd;

/**
 * ImageObjectSchema
 *
 * Outputs ImageObject JSON-LD for all images on singular posts/pages.
 * Featured image + content images are collected, deduplicated by URL,
 * and serialised into schema.org structured data.
 *
 * BL-003
 */
class ImageObjectSchema {

	private const ENABLED_OPTION = 'holvek_image_schema_enabled';
	private const LICENSE_OPTION = 'holvek_image_license_url';

	public function register(): void {
		add_action( 'wp_footer', array( $this, 'outputImageSchema' ), 15 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	// ── Schema output ─────────────────────────────────────────────────────────

	public function outputImageSchema(): void {
		if ( ! is_singular() ) {
			return;
		}

		if ( get_option( self::ENABLED_OPTION, 'yes' ) === 'no' ) {
			return;
		}

		$images = $this->collectImages( get_the_ID() );

		if ( empty( $images ) ) {
			return;
		}

		$licenseUrl = get_option( self::LICENSE_OPTION, home_url( '/' ) );

		$objects = array();
		foreach ( $images as $img ) {
			$obj = array_filter(
				array(
					'@context'           => 'https://schema.org',
					'@type'              => 'ImageObject',
					'url'                => $img['url'],
					'contentUrl'         => $img['url'],
					'width'              => $img['width'] ?: null,
					'height'             => $img['height'] ?: null,
					'name'               => $img['alt'] ?: get_the_title(),
					'caption'            => $img['caption'] ?: null,
					'creditText'         => get_bloginfo( 'name' ),
					'acquireLicensePage' => $licenseUrl ?: null,
				)
			);

			$objects[] = $obj;
		}

		if ( empty( $objects ) ) {
			return;
		}

		$schema = ( count( $objects ) === 1 )
			? $objects[0]
			: array(
				'@context' => 'https://schema.org',
				'@graph'   => $objects,
			);

		// Remove @context from individual objects when using @graph
		if ( isset( $schema['@graph'] ) ) {
			foreach ( $schema['@graph'] as &$item ) {
				unset( $item['@context'] );
			}
			unset( $item );
		}

		JsonLd::printScript( $schema );
	}

	// ── Image collection ──────────────────────────────────────────────────────

	/**
	 * Collect all images for a post: featured image + content images.
	 * Returns array of { url, width, height, alt, caption }.
	 */
	private function collectImages( int $postId ): array {
		$seen   = array();
		$images = array();

		// 1. Featured image
		$thumbId = get_post_thumbnail_id( $postId );
		if ( $thumbId ) {
			$src = wp_get_attachment_image_src( $thumbId, 'full' );
			if ( $src && ! empty( $src[0] ) ) {
				$url = $src[0];
				if ( ! isset( $seen[ $url ] ) ) {
					$seen[ $url ] = true;
					$images[]     = array(
						'url'     => $url,
						'width'   => (int) ( $src[1] ?? 0 ),
						'height'  => (int) ( $src[2] ?? 0 ),
						'alt'     => (string) get_post_meta( $thumbId, '_wp_attachment_image_alt', true ),
						'caption' => wp_get_attachment_caption( $thumbId ),
					);
				}
			}
		}

		// 2. Content images (wp-image-{id} class pattern)
		$content = get_post_field( 'post_content', $postId );
		if ( empty( $content ) ) {
			return $images;
		}

		// Extract attachment IDs from class="wp-image-123"
		preg_match_all( '/class=["\'][^"\']*wp-image-(\d+)[^"\']*["\']/', $content, $idMatches );
		$attachmentIds = array_unique( array_map( 'intval', $idMatches[1] ?? array() ) );

		foreach ( $attachmentIds as $attId ) {
			$src = wp_get_attachment_image_src( $attId, 'full' );
			if ( ! $src || empty( $src[0] ) ) {
				continue;
			}
			$url = $src[0];
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$images[]     = array(
				'url'     => $url,
				'width'   => (int) ( $src[1] ?? 0 ),
				'height'  => (int) ( $src[2] ?? 0 ),
				'alt'     => (string) get_post_meta( $attId, '_wp_attachment_image_alt', true ),
				'caption' => wp_get_attachment_caption( $attId ),
			);
		}

		// Also capture <img src="..."> without wp-image class (external or non-library images)
		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $srcMatches );
		foreach ( $srcMatches[1] ?? array() as $rawSrc ) {
			$url = esc_url_raw( $rawSrc );
			if ( empty( $url ) || isset( $seen[ $url ] ) ) {
				continue;
			}
			// Only include images hosted on this site
			if ( strpos( $url, home_url() ) !== 0 ) {
				continue;
			}
			$seen[ $url ] = true;

			// Try to extract alt from the same <img> tag
			preg_match( '/alt=["\']([^"\']*)["\']/', $srcMatches[0][ array_search( $rawSrc, $srcMatches[1], true ) ], $altMatch );

			$images[] = array(
				'url'     => $url,
				'width'   => 0,
				'height'  => 0,
				'alt'     => $altMatch[1] ?? '',
				'caption' => '',
			);
		}

		return $images;
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/image-schema',
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
	}

	public function getSettings( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'enabled'     => get_option( self::ENABLED_OPTION, 'yes' ) === 'yes',
				'license_url' => get_option( self::LICENSE_OPTION, home_url( '/' ) ),
			)
		);
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();

		if ( isset( $params['enabled'] ) ) {
			update_option( self::ENABLED_OPTION, $params['enabled'] ? 'yes' : 'no' );
		}

		if ( isset( $params['license_url'] ) ) {
			update_option( self::LICENSE_OPTION, esc_url_raw( $params['license_url'] ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
