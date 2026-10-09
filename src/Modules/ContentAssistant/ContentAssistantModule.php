<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Ai\AiGateway;
use Holvek\WordPress\Modules\Schema\JsonLd;

class ContentAssistantModule {

	private EventManager $eventManager;

	public function __construct( EventManager $eventManager ) {
		$this->eventManager = $eventManager;
	}

	public function boot(): void {
		$this->eventManager->addAction( 'init', array( $this, 'registerMetaFields' ) );
		$this->eventManager->addAction( 'enqueue_block_editor_assets', array( $this, 'enqueueEditorAssets' ) );
		$this->eventManager->addAction( 'rest_api_init', array( $this, 'registerRestRoutes' ) );

		// Internal Link Index — keep the link table updated on content changes
		$linkEngine = new InternalLinkEngine();
		$this->eventManager->addAction(
			'save_post',
			function ( int $postId ) use ( $linkEngine ) {
				$post = get_post( $postId );
				if ( $post ) {
					$linkEngine->indexPostLinks( $postId, $post );
				}
			},
			20
		);
		$this->eventManager->addAction( 'deleted_post', array( $linkEngine, 'removePostLinks' ) );
	}

	/**
	 * Post meta exposed to the block editor.
	 *
	 * Sanitization runs here, on register_post_meta, so a REST write cannot
	 * skip the React form. auth_callback checks edit_post for that post.
	 *
	 * @return array<int, string>
	 */
	public static function editorMetaKeys(): array {
		return array(
			'_holvek_meta_title',
			'_holvek_meta_description',
			'_holvek_is_news',
			'_holvek_noindex',
			'_holvek_nofollow',
			'_holvek_canonical_url',
			'_holvek_is_cornerstone',
			'_holvek_og_title',
			'_holvek_og_description',
			'_holvek_og_image',
			'_holvek_twitter_title',
			'_holvek_focus_keyword',
			'_holvek_primary_schema',
			'_holvek_schema_product_name',
			'_holvek_schema_product_description',
			'_holvek_schema_product_price',
			'_holvek_schema_product_currency',
			'_holvek_schema_product_rating',
			'_holvek_schema_product_availability',
			'_holvek_schema_faq_questions',
			'_holvek_schema_howto_name',
			'_holvek_schema_howto_description',
			'_holvek_schema_howto_steps',
			'_holvek_schema_howto_supplies',
			'_holvek_schema_howto_tools',
			'_holvek_schema_localbusiness_name',
			'_holvek_schema_localbusiness_telephone',
			'_holvek_schema_localbusiness_street',
			'_holvek_schema_localbusiness_city',
			'_holvek_schema_localbusiness_postal',
			'_holvek_schema_localbusiness_country',
			'_holvek_additional_keywords',
			'_holvek_schema_stack',
			'_holvek_is_pillar',
			'_holvek_pagespeed_cache',
			'_holvek_ranking_keywords',
			'_holvek_ranking_keywords_at',
			'_holvek_disable_social_share',
			'_holvek_custom_schema_properties',
			'_holvek_schema_custom_type',
			'_holvek_schema_recipe_name',
			'_holvek_schema_recipe_description',
			'_holvek_schema_recipe_ingredients',
			'_holvek_schema_recipe_instructions',
			'_holvek_schema_recipe_prep_time',
			'_holvek_schema_recipe_cook_time',
			'_holvek_schema_recipe_calories',
			'_holvek_schema_recipe_cuisine',
			'_holvek_schema_recipe_yield',
			'_holvek_schema_event_name',
			'_holvek_schema_event_description',
			'_holvek_schema_event_start_date',
			'_holvek_schema_event_end_date',
			'_holvek_schema_event_venue',
			'_holvek_schema_event_address',
			'_holvek_schema_event_organizer',
			'_holvek_schema_event_performer',
			'_holvek_schema_event_price',
			'_holvek_schema_event_currency',
			'_holvek_schema_event_status',
		);
	}

