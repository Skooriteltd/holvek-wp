<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;

/**
 * Boots the sitemap module, registers REST endpoints for sitemaps config,
 * and attaches hooks to clear sitemap cache on post mutations.
 */
class SitemapModule {

	private EventManager $eventManager;
	private SitemapRouteManager $routeManager;

	public function __construct( EventManager $eventManager, SitemapRouteManager $routeManager ) {
		$this->eventManager = $eventManager;
		$this->routeManager = $routeManager;
	}

	public function boot(): void {
		// Core sitemaps remain enabled unless an administrator explicitly
		// enables this plugin's sitemap provider. This avoids silently
		// disrupting established SEO tooling on activation.
		$this->eventManager->addFilter( 'wp_sitemaps_enabled', array( $this, 'coreSitemapsEnabled' ) );

		// Add custom rewrite rules
		$this->eventManager->addAction( 'init', array( $this->routeManager, 'addRewriteRules' ) );

		// Register custom query vars for date-based sub-sitemap routing
		$this->eventManager->addFilter( 'query_vars', array( $this->routeManager, 'registerQueryVars' ) );

		// Handle virtual sitemap requests
		$this->eventManager->addAction( 'template_redirect', array( $this->routeManager, 'handleSitemapRequests' ), 0 );

		// Ensure rewrite rules are flushed on activation
		$this->eventManager->addAction( 'holvek_activation', array( $this->routeManager, 'flushRules' ) );

		// Clear sitemap cache when content changes
		$this->eventManager->addAction( 'save_post', array( SitemapGenerator::class, 'clearCache' ) );
		$this->eventManager->addAction( 'deleted_post', array( SitemapGenerator::class, 'clearCache' ) );
		$this->eventManager->addAction( 'transition_post_status', array( SitemapGenerator::class, 'clearCache' ) );

		$this->eventManager->addAction( 'save_post', array( VideoSitemapGenerator::class, 'clearCache' ) );
		$this->eventManager->addAction( 'deleted_post', array( VideoSitemapGenerator::class, 'clearCache' ) );
		$this->eventManager->addAction( 'transition_post_status', array( VideoSitemapGenerator::class, 'clearCache' ) );

		// Register configuration routes
		$this->eventManager->addAction( 'rest_api_init', array( $this, 'registerRoutes' ) );

		// Another SEO plugin's sitemap is a Holvek-screen warning, not a front-end hook.
		$this->eventManager->addAction( 'admin_notices', array( $this, 'renderConflictNotice' ) );
		add_action( 'wp_ajax_holvek_dismiss_sitemap_conflict', array( $this, 'dismissConflictNotice' ) );
	}

	/**
	 * SEO plugins that publish their own sitemap alongside Holvek.
	 *
	 * @return array<string, string> Plugin file => short name.
	 */
	private static function knownSitemapPlugins(): array {
		return array(
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'wordpress-seo-premium/wp-seo-premium.php'    => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math',
			'seo-by-rank-math-pro/rank-math-pro.php'      => 'Rank Math',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => 'All in One SEO',
			'wp-seopress/seopress.php'                    => 'SEOPress',
			'wp-seopress-pro/seopress-pro.php'            => 'SEOPress',
		);
	}

