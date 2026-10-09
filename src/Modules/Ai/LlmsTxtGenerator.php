<?php

namespace Holvek\WordPress\Modules\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LlmsTxtGenerator: Serves a machine-readable /llms.txt file for LLM ingestion.
 *
 * The llms.txt standard (proposed by Answer.AI) gives AI assistants
 * a structured overview of the site's content and permission guidelines.
 *
 * BL-033 enhancements:
 * - Auto-populates cornerstone content, Disallow/Allow sections from per-post meta
 * - Manual override mode (stored content served instead of auto-generated)
 * - Cache invalidation on save_post and option changes
 * - Preview REST endpoint
 *
 * @see https://llmstxt.org/
 */
class LlmsTxtGenerator {

	private const CACHE_TRANSIENT  = 'holvek_llms_txt_content';
	private const CONFIG_OPTION    = 'holvek_llms_txt_config';
	private const OVERRIDE_OPTION  = 'holvek_llms_txt_manual_override';
	private const OVERRIDE_CONTENT = 'holvek_llms_txt_manual_content';

	public function boot(): void {
		add_action( 'init', array( $this, 'registerRewriteRule' ) );
		add_action( 'template_redirect', array( $this, 'serveFile' ) );
		add_filter( 'query_vars', array( $this, 'registerQueryVar' ) );

		// BL-033: Cache invalidation hooks
		add_action( 'save_post', array( $this, 'invalidateCache' ) );
		add_action( 'update_option_holvek_llms_txt_config', array( $this, 'invalidateCache' ) );
	}

	public function invalidateCache(): void {
		delete_transient( self::CACHE_TRANSIENT );
	}

	public function registerRewriteRule(): void {
		add_rewrite_rule( '^llms\\.txt$', 'index.php?holvek_llms_txt=1', 'top' );
	}

	public function registerQueryVar( array $vars ): array {
		$vars[] = 'holvek_llms_txt';
		return $vars;
	}

