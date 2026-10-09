<?php

namespace Holvek\WordPress\Modules\Schema\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArticleSchema {

	public function isApplicable( ?int $postId = null ): bool {
		if ( $postId !== null ) {
			$post = get_post( $postId );
			return $post && in_array( $post->post_type, array( 'post', 'page' ), true );
		}
		return is_single();
	}

	public function generate( ?int $postId = null ): array {
		$post = $postId !== null ? get_post( $postId ) : get_post();
		if ( ! $post ) {
			return array();
		}
		$schemaType = 'Article';

		// Check if News optimization is needed
		$isNews = get_post_meta( $post->ID, '_holvek_is_news', true );
		if ( $isNews === 'yes' ) {
			$schemaType = 'NewsArticle';
		}

		$schema = array(
			'@type'            => $schemaType,
			'@id'              => get_permalink( $post->ID ) . '#article',
			'headline'         => get_the_title( $post->ID ),
			'datePublished'    => get_the_date( 'c', $post->ID ),
			'dateModified'     => get_the_modified_date( 'c', $post->ID ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				'url'   => get_author_posts_url( $post->post_author ),
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'logo'  => array(
					'@type' => 'ImageObject',
					'url'   => get_site_icon_url() ?: '', // MVP: Fallback to site icon
				),
			),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post->ID ),
			),
		);

		if ( has_post_thumbnail( $post->ID ) ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		return $schema;
	}
}
