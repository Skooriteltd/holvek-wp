<?php

namespace Holvek\WordPress\Modules\Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal setup wizard with zero-configuration philosophy.
 *
 * Design Principles:
 * - Everything works out of the box with sensible defaults.
 * - The wizard asks only 1-2 questions, not 10.
 * - Auto-detects site type, social profiles, and existing SEO plugins.
 * - Completes in under 30 seconds.
 */
class SetupWizard {

	private const COMPLETED_OPTION = 'holvek_setup_complete';

	private const DISMISSED_META = 'holvek_setup_notice_dismissed';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'showWelcomeNotice' ) );
		add_action( 'wp_ajax_holvek_dismiss_setup_notice', array( $this, 'dismissWelcomeNotice' ) );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Show a dismissible welcome notice that links to the setup wizard.
	 */
	public function showWelcomeNotice(): void {
		if ( get_option( self::COMPLETED_OPTION ) || get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_seo' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->isHolvekScreen() ) {
			return;
		}

		$setupUrl = admin_url( 'admin.php?page=holvek#setup' );
		$nonce    = wp_create_nonce( 'holvek_dismiss_setup_notice' );

		echo '<div class="notice notice-info is-dismissible holvek-setup-notice" style="border-left-color: #3b82f6; padding: 16px 20px;" data-nonce="' . esc_attr( $nonce ) . '">';
		echo '<div>';
		echo '<p style="margin:0; font-size:15px; font-weight:600; color:#0f172a;">' . esc_html__( 'Welcome to Holvek!', 'holvek' ) . '</p>';
		echo '<p style="margin:4px 0 0; color:#475569;">';
		printf(
			wp_kses(
				/* translators: %s: setup wizard URL */
				__( 'Your SEO is already working with smart defaults. <a href="%s" style="color:#3b82f6; font-weight:500;">Complete the 30-second setup</a> to customize site type and import existing SEO data.', 'holvek' ),
				array(
					'a' => array(
						'href'  => true,
						'style' => true,
					),
				)
			),
			esc_url( $setupUrl )
		);
		echo '</p>';
		echo '</div>';
		echo '</div>';
		echo '<script>';
		echo 'document.addEventListener("DOMContentLoaded",function(){var el=document.querySelector(".holvek-setup-notice");if(!el){return;}el.addEventListener("click",function(e){if(!e.target.classList.contains("notice-dismiss")){return;}var body=new FormData();body.append("action","holvek_dismiss_setup_notice");body.append("_ajax_nonce",el.dataset.nonce);fetch(window.ajaxurl,{method:"POST",body:body});});});';
		echo '</script>';
	}

	/**
	 * Keep the welcome notice dismissed for this user.
	 */
	public function dismissWelcomeNotice(): void {
		check_ajax_referer( 'holvek_dismiss_setup_notice' );
		if ( ! current_user_can( 'manage_seo' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sorry, you are not allowed to do that.', 'holvek' ) ),
				403
			);
		}
		update_user_meta( get_current_user_id(), self::DISMISSED_META, '1' );
		wp_send_json_success();
	}

	/**
	 * Admin notices stay on Holvek screens.
	 */
	private function isHolvekScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return is_object( $screen ) && isset( $screen->id ) && str_contains( (string) $screen->id, 'holvek' );
	}

	/**
	 * Register REST routes for the wizard.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/setup/auto-detect',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'autoDetect' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ); },
			)
		);

		register_rest_route(
			'holvek/v1',
			'/setup/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'completeSetup' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ); },
			)
		);
	}

	/**
	 * Auto-detect site configuration to pre-fill the wizard.
	 * This eliminates most manual configuration entirely.
	 */
	public function autoDetect( \WP_REST_Request $request ): \WP_REST_Response {
		// Detect site type from content
		$siteType = $this->detectSiteType();

		// Detect existing social profiles from other plugins or theme settings
		$socialProfiles = $this->detectSocialProfiles();

		// Detect if other SEO plugins have data to import
		$migrationManager = new \Holvek\WordPress\Modules\Migration\MigrationManager();
		$detectedPlugins  = $migrationManager->detectPlugins();

		// Check what's already configured
		$hasGoogleKey = ! empty( get_option( 'holvek_google_indexing_key', '' ) );
		$hasBingKey   = ! empty( get_option( 'holvek_indexnow_key', '' ) );

		return rest_ensure_response(
			array(
				'site_type'           => $siteType,
				'site_name'           => get_bloginfo( 'name' ),
				'site_tagline'        => get_bloginfo( 'description' ),
				'social'              => $socialProfiles,
				'detected_plugins'    => $detectedPlugins,
				'indexing_configured' => $hasGoogleKey || $hasBingKey,
				'setup_complete'      => (bool) get_option( self::COMPLETED_OPTION ),
			)
		);
	}

	/**
	 * Complete the setup wizard and save preferences.
	 */
	public function completeSetup( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		update_user_meta( get_current_user_id(), self::DISMISSED_META, '1' );

		if ( ! empty( $params['skip'] ) ) {
			update_option( self::COMPLETED_OPTION, true );
			return rest_ensure_response(
				array(
					'success' => true,
					'skipped' => true,
					'message' => __( 'Setup skipped. Holvek keeps the current defaults.', 'holvek' ),
				)
			);
		}

		// Save site type (affects schema output)
		if ( ! empty( $params['site_type'] ) ) {
			update_option( 'holvek_site_type', sanitize_text_field( $params['site_type'] ) );
		}

		// Save social profiles
		$socialFields = array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube' );
		foreach ( $socialFields as $field ) {
			if ( isset( $params['social'][ $field ] ) ) {
				update_option( 'holvek_social_' . $field, esc_url_raw( $params['social'][ $field ] ) );
			}
		}

		// Mark setup as complete — never show the notice again
		update_option( self::COMPLETED_OPTION, true );

		// Flush rewrite rules to ensure sitemaps work
		flush_rewrite_rules( false );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Setup complete. Holvek is using the choices you saved.', 'holvek' ),
			)
		);
	}

	/**
	 * Detect site type from installed plugins and content patterns.
	 */
	private function detectSiteType(): string {
		// WooCommerce = eCommerce
		if ( class_exists( 'WooCommerce' ) ) {
			return 'ecommerce';
		}

		// Check for news-like publishing patterns (high post frequency)
		$recentPosts = wp_count_posts();
		$totalPosts  = $recentPosts->publish ?? 0;

		if ( $totalPosts > 100 ) {
			return 'news';
		}

		// Check for portfolio-like content
		$pages      = wp_count_posts( 'page' );
		$totalPages = $pages->publish ?? 0;

		if ( $totalPages > $totalPosts && $totalPages > 5 ) {
			return 'business';
		}

		return 'blog';
	}

	/**
	 * Try to detect social profiles from existing plugin data or theme mods.
	 */
	private function detectSocialProfiles(): array {
		$profiles = array(
			'facebook'  => '',
			'twitter'   => '',
			'instagram' => '',
			'linkedin'  => '',
			'youtube'   => '',
		);

		// Try Yoast social profiles
		$yoastSocial = get_option( 'wpseo_social', array() );
		if ( ! empty( $yoastSocial ) ) {
			$profiles['facebook']  = $yoastSocial['facebook_site'] ?? '';
			$profiles['twitter']   = $yoastSocial['twitter_site'] ?? '';
			$profiles['instagram'] = $yoastSocial['instagram_url'] ?? '';
			$profiles['linkedin']  = $yoastSocial['linkedin_url'] ?? '';
			$profiles['youtube']   = $yoastSocial['youtube_url'] ?? '';
		}

		// Try RankMath social
		$rmOptions = get_option( 'rank-math-options-titles', array() );
		if ( ! empty( $rmOptions ) ) {
			$profiles['facebook'] = $profiles['facebook'] ?: ( $rmOptions['social_url_facebook'] ?? '' );
			$profiles['twitter']  = $profiles['twitter'] ?: ( $rmOptions['twitter_author_names'] ?? '' );
		}

		// Check already-saved Holvek values
		foreach ( $profiles as $key => &$val ) {
			$existing = get_option( 'holvek_social_' . $key, '' );
			if ( ! empty( $existing ) ) {
				$val = $existing;
			}
		}

		return $profiles;
	}
}
