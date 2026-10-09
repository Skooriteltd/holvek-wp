<?php

namespace Holvek\WordPress\Modules\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-detects installed SEO plugins and migrates their data to Holvek.
 * Supports: Yoast SEO, RankMath, All in One SEO (AIOSEO).
 *
 * Philosophy: One click, zero data loss.
 */
class MigrationManager {

	/**
	 * Meta key mapping from competitor plugins to Holvek.
	 */
	private const META_MAP = array(
		'yoast'    => array(
			'_yoast_wpseo_title'                 => '_holvek_meta_title',
			'_yoast_wpseo_metadesc'              => '_holvek_meta_description',
			'_yoast_wpseo_focuskw'               => '_holvek_focus_keyword',
			'_yoast_wpseo_opengraph-title'       => '_holvek_og_title',
			'_yoast_wpseo_opengraph-description' => '_holvek_og_description',
			'_yoast_wpseo_opengraph-image'       => '_holvek_og_image',
			'_yoast_wpseo_twitter-title'         => '_holvek_twitter_title',
			'_yoast_wpseo_meta-robots-noindex'   => '_holvek_noindex',
			'_yoast_wpseo_meta-robots-nofollow'  => '_holvek_nofollow',
		),
		'rankmath' => array(
			'rank_math_title'                => '_holvek_meta_title',
			'rank_math_description'          => '_holvek_meta_description',
			'rank_math_focus_keyword'        => '_holvek_focus_keyword',
			'rank_math_facebook_title'       => '_holvek_og_title',
			'rank_math_facebook_description' => '_holvek_og_description',
			'rank_math_facebook_image'       => '_holvek_og_image',
			'rank_math_twitter_title'        => '_holvek_twitter_title',
			'rank_math_robots'               => '_holvek_noindex',
		),
		'aioseo'   => array(
			'_aioseo_title'          => '_holvek_meta_title',
			'_aioseo_description'    => '_holvek_meta_description',
			'_aioseo_keywords'       => '_holvek_focus_keyword',
			'_aioseo_og_title'       => '_holvek_og_title',
			'_aioseo_og_description' => '_holvek_og_description',
			'_aioseo_og_image'       => '_holvek_og_image',
			'_aioseo_twitter_title'  => '_holvek_twitter_title',
		),
	);

	/**
	 * Detect which SEO plugins are currently active or have left data behind.
	 */
	public function detectPlugins(): array {
		global $wpdb;
		$detected = array();

		// Yoast SEO
		$yoastCount = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_yoast_wpseo_title' AND meta_value != ''"
		);
		if ( $yoastCount > 0 || defined( 'WPSEO_VERSION' ) ) {
			$detected[] = array(
				'id'     => 'yoast',
				'name'   => 'Yoast SEO',
				'posts'  => $yoastCount,
				'active' => defined( 'WPSEO_VERSION' ),
			);
		}