	public function registerMetaFields(): void {
		foreach ( array( 'post', 'page' ) as $postType ) {
			foreach ( self::editorMetaKeys() as $metaKey ) {
				register_post_meta(
					$postType,
					$metaKey,
					array(
						'show_in_rest'      => true,
						'single'            => true,
						'type'              => 'string',
						'auth_callback'     => static function ( $allowed, $key, $objectId ): bool {
							unset( $allowed, $key );
							return current_user_can( 'edit_post', (int) $objectId );
						},
						'sanitize_callback' => static function ( $value, $key ) {
							return self::sanitizeMetaValue( (string) $key, $value );
						},
					)
				);
			}
		}
	}

	/**
	 * Sanitize one editor meta value.
	 *
	 * Custom schema keeps the JsonLd result, including WP_Error, so an invalid
	 * document is rejected instead of stored as an empty string.
	 *
	 * @param mixed $value Raw REST value.
	 * @return string|\WP_Error
	 */
	public static function sanitizeMetaValue( string $metaKey, $value ) {
		if ( '_holvek_custom_schema_properties' === $metaKey ) {
			return JsonLd::sanitizeCustomMeta( is_string( $value ) ? $value : '' );
		}
		if ( '_holvek_schema_faq_questions' === $metaKey ) {
			return self::sanitizeFaqMeta( $value );
		}
		if ( in_array( $metaKey, array( '_holvek_canonical_url', '_holvek_og_image' ), true ) ) {
			return self::sanitizeHttpUrl( $value );
		}
		if ( in_array( $metaKey, array( '_holvek_noindex', '_holvek_nofollow', '_holvek_is_news', '_holvek_is_cornerstone', '_holvek_is_pillar', '_holvek_disable_social_share' ), true ) ) {
			return self::sanitizeYesNo( $value );
		}
		if ( in_array( $metaKey, array( '_holvek_meta_description', '_holvek_og_description', '_holvek_schema_product_description', '_holvek_schema_howto_description', '_holvek_schema_recipe_description', '_holvek_schema_event_description' ), true ) ) {
			return sanitize_textarea_field( is_string( $value ) ? $value : '' );
		}

		return sanitize_text_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * An http or https URL, or an empty string.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitizeHttpUrl( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$valid = wp_http_validate_url( $value );
		if ( ! is_string( $valid ) || '' === $valid ) {
			return '';
		}
		return esc_url_raw( $valid );
	}

	/**
	 * Robots and other on/off flags. Anything other than yes is stored as no.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitizeYesNo( $value ): string {
		if ( ! is_string( $value ) ) {
			return 'no';
		}
		return strtolower( trim( $value ) ) === 'yes' ? 'yes' : 'no';
	}

	/**
	 * FAQ schema is a JSON list of question and answer strings.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitizeFaqMeta( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '[]';
		}
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return '[]';
		}

		$clean = array();
		foreach ( $decoded as $row ) {
			if ( count( $clean ) >= 50 || ! is_array( $row ) ) {
				continue;
			}
			$question = sanitize_text_field( isset( $row['question'] ) ? (string) $row['question'] : '' );
			$answer   = sanitize_textarea_field( isset( $row['answer'] ) ? (string) $row['answer'] : '' );
			if ( '' === $question && '' === $answer ) {
				continue;
			}
			$clean[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		$json = wp_json_encode( $clean );
		return is_string( $json ) ? $json : '[]';
	}

	public function enqueueEditorAssets(): void {
		$assetFile = HOLVEK_PLUGIN_DIR . 'build/editor.asset.php';

		if ( ! file_exists( $assetFile ) ) {
			return;
		}

		$assets = require $assetFile;

		$dependencies = array_merge(
			$assets['dependencies'],
			array( 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-components', 'wp-data', 'wp-core-data' )
		);

		wp_enqueue_script(
			'holvek-editor-js',
			HOLVEK_PLUGIN_URL . 'build/editor.js',
			$dependencies,
			$assets['version'],
			true
		);

		$editorConfig = array(
			'apiUrl'            => esc_url_raw( rest_url( 'holvek/v1' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'defaultShareImage' => esc_url_raw( get_option( 'holvek_default_share_image', '' ) ),
			'cloudConfigured'   => \Holvek\WordPress\Core\Mode\OperatingMode::isCloudConfigured() ? '1' : '0',
		);

		wp_localize_script( 'holvek-editor-js', 'holvekEditorConfig', $editorConfig );
	}

	public function registerRestRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/ai/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generateAiContent' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/content/internal-links',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'getContentInternalLinks' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);
	}

	public function checkPermission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Handle AI text generation requests.
	 */
	public function generateAiContent( \WP_REST_Request $request ): \WP_REST_Response {
		$params       = $request->get_json_params();
		$action       = isset( $params['action'] ) ? sanitize_key( $params['action'] ) : '';
		$title        = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		$content      = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
		$focusKeyword = isset( $params['focus_keyword'] ) ? sanitize_text_field( $params['focus_keyword'] ) : '';

		if ( empty( $action ) ) {
			return new \WP_Error( 'missing_action', __( 'Action parameter is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$aiGateway    = new AiGateway();
		$systemPrompt = 'You are an elite, senior WordPress SEO and AEO writing specialist. Keep responses professional, highly optimized, and concise.';

		// Build targeted prompt based on desired action
		switch ( $action ) {
			case 'generate_titles_metas':
				$prompt = "Given the article title: '$title'\nContent: " . wp_strip_all_tags( $content ) . "\nFocus Keyword: '$focusKeyword'\n\nGenerate exactly 3 high-CTR Meta Titles (under 60 characters) and 3 highly compelling Meta Descriptions (under 160 characters). You MUST format the output as a valid, parsable JSON object with keys 'titles' (array of strings) and 'descriptions' (array of strings). Do not include any explanation or markdown block backticks (like ```json), return raw JSON only.";
				break;

			case 'generate_faqs':
				$prompt = "Based on the content:\n" . wp_strip_all_tags( $content ) . "\n\nGenerate exactly 3 highly relevant FAQ questions and helpful concise answers. Format your output as a valid, parsable JSON array of objects, where each object has keys 'question' and 'answer'. Do not include markdown code block formatting (like ```json), return raw JSON only.";
				break;

			case 'generate_outline_summary':
				$prompt = "Create a high-level content summary (1-2 sentences) and a detailed, structured SEO outline (using ##, ### Markdown headers) for an article with:\nTitle: '$title'\nFocus Keyword: '$focusKeyword'\n\nProvide structural recommendations (word count target, search intent target, key headings to add). Format your reply nicely using standard Markdown.";
				break;

			case 'writing_assistant':
				$prompt = "Analyze the content for SEO best practices:\nFocus Keyword: '$focusKeyword'\nTitle: '$title'\nContent: " . wp_strip_all_tags( $content ) . "\n\nProvide exactly 3 bullet points with highly actionable suggestions to improve keyword placement, heading structure, or readability ease. Keep each bullet point short, punchy, and professional.";
				break;

			default:
				return new \WP_Error( 'invalid_action', __( 'Invalid AI action.', 'holvek' ), array( 'status' => 400 ) );
		}

		$result = $aiGateway->queryModel( $prompt, $systemPrompt );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'ai_generation_failed', $result['message'], array( 'status' => 400 ) );
		}

		$text = $result['text'];

		// Clean up markdown block wrapping if LLM returned it anyway
		if ( strpos( $text, '```' ) !== false ) {
			$text = preg_replace( '/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $text );
			$text = trim( $text );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'result'  => $text,
			)
		);
	}

	/**
	 * Fetch related link suggestions and orphan status.
	 */
	public function getContentInternalLinks( \WP_REST_Request $request ): \WP_REST_Response {
		$params  = $request->get_json_params();
		$postId  = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
		$content = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';

		if ( ! $postId ) {
			return new \WP_Error( 'missing_id', __( 'Post ID is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$linkEngine      = new InternalLinkEngine();
		$recommendations = $linkEngine->getRecommendations( $postId, $content );
		$orphanStatus    = $linkEngine->checkOrphanStatus( $postId );

		return rest_ensure_response(
			array(
				'success'         => true,
				'recommendations' => $recommendations,
				'orphan_status'   => $orphanStatus,
			)
		);
	}
}
