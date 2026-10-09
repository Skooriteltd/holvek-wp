<?php

namespace Holvek\WordPress\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenGraphGenerator {

	public function outputSocialMetaTags(): void {
		if ( is_admin() ) {
			return;
		}

		echo "<!-- Holvek Social Meta Tags -->\n";

		$title       = $this->getContextTitle();
		$description = $this->getContextDescription();
		$url         = $this->getContextUrl();
		$ogType      = $this->getOgType();
		$image       = $this->getContextImage();

		// Open Graph
		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( get_locale() ) );
		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $ogType ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );

		if ( ! empty( $image['url'] ) ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image['url'] ) );
			if ( ! empty( $image['width'] ) ) {
				printf( '<meta property="og:image:width" content="%d" />' . "\n", (int) $image['width'] );
			}
			if ( ! empty( $image['height'] ) ) {
				printf( '<meta property="og:image:height" content="%d" />' . "\n", (int) $image['height'] );
			}
			echo '<meta property="og:image:type" content="image/jpeg" />' . "\n";
		}

		// Twitter Cards
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );

		if ( ! empty( $image['url'] ) ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image['url'] ) );
		}

		echo "<!-- /Holvek Social Meta Tags -->\n\n";
	}

	// ── Context helpers ──────────────────────────────────────────────────────

	private function getOgType(): string {
		if ( is_singular( 'post' ) ) {
			return 'article';
		}
		return 'website';
	}

	private function getContextUrl(): string {
		if ( is_singular() ) {
			return (string) get_permalink();
		}
		global $wp;
		return home_url( add_query_arg( array(), $wp->request ) );
	}

	private function getContextTitle(): string {
		if ( is_singular() ) {
			$custom = get_post_meta( get_the_ID(), '_holvek_og_title', true );
			return ! empty( $custom ) ? $custom : get_the_title();
		}

		if ( is_front_page() || is_home() ) {
			return get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return ( $term instanceof \WP_Term ) ? $term->name : get_bloginfo( 'name' );
		}

		if ( is_author() ) {
			$author = get_queried_object();
			return ( $author instanceof \WP_User )
				? 'Posts by ' . $author->display_name
				: get_bloginfo( 'name' );
		}

		if ( is_search() ) {
			return 'Search results for: ' . get_search_query();
		}

		if ( is_post_type_archive() ) {
			$postType = get_post_type_object( (string) get_query_var( 'post_type' ) );
			return ( $postType instanceof \WP_Post_Type )
				? ( $postType->labels->archive_title ?? $postType->labels->name ?? $postType->name )
				: get_bloginfo( 'name' );
		}

		return get_bloginfo( 'name' );
	}

	private function getContextDescription(): string {
		if ( is_singular() ) {
			$custom = get_post_meta( get_the_ID(), '_holvek_og_description', true );
			if ( ! empty( $custom ) ) {
				return $custom;
			}
			$excerpt = get_post_field( 'post_excerpt', get_the_ID() );
			return ! empty( $excerpt )
				? wp_trim_words( $excerpt, 25 )
				: wp_trim_words( get_post_field( 'post_content', get_the_ID() ), 25 );
		}

		if ( is_front_page() || is_home() ) {
			return get_bloginfo( 'description' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term     = get_queried_object();
			$termDesc = ( $term instanceof \WP_Term ) ? wp_strip_all_tags( term_description( $term ) ) : '';
			return ! empty( $termDesc ) ? wp_trim_words( $termDesc, 25 ) : get_bloginfo( 'description' );
		}

		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author instanceof \WP_User ) {
				$bio = get_the_author_meta( 'description', $author->ID );
				return ! empty( $bio ) ? wp_trim_words( $bio, 25 ) : get_bloginfo( 'description' );
			}
		}

		return get_bloginfo( 'description' );
	}

	/**
	 * Returns ['url' => string, 'width' => int, 'height' => int] or empty array.
	 * Tries in order: post custom meta, featured image, site default option.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function getContextImage(): array {
		// Singular: check custom OG image meta, then featured image
		if ( is_singular() ) {
			$postId    = get_the_ID();
			$customUrl = get_post_meta( $postId, '_holvek_og_image', true );
			if ( ! empty( $customUrl ) ) {
				return $this->resolveDimensions( $customUrl );
			}
			if ( has_post_thumbnail( $postId ) ) {
				$thumbId = get_post_thumbnail_id( $postId );
				return $this->resolveAttachmentImage( (int) $thumbId );
			}
		}

		// Site default share image
		$defaultId = (int) get_option( 'holvek_default_share_image_id', 0 );
		if ( $defaultId > 0 ) {
			return $this->resolveAttachmentImage( $defaultId );
		}

		$defaultUrl = (string) get_option( 'holvek_default_share_image', '' );
		if ( ! empty( $defaultUrl ) ) {
			return $this->resolveDimensions( $defaultUrl );
		}

		// Site icon as last resort
		$siteIconUrl = get_site_icon_url( 512 );
		if ( ! empty( $siteIconUrl ) ) {
			return array(
				'url'    => $siteIconUrl,
				'width'  => 512,
				'height' => 512,
			);
		}

		return array(
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * Resolve dimensions from an attachment ID.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function resolveAttachmentImage( int $attachmentId ): array {
		$src = wp_get_attachment_image_src( $attachmentId, 'full' );
		if ( $src === false || empty( $src[0] ) ) {
			return array(
				'url'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}
		return array(
			'url'    => $src[0],
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
		);
	}

	/**
	 * Resolve dimensions from a URL by looking up the attachment.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function resolveDimensions( string $url ): array {
		$attachmentId = attachment_url_to_postid( $url );
		if ( $attachmentId > 0 ) {
			return $this->resolveAttachmentImage( $attachmentId );
		}
		// URL not in media library — return it without dimensions
		return array(
			'url'    => $url,
			'width'  => 0,
			'height' => 0,
		);
	}
}
