<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FaviconAudit: Warns admins when no site icon (favicon) has been configured.
 *
 * WordPress outputs favicon tags natively once a site icon is set in
 * Customizer → Site Identity. This module audits that setting and
 * shows a dismissible admin notice with a direct link to fix it.
 */
class FaviconAudit {

	private const DISMISSED_OPTION = 'holvek_favicon_notice_dismissed';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybeShowNotice' ) );
		add_action( 'wp_ajax_holvek_dismiss_favicon_notice', array( $this, 'dismissNotice' ) );
		// Auto-clear the dismissed flag if a site icon gets set
		add_action( 'update_option_site_icon', array( $this, 'clearDismissed' ) );
	}

	/**
	 * Show a dismissible admin notice if no site icon is configured.
	 */
	public function maybeShowNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::DISMISSED_OPTION ) ) {
			return;
		}
		if ( ! $this->isHolvekScreen() ) {
			return;
		}
		if ( ! empty( get_site_icon_url( 32 ) ) ) {
			return; // Already set — nothing to do
		}

		$customizerUrl = admin_url( 'customize.php?autofocus[control]=site_icon' );
		$nonce         = wp_create_nonce( 'holvek_dismiss_favicon_notice' );

		printf(
			'<div class="notice notice-warning is-dismissible holvek-favicon-notice" data-nonce="%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>
            <script>
            (function(){
                document.addEventListener("DOMContentLoaded", function(){
                    var n = document.querySelector(".holvek-favicon-notice");
                    if (!n) return;
                    n.addEventListener("click", function(e){
                        if (!e.target.classList.contains("notice-dismiss")) return;
                        var fd = new FormData();
                        fd.append("action", "holvek_dismiss_favicon_notice");
                        fd.append("nonce", n.dataset.nonce);
                        fetch(ajaxurl, { method: "POST", body: fd });
                    });
                });
            })();
            </script>',
			esc_attr( $nonce ),
			esc_html__( 'Holvek: no favicon is set for this site. A favicon is shown in browser tabs and bookmarks.', 'holvek' ),
			esc_url( $customizerUrl ),
			esc_html__( 'Set your favicon in the Customizer.', 'holvek' )
		);
	}

	private function isHolvekScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return is_object( $screen ) && isset( $screen->id ) && str_contains( (string) $screen->id, 'holvek' );
	}

	/**
	 * AJAX: persist the dismissal so the notice does not reappear.
	 */
	public function dismissNotice(): void {
		check_ajax_referer( 'holvek_dismiss_favicon_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'holvek' ) ), 403 );
		}
		update_option( self::DISMISSED_OPTION, true, false );
		wp_send_json_success();
	}

	/**
	 * Clear the dismissed flag when a site icon is set so we never
	 * re-nag if the icon is later removed and the user needs reminding.
	 */
	public function clearDismissed(): void {
		delete_option( self::DISMISSED_OPTION );
	}
}