	public function serveFile(): void {
		if ( ! get_query_var( 'holvek_llms_txt' ) ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		// BL-033: manual override mode
		if ( get_option( self::OVERRIDE_OPTION, false ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- llms.txt is a text/plain document.
			echo (string) get_option( self::OVERRIDE_CONTENT, '' );
			exit;
		}

		// Try cache
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( $cached !== false ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- llms.txt is a text/plain document.
			echo $cached;
			exit;
		}

		$config  = get_option( self::CONFIG_OPTION, array() );
		$content = $this->buildContent( $config );

		set_transient( self::CACHE_TRANSIENT, $content, 12 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- llms.txt is a text/plain document.
		echo $content;
		exit;
	}

	public function buildContent( array $config ): string {
		$siteName    = get_bloginfo( 'name' );
		$siteUrl     = get_site_url();
		$description = $config['description'] ?? get_bloginfo( 'description' );
		$allowIndex  = $config['allow_index'] ?? true;
		$allowTrain  = $config['allow_training'] ?? false;
		$extraNotes  = $config['extra_notes'] ?? '';

		// BL-033: find contact page automatically
		$contactPage = get_pages(
			array(
				'meta_key' => '_wp_page_template',
				'number'   => 1,
			)
		);
		$contactUrl  = $config['contact_url'] ?? '';
		if ( empty( $contactUrl ) ) {
			$pages      = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'name'           => 'contact',
					'posts_per_page' => 1,
				)
			);
			$contactUrl = ! empty( $pages ) ? get_permalink( $pages[0]->ID ) : get_option( 'admin_email' );
		}

		$output  = "# {$siteName}\n\n";
		$output .= "> {$description}\n\n";
		$output .= "## Site\n\n";
		$output .= "- URL: {$siteUrl}\n";
		$output .= "- Contact: {$contactUrl}\n\n";

		$output .= "## Permissions\n\n";
		$output .= '- Indexing: ' . ( $allowIndex ? 'Allowed' : 'Not Allowed' ) . "\n";
		$output .= '- Training data use: ' . ( $allowTrain ? 'Allowed' : 'Not Allowed' ) . "\n\n";

		// Structured data link
		$output .= "## Structured Data\n\n";
		$output .= "- Schema Map: {$siteUrl}/wp-json/holvek/v1/schemamap\n\n";

		// BL-033: Cornerstone content (auto-populated)
		$output      .= "## Cornerstone Content\n\n";
		$cornerstones = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_holvek_is_cornerstone',
						'value' => 'yes',
					),
				),
			)
		);

		if ( ! empty( $cornerstones ) ) {
			foreach ( $cornerstones as $post ) {
				$output .= "- [{$post->post_title}](" . get_permalink( $post->ID ) . ")\n";
			}
		} else {
			// Fall back to recent posts if no cornerstone content set
			$recent = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			foreach ( $recent as $post ) {
				$output .= "- [{$post->post_title}](" . get_permalink( $post->ID ) . ")\n";
			}
		}
		$output .= "\n";

		// BL-033: Disallow section (posts explicitly opting out of AI training)
		$disallowed = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_query'     => array(
					array(
						'key'   => '_holvek_ai_training_allow',
						'value' => 'no',
					),
				),
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $disallowed ) ) {
			$output .= "## Disallow\n\n";
			foreach ( $disallowed as $id ) {
				$output .= '- ' . get_permalink( $id ) . "\n";
			}
			$output .= "\n";
		}

		// BL-033: Allow section (posts explicitly opting into AI training)
		$allowed = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_query'     => array(
					array(
						'key'   => '_holvek_ai_training_allow',
						'value' => 'yes',
					),
				),
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $allowed ) ) {
			$output .= "## Allow\n\n";
			foreach ( $allowed as $id ) {
				$output .= '- ' . get_permalink( $id ) . "\n";
			}
			$output .= "\n";
		}

		if ( ! empty( $extraNotes ) ) {
			$output .= "## Notes\n\n";
			$output .= $extraNotes . "\n";
		}

		return $output;
	}

	public function registerRestRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/llms-txt/config',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getConfig' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveConfig' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		// BL-033: preview endpoint
		register_rest_route(
			'holvek/v1',
			'/ai/llms-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'previewContent' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	public function getConfig(): \WP_REST_Response {
		$config = get_option(
			self::CONFIG_OPTION,
			array(
				'description'    => get_bloginfo( 'description' ),
				'allow_index'    => true,
				'allow_training' => false,
				'contact_url'    => get_option( 'admin_email' ),
				'extra_notes'    => '',
			)
		);

		$config['manual_override'] = (bool) get_option( self::OVERRIDE_OPTION, false );
		$config['manual_content']  = (string) get_option( self::OVERRIDE_CONTENT, '' );
		$config['preview']         = $this->buildContent( $config );

		return rest_ensure_response( $config );
	}

	public function saveConfig( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();

		$config = array(
			'description'    => sanitize_text_field( $params['description'] ?? '' ),
			'allow_index'    => (bool) ( $params['allow_index'] ?? true ),
			'allow_training' => (bool) ( $params['allow_training'] ?? false ),
			'contact_url'    => sanitize_text_field( $params['contact_url'] ?? '' ),
			'extra_notes'    => sanitize_textarea_field( $params['extra_notes'] ?? '' ),
		);

		update_option( self::CONFIG_OPTION, $config );

		// BL-033: manual override
		if ( isset( $params['manual_override'] ) ) {
			update_option( self::OVERRIDE_OPTION, (bool) $params['manual_override'] );
		}
		if ( isset( $params['manual_content'] ) ) {
			update_option( self::OVERRIDE_CONTENT, sanitize_textarea_field( $params['manual_content'] ) );
		}

		$this->invalidateCache();
		flush_rewrite_rules();

		$config['preview'] = $this->buildContent( $config );
		return rest_ensure_response(
			array(
				'success' => true,
				'config'  => $config,
			)
		);
	}

	// BL-033: Preview without saving
	public function previewContent( \WP_REST_Response $request ): \WP_REST_Response {
		$config  = get_option( self::CONFIG_OPTION, array() );
		$content = $this->buildContent( $config );
		return rest_ensure_response( array( 'content' => $content ) );
	}
}
