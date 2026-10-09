<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Modules\Indexing\BingIndexNowApi;

/**
 * Manages rewrite rules and virtual endpoint interception for XML sitemaps and IndexNow keys.
 *
 * Supported routes:
 *   /sitemap.xml                   → Sitemap Index
 *   /sitemap-posts-YYYY-MM.xml     → Date-based sub-sitemap chunk
 *   /sitemap-posts-YYYY-MM-N.xml   → Later page when that month exceeds the URL limit
 *   /sitemap-news.xml              → Google News sitemap (last 48 hours)
 *   /news-sitemap.xml              → Legacy alias for news sitemap
 *   /video-sitemap.xml             → Video sitemap
 *   /{indexnow-key}.txt            → IndexNow verification file
 */
class SitemapRouteManager {

	private SitemapGenerator $sitemapGenerator;
	private NewsSitemapGenerator $newsSitemapGenerator;
	private VideoSitemapGenerator $videoSitemapGenerator;

	public function __construct(
		SitemapGenerator $sitemapGenerator,
		NewsSitemapGenerator $newsSitemapGenerator,
		VideoSitemapGenerator $videoSitemapGenerator
	) {
		$this->sitemapGenerator      = $sitemapGenerator;
		$this->newsSitemapGenerator  = $newsSitemapGenerator;
		$this->videoSitemapGenerator = $videoSitemapGenerator;
	}

	public function addRewriteRules(): void {
		if ( SitemapSettings::get( 'enable_index_sitemap', 'no' ) === 'yes' ) {
			// Main sitemap index
			add_rewrite_rule( '^sitemap\.xml$', 'index.php?holvek_sitemap=index', 'top' );

			// Date-based sub-sitemap chunks: sitemap-posts-2024-05.xml and sitemap-posts-2024-05-2.xml
			add_rewrite_rule(
				'^sitemap-posts-(\d{4}-\d{2})(?:-([2-9]|[1-9]\d+))?\.xml$',
				'index.php?holvek_sitemap=chunk&holvek_sitemap_period=$matches[1]&holvek_sitemap_page=$matches[2]',
				'top'
			);
		}

		if ( SitemapSettings::get( 'enable_news_sitemap', 'no' ) === 'yes' ) {
			// Primary news sitemap route
			add_rewrite_rule( '^sitemap-news\.xml$', 'index.php?holvek_sitemap=news', 'top' );
			// Legacy alias for backward compatibility
			add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?holvek_sitemap=news', 'top' );
		}

		if ( SitemapSettings::get( 'enable_video_sitemap', 'no' ) === 'yes' ) {
			add_rewrite_rule( '^video-sitemap\.xml$', 'index.php?holvek_sitemap=video', 'top' );
		}

		$bingKey = (string) get_option( 'holvek_indexnow_key', '' );
		if ( BingIndexNowApi::isValidKey( $bingKey ) ) {
			add_rewrite_rule( '^' . preg_quote( $bingKey, '/' ) . '\.txt$', 'index.php?holvek_bing_key=1', 'top' );
		}
	}

	/**
	 * Register custom query vars so WordPress passes them through.
	 */
	public function registerQueryVars( array $vars ): array {
		$vars[] = 'holvek_sitemap';
		$vars[] = 'holvek_sitemap_period';
		$vars[] = 'holvek_sitemap_page';
		$vars[] = 'holvek_bing_key';
		return $vars;
	}

