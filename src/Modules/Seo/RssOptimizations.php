<?php

namespace Holvek\WordPress\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes RSS feeds by prepending/appending custom HTML/text,
 * adding attribution, branding, and anti-scraping elements.
 */
class RssOptimizations {

	/**
	 * Boot the RSS optimization hooks.
	 */
	public function register(): void {
		add_filter( 'the_content_feed', array( $this, 'optimizeFeedContent' ) );
		add_filter( 'the_excerpt_rss', array( $this, 'optimizeFeedContent' ) );
	}

	/**
	 * Modifies the RSS feed item content/excerpt with custom prepend/append attributions.
	 */
	public function optimizeFeedContent( string $content ): string {
		if ( ! is_feed() ) {
			return $content;
		}

		$before = get_option( 'holvek_rss_before_content', '' );
		$after  = get_option( 'holvek_rss_after_content', '' );

		// If both are empty, apply premium default attribution to prevent site scraping
		if ( empty( $before ) && empty( $after ) ) {
			$postTitle = get_the_title();
			$postUrl   = esc_url( get_permalink() );
			$siteTitle = get_bloginfo( 'name' );
			$siteUrl   = esc_url( home_url( '/' ) );

			$after = sprintf(
				'<p>The post <a href="%s">%s</a> first appeared on <a href="%s">%s</a>.</p>',
				$postUrl,
				esc_html( $postTitle ),
				$siteUrl,
				esc_html( $siteTitle )
			);
		} else {
			// Replace placeholder tokens
			$tokens = array(
				'%%POST_LINK%%' => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink() ), esc_html( get_the_title() ) ),
				'%%SITE_LINK%%' => sprintf( '<a href="%s">%s</a>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) ),
				'%%AUTHOR%%'    => esc_html( get_the_author() ),
			);

			$before = str_replace( array_keys( $tokens ), array_values( $tokens ), $before );
			$after  = str_replace( array_keys( $tokens ), array_values( $tokens ), $after );
		}

		return $before . $content . $after;
	}
}
