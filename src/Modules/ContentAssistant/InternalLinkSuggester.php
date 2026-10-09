<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InternalLinkSuggester
 *
 * Suggests contextually relevant internal links for the post being edited.
 * Matches on focus keyword overlap and deprioritises already-linked posts.
 * Orphaned posts (zero inbound links) are surfaced as high-priority targets.
 *
 * BL-009
 */
class InternalLinkSuggester {

	private const CACHE_TTL = HOUR_IN_SECONDS;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/content/internal-link-suggestions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getSuggestions' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function getSuggestions( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'post_id' );

		$cacheKey = 'holvek_link_suggestions_' . $postId;
		$cached   = get_transient( $cacheKey );
		if ( $cached !== false ) {
			return rest_ensure_response( $cached );
		}

		$sourcePost = get_post( $postId );
		if ( ! $sourcePost ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'holvek' ), array( 'status' => 404 ) );
		}

		// Get focus keywords
		$keywordRaw = (string) get_post_meta( $postId, '_holvek_focus_keyword', true );
		$keywords   = array_filter( array_map( 'trim', explode( ',', $keywordRaw ) ) );

		// Find candidate posts
		$candidates = $this->findCandidates( $postId, $keywords );

		// Find already-linked post URLs to exclude them
		$linkedUrls = $this->extractLinkedUrls( $sourcePost->post_content );

		// Filter out already-linked posts and the source itself
		$suggestions = array();
		foreach ( $candidates as $candidate ) {
			$url = get_permalink( $candidate->ID );
			if ( in_array( $url, $linkedUrls, true ) ) {
				continue;
			}
			if ( $candidate->ID === $postId ) {
				continue;
			}

			$candidateKeyword = get_post_meta( $candidate->ID, '_holvek_focus_keyword', true );
			$inboundLinks     = (int) get_post_meta( $candidate->ID, '_holvek_inbound_links', true );

			$suggestions[] = array(
				'id'                    => $candidate->ID,
				'title'                 => $candidate->post_title,
				'url'                   => $url,
				'excerpt'               => $this->getExcerpt( $candidate, 30 ),
				'suggested_anchor_text' => $candidateKeyword ?: $candidate->post_title,
				'orphan'                => $inboundLinks === 0,
			);

			if ( count( $suggestions ) >= 8 ) {
				break;
			}
		}

		// Promote orphaned posts to top
		usort( $suggestions, fn( $a, $b ) => $b['orphan'] <=> $a['orphan'] );

		$result = array(
			'post_id'     => $postId,
			'suggestions' => $suggestions,
		);
		set_transient( $cacheKey, $result, self::CACHE_TTL );

		return rest_ensure_response( $result );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function findCandidates( int $sourcePostId, array $keywords ): array {
		if ( empty( $keywords ) ) {
			// No keywords: fall back to recently modified posts
			$q = new \WP_Query(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'post__not_in'   => array( $sourcePostId ),
				)
			);
			return $q->posts;
		}

		$metaQueries = array( 'relation' => 'OR' );
		foreach ( $keywords as $kw ) {
			$metaQueries[] = array(
				'key'     => '_holvek_focus_keyword',
				'value'   => $kw,
				'compare' => 'LIKE',
			);
		}

		$q = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'post__not_in'   => array( $sourcePostId ),
				'meta_query'     => $metaQueries,
			)
		);

		$posts = $q->posts;

		// Supplement with title-based matches if we have fewer than 8
		if ( count( $posts ) < 8 ) {
			$titleMatches = $this->findByTitleKeywords( $sourcePostId, $keywords, wp_list_pluck( $posts, 'ID' ) );
			$posts        = array_merge( $posts, $titleMatches );
		}

		return $posts;
	}

	private function findByTitleKeywords( int $excludeId, array $keywords, array $excludeIds ): array {
		global $wpdb;
		$excludeIds[] = $excludeId;
		$excludeIds   = array_map( 'absint', $excludeIds );

		$results = array();
		foreach ( $keywords as $kw ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->prefix}posts
                     WHERE post_status = 'publish'
                       AND post_type IN ('post','page')
                       AND ID NOT IN (" . implode( ',', array_fill( 0, count( $excludeIds ), '%d' ) ) . ')
                       AND post_title LIKE %s
                     LIMIT 5',
					array_merge( $excludeIds, array( '%' . $wpdb->esc_like( $kw ) . '%' ) )
				)
			);
			foreach ( $rows as $row ) {
				if ( ! in_array( $row->ID, array_column( $results, 'ID' ), true ) ) {
					$results[] = $row;
				}
			}
		}

		return $results;
	}

	/**
	 * Extract absolute URLs from all <a href="..."> in the content.
	 */
	private function extractLinkedUrls( string $content ): array {
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
		return array_unique( $matches[1] ?? array() );
	}

	private function getExcerpt( object $post, int $wordCount ): string {
		$text  = wp_strip_all_tags( $post->post_content );
		$words = explode( ' ', $text );
		return implode( ' ', array_slice( $words, 0, $wordCount ) ) . ( count( $words ) > $wordCount ? '…' : '' );
	}
}
