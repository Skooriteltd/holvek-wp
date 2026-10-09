<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MultisiteNetworkSeo
 *
 * BL-035: WordPress Multisite support — network-level SEO settings
 * with per-site overrides. Network admins manage defaults; site admins
 * can override them.
 */
class MultisiteNetworkSeo {

	private const NETWORK_OPTION = 'holvek_network_seo_settings';

	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		// Existing sites are installed by network activation. A site created later
		// still needs tables and capabilities while this plugin stays network-active.
		add_action( 'wp_initialize_site', array( $this, 'initializeNewSite' ) );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'network_admin_menu', array( $this, 'addNetworkMenu' ) );
		add_filter( 'holvek_get_option', array( $this, 'maybeUseNetworkDefault' ), 10, 2 );
	}

	/**
	 * Install this plugin on a new site only when it is active for the network.
	 *
	 * WordPress has already switched to the new site when this hook runs. Switch
	 * again only if the caller invokes the method directly.
	 *
	 * @param mixed $newSite Site object with blog_id, or a numeric site id.
	 */
	public function initializeNewSite( $newSite ): void {
		if ( ! is_multisite() || ! $this->isNetworkActive() ) {
			return;
		}

		$siteId = 0;
		if ( is_object( $newSite ) && isset( $newSite->blog_id ) ) {
			$siteId = (int) $newSite->blog_id;
		} elseif ( is_numeric( $newSite ) ) {
			$siteId = (int) $newSite;
		}
		if ( $siteId < 1 ) {
			return;
		}

		$switched = false;
		if ( get_current_blog_id() !== $siteId ) {
			switch_to_blog( $siteId );
			$switched = true;
		}

		\Holvek\WordPress\Plugin::activate( false );

		if ( $switched ) {
			restore_current_blog();
		}
	}

	private function isNetworkActive(): bool {
		if ( ! defined( 'HOLVEK_PLUGIN_FILE' ) ) {
			return false;
		}

		$basename = plugin_basename( HOLVEK_PLUGIN_FILE );
		if ( function_exists( 'is_plugin_active_for_network' ) ) {
			return (bool) is_plugin_active_for_network( $basename );
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ $basename ] );
	}

	private function networkForbidden(): \WP_Error {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'holvek' ),
			array( 'status' => 403 )
		);
	}

	public function registerRoutes(): void {
		$networkAdminCap = fn() => current_user_can( 'manage_network_options' );
		$siteAdminCap    = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		// Network defaults (super admin only)
		register_rest_route(
			'holvek/v1',
			'/network/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getNetworkSettings' ),
					'permission_callback' => $networkAdminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveNetworkSettings' ),
					'permission_callback' => $networkAdminCap,
				),
			)
		);

		// Per-site override status
		register_rest_route(
			'holvek/v1',
			'/network/site-overrides',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getSiteOverrides' ),
				'permission_callback' => $siteAdminCap,
			)
		);

		// List all network sites with basic SEO health
		register_rest_route(
			'holvek/v1',
			'/network/sites',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getNetworkSites' ),
				'permission_callback' => $networkAdminCap,
			)
		);
	}

	public function getNetworkSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		unset( $request );

		if ( ! is_multisite() ) {
			return new \WP_Error( 'not_multisite', __( 'Not a multisite network.', 'holvek' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return $this->networkForbidden();
		}

		$settings = get_site_option( self::NETWORK_OPTION, $this->getDefaults() );
		return rest_ensure_response( $settings );
	}

	public function saveNetworkSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! is_multisite() ) {
			return new \WP_Error( 'not_multisite', __( 'Not a multisite network.', 'holvek' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return $this->networkForbidden();
		}

		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$settings = array(
			'default_schema_type'  => sanitize_text_field( $params['default_schema_type'] ?? 'Article' ),
			'force_ssl'            => (bool) ( $params['force_ssl'] ?? true ),
			'block_ai_bots'        => (bool) ( $params['block_ai_bots'] ?? false ),
			'allow_site_overrides' => (bool) ( $params['allow_site_overrides'] ?? true ),
			'shared_indexnow_key'  => sanitize_text_field( $params['shared_indexnow_key'] ?? '' ),
			'network_robots_rules' => sanitize_textarea_field( $params['network_robots_rules'] ?? '' ),
		);

		update_site_option( self::NETWORK_OPTION, $settings );
		return rest_ensure_response(
			array(
				'success'  => true,
				'settings' => $settings,
			)
		);
	}

	public function getSiteOverrides( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! is_multisite() ) {
			return new \WP_Error( 'not_multisite', __( 'Not a multisite network.', 'holvek' ), array( 'status' => 400 ) );
		}

		$networkSettings = get_site_option( self::NETWORK_OPTION, $this->getDefaults() );
		$siteOverrides   = get_option( 'holvek_site_overrides', array() );

		return rest_ensure_response(
			array(
				'network_settings' => $networkSettings,
				'site_overrides'   => $siteOverrides,
				'can_override'     => $networkSettings['allow_site_overrides'] ?? true,
			)
		);
	}

	public function getNetworkSites( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		unset( $request );

		if ( ! is_multisite() ) {
			return new \WP_Error( 'not_multisite', __( 'Not a multisite network.', 'holvek' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return $this->networkForbidden();
		}

		$sites  = get_sites( array( 'number' => 100 ) );
		$result = array();

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			$result[] = array(
				'blog_id'     => $site->blog_id,
				'domain'      => $site->domain . $site->path,
				'name'        => get_bloginfo( 'name' ),
				'admin_email' => get_option( 'admin_email' ),
				'post_count'  => wp_count_posts()->publish ?? 0,
			);
			restore_current_blog();
		}

		return rest_ensure_response(
			array(
				'sites' => $result,
				'total' => count( $result ),
			)
		);
	}

	public function addNetworkMenu(): void {
		add_menu_page(
			'Holvek Network',
			'Holvek',
			'manage_network_options',
			'holvek-network',
			array( $this, 'renderNetworkAdminPage' ),
			'dashicons-chart-area',
			30
		);
	}

	public function renderNetworkAdminPage(): void {
		echo '<div id="holvek-network-admin"><!-- React renders here --></div>';
	}

	/**
	 * Filter: fall back to network defaults when a site option is not set.
	 */
	public function maybeUseNetworkDefault( $value, string $optionKey ) {
		if ( $value !== '' && $value !== null && $value !== false ) {
			return $value;
		}

		$networkSettings = get_site_option( self::NETWORK_OPTION, array() );
		$networkKey      = str_replace( 'holvek_', '', $optionKey );

		return $networkSettings[ $networkKey ] ?? $value;
	}

	private function getDefaults(): array {
		return array(
			'default_schema_type'  => 'Article',
			'force_ssl'            => true,
			'block_ai_bots'        => false,
			'allow_site_overrides' => true,
			'shared_indexnow_key'  => '',
			'network_robots_rules' => '',
		);
	}
}
