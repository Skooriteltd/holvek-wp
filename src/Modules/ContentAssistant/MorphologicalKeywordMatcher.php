<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MorphologicalKeywordMatcher
 *
 * Recognises inflected and stemmed forms of a focus keyword when scoring
 * content. English-only stemming via a Porter-Stemmer approximation.
 *
 * BL-025
 */
class MorphologicalKeywordMatcher {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/content/keyword-density',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyseKeywordDensity' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/content/stem',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stemEndpoint' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	public function analyseKeywordDensity( \WP_REST_Request $request ): \WP_REST_Response {
		$params  = $request->get_json_params();
		$postId  = absint( $params['post_id'] ?? 0 );
		$keyword = sanitize_text_field( $params['keyword'] ?? '' );

		if ( $postId > 0 ) {
			$keyword = $keyword ?: (string) get_post_meta( $postId, '_holvek_focus_keyword', true );
			$content = wp_strip_all_tags( get_post_field( 'post_content', $postId ) );
		} else {
			$content = wp_strip_all_tags( $params['content'] ?? '' );
		}

		if ( empty( $keyword ) || empty( $content ) ) {
			return new \WP_Error( 'missing_input', __( 'keyword and content (or post_id) are required.', 'holvek' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $this->analyseContent( $content, $keyword ) );
	}

	public function stemEndpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$word   = sanitize_text_field( $params['word'] ?? '' );
		if ( empty( $word ) ) {
			return new \WP_Error( 'missing_word', __( 'word is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'word'  => $word,
				'stem'  => $this->stem( $word ),
				'forms' => $this->inflections( $word ),
			)
		);
	}

	// ── Core analysis ─────────────────────────────────────────────────────────

	public function analyseContent( string $content, string $keyword ): array {
		$words   = $this->tokenise( $content );
		$total   = count( $words );
		$kw      = mb_strtolower( trim( $keyword ) );
		$kwStem  = $this->stem( $kw );
		$kwForms = $this->inflections( $kw );

		$exact       = 0;
		$morphoCount = 0;
		$matches     = array();

		foreach ( $words as $word ) {
			$lower = mb_strtolower( $word );
			if ( $lower === $kw || mb_strpos( $lower, $kw ) !== false ) {
				++$exact;
				$matches[] = $word;
			} elseif ( $this->stem( $lower ) === $kwStem ) {
				++$morphoCount;
				$matches[] = $word;
			} elseif ( in_array( $lower, $kwForms, true ) ) {
				++$morphoCount;
				$matches[] = $word;
			}
		}

		$combined = $exact + $morphoCount;

		return array(
			'keyword'               => $keyword,
			'keyword_stem'          => $kwStem,
			'keyword_forms'         => $kwForms,
			'total_words'           => $total,
			'exact_matches'         => $exact,
			'morphological_matches' => $morphoCount,
			'total_matches'         => $combined,
			'density_percent'       => $total > 0 ? round( ( $combined / $total ) * 100, 2 ) : 0.0,
			'matched_words'         => array_unique( $matches ),
			'recommendation'        => $this->getRecommendation( $total, $combined ),
		);
	}

	public function getRecommendation( int $totalWords, int $matches ): string {
		if ( $totalWords === 0 ) {
			return 'No content to analyse.';
		}
		$density = ( $matches / $totalWords ) * 100;
		if ( $density < 0.5 ) {
			return 'Keyword density is too low. Add more natural keyword mentions.';
		}
		if ( $density > 3.0 ) {
			return 'Keyword density is too high — this may read as stuffing. Reduce repetition.';
		}
		return 'Keyword density is in the ideal range (0.5%–3.0%). ✅';
	}

	// ── Porter Stemmer (simplified English) ──────────────────────────────────

	public function stem( string $word ): string {
		$word = mb_strtolower( trim( $word ) );

		if ( mb_strlen( $word ) <= 2 ) {
			return $word;
		}

		// Step 1a
		if ( mb_substr( $word, -4 ) === 'sses' ) {
			$word = mb_substr( $word, 0, -2 );
		} elseif ( mb_substr( $word, -3 ) === 'ies' ) {
			$word = mb_substr( $word, 0, -2 );
		} elseif ( mb_substr( $word, -2 ) !== 'ss' && mb_substr( $word, -1 ) === 's' ) {
			$word = mb_substr( $word, 0, -1 );
		}

		// Step 1b
		if ( mb_substr( $word, -3 ) === 'ing' ) {
			$base = mb_substr( $word, 0, -3 );
			if ( mb_strlen( $base ) > 1 ) {
				$word = $base;
			}
		} elseif ( mb_substr( $word, -2 ) === 'ed' ) {
			$base = mb_substr( $word, 0, -2 );
			if ( mb_strlen( $base ) > 1 ) {
				$word = $base;
			}
		} elseif ( mb_substr( $word, -2 ) === 'er' ) {
			$base = mb_substr( $word, 0, -2 );
			if ( mb_strlen( $base ) > 2 ) {
				$word = $base;
			}
		} elseif ( mb_substr( $word, -2 ) === 'ly' ) {
			$base = mb_substr( $word, 0, -2 );
			if ( mb_strlen( $base ) > 2 ) {
				$word = $base;
			}
		}

		return $word;
	}

	public function inflections( string $keyword ): array {
		$kw    = mb_strtolower( trim( $keyword ) );
		$forms = array( $kw );
		$stem  = $this->stem( $kw );

		// Plurals
		$forms[] = $kw . 's';
		$forms[] = $kw . 'es';

		// -ing forms
		if ( mb_substr( $kw, -1 ) === 'e' ) {
			$forms[] = mb_substr( $kw, 0, -1 ) . 'ing';
		} else {
			$forms[] = $kw . 'ing';
		}

		// -ed forms
		if ( mb_substr( $kw, -1 ) === 'e' ) {
			$forms[] = $kw . 'd';
		} else {
			$forms[] = $kw . 'ed';
		}

		// -er form
		$forms[] = $kw . 'er';
		$forms[] = $kw . 'ers';

		// -ly form
		$forms[] = $kw . 'ly';

		// Based on stem
		if ( $stem !== $kw ) {
			$forms[] = $stem;
			$forms[] = $stem . 'ing';
			$forms[] = $stem . 'ed';
			$forms[] = $stem . 's';
		}

		return array_unique( array_values( array_filter( $forms ) ) );
	}

	private function tokenise( string $text ): array {
		$clean = preg_replace( '/[^a-zA-Z\s\-]/', ' ', $text );
		return array_filter( preg_split( '/\s+/', mb_strtolower( (string) $clean ) ), fn( $w ) => mb_strlen( $w ) > 1 );
	}
}