		// RankMath
		$rmCount = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_title' AND meta_value != ''"
		);
		if ( $rmCount > 0 || defined( 'RANK_MATH_VERSION' ) ) {
			$detected[] = array(
				'id'     => 'rankmath',
				'name'   => 'Rank Math',
				'posts'  => $rmCount,
				'active' => defined( 'RANK_MATH_VERSION' ),
			);
		}

		// AIOSEO
		$aioCount = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_aioseo_title' AND meta_value != ''"
		);
		if ( $aioCount > 0 || defined( 'AIOSEO_VERSION' ) ) {
			$detected[] = array(
				'id'     => 'aioseo',
				'name'   => 'All in One SEO',
				'posts'  => $aioCount,
				'active' => defined( 'AIOSEO_VERSION' ),
			);
		}

		return $detected;
	}

	private const BATCH_SIZE = 50;

	/**
	 * Count fields that would be copied. This does not write.
	 *
	 * @return array<string, mixed>
	 */
	public function preview( string $pluginId ): array {
		if ( ! isset( self::META_MAP[ $pluginId ] ) ) {
			return array(
				'success' => false,
				'wrote'   => false,
				'message' => __( 'Unknown plugin.', 'holvek' ),
			);
		}

		global $wpdb;
		$fields  = 0;
		$skipped = 0;

		foreach ( self::META_MAP[ $pluginId ] as $sourceKey => $targetKey ) {
			$total    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
					$sourceKey
				)
			);
			$already  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} AS source_meta INNER JOIN {$wpdb->postmeta} AS target_meta ON source_meta.post_id = target_meta.post_id WHERE source_meta.meta_key = %s AND source_meta.meta_value != '' AND target_meta.meta_key = %s AND target_meta.meta_value != ''",
					$sourceKey,
					$targetKey
				)
			);
			$fields  += $total;
			$skipped += min( $already, $total );
		}

		$copy = max( 0, $fields - $skipped );

		return array(
			'success' => true,
			'wrote'   => false,
			'fields'  => $fields,
			'copy'    => $copy,
			'skipped' => $skipped,
			'message' => $this->previewMessage( $copy, $skipped, $pluginId ),
		);
	}

	/**
	 * Copy one plugin's meta in batches of 50.
	 *
	 * An existing non-empty `_holvek_*` value is left unchanged.
	 *
	 * @return array<string, mixed>
	 */
	public function migrate( string $pluginId ): array {
		if ( ! isset( self::META_MAP[ $pluginId ] ) ) {
			return array(
				'success' => false,
				'wrote'   => false,
				'message' => __( 'Unknown plugin.', 'holvek' ),
			);
		}

		global $wpdb;
		$map      = self::META_MAP[ $pluginId ];
		$migrated = 0;
		$skipped  = 0;

		foreach ( $map as $sourceKey => $targetKey ) {
			$offset = 0;

			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT %d OFFSET %d",
						$sourceKey,
						self::BATCH_SIZE,
						$offset
					)
				);

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$postId = (int) $row->post_id;
					$value  = (string) $row->meta_value;

					if ( 'rank_math_robots' === $sourceKey ) {
						$this->writeRankMathRobots( $postId, $value, $migrated, $skipped );
						continue;
					}

					if ( in_array( $targetKey, array( '_holvek_noindex', '_holvek_nofollow' ), true ) && 'yoast' === $pluginId ) {
						$value = ( '1' === $value ) ? 'yes' : 'no';
					}

					if ( '_holvek_focus_keyword' === $targetKey && in_array( $pluginId, array( 'rankmath', 'aioseo' ), true ) ) {
						$value = $this->firstKeyword( $value );
					}

					$this->writeIfEmpty( $postId, $targetKey, sanitize_text_field( $value ), $migrated, $skipped );
				}

				$rowCount = count( $rows );
				$offset  += self::BATCH_SIZE;
			} while ( $rowCount === self::BATCH_SIZE );
		}

		return array(
			'success'    => true,
			'wrote'      => true,
			'migrated'   => $migrated,
			'skipped'    => $skipped,
			'batch_size' => self::BATCH_SIZE,
			'message'    => $this->migrateMessage( $migrated, $skipped, $pluginId ),
		);
	}

	/**
	 * @param mixed $value Stored meta.
	 * @return mixed
	 */
	private function decodeStored( string $value ) {
		$decoded = maybe_unserialize( $value );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( is_string( $decoded ) && str_starts_with( ltrim( $decoded ), '[' ) ) {
			$json = json_decode( $decoded, true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}
		return $decoded;
	}

	private function firstKeyword( string $value ): string {
		$decoded = $this->decodeStored( $value );
		if ( is_array( $decoded ) ) {
			$first = reset( $decoded );
			$value = is_scalar( $first ) ? (string) $first : '';
		}
		$parts = preg_split( '/\s*,\s*/', $value );
		return trim( (string) ( $parts[0] ?? '' ) );
	}

	/**
	 * Rank Math stores robots as a serialized list or a comma-separated string.
	 *
	 * @param int $migrated Updated by reference.
	 * @param int $skipped  Updated by reference.
	 */
	private function writeRankMathRobots( int $postId, string $value, int &$migrated, int &$skipped ): void {
		$decoded = $this->decodeStored( $value );
		if ( is_array( $decoded ) ) {
			$tokens = $decoded;
		} else {
			$tokens = preg_split( '/\s*,\s*/', (string) $decoded ) ?: array();
		}
		$tokens = array_map(
			static function ( $token ): string {
				return strtolower( trim( (string) $token ) );
			},
			$tokens
		);

		$this->writeIfEmpty( $postId, '_holvek_noindex', in_array( 'noindex', $tokens, true ) ? 'yes' : 'no', $migrated, $skipped );
		if ( in_array( 'nofollow', $tokens, true ) ) {
			$this->writeIfEmpty( $postId, '_holvek_nofollow', 'yes', $migrated, $skipped );
		}
	}

	/**
	 * @param int $migrated Updated by reference.
	 * @param int $skipped  Updated by reference.
	 */
	private function writeIfEmpty( int $postId, string $targetKey, string $value, int &$migrated, int &$skipped ): void {
		$existing = get_post_meta( $postId, $targetKey, true );
		if ( ! empty( $existing ) ) {
			++$skipped;
			return;
		}
		if ( '' === $value ) {
			return;
		}
		update_post_meta( $postId, $targetKey, $value );
		++$migrated;
	}

	private function pluginLabel( string $pluginId ): string {
		if ( 'yoast' === $pluginId ) {
			return 'Yoast SEO';
		}
		if ( 'rankmath' === $pluginId ) {
			return 'Rank Math';
		}
		if ( 'aioseo' === $pluginId ) {
			return 'All in One SEO';
		}
		return '';
	}

	private function previewMessage( int $copy, int $skipped, string $pluginId ): string {
		return sprintf(
			/* translators: 1: number of fields to copy, 2: number skipped, 3: the other SEO plugin name. */
			__( 'Will copy %1$d fields. %2$d already have Holvek data and will be skipped. Deactivate %3$s after import so both plugins do not print titles and schema.', 'holvek' ),
			$copy,
			$skipped,
			$this->pluginLabel( $pluginId )
		);
	}

	private function migrateMessage( int $migrated, int $skipped, string $pluginId ): string {
		return sprintf(
			/* translators: 1: number of fields copied, 2: number skipped, 3: the other SEO plugin name. */
			__( 'Migrated %1$d meta entries. Skipped %2$d that already had Holvek data. Deactivate %3$s after import so both plugins do not print titles and schema.', 'holvek' ),
			$migrated,
			$skipped,
			$this->pluginLabel( $pluginId )
		);
	}

	/**
	 * Register REST routes for migration.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/migration/detect',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handleDetect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ); },
			)
		);

		register_rest_route(
			'holvek/v1',
			'/migration/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handleMigrate' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ); },
			)
		);
	}

	public function handleDetect( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->detectPlugins() );
	}

	public function handleMigrate( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$pluginId = sanitize_text_field( (string) ( $params['plugin_id'] ?? $request->get_param( 'plugin_id' ) ?? '' ) );
		$confirm  = ! empty( $params['confirm'] ) || true === $request->get_param( 'confirm' );

		if ( ! $confirm ) {
			return rest_ensure_response( $this->preview( $pluginId ) );
		}

		return rest_ensure_response( $this->migrate( $pluginId ) );
	}
}
