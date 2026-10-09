<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom404Handler: Lets admins select a WordPress page to serve as the 404 template.
 *
 * Intercepts template_include when is_404() is true, loads the selected page content,
 * and ensures the HTTP status header remains 404 for correct SEO behaviour.
 */
class Custom404Handler {

	private const OPTION_PAGE_ID = 'holvek_custom_404_page_id';

	public function register(): void {
		add_filter( 'template_include', array( $this, 'maybeServeCustom404' ), 99 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Replace the template with the custom 404 page template when configured.
	 */
	public function maybeServeCustom404( string $template ): string {
		if ( ! is_404() ) {
			return $template;
		}

		$pageId = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $pageId <= 0 ) {
			return $template;
		}

		$page = get_post( $pageId );
		if ( ! $page || $page->post_status !== 'publish' ) {
			return $template;
		}

		// Make the custom page available in The Loop
		global $wp_query;
		$wp_query->queried_object    = $page;
		$wp_query->queried_object_id = $pageId;

		// Preserve HTTP 404 status — required for correct SEO signals
		status_header( 404 );
		nocache_headers();

		// Resolve the page template from the theme
		$pageTemplate = get_page_template_slug( $pageId );
		if ( $pageTemplate && locate_template( $pageTemplate ) ) {
			return locate_template( $pageTemplate );
		}

		$located = locate_template( array( 'page.php', 'singular.php', 'index.php' ) );
		return ! empty( $located ) ? $located : $template;
	}

	// ── REST API ─────────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/404-page',
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
		$pageId = (int) get_option( self::OPTION_PAGE_ID, 0 );

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$pageList = array_map(
			fn( $p ) => array(
				'id'    => $p->ID,
				'title' => $p->post_title,
				'url'   => get_permalink( $p->ID ),
			),
			$pages
		);

		return rest_ensure_response(
			array(
				'page_id' => $pageId,
				'pages'   => $pageList,
			)
		);
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$pageId = isset( $params['page_id'] ) ? absint( $params['page_id'] ) : 0;
		update_option( self::OPTION_PAGE_ID, $pageId );
		return rest_ensure_response(
			array(
				'success' => true,
				'page_id' => $pageId,
			)
		);
	}
}
