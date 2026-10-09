<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SitemapPriorityManager
 *
 * BL-015: Per-post-type sitemap priority and changefreq controls.
 * Also supports per-URL override via post meta.
 */
class SitemapPriorityManager {

	private const OPTION_KEY = 'holvek_sitemap_priority_settings';

	// Defaults per post type
	private const DEFAULTS = array(
		'page'       => array(
			'priority'   => '0.8',
			'changefreq' => 'monthly',
		),
		'post'       => array(
			'priority'   => '0.6',
			'changefreq' => 'weekly',
		),
		'attachment' => array(
			'priority'   => '0.3',
			'changefreq' => 'yearly',
		),
	);

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/sitemap/priority-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		// Per-URL override (via post meta)
		register_rest_route(
			'holvek/v1',
			'/sitemap/url-override/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getUrlOverride' ),
					'permission_callback' => fn( \WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveUrlOverride' ),
					'permission_callback' => fn( \WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) ),
				),
			)
		);
	}

	public function getSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$saved     = (array) get_option( self::OPTION_KEY, array() );
		$postTypes = get_post_types( array( 'public' => true ), 'objects' );
		$result    = array();

		foreach ( $postTypes as $type ) {
			$slug            = $type->name;
			$default         = self::DEFAULTS[ $slug ] ?? array(
				'priority'   => '0.5',
				'changefreq' => 'weekly',
			);
			$result[ $slug ] = array(
				'label'      => $type->label,
				'priority'   => $saved[ $slug ]['priority'] ?? $default['priority'],
				'changefreq' => $saved[ $slug ]['changefreq'] ?? $default['changefreq'],
				'enabled'    => (bool) ( $saved[ $slug ]['enabled'] ?? true ),
			);
		}

		return rest_ensure_response( $result );
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = $request->get_json_params();
		$valid    = array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );
		$settings = array();

		foreach ( $params as $postType => $config ) {
			$postType   = sanitize_key( $postType );
			$priority   = (float) ( $config['priority'] ?? 0.5 );
			$priority   = max( 0.0, min( 1.0, $priority ) );
			$changefreq = in_array( $config['changefreq'] ?? 'weekly', $valid, true ) ? $config['changefreq'] : 'weekly';

			$settings[ $postType ] = array(
				'priority'   => number_format( $priority, 1 ),
				'changefreq' => $changefreq,
				'enabled'    => (bool) ( $config['enabled'] ?? true ),
			);
		}

		update_option( self::OPTION_KEY, $settings );

		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $settings,
			)
		);
	}

	public function getUrlOverride( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'post_id' );
		return rest_ensure_response(
			array(
				'post_id'    => $postId,
				'priority'   => get_post_meta( $postId, '_holvek_sitemap_priority', true ) ?: null,
				'changefreq' => get_post_meta( $postId, '_holvek_sitemap_changefreq', true ) ?: null,
				'excluded'   => get_post_meta( $postId, '_holvek_sitemap_exclude', true ) === 'yes',
			)
		);
	}

	public function saveUrlOverride( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'post_id' );
		$params = $request->get_json_params();
		$valid  = array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );

		if ( array_key_exists( 'priority', $params ) ) {
			$priority = max( 0.0, min( 1.0, (float) $params['priority'] ) );
			update_post_meta( $postId, '_holvek_sitemap_priority', number_format( $priority, 1 ) );
		}

		if ( array_key_exists( 'changefreq', $params ) ) {
			$cf = in_array( $params['changefreq'], $valid, true ) ? $params['changefreq'] : 'weekly';
			update_post_meta( $postId, '_holvek_sitemap_changefreq', $cf );
		}

		if ( array_key_exists( 'excluded', $params ) ) {
			update_post_meta( $postId, '_holvek_sitemap_exclude', $params['excluded'] ? 'yes' : 'no' );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'post_id' => $postId,
			)
		);
	}

	// ── Static helpers for SitemapGenerator ──────────────────────────────────

	/**
	 * Get the priority for a given post. Per-URL > per-post-type > global default.
	 */
	public static function getPriority( int $postId, string $postType ): string {
		$perUrl = (string) get_post_meta( $postId, '_holvek_sitemap_priority', true );
		if ( $perUrl !== '' ) {
			return $perUrl;
		}

		$settings = (array) get_option( self::OPTION_KEY, array() );
		$defaults = self::DEFAULTS[ $postType ] ?? array( 'priority' => '0.5' );
		return $settings[ $postType ]['priority'] ?? $defaults['priority'];
	}

	/**
	 * Get the changefreq for a given post. Per-URL > per-post-type > global default.
	 */
	public static function getChangefreq( int $postId, string $postType ): string {
		$perUrl = (string) get_post_meta( $postId, '_holvek_sitemap_changefreq', true );
		if ( $perUrl !== '' ) {
			return $perUrl;
		}

		$settings = (array) get_option( self::OPTION_KEY, array() );
		$defaults = self::DEFAULTS[ $postType ] ?? array( 'changefreq' => 'weekly' );
		return $settings[ $postType ]['changefreq'] ?? $defaults['changefreq'];
	}

	/**
	 * Returns true if this post should be excluded from the sitemap.
	 */
	public static function isExcluded( int $postId, string $postType ): bool {
		if ( get_post_meta( $postId, '_holvek_sitemap_exclude', true ) === 'yes' ) {
			return true;
		}
		$settings = (array) get_option( self::OPTION_KEY, array() );
		return isset( $settings[ $postType ]['enabled'] ) && $settings[ $postType ]['enabled'] === false;
	}
}