	public function handleSitemapRequests(): void {
		$type = '';
		if ( function_exists( 'get_query_var' ) ) {
			$queried = get_query_var( 'holvek_sitemap' );
			if ( is_string( $queried ) ) {
				$type = $queried;
			}
		}

		if ( $type !== '' ) {
			$period = (string) get_query_var( 'holvek_sitemap_period' );
			$page   = (int) get_query_var( 'holvek_sitemap_page' );
			$this->dispatch( $type, $period, $page > 0 ? $page : 1 );
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		// Strip query strings for clean matching
		$path = strtok( $uri, '?' );
		if ( ! is_string( $path ) ) {
			return;
		}

		// ── News Sitemap (sitemap-news.xml or legacy news-sitemap.xml) ──
		if ( preg_match( '#/(sitemap-news|news-sitemap)\.xml$#', $path ) ) {
			$this->dispatch( 'news', '', 1 );
		}

		// ── Video Sitemap ──
		if ( preg_match( '#/video-sitemap\.xml$#', $path ) ) {
			$this->dispatch( 'video', '', 1 );
		}

		// ── Date-based sub-sitemap chunk (sitemap-posts-2024-05.xml or sitemap-posts-2024-05-2.xml) ──
		if ( preg_match( '#/sitemap-posts-(\d{4}-\d{2})(?:-([2-9]|[1-9]\d+))?\.xml$#', $path, $matches ) ) {
			$page = isset( $matches[2] ) && $matches[2] !== '' ? (int) $matches[2] : 1;
			$this->dispatch( 'chunk', $matches[1], $page );
		}

		// ── Main Sitemap Index ──
		if ( preg_match( '#/sitemap\.xml$#', $path ) ) {
			$this->dispatch( 'index', '', 1 );
		}

		// IndexNow verification file. Match the home path, not a substring.
		$bingKey = (string) get_option( 'holvek_indexnow_key', '' );
		if ( BingIndexNowApi::keyFilePathMatches( $uri, home_url(), $bingKey ) ) {
			BingIndexNowApi::sendKeyFile( $bingKey );
		}
	}

	/**
	 * Serve one sitemap type, or 404 when that sitemap is turned off.
	 */
	private function dispatch( string $type, string $period, int $page ): void {
		if ( $type === 'news' ) {
			if ( SitemapSettings::get( 'enable_news_sitemap', 'no' ) === 'yes' ) {
				$this->newsSitemapGenerator->serveSitemap();
				exit;
			}
			$this->trigger404();
		}

		if ( $type === 'video' ) {
			if ( SitemapSettings::get( 'enable_video_sitemap', 'no' ) === 'yes' ) {
				$this->videoSitemapGenerator->serveSitemap();
				exit;
			}
			$this->trigger404();
		}

		if ( $type === 'chunk' ) {
			if ( SitemapSettings::get( 'enable_index_sitemap', 'no' ) === 'yes' && preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
				$this->sitemapGenerator->serveSitemap( $period, max( 1, $page ) );
				exit;
			}
			$this->trigger404();
		}

		if ( $type === 'index' ) {
			if ( SitemapSettings::get( 'enable_index_sitemap', 'no' ) === 'yes' ) {
				$this->sitemapGenerator->serveSitemap( null );
				exit;
			}
			$this->trigger404();
		}
	}

	private function trigger404(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		get_template_part( '404' );
		exit;
	}

	public function flushRules(): void {
		$this->addRewriteRules();
		flush_rewrite_rules();
	}

	/**
	 * Remove persisted plugin routes during deactivation, then rebuild the
	 * ruleset without them. This is intentionally separate from flushRules(),
	 * which adds the current plugin routes before persisting them.
	 */
	public function removeRulesAndFlush(): void {
		global $wp_rewrite;

		if ( isset( $wp_rewrite->extra_rules_top ) && is_array( $wp_rewrite->extra_rules_top ) ) {
			foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $pattern ) {
				if (
					str_starts_with( $pattern, '^sitemap\\.xml$' ) ||
					str_starts_with( $pattern, '^sitemap-posts-' ) ||
					str_starts_with( $pattern, '^sitemap-news\\.xml$' ) ||
					str_starts_with( $pattern, '^news-sitemap\\.xml$' ) ||
					str_starts_with( $pattern, '^video-sitemap\\.xml$' ) ||
					str_contains( (string) $wp_rewrite->extra_rules_top[ $pattern ], 'holvek_' )
				) {
					unset( $wp_rewrite->extra_rules_top[ $pattern ] );
				}
			}
		}

		flush_rewrite_rules();
	}
}
