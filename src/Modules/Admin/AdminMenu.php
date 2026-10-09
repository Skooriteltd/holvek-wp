<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminMenu {

	public function registerMenu(): void {
		// Use the lowest-tier SEO capability as the menu gate.
		// The React SPA handles per-tab permission gating based on the
		// capabilities map passed via wp_localize_script in enqueueAssets().
		add_menu_page(
			'Holvek SEO',
			'Holvek',
			'edit_seo_meta',
			'holvek',
			array( $this, 'renderAdminPage' ),
			'dashicons-chart-area',
			85
		);
	}

	public function enqueueAssets( string $hook ): void {
		// Only load assets on our plugin page
		if ( $hook !== 'toplevel_page_holvek' ) {
			return;
		}

		$assetFile = HOLVEK_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $assetFile ) ) {
			$assets = require $assetFile;
			wp_enqueue_script(
				'holvek-admin-js',
				HOLVEK_PLUGIN_URL . 'build/index.js',
				$assets['dependencies'],
				$assets['version'],
				true
			);

			$adminConfig = array(
				'apiUrl'          => esc_url_raw( rest_url( 'holvek/v1' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'setupComplete'   => get_option( 'holvek_setup_complete' ) ? '1' : '0',
				'isMultisite'     => is_multisite() ? '1' : '0',
				'cloudConfigured' => \Holvek\WordPress\Core\Mode\OperatingMode::isCloudConfigured() ? '1' : '0',
				'capabilities'    => array(
					'manage_seo'       => current_user_can( 'manage_seo' ),
					'view_seo_reports' => current_user_can( 'view_seo_reports' ),
					'manage_redirects' => current_user_can( 'manage_redirects' ),
					'edit_seo_meta'    => current_user_can( 'edit_seo_meta' ),
					'manage_options'   => current_user_can( 'manage_options' ),
				),
			);

			wp_localize_script( 'holvek-admin-js', 'holvekAdminConfig', $adminConfig );
		}

		wp_enqueue_style(
			'holvek-admin-css',
			HOLVEK_PLUGIN_URL . 'build/index.css',
			array(),
			HOLVEK_VERSION
		);
		wp_style_add_data( 'holvek-admin-css', 'rtl', 'replace' );
	}

	public function renderAdminPage(): void {
		echo '<div class="wrap"><div id="holvek-admin-app"></div></div>';
	}
}
