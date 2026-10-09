<?php

namespace Holvek\WordPress\Modules\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CookieBannerModule: Lightweight cookie notice.
 *
 * Injects a consent bar into wp_footer with zero JS dependencies.
 * This module records a visitor's notice choice. It does not discover, block,
 * categorize, or manage third-party scripts and is therefore not presented as
 * a GDPR/CCPA consent-management solution.
 * Stores consent in a first-party cookie (holvek_consent).
 */
class CookieBannerModule {

	private const OPTION_KEY     = 'holvek_cookie_banner';
	private const CONSENT_COOKIE = 'holvek_consent';

	public function boot(): void {
		add_action( 'wp_footer', array( $this, 'renderBanner' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function enqueueAssets(): void {
		$config = $this->getConfig();
		if ( ! $config['enabled'] ) {
			return;
		}

		$pluginUrl = HOLVEK_PLUGIN_URL;

		wp_enqueue_style(
			'holvek-cookie-banner',
			$pluginUrl . 'build/cookie-banner.css',
			array(),
			HOLVEK_VERSION ?? '1.0.1'
		);

		wp_enqueue_script(
			'holvek-cookie-banner',
			$pluginUrl . 'build/cookie-banner.js',
			array(),
			HOLVEK_VERSION ?? '1.0.1',
			true
		);

		wp_localize_script(
			'holvek-cookie-banner',
			'holvekCookieConfig',
			array(
				'cookieName' => self::CONSENT_COOKIE,
				'cookieDays' => 365,
			)
		);
	}

	public function renderBanner(): void {
		$config = $this->getConfig();
		if ( ! $config['enabled'] ) {
			return;
		}
		?>
		<div id="holvek-cookie-banner" class="holvek-cookie-banner" role="dialog" aria-live="polite" aria-label="<?php echo esc_attr__( 'Cookie consent', 'holvek' ); ?>" style="display:none;">
			<div class="holvek-cookie-banner__inner">
				<p class="holvek-cookie-banner__text"><?php echo wp_kses_post( $config['message'] ); ?>
					<?php if ( ! empty( $config['policy_url'] ) ) : ?>
						<a href="<?php echo esc_url( $config['policy_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Privacy Policy', 'holvek' ); ?></a>.
					<?php endif; ?>
				</p>
				<div class="holvek-cookie-banner__actions">
					<button id="holvek-cookie-accept" class="holvek-cookie-btn holvek-cookie-btn--accept"><?php echo esc_html( $config['accept_label'] ); ?></button>
					<button id="holvek-cookie-decline" class="holvek-cookie-btn holvek-cookie-btn--decline"><?php echo esc_html( $config['decline_label'] ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// ── REST API ─────────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/cookie-banner',
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

	public function getSettings(): \WP_REST_Response {
		return rest_ensure_response( $this->getConfig() );
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$config = array(
			'enabled'       => (bool) ( $params['enabled'] ?? false ),
			'message'       => wp_kses_post( $params['message'] ?? $this->defaultMessage() ),
			'accept_label'  => sanitize_text_field( $params['accept_label'] ?? 'Accept' ),
			'decline_label' => sanitize_text_field( $params['decline_label'] ?? 'Decline' ),
			'policy_url'    => esc_url_raw( $params['policy_url'] ?? '' ),
		);
		update_option( self::OPTION_KEY, $config, true );
		return rest_ensure_response(
			array(
				'success' => true,
				'config'  => $config,
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getConfig(): array {
		$defaults = array(
			'enabled'       => false,
			'message'       => $this->defaultMessage(),
			'accept_label'  => 'Accept',
			'decline_label' => 'Decline',
			'policy_url'    => '',
		);
		$saved    = get_option( self::OPTION_KEY, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	private function defaultMessage(): string {
		return 'This site uses cookies. Review the privacy policy for details.';
	}
}