	/**
	 * Names of other active SEO plugins that also publish a sitemap.
	 *
	 * @return array<int, string>
	 */
	public static function conflictingSitemapPlugins(): array {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}
		if ( function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$names = array();
		foreach ( self::knownSitemapPlugins() as $file => $label ) {
			if ( in_array( $file, $active, true ) && ! in_array( $label, $names, true ) ) {
				$names[] = $label;
			}
		}

		if ( defined( 'WPSEO_VERSION' ) && ! in_array( 'Yoast SEO', $names, true ) ) {
			$names[] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) && ! in_array( 'Rank Math', $names, true ) ) {
			$names[] = 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) && ! in_array( 'All in One SEO', $names, true ) ) {
			$names[] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) && ! in_array( 'SEOPress', $names, true ) ) {
			$names[] = 'SEOPress';
		}

		$filtered = apply_filters( 'holvek_sitemap_conflicting_plugins', $names );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$clean = array();
		foreach ( $filtered as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( $name !== '' && ! in_array( $name, $clean, true ) ) {
				$clean[] = $name;
			}
		}
		return $clean;
	}

	/**
	 * Notice text when Holvek and another SEO plugin would both publish a sitemap.
	 */
	public function conflictNoticeText(): string {
		if ( SitemapSettings::get( 'enable_index_sitemap', 'no' ) !== 'yes' ) {
			return '';
		}

		$names = self::conflictingSitemapPlugins();
		if ( $names === array() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: names of the other SEO plugins. */
			__( 'Another SEO plugin is also publishing a sitemap (%s). Disable the Holvek XML sitemap or disable the sitemap in the other plugin so search engines receive one sitemap.', 'holvek' ),
			implode( ', ', $names )
		);
	}

	/**
	 * Tell an SEO manager, on a Holvek screen, to leave only one sitemap published.
	 */
	public function renderConflictNotice(): void {
		if ( ! current_user_can( 'manage_seo' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->id ) || ! str_contains( (string) $screen->id, 'holvek' ) ) {
			return;
		}

		$text = $this->conflictNoticeText();
		if ( $text === '' || get_user_meta( get_current_user_id(), 'holvek_sitemap_conflict_dismissed', true ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'holvek_dismiss_sitemap_conflict' );
		printf(
			'<div class="notice notice-warning is-dismissible holvek-sitemap-conflict-notice" data-nonce="%1$s"><p>%2$s</p></div>',
			esc_attr( $nonce ),
			esc_html( $text )
		);
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var el=document.querySelector(".holvek-sitemap-conflict-notice");if(!el){return;}el.addEventListener("click",function(e){if(!e.target.classList.contains("notice-dismiss")){return;}var body=new FormData();body.append("action","holvek_dismiss_sitemap_conflict");body.append("_ajax_nonce",el.dataset.nonce);fetch(window.ajaxurl,{method:"POST",body:body});});});</script>';
	}

	/**
	 * Keep the sitemap conflict notice dismissed for this user.
	 */
	public function dismissConflictNotice(): void {
		check_ajax_referer( 'holvek_dismiss_sitemap_conflict' );
		if ( ! current_user_can( 'manage_seo' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sorry, you are not allowed to do that.', 'holvek' ) ),
				403
			);
		}
		update_user_meta( get_current_user_id(), 'holvek_sitemap_conflict_dismissed', '1' );
		wp_send_json_success();
	}

	/**
	 * Register REST API routes for sitemap settings.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/sitemaps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSitemapSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateSitemapSettings' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);
	}

	public function checkPermission(): bool {
		return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
	}

	private function isPluginSitemapEnabled(): bool {
		return SitemapSettings::get( 'enable_index_sitemap', 'no' ) === 'yes';
	}

	public function coreSitemapsEnabled( bool $enabled ): bool {
		return $this->isPluginSitemapEnabled() ? false : $enabled;
	}

	/**
	 * Get sitemap configuration.
	 */
	public function getSitemapSettings( \WP_REST_Request $request ): \WP_REST_Response {
		// Get all public post types for checkboxes in frontend
		$allPublicTypes = get_post_types( array( 'public' => true ), 'objects' );
		$typesList      = array();
		foreach ( $allPublicTypes as $type ) {
			$typesList[] = array(
				'name'  => $type->name,
				'label' => $type->label ?: $type->name,
			);
		}

		return rest_ensure_response(
			array(
				'enable_index_sitemap'    => $this->isPluginSitemapEnabled(),
				'enable_news_sitemap'     => SitemapSettings::get( 'enable_news_sitemap', 'no' ) === 'yes',
				'enable_video_sitemap'    => SitemapSettings::get( 'enable_video_sitemap', 'no' ) === 'yes',
				'exclude_types'           => SitemapSettings::get( 'sitemap_exclude_types', array() ),
				'exclude_posts'           => SitemapSettings::get( 'sitemap_exclude_posts', '' ),
				'sitemap_changefreq_post' => SitemapSettings::get( 'sitemap_changefreq_post', 'weekly' ),
				'sitemap_changefreq_page' => SitemapSettings::get( 'sitemap_changefreq_page', 'weekly' ),
				'sitemap_priority_post'   => SitemapSettings::get( 'sitemap_priority_post', '0.6' ),
				'sitemap_priority_page'   => SitemapSettings::get( 'sitemap_priority_page', '0.8' ),
				'available_types'         => $typesList,
				'sitemap_conflict'        => $this->conflictNoticeText(),
			)
		);
	}

	/**
	 * Update sitemap configuration.
	 */
	public function updateSitemapSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();

		if ( isset( $params['enable_index_sitemap'] ) ) {
			SitemapSettings::set( 'enable_index_sitemap', $params['enable_index_sitemap'] ? 'yes' : 'no' );
		}

		if ( isset( $params['enable_news_sitemap'] ) ) {
			SitemapSettings::set( 'enable_news_sitemap', $params['enable_news_sitemap'] ? 'yes' : 'no' );
		}

		if ( isset( $params['enable_video_sitemap'] ) ) {
			SitemapSettings::set( 'enable_video_sitemap', $params['enable_video_sitemap'] ? 'yes' : 'no' );
		}

		if ( isset( $params['exclude_types'] ) && is_array( $params['exclude_types'] ) ) {
			$cleaned = array_map( 'sanitize_text_field', $params['exclude_types'] );
			SitemapSettings::set( 'sitemap_exclude_types', $cleaned );
		}

		if ( isset( $params['exclude_posts'] ) ) {
			SitemapSettings::set( 'sitemap_exclude_posts', sanitize_text_field( $params['exclude_posts'] ) );
		}

		if ( isset( $params['sitemap_changefreq_post'] ) ) {
			SitemapSettings::set( 'sitemap_changefreq_post', sanitize_text_field( $params['sitemap_changefreq_post'] ) );
		}

		if ( isset( $params['sitemap_changefreq_page'] ) ) {
			SitemapSettings::set( 'sitemap_changefreq_page', sanitize_text_field( $params['sitemap_changefreq_page'] ) );
		}

		if ( isset( $params['sitemap_priority_post'] ) ) {
			SitemapSettings::set( 'sitemap_priority_post', sanitize_text_field( $params['sitemap_priority_post'] ) );
		}

		if ( isset( $params['sitemap_priority_page'] ) ) {
			SitemapSettings::set( 'sitemap_priority_page', sanitize_text_field( $params['sitemap_priority_page'] ) );
		}

		// Clear transient cache so new rules apply immediately
		SitemapGenerator::clearCache();
		VideoSitemapGenerator::clearCache();
		$this->routeManager->removeRulesAndFlush();
		if ( $this->isPluginSitemapEnabled() ) {
			$this->routeManager->flushRules();
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
