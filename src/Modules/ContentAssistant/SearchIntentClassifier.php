<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SearchIntentClassifier
 *
 * Classifies post search intent (informational / commercial / navigational /
 * transactional) using rule-based signal scoring — no LLM required.
 * Saves the result to post meta on every save_post and exposes REST endpoints
 * for per-post queries and bulk classification.
 *
 * BL-024
 */
class SearchIntentClassifier {

	private const META_KEY = '_holvek_search_intent';

	private const SIGNALS = array(
		'transactional' => array(
			'buy',
			'order',
			'purchase',
			'shop',
			'checkout',
			'discount',
			'deal',
			'coupon',
			'promo',
			'sale',
			'price',
			'cost',
			'cheap',
			'affordable',
			'$',
			'£',
			'€',
			'add to cart',
			'get started',
			'sign up',
			'subscribe',
		),
		'commercial'    => array(
			'best',
			'top',
			'review',
			'reviews',
			'vs',
			'versus',
			'compare',
			'comparison',
			'alternative',
			'alternatives',
			'rating',
			'recommend',
			'recommendation',
			'pros and cons',
			'worth it',
			'is it good',
		),
		'navigational'  => array(
			'login',
			'log in',
			'sign in',
			'contact',
			'about',
			'homepage',
			'official',
			'website',
			'portal',
			'dashboard',
			'account',
		),
		'informational' => array(
			'how',
			'what',
			'why',
			'when',
			'where',
			'who',
			'which',
			'guide',
			'tutorial',
			'tips',
			'learn',
			'explained',
			'introduction',
			'beginners',
			'basics',
			'overview',
			'definition',
			'example',
			'examples',
			'step by step',
			'how to',
			'what is',
			'what are',
		),
	);

	private const TIPS = array(
		'transactional' => array(
			'Add a clear, above-the-fold CTA button.',
			'Include Product or Offer schema markup.',
			'Add social proof (reviews, testimonials) near the CTA.',
			'Ensure price and availability are prominently displayed.',
		),
		'commercial'    => array(
			'Add a structured comparison table.',
			'Include AggregateRating schema if reviews are present.',
			'Link to authoritative sources and cite evidence.',
			'Add pros/cons section to improve dwell time.',
		),
		'navigational'  => array(
			'Ensure your brand name is prominent in the title tag.',
			'Add breadcrumbs for navigation clarity.',
			'Link to the most relevant destination from the intro.',
		),
		'informational' => array(
			'Add FAQ schema to capture People Also Ask results.',
			'Structure content with clear H2/H3 headings.',
			'Add a table of contents for long-form content.',
			'Include HowTo schema if applicable.',
		),
	);

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'save_post', array( $this, 'classifyOnSave' ), 20 );
	}

	// ── Auto-classify on save ─────────────────────────────────────────────────

	public function classifyOnSave( int $postId ): void {
		// Skip autosaves, revisions, and non-public post types
		if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}

		$post = get_post( $postId );
		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
			return;
		}

		$intent = $this->classifyPost( $postId );
		update_post_meta( $postId, self::META_KEY, $intent );
	}

	// ── Classification engine ─────────────────────────────────────────────────

	public function classifyPost( int $postId ): string {
		$post    = get_post( $postId );
		$keyword = (string) get_post_meta( $postId, '_holvek_focus_keyword', true );

		if ( ! $post ) {
			return 'informational';
		}

		$text = mb_strtolower(
			$keyword . ' ' .
			$post->post_title . ' ' .
			wp_strip_all_tags( mb_substr( $post->post_content, 0, 1500 ) )
		);

		$scores = array(
			'transactional' => 0,
			'commercial'    => 0,
			'navigational'  => 0,
			'informational' => 0,
		);

		foreach ( self::SIGNALS as $intent => $signals ) {
			foreach ( $signals as $signal ) {
				if ( mb_strpos( $text, mb_strtolower( $signal ) ) !== false ) {
					++$scores[ $intent ];
				}
			}
		}

		arsort( $scores );
		$winner = array_key_first( $scores );
		$max    = $scores[ $winner ];

		return ( $max > 0 ) ? $winner : 'informational';
	}

	public function getConfidenceScore( int $postId ): array {
		$post    = get_post( $postId );
		$keyword = (string) get_post_meta( $postId, '_holvek_focus_keyword', true );

		if ( ! $post ) {
			return array(
				'scores'        => array(),
				'winner'        => 'informational',
				'total_signals' => 0,
			);
		}

		$text = mb_strtolower(
			$keyword . ' ' .
			$post->post_title . ' ' .
			wp_strip_all_tags( mb_substr( $post->post_content, 0, 1500 ) )
		);

		$scores = array(
			'transactional' => 0,
			'commercial'    => 0,
			'navigational'  => 0,
			'informational' => 0,
		);
		$found  = array();

		foreach ( self::SIGNALS as $intent => $signals ) {
			foreach ( $signals as $signal ) {
				if ( mb_strpos( $text, mb_strtolower( $signal ) ) !== false ) {
					++$scores[ $intent ];
					$found[] = array(
						'intent' => $intent,
						'signal' => $signal,
					);
				}
			}
		}

		arsort( $scores );
		$winner = array_key_first( $scores );
		$total  = array_sum( $scores );

		return array(
			'scores'        => $scores,
			'winner'        => $winner,
			'total_signals' => $total,
			'confidence'    => $total > 0 ? round( ( $scores[ $winner ] / $total ) * 100, 1 ) : 0,
			'signals_found' => $found,
		);
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		$editorCap = fn() => current_user_can( 'edit_posts' );

		register_rest_route(
			'holvek/v1',
			'/content/intent',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getIntent' ),
				'permission_callback' => $editorCap,
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/content/intent/bulk-classify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulkClassify' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);
	}

	public function getIntent( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'post_id' );
		$post   = get_post( $postId );

		if ( ! $post ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'holvek' ), array( 'status' => 404 ) );
		}

		$stored     = get_post_meta( $postId, self::META_KEY, true );
		$confidence = $this->getConfidenceScore( $postId );

		return rest_ensure_response(
			array(
				'post_id'           => $postId,
				'intent'            => $stored ?: $confidence['winner'],
				'confidence_score'  => $confidence['confidence'],
				'signals_found'     => $confidence['signals_found'],
				'optimisation_tips' => self::TIPS[ $confidence['winner'] ] ?? array(),
			)
		);
	}

	public function bulkClassify( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_query'     => array(
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$classified = 0;
		foreach ( $query->posts as $post ) {
			$intent = $this->classifyPost( $post->ID );
			update_post_meta( $post->ID, self::META_KEY, $intent );
			++$classified;
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'classified' => $classified,
				'remaining'  => max( 0, $query->found_posts - $classified ),
			)
		);
	}
}
