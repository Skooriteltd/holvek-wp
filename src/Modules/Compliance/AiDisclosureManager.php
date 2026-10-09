<?php

namespace Holvek\WordPress\Modules\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AiDisclosureManager
 *
 * Injects FTC-compliant AI content disclosure notices on posts flagged with
 * `_holvek_ai_generated = 'yes'`. Also adds a visible indicator column
 * in the WordPress post list table.
 *
 * BL-016
 */
class AiDisclosureManager {

	private const AI_META_KEY     = '_holvek_ai_generated';
	private const DISCLOSURE_TEXT = 'holvek_ai_disclosure_text';
	private const DISCLOSURE_POS  = 'holvek_ai_disclosure_position';

	private const DEFAULT_TEXT = 'This article contains material generated with the assistance of Artificial Intelligence (AI). All AI-generated content has been fact-checked, edited, and approved by a human editor.';

	public function register(): void {
		add_filter( 'the_content', array( $this, 'injectDisclosure' ) );
		add_filter( 'manage_posts_columns', array( $this, 'addPostColumn' ) );
		add_filter( 'manage_pages_columns', array( $this, 'addPostColumn' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'outputPostColumn' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'outputPostColumn' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	// ── Content filter ────────────────────────────────────────────────────────

	public function injectDisclosure( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$postId = get_the_ID();
		if ( ! $postId ) {
			return $content;
		}

		$isAi = get_post_meta( $postId, self::AI_META_KEY, true );
		if ( $isAi !== 'yes' ) {
			return $content;
		}

		$text     = get_option( self::DISCLOSURE_TEXT, self::DEFAULT_TEXT );
		$position = get_option( self::DISCLOSURE_POS, 'after' );

		$notice = sprintf(
			'<aside class="holvek-ai-disclosure" aria-label="%s" role="note" style="padding:12px 16px;margin:16px 0;border-left:4px solid #4f46e5;background:#f5f3ff;font-size:0.875em;">'
			. '<p style="margin:0;">🤖 %s</p>'
			. '</aside>',
			esc_attr__( 'AI Content Disclosure', 'holvek' ),
			esc_html( $text )
		);

		return ( $position === 'before' )
			? $notice . $content
			: $content . $notice;
	}

	// ── Post list column ──────────────────────────────────────────────────────

	public function addPostColumn( array $columns ): array {
		$columns['holvek_ai'] = '🤖 AI';
		return $columns;
	}

	public function outputPostColumn( string $column, int $postId ): void {
		if ( $column !== 'holvek_ai' ) {
			return;
		}

		$isAi = get_post_meta( $postId, self::AI_META_KEY, true );
		if ( $isAi === 'yes' ) {
			echo '<span title="AI-assisted content" style="font-size:1.2em;">🤖</span>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/ai-disclosure',
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
		return rest_ensure_response(
			array(
				'text'     => get_option( self::DISCLOSURE_TEXT, self::DEFAULT_TEXT ),
				'position' => get_option( self::DISCLOSURE_POS, 'after' ),
			)
		);
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();

		if ( isset( $params['text'] ) ) {
			update_option( self::DISCLOSURE_TEXT, sanitize_text_field( $params['text'] ) );
		}

		if ( isset( $params['position'] ) ) {
			$position = in_array( $params['position'], array( 'before', 'after' ), true ) ? $params['position'] : 'after';
			update_option( self::DISCLOSURE_POS, $position );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}
}
