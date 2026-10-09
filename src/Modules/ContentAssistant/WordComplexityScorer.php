<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordComplexityScorer
 *
 * Analyses post content for polysyllabic words that may reduce readability.
 * Returns a complexity score (0–100, lower = simpler) and flags specific
 * words with simpler alternatives where available.
 *
 * BL-029
 */
class WordComplexityScorer {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/content/complexity',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scoreContent' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	public function scoreContent( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = $request->get_json_params();
		$postId     = absint( $params['post_id'] ?? 0 );
		$rawContent = $params['content'] ?? '';

		if ( $postId > 0 ) {
			$rawContent = get_post_field( 'post_content', $postId );
		}

		$text  = wp_strip_all_tags( $rawContent );
		$words = $this->tokenise( $text );

		if ( empty( $words ) ) {
			return rest_ensure_response(
				array(
					'total_words'       => 0,
					'complex_words'     => 0,
					'complexity_score'  => 0.0,
					'complex_word_list' => array(),
					'grade'             => 'simple',
				)
			);
		}

		$complexMap = array();
		foreach ( $words as $word ) {
			$lower = strtolower( $word );
			if ( $this->isComplex( $lower ) ) {
				if ( ! isset( $complexMap[ $lower ] ) ) {
					$complexMap[ $lower ] = array(
						'word'       => $lower,
						'syllables'  => $this->countSyllables( $lower ),
						'suggestion' => self::SIMPLE_ALTERNATIVES[ $lower ] ?? null,
						'count'      => 0,
					);
				}
				++$complexMap[ $lower ]['count'];
			}
		}

		$totalWords   = count( $words );
		$complexCount = array_sum( array_column( $complexMap, 'count' ) );
		$score        = ( $totalWords > 0 ) ? round( ( $complexCount / $totalWords ) * 100, 1 ) : 0.0;

		$grade = match ( true ) {
			$score < 10  => 'simple',
			$score < 20  => 'moderate',
			default      => 'complex',
		};

		// Sort by frequency descending
		$wordList = array_values( $complexMap );
		usort( $wordList, fn( $a, $b ) => $b['count'] <=> $a['count'] );

		return rest_ensure_response(
			array(
				'post_id'           => $postId ?: null,
				'total_words'       => $totalWords,
				'complex_words'     => $complexCount,
				'complexity_score'  => $score,
				'complex_word_list' => array_slice( $wordList, 0, 50 ),
				'grade'             => $grade,
			)
		);
	}

	// ── Syllable counting ─────────────────────────────────────────────────────

	public function countSyllables( string $word ): int {
		$word = strtolower( preg_replace( '/[^a-z]/', '', $word ) );

		if ( empty( $word ) ) {
			return 1;
		}

		// Strip common silent suffixes
		$word = preg_replace( '/(?:[^laeiouy]es|[^laeiouy]ed|[aeiou]le|e)$/', '', $word );

		// Count vowel groups
		preg_match_all( '/[aeiouy]+/', $word, $matches );
		$count = count( $matches[0] );

		return max( 1, $count );
	}

	public function isComplex( string $word ): bool {
		if ( mb_strlen( $word ) < 4 ) {
			return false;
		}

		if ( in_array( $word, self::COMMON_WORDS, true ) ) {
			return false;
		}

		return $this->countSyllables( $word ) >= 3;
	}

	private function tokenise( string $text ): array {
		// Remove punctuation and split
		$clean = preg_replace( '/[^a-zA-Z\s]/', ' ', $text );
		return array_filter( explode( ' ', (string) $clean ), fn( $w ) => mb_strlen( trim( $w ) ) > 1 );
	}

	// ── Data ──────────────────────────────────────────────────────────────────

	private const SIMPLE_ALTERNATIVES = array(
		'utilise'        => 'use',
		'utilize'        => 'use',
		'facilitate'     => 'help',
		'implement'      => 'use',
		'demonstrate'    => 'show',
		'approximately'  => 'about',
		'nevertheless'   => 'but',
		'subsequently'   => 'then',
		'terminate'      => 'end',
		'commence'       => 'start',
		'sufficient'     => 'enough',
		'requirement'    => 'need',
		'endeavour'      => 'try',
		'endeavor'       => 'try',
		'optimise'       => 'improve',
		'optimize'       => 'improve',
		'comprehensive'  => 'complete',
		'additional'     => 'extra',
		'frequently'     => 'often',
		'majority'       => 'most',
		'numerous'       => 'many',
		'currently'      => 'now',
		'therefore'      => 'so',
		'modification'   => 'change',
		'characteristic' => 'feature',
		'consideration'  => 'thought',
		'communicate'    => 'tell',
		'fundamental'    => 'basic',
		'acknowledge'    => 'accept',
	);

	private const COMMON_WORDS = array(
		'the',
		'be',
		'to',
		'of',
		'and',
		'a',
		'in',
		'that',
		'have',
		'it',
		'for',
		'not',
		'on',
		'with',
		'he',
		'as',
		'you',
		'do',
		'at',
		'this',
		'but',
		'his',
		'by',
		'from',
		'they',
		'we',
		'say',
		'her',
		'she',
		'or',
		'an',
		'will',
		'my',
		'one',
		'all',
		'would',
		'there',
		'their',
		'what',
		'so',
		'up',
		'out',
		'if',
		'about',
		'who',
		'get',
		'which',
		'go',
		'me',
		'when',
		'make',
		'can',
		'like',
		'time',
		'no',
		'just',
		'him',
		'know',
		'take',
		'people',
		'into',
		'year',
		'your',
		'good',
		'some',
		'could',
		'them',
		'see',
		'other',
		'than',
		'then',
		'now',
		'look',
		'only',
		'come',
		'its',
		'over',
		'think',
		'also',
		'back',
		'after',
		'use',
		'two',
		'how',
		'our',
		'work',
		'first',
		'well',
		'way',
		'even',
		'new',
		'want',
		'any',
		'these',
		'give',
		'day',
		'most',
		'us',
		'great',
		'between',
		'need',
		'large',
		'often',
		'hand',
		'high',
		'place',
		'hold',
		'turn',
		'help',
		'around',
		'open',
		'seem',
		'together',
		'next',
		'white',
		'children',
		'begin',
		'got',
		'walk',
		'example',
		'ease',
		'paper',
		'group',
		'always',
		'music',
		'those',
		'both',
		'mark',
		'book',
		'letter',
		'until',
		'mile',
		'river',
		'car',
		'feet',
		'care',
		'second',
		'enough',
		'plain',
		'girl',
		'usual',
		'young',
		'ready',
		'above',
		'ever',
		'red',
		'list',
		'though',
		'feel',
		'talk',
		'bird',
		'soon',
		'body',
		'dog',
		'family',
		'direct',
		'pose',
		'leave',
		'song',
		'measure',
		'door',
		'product',
		'black',
		'short',
		'numeral',
		'class',
		'wind',
		'question',
		'happen',
		'complete',
		'ship',
		'area',
		'half',
		'rock',
		'order',
		'fire',
		'south',
		'problem',
		'piece',
		'told',
		'knew',
		'pass',
		'since',
		'top',
		'whole',
		'king',
		'space',
		'heard',
		'best',
		'hour',
		'better',
		'true',
	);
}
