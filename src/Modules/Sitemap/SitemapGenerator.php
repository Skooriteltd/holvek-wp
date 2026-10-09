<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a Sitemap Index and date-based sub-sitemaps.
 *
 * Architecture:
 *   /sitemap.xml           → <sitemapindex> listing all YYYY-MM sub-sitemaps
 *   /sitemap-posts-2024-05.xml → <urlset> with posts from May 2024
 *   /sitemap-posts-2024-05-2.xml → the next page when that month exceeds the URL limit
 *
 * Performance:
 *   - Uses raw $wpdb queries joining wp_postmeta to pre-filter noindex in a single pass.
 *   - Each sub-sitemap is capped at the configured URL limit and cached independently via transients.
 *   - Eliminates the N+1 query storm from the old get_posts + get_post_meta loop.
 */
class SitemapGenerator {

	private const INDEX_TRANSIENT        = 'holvek_sitemap_index_cache';
	private const CHUNK_TRANSIENT_PREFIX = 'holvek_sitemap_chunk_';
	private const CACHE_EXPIRATION       = 4 * HOUR_IN_SECONDS;
	private const MAX_URLS_PER_CHUNK     = 1000;

	/**
	 * Serve the sitemap index or a date-based chunk.
	 *
	 * @param string|null $chunk e.g. '2024-05' or null for the index.
	 * @param int         $page  Page within that month. Page 1 has no suffix.
	 */
	public function serveSitemap( ?string $chunk = null, int $page = 1 ): void {
		header( 'Content-Type: text/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow', true );

		if ( $chunk === null ) {
			echo $this->indexXml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo $this->chunkXml( $chunk, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Sitemap index XML. Cached per URL limit.
	 */
	public function indexXml(): string {
		$key = self::INDEX_TRANSIENT . '_' . self::urlLimit();
		$xml = get_transient( $key );
		if ( is_string( $xml ) ) {
			return $xml;
		}

		$xml = $this->buildIndex();
		set_transient( $key, $xml, self::CACHE_EXPIRATION );
		return $xml;
	}

	/**
	 * One month page of the sitemap.
	 */
	public function chunkXml( string $period, int $page = 1 ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) || $page < 1 ) {
			return $this->emptyUrlset();
		}

		$key = self::CHUNK_TRANSIENT_PREFIX . $period . '_' . $page . '_' . self::urlLimit();
		$xml = get_transient( $key );
		if ( is_string( $xml ) ) {
			return $xml;
		}

		$xml = $this->buildChunk( $period, $page );
		set_transient( $key, $xml, self::CACHE_EXPIRATION );
		return $xml;
	}

	/**
	 * URLs stored in one sitemap file.
	 *
	 * The default is 1,000. The holvek_sitemap_urls_per_file filter may set
	 * another limit from 1 to 50,000, which is the sitemap protocol maximum.
	 */
	public static function urlLimit(): int {
		$filtered = apply_filters( 'holvek_sitemap_urls_per_file', self::MAX_URLS_PER_CHUNK );
		$limit    = is_numeric( $filtered ) ? (int) $filtered : self::MAX_URLS_PER_CHUNK;
		if ( $limit < 1 ) {
			$limit = self::MAX_URLS_PER_CHUNK;
		}
		if ( $limit > 50000 ) {
			$limit = 50000;
		}
		return $limit;
	}

	/**
	 * Clear all sitemap caches (call when posts are updated or settings change).
	 */
	public static function clearCache(): void {
		delete_transient( self::INDEX_TRANSIENT );

		global $wpdb;
		foreach ( array( self::INDEX_TRANSIENT, self::CHUNK_TRANSIENT_PREFIX ) as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
				)
			);
		}
	}

	/**
	 * Generate the sitemapindex XML listing every date-based file.
	 */
	private function buildIndex(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$limit = self::urlLimit();
		foreach ( $this->periodCounts() as $row ) {
			$period = (string) ( $row->period ?? '' );
			$count  = (int) ( $row->url_count ?? 0 );
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) || $count < 1 ) {
				continue;
			}

			$pages = (int) ceil( $count / $limit );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$suffix = $page === 1 ? '' : '-' . $page;
				$loc    = home_url( '/sitemap-posts-' . $period . $suffix . '.xml' );
				$xml   .= "  <sitemap>\n";
				$xml   .= '    <loc>' . esc_url( $loc ) . "</loc>\n";
				$xml   .= "  </sitemap>\n";
			}
		}

		if ( SitemapSettings::get( 'enable_news_sitemap', 'no' ) === 'yes' ) {
			$xml .= "  <sitemap>\n";
			$xml .= '    <loc>' . esc_url( home_url( '/sitemap-news.xml' ) ) . "</loc>\n";
			$xml .= "  </sitemap>\n";
		}

		if ( SitemapSettings::get( 'enable_video_sitemap', 'no' ) === 'yes' ) {
			$xml .= "  <sitemap>\n";
			$xml .= '    <loc>' . esc_url( home_url( '/video-sitemap.xml' ) ) . "</loc>\n";
			$xml .= "  </sitemap>\n";
		}

		$xml .= '</sitemapindex>';
		return $xml;
	}

	/**
	 * Published, indexable URL counts grouped by YYYY-MM.
	 *
	 * @return array<int, object>
	 */
	private function periodCounts(): array {
		global $wpdb;

		$postTypes = $this->getActivePostTypes();
		if ( $postTypes === array() ) {
			return array();
		}

		$args       = $postTypes;
		$excludeSql = esc_sql( $this->excludedIdFragment( $args ) );
		$inList     = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );

		// The IN list and the optional ID exclusion are placeholder strings. Values go through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$sql  = $wpdb->prepare(
			"SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS period, COUNT(DISTINCT p.ID) AS url_count
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON p.ID = pm.post_id AND pm.meta_key = '_holvek_noindex'
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ($inList)
			   AND (pm.meta_value IS NULL OR pm.meta_value != 'yes')
			   {$excludeSql}
			 GROUP BY period
			 ORDER BY period DESC",
			...$args
		);
		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Generate the urlset XML for one page of a YYYY-MM period.
	 */
	private function buildChunk( string $period, int $page ): string {
		global $wpdb;

		$postTypes = $this->getActivePostTypes();
		if ( $postTypes === array() ) {
			return $this->emptyUrlset();
		}

		$yearMonth  = $period . '-01';
		$args       = array_merge( $postTypes, array( $yearMonth, $yearMonth ) );
		$excludeSql = esc_sql( $this->excludedIdFragment( $args ) );
		$args[]     = self::urlLimit();
		$args[]     = ( $page - 1 ) * self::urlLimit();
		$inList     = implode( ',', array_fill( 0, count( $postTypes ), '%s' ) );

		// The IN list and the optional ID exclusion are placeholder strings. Values go through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$sql  = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_modified_gmt,
			        thumb.meta_value AS thumbnail_id
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON p.ID = pm.post_id AND pm.meta_key = '_holvek_noindex'
			 LEFT JOIN {$wpdb->postmeta} thumb
			   ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ($inList)
			   AND (pm.meta_value IS NULL OR pm.meta_value != 'yes')
			   AND YEAR(p.post_date) = YEAR(%s)
			   AND MONTH(p.post_date) = MONTH(%s)
			   {$excludeSql}
			 ORDER BY p.post_modified_gmt DESC
			 LIMIT %d OFFSET %d",
			...$args
		);
		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! $this->rowIsPublic( $row ) ) {
				continue;
			}

			$postId     = (int) $row->ID;
			$postType   = (string) ( $row->post_type ?? 'post' );
			$url        = get_permalink( $postId );
			$modified   = mysql2date( 'c', (string) ( $row->post_modified_gmt ?? '' ) );
			$priority   = SitemapPriorityManager::getPriority( $postId, $postType );
			$changefreq = SitemapPriorityManager::getChangefreq( $postId, $postType );

			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_url( $url ) . "</loc>\n";
			if ( $modified !== '' ) {
				$xml .= '    <lastmod>' . esc_html( $modified ) . "</lastmod>\n";
			}
			$xml .= '    <changefreq>' . esc_html( $changefreq ) . "</changefreq>\n";
			$xml .= '    <priority>' . esc_html( $priority ) . "</priority>\n";

			if ( ! empty( $row->thumbnail_id ) && function_exists( 'wp_get_attachment_url' ) ) {
				$imageUrl = wp_get_attachment_url( (int) $row->thumbnail_id );
				if ( $imageUrl ) {
					$xml .= "    <image:image>\n";
					$xml .= '      <image:loc>' . esc_url( $imageUrl ) . "</image:loc>\n";
					$xml .= "    </image:image>\n";
				}
			}

			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * Public post types minus the types an administrator excluded.
	 *
	 * An empty public-type list falls back to post and page. A list that
	 * becomes empty because every type was excluded stays empty.
	 *
	 * @return array<int, string>
	 */
	private function getActivePostTypes(): array {
		$postTypes = get_post_types( array( 'public' => true ) );
		if ( ! is_array( $postTypes ) || $postTypes === array() ) {
			$postTypes = array( 'post', 'page' );
		}

		$excluded = SitemapSettings::get( 'sitemap_exclude_types', array() );
		if ( is_array( $excluded ) && $excluded !== array() ) {
			$postTypes = array_diff( $postTypes, $excluded );
		}

		return array_values( $postTypes );
	}

	/**
	 * Placeholder fragment for manually excluded post IDs.
	 *
	 * The text contains only %d placeholders. The ID values are appended to $args.
	 * esc_sql() does not change this fragment.
	 *
	 * @param array<int, mixed> $args Prepared values, updated in place.
	 */
	private function excludedIdFragment( array &$args ): string {
		$ids = $this->getExcludedPostIds();
		if ( $ids === array() ) {
			return '';
		}

		foreach ( $ids as $id ) {
			$args[] = (int) $id;
		}

		return 'AND p.ID NOT IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
	}

	/**
	 * Parse the comma-separated list of manually excluded post IDs.
	 *
	 * @return array<int, int>
	 */
	private function getExcludedPostIds(): array {
		$raw = SitemapSettings::get( 'sitemap_exclude_posts', '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $raw ) as $part ) {
			$id = (int) trim( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, 500 );
	}

	/**
	 * Drop rows the SQL filter should already have removed.
	 */
	private function rowIsPublic( object $row ): bool {
		$postId = (int) ( $row->ID ?? 0 );
		if ( $postId < 1 ) {
			return false;
		}
		if ( (int) get_option( 'page_on_front' ) === $postId ) {
			return false;
		}
		if ( get_post_meta( $postId, '_holvek_noindex', true ) === 'yes' ) {
			return false;
		}
		if ( in_array( $postId, $this->getExcludedPostIds(), true ) ) {
			return false;
		}

		$type     = (string) ( $row->post_type ?? '' );
		$excluded = SitemapSettings::get( 'sitemap_exclude_types', array() );
		if ( is_array( $excluded ) && in_array( $type, $excluded, true ) ) {
			return false;
		}
		if ( SitemapPriorityManager::isExcluded( $postId, $type ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return a valid but empty urlset for invalid chunk requests.
	 */
	private function emptyUrlset(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
	}
}
