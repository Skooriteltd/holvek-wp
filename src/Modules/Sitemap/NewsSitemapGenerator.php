<?php

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google News sitemap. Only posts published in the last 48 hours are listed.
 */
class NewsSitemapGenerator {

	private const WINDOW_SECONDS = 48 * HOUR_IN_SECONDS;

	public function serveSitemap(): void {
		header( 'Content-Type: text/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow', true );

		echo $this->generateXml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * News urlset. Posts outside the last 48 hours are omitted even if the query returns them.
	 */
	public function generateXml(): string {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				// Google News accepts at most 1,000 URLs in this file.
				'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'date_query'     => array(
					array(
						'after' => '48 hours ago',
					),
				),
			)
		);

		$siteName = get_bloginfo( 'name' );
		$xml      = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml     .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml     .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! $this->publishedWithinWindow( $post ) ) {
				continue;
			}

			$postId = (int) ( $post->ID ?? 0 );
			if ( $postId < 1 || get_post_meta( $postId, '_holvek_noindex', true ) === 'yes' ) {
				continue;
			}

			$url         = get_permalink( $postId );
			$publishDate = $this->publicationDate( $post );
			$title       = get_the_title( $postId );

			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_url( $url ) . "</loc>\n";
			$xml .= "    <news:news>\n";
			$xml .= "      <news:publication>\n";
			$xml .= '        <news:name>' . esc_html( $siteName ) . "</news:name>\n";
			$xml .= '        <news:language>' . esc_html( get_bloginfo( 'language' ) ) . "</news:language>\n";
			$xml .= "      </news:publication>\n";
			$xml .= '      <news:publication_date>' . esc_html( $publishDate ) . "</news:publication_date>\n";
			$xml .= '      <news:title>' . esc_html( $title ) . "</news:title>\n";
			$xml .= "    </news:news>\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * True when the post was published during the last 48 hours, inclusive of the cutoff.
	 */
	private function publishedWithinWindow( object $post ): bool {
		$published = $this->publishedTimestamp( $post );
		if ( $published === null ) {
			return false;
		}

		$now = strtotime( current_time( 'mysql', true ) . ' UTC' );
		if ( $now === false ) {
			return false;
		}

		$cutoff = $now - self::WINDOW_SECONDS;
		return $published >= $cutoff && $published <= $now;
	}

	private function publishedTimestamp( object $post ): ?int {
		$raw = '';
		if ( isset( $post->post_date_gmt ) && is_string( $post->post_date_gmt ) && $post->post_date_gmt !== '' && $post->post_date_gmt !== '0000-00-00 00:00:00' ) {
			$raw = $post->post_date_gmt;
		} elseif ( isset( $post->post_date ) && is_string( $post->post_date ) ) {
			$raw = $post->post_date;
		}
		if ( $raw === '' ) {
			return null;
		}

		$timestamp = strtotime( $raw . ' UTC' );
		if ( $timestamp === false ) {
			return null;
		}
		return $timestamp;
	}

	private function publicationDate( object $post ): string {
		$timestamp = $this->publishedTimestamp( $post );
		if ( $timestamp === null ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:s+00:00', $timestamp );
	}
}
