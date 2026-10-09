<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ContentGapAnalyser
 *
 * BL-018: Identifies sub-topics covered by competitors / top-ranking pages
 * but absent from this site's content. Uses the site's configured LLM API key.
 */
class ContentGapAnalyser {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/content/gap-analysis',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyseGap' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/content/gap-drafts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createDraftPosts' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	public function analyseGap( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_json_params();
		$keyword = sanitize_text_field( $params['keyword'] ?? '' );
		$postId  = absint( $params['post_id'] ?? 0 );

		if ( empty( $keyword ) ) {
			return new \WP_Error( 'missing_keyword', __( 'A target keyword is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		if ( $this->getApiKey() === '' && ! \Holvek\WordPress\Core\Mode\OperatingMode::isCloudConfigured() ) {
			return \Holvek\WordPress\Core\Mode\OperatingMode::disconnected( __( 'Cloud content-gap analysis', 'holvek' ) );
		}

		// Build a map of existing content topics
		$existingTopics = $this->getExistingTopics( $keyword );

		// Ask LLM for sub-topics typically covered when ranking for this keyword
		$gapTopics = $this->askLlmForGapTopics( $keyword, $existingTopics );

		if ( is_wp_error( $gapTopics ) ) {
			return $gapTopics;
		}

		return rest_ensure_response(
			array(
				'keyword'         => $keyword,
				'existing_topics' => $existingTopics,
				'gap_topics'      => $gapTopics,
				'gap_count'       => count( $gapTopics ),
			)
		);
	}

	public function createDraftPosts( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$topics = $params['topics'] ?? array();

		if ( empty( $topics ) || ! is_array( $topics ) ) {
			return new \WP_Error( 'missing_topics', __( 'topics array is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		$created = array();
		foreach ( array_slice( $topics, 0, 10 ) as $topic ) {
			$title   = sanitize_text_field( is_array( $topic ) ? ( $topic['title'] ?? '' ) : $topic );
			$keyword = sanitize_text_field( is_array( $topic ) ? ( $topic['keyword'] ?? $title ) : $title );

			if ( empty( $title ) ) {
				continue;
			}

			$postId = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_status'  => 'draft',
					'post_type'    => 'post',
					'post_content' => sprintf( '<!-- Draft created by Holvek Content Gap Analysis for keyword: %s -->', esc_html( $keyword ) ),
				)
			);

			if ( ! is_wp_error( $postId ) ) {
				update_post_meta( $postId, '_holvek_focus_keyword', $keyword );
				update_post_meta( $postId, '_holvek_gap_analysis_source', 'yes' );
				$created[] = array(
					'post_id'  => $postId,
					'title'    => $title,
					'edit_url' => get_edit_post_link( $postId, 'raw' ),
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'created' => $created,
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getExistingTopics( string $keyword ): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$topics = array();
		foreach ( $posts as $id ) {
			$kw = get_post_meta( $id, '_holvek_focus_keyword', true );
			if ( ! empty( $kw ) ) {
				$topics[] = (string) $kw;
			}
			$topics[] = get_the_title( $id );
		}

		return array_unique( array_filter( $topics ) );
	}

	private function askLlmForGapTopics( string $keyword, array $existing ): array|\WP_Error {
		$userId   = get_current_user_id();
		$metering = class_exists( \Holvek\WordPress\Modules\Ai\UsageMeteringManager::class )
			? \Holvek\WordPress\Modules\Ai\UsageMeteringManager::getInstance()
			: null;

		if ( $userId && $metering && ! $metering->checkLimit( $userId, 'openai' ) ) {
			return new \WP_Error( 'rate_limit_exceeded', __( 'AI token usage limit exceeded for this billing cycle.', 'holvek' ), array( 'status' => 429 ) );
		}

		$apiKey = $this->getApiKey();
		if ( $apiKey === '' ) {
			$offload = ( new \Holvek\WordPress\Core\Api\BackendApiClient() )->analyzeContentGap( $keyword, $existing );
			if ( is_wp_error( $offload ) ) {
				return $offload;
			}

			if ( empty( $offload['success'] ) ) {
				$message = isset( $offload['message'] ) && is_string( $offload['message'] ) && $offload['message'] !== ''
					? $offload['message']
					: __( 'Cloud content-gap analysis did not return a result.', 'holvek' );
				return new \WP_Error( 'content_gap_unavailable', $message, array( 'status' => 502 ) );
			}

			$data = $offload['data'] ?? array();
			if ( isset( $data['gap_topics'] ) && is_array( $data['gap_topics'] ) ) {
				return $data['gap_topics'];
			}

			return is_array( $data ) ? $data : array();
		}

		$existingList = implode( ', ', array_slice( $existing, 0, 30 ) );
		$prompt       = "You are an expert SEO consultant. I want to rank for the keyword: \"{$keyword}\".\n\n"
			. "My site already covers these topics: {$existingList}\n\n"
			. 'List 10 sub-topics or related angles that top-ranking pages typically cover for this keyword '
			. "but which are NOT already in my list. Format: JSON array of objects with 'title' and 'keyword' fields. "
			. 'Return ONLY the JSON array.';

		$provider = get_option( 'holvek_ai_provider', 'openai' );
		$model    = get_option( 'holvek_ai_model', get_option( 'holvek_openai_model', 'gpt-4o-mini' ) );

		$response = wp_safe_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'max_tokens' => 600,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$content = $body['choices'][0]['message']['content'] ?? '';

		if ( $userId && $metering ) {
			$promptTokens = (int) ceil( strlen( $prompt ) / 4 );
			$outputTokens = (int) ceil( strlen( $content ) / 4 );
			$metering->record( $userId, 'openai', 'content_gap', $promptTokens + $outputTokens );
		}

		// Extract JSON from the response
		preg_match( '/\[.*\]/s', $content, $matches );
		$topics = json_decode( $matches[0] ?? '[]', true );

		return is_array( $topics ) ? $topics : array();
	}

	private function getApiKey(): string {
		$encryptedKey = get_option( 'holvek_openai_key', '' );
		if ( empty( $encryptedKey ) ) {
			$encryptedKey = get_option( 'holvek_openai_api_key', '' );
		}
		return \Holvek\WordPress\Core\Security\KeyVault::decrypt( $encryptedKey );
	}
}
