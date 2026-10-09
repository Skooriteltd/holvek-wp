<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal link recommendation engine with indexed orphan detection.
 *
 * Architecture:
 *   - Link Index Table (wp_holvek_links): Populated on post save by parsing
 *     HTML content and extracting internal links. Columns: post_id, target_url, anchor_text.
 *   - Orphan Detection: Finding incoming links is now a lightning-fast indexed
 *     SELECT COUNT(*) WHERE target_url = %s, instead of the old LIKE '%url%' full-table scan.
 */
class InternalLinkEngine {

	/**
	 * Get contextual internal link recommendations for the current content.
	 */
	public function getRecommendations( int $postId, string $content ): array {
		if ( empty( $content ) ) {
			return array();
		}

		// Get 40 most recent published posts/pages for quick scanning
		$args = array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'posts_per_page'         => 40,
			'post__not_in'           => array( $postId ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$posts           = get_posts( $args );
		$recommendations = array();
		$contentLower    = mb_strtolower( wp_strip_all_tags( $content ) );

		// 1. Exact Title / Focus Keyword Substring Match
		foreach ( $posts as $p ) {
			$title      = $p->post_title;
			$titleLower = mb_strtolower( $title );

			// Skip extremely short titles (less than 4 chars) to avoid false matches
			if ( strlen( $titleLower ) < 4 ) {
				continue;
			}

			// Look for title substring in content
			$pos = mb_strpos( $contentLower, $titleLower );
			if ( $pos !== false ) {
				// Find the original casing in the content
				$matchedAnchor = mb_substr( wp_strip_all_tags( $content ), $pos, mb_strlen( $title ) );

				$recommendations[] = array(
					'type'    => 'anchor_match',
					'post_id' => $p->ID,
					'title'   => $title,
					'url'     => get_permalink( $p->ID ),
					'anchor'  => $matchedAnchor,
					'reason'  => sprintf( /* translators: %s: Post title */ __( 'Matches the exact title of "%s"', 'holvek' ), $title ),
				);
			}
		}

		// 2. Category & Tag Taxonomic overlap search
		if ( count( $recommendations ) < 5 ) {
			$categories = wp_get_post_categories( $postId );
			$tags       = wp_get_post_tags( $postId );
			$taxIds     = array_merge( $categories, wp_list_pluck( $tags, 'term_id' ) );

			if ( ! empty( $taxIds ) ) {
				$argsOverlap = array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'post__not_in'   => array_merge( array( $postId ), wp_list_pluck( $recommendations, 'post_id' ) ),
					'tax_query'      => array(
						'relation' => 'OR',
						array(
							'taxonomy' => 'category',
							'field'    => 'term_id',
							'terms'    => $taxIds,
						),
						array(
							'taxonomy' => 'post_tag',
							'field'    => 'term_id',
							'terms'    => $taxIds,
						),
					),
					'no_found_rows'  => true,
				);

				$overlapPosts = get_posts( $argsOverlap );
				foreach ( $overlapPosts as $op ) {
					if ( count( $recommendations ) >= 5 ) {
						break;
					}

					$recommendations[] = array(
						'type'    => 'topic_overlap',
						'post_id' => $op->ID,
						'title'   => $op->post_title,
						'url'     => get_permalink( $op->ID ),
						'anchor'  => __( 'related content', 'holvek' ),
						'reason'  => __( 'Shares matching categories or tags.', 'holvek' ),
					);
				}
			}
		}

		return array_slice( $recommendations, 0, 5 );
	}

	/**
	 * Verify if the current post is an "Orphan" (has zero incoming internal links).
	 *
	 * Uses the indexed wp_holvek_links table for O(1) lookup instead of
	 * the previous LIKE '%url%' full-table scan on wp_posts.
	 */
	public function checkOrphanStatus( int $postId ): array {
		global $wpdb;

		$permalink = get_permalink( $postId );
		if ( ! $permalink ) {
			return array(
				'is_orphan'      => true,
				'incoming_count' => 0,
			);
		}

		$path = wp_make_link_relative( $permalink );

		// Lightning-fast indexed query against the link index table
		$incomingCount = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_links WHERE target_url = %s OR target_url = %s",
				$permalink,
				$path
			)
		);

		return array(
			'is_orphan'      => ( $incomingCount === 0 ),
			'incoming_count' => $incomingCount,
		);
	}

	/**
	 * Index all internal links found in a post's content.
	 * Called on save_post hook to keep the link index fresh.
	 *
	 * @param int      $postId  The ID of the post being saved.
	 * @param \WP_Post $post    The post object.
	 */
	public function indexPostLinks( int $postId, \WP_Post $post ): void {
		// Only index published posts/pages
		if ( $post->post_status !== 'publish' || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Skip auto-saves and revisions
		if ( wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'holvek_links';

		// Clear existing links for this post — full re-index on each save
		$wpdb->delete( $table, array( 'post_id' => $postId ), array( '%d' ) );

		$content = $post->post_content;
		if ( empty( $content ) ) {
			return;
		}

		$siteUrl    = home_url();
		$siteDomain = wp_parse_url( $siteUrl, PHP_URL_HOST );

		// Parse all <a> tags from the content
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $content, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		$inserted = array();
		foreach ( $matches as $match ) {
			$href   = $match[1];
			$anchor = wp_strip_all_tags( $match[2] );

			// Determine if this is an internal link
			if ( ! $this->isInternalUrl( $href, $siteDomain, $siteUrl ) ) {
				continue;
			}

			// Normalize the target URL to relative path for consistent matching
			$targetUrl = $this->normalizeTargetUrl( $href, $siteUrl );

			// Deduplicate within the same post
			$dedupKey = $postId . '::' . $targetUrl;
			if ( isset( $inserted[ $dedupKey ] ) ) {
				continue;
			}
			$inserted[ $dedupKey ] = true;

			$wpdb->insert(
				$table,
				array(
					'post_id'     => $postId,
					'target_url'  => $targetUrl,
					'anchor_text' => mb_substr( $anchor, 0, 255 ),
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Remove all links from the index when a post is deleted.
	 */
	public function removePostLinks( int $postId ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'holvek_links';
		$wpdb->delete( $table, array( 'post_id' => $postId ), array( '%d' ) );
	}

	/**
	 * Check if a URL points to the same site.
	 */
	private function isInternalUrl( string $href, string $siteDomain, string $siteUrl ): bool {
		if ( empty( $href ) || $href[0] === '#' || strpos( $href, 'javascript:' ) === 0
			|| strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 ) {
			return false;
		}

		// Relative URLs are internal
		if ( $href[0] === '/' && ( $href[1] ?? '' ) !== '/' ) {
			return true;
		}

		// Absolute URL — check domain matches
		$parsedHost = wp_parse_url( $href, PHP_URL_HOST );
		if ( $parsedHost === null ) {
			return true; // Relative path without leading slash
		}

		return strcasecmp( $parsedHost, $siteDomain ) === 0;
	}

	/**
	 * Normalize a target URL to a consistent relative path for index matching.
	 */
	private function normalizeTargetUrl( string $href, string $siteUrl ): string {
		// If absolute URL pointing to our site, extract the path
		if ( strpos( $href, 'http://' ) === 0 || strpos( $href, 'https://' ) === 0 ) {
			$path = wp_parse_url( $href, PHP_URL_PATH );
			return $path ?: '/';
		}

		// Already relative
		if ( $href[0] === '/' ) {
			return strtok( $href, '?' ); // Strip query strings
		}

		return '/' . strtok( $href, '?' );
	}
}
