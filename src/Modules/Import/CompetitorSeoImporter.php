<?php

namespace Holvek\WordPress\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CompetitorSeoImporter
 *
 * BL-044: Imports SEO settings from Yoast SEO and All-in-One SEO Pack
 * into Holvek meta keys. One-time migration with dry-run support.
 */
class CompetitorSeoImporter {

	private const YOAST_TITLE     = '_yoast_wpseo_title';
	private const YOAST_DESC      = '_yoast_wpseo_metadesc';
	private const YOAST_KW        = '_yoast_wpseo_focuskw';
	private const YOAST_NOINDEX   = '_yoast_wpseo_meta-robots-noindex';
	private const YOAST_CANONICAL = '_yoast_wpseo_canonical';

	private const AIOSEO_TITLE  = '_aioseo_title';
	private const AIOSEO_DESC   = '_aioseo_description';
	private const AIOSEO_KW     = '_aioseo_keywords';
	private const AIOSEO_ROBOTS = '_aioseo_robots_default';

	private const RANKMATH_TITLE     = 'rank_math_title';
	private const RANKMATH_DESC      = 'rank_math_description';
	private const RANKMATH_KW        = 'rank_math_focus_keyword';
	private const RANKMATH_CANONICAL = 'rank_math_canonical_url';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/import/detect',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'detectInstalledPlugins' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/import/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'previewImport' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/import/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'runImport' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	public function detectInstalledPlugins( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$yoastData  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::YOAST_TITLE )
		);
		$aioseoData = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::AIOSEO_TITLE )
		);

				$rankMathData = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::RANKMATH_TITLE )
				);

		return rest_ensure_response(
			array(
				'yoast_seo'      => array(
					'detected'   => $yoastData > 0,
					'post_count' => $yoastData,
				),
				'all_in_one_seo' => array(
					'detected'   => $aioseoData > 0,
					'post_count' => $aioseoData,
				),
				'rank_math'      => array(
					'detected'   => $rankMathData > 0,
					'post_count' => $rankMathData,
				),
			)
		);
	}

	public function previewImport( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$source = sanitize_key( $params['source'] ?? 'yoast' );
		$limit  = min( 50, max( 1, (int) ( $params['limit'] ?? 10 ) ) );

		$results = $this->doImport( $source, $limit, true );
		return rest_ensure_response(
			array(
				'source'  => $source,
				'preview' => $results,
			)
		);
	}

	public function runImport( \WP_REST_Request $request ): \WP_REST_Response {
		$params    = $request->get_json_params();
		$source    = sanitize_key( $params['source'] ?? 'yoast' );
		$overwrite = (bool) ( $params['overwrite'] ?? false );

		$results = $this->doImport( $source, 5000, false, $overwrite );

		return rest_ensure_response(
			array(
				'success'  => true,
				'source'   => $source,
				'imported' => $results['imported'],
				'skipped'  => $results['skipped'],
				'details'  => array_slice( $results['details'], 0, 20 ),
			)
		);
	}

	private function doImport( string $source, int $limit, bool $dryRun = false, bool $overwrite = false ): array {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		$details  = array();

		if ( $source === 'yoast' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key IN (%s, %s, %s, %s, %s)
                     ORDER BY post_id LIMIT %d",
					self::YOAST_TITLE,
					self::YOAST_DESC,
					self::YOAST_KW,
					self::YOAST_NOINDEX,
					self::YOAST_CANONICAL,
					$limit * 5
				)
			);

			$byPost = array();
			foreach ( $rows as $row ) {
				$byPost[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}

			foreach ( array_slice( $byPost, 0, $limit, true ) as $postId => $meta ) {
				$detail = array(
					'post_id' => $postId,
					'fields'  => array(),
				);

				$map = array(
					self::YOAST_TITLE     => '_holvek_meta_title',
					self::YOAST_DESC      => '_holvek_meta_description',
					self::YOAST_KW        => '_holvek_focus_keyword',
					self::YOAST_CANONICAL => '_holvek_canonical_url',
				);

				foreach ( $map as $fromKey => $toKey ) {
					if ( ! isset( $meta[ $fromKey ] ) || empty( $meta[ $fromKey ] ) ) {
						continue;
					}
					$existing = get_post_meta( $postId, $toKey, true );
					if ( ! empty( $existing ) && ! $overwrite ) {
						++$skipped;
						continue;
					}
					if ( ! $dryRun ) {
						update_post_meta( $postId, $toKey, $meta[ $fromKey ] );
					}
					$detail['fields'][] = $toKey;
					++$imported;
				}

				// noindex
				if ( isset( $meta[ self::YOAST_NOINDEX ] ) && $meta[ self::YOAST_NOINDEX ] === '1' ) {
					if ( ! $dryRun ) {
						update_post_meta( $postId, '_holvek_noindex', 'yes' );
					}
					$detail['fields'][] = '_holvek_noindex';
				}

				$details[] = $detail;
			}
		} elseif ( $source === 'aioseo' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key IN (%s, %s, %s)
                     ORDER BY post_id LIMIT %d",
					self::AIOSEO_TITLE,
					self::AIOSEO_DESC,
					self::AIOSEO_KW,
					$limit * 3
				)
			);

			$byPost = array();
			foreach ( $rows as $row ) {
				$byPost[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}

			$map = array(
				self::AIOSEO_TITLE => '_holvek_meta_title',
				self::AIOSEO_DESC  => '_holvek_meta_description',
				self::AIOSEO_KW    => '_holvek_focus_keyword',
			);

			foreach ( array_slice( $byPost, 0, $limit, true ) as $postId => $meta ) {
				$detail = array(
					'post_id' => $postId,
					'fields'  => array(),
				);

				foreach ( $map as $fromKey => $toKey ) {
					if ( empty( $meta[ $fromKey ] ) ) {
						continue;
					}
					$existing = get_post_meta( $postId, $toKey, true );
					if ( ! empty( $existing ) && ! $overwrite ) {
						++$skipped;
						continue;
					}
					if ( ! $dryRun ) {
						update_post_meta( $postId, $toKey, $meta[ $fromKey ] );
					}
					$detail['fields'][] = $toKey;
					++$imported;
				}

				$details[] = $detail;
			}
		} elseif ( $source === 'rankmath' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key IN (%s, %s, %s, %s)
                     ORDER BY post_id LIMIT %d",
					self::RANKMATH_TITLE,
					self::RANKMATH_DESC,
					self::RANKMATH_KW,
					self::RANKMATH_CANONICAL,
					$limit * 4
				)
			);

			$byPost = array();
			foreach ( $rows as $row ) {
				$byPost[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}

			$map = array(
				self::RANKMATH_TITLE     => '_holvek_meta_title',
				self::RANKMATH_DESC      => '_holvek_meta_description',
				self::RANKMATH_KW        => '_holvek_focus_keyword',
				self::RANKMATH_CANONICAL => '_holvek_canonical_url',
			);

			foreach ( array_slice( $byPost, 0, $limit, true ) as $postId => $meta ) {
				$detail = array(
					'post_id' => $postId,
					'fields'  => array(),
				);

				foreach ( $map as $fromKey => $toKey ) {
					if ( empty( $meta[ $fromKey ] ) ) {
						continue;
					}
					$existing = get_post_meta( $postId, $toKey, true );
					if ( ! empty( $existing ) && ! $overwrite ) {
						++$skipped;
						continue;
					}
					if ( ! $dryRun ) {
						update_post_meta( $postId, $toKey, $meta[ $fromKey ] );
					}
					$detail['fields'][] = $toKey;
					++$imported;
				}

				$details[] = $detail;
			}
		}

		return compact( 'imported', 'skipped', 'details' );
	}
}
