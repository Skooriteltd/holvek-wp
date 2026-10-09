<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans post content to detect YouTube, Vimeo, and HTML5 video embeds
 * and auto-generates structured VideoObject schemas.
 */
class VideoExtractor {

	/**
	 * Extracts video objects from post content.
	 */
	public static function extractVideoObjects( int $postId ): array {
		$post = get_post( $postId );
		if ( ! $post ) {
			return array();
		}

		// Check if there is manual video metadata first
		$manualVideoUrl = get_post_meta( $postId, '_holvek_video_url', true );
		$videoObjects   = array();

		if ( ! empty( $manualVideoUrl ) ) {
			$thumbnail   = get_post_meta( $postId, '_holvek_video_thumbnail', true ) ?: get_the_post_thumbnail_url( $postId, 'full' );
			$title       = get_post_meta( $postId, '_holvek_video_title', true ) ?: get_the_title( $postId );
			$desc        = get_post_meta( $postId, '_holvek_video_description', true ) ?: wp_strip_all_tags( get_the_excerpt( $postId ) );
			$durationRaw = get_post_meta( $postId, '_holvek_video_duration', true );

			$duration = 'PT1M'; // default fallback
			if ( ! empty( $durationRaw ) ) {
				if ( is_numeric( $durationRaw ) ) {
					$duration = 'PT' . intval( $durationRaw ) . 'S';
				} elseif ( strpos( $durationRaw, 'PT' ) === 0 ) {
					$duration = $durationRaw;
				}
			}

			$videoObjects[] = self::buildVideoObjectSchema(
				$title,
				$desc,
				$thumbnail ?: get_site_icon_url(),
				get_post_modified_time( 'c', false, $post ),
				$manualVideoUrl,
				$duration
			);
			return $videoObjects;
		}

		// Auto-extract embeds from content
		$content = $post->post_content;
		if ( empty( $content ) ) {
			return array();
		}

		// 1. YouTube Matchers
		// Standard YouTube URL: youtube.com/watch?v=ID or youtu.be/ID
		// Embed: youtube.com/embed/ID
		if ( preg_match_all( '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $content, $matches ) ) {
			$youtubeIds = array_unique( $matches[1] );
			foreach ( $youtubeIds as $idx => $id ) {
				$videoObjects[] = self::buildVideoObjectSchema(
					get_the_title( $postId ) . ' - Video ' . ( $idx + 1 ),
					wp_strip_all_tags( get_the_excerpt( $postId ) ) ?: get_the_title( $postId ),
					"https://img.youtube.com/vi/{$id}/maxresdefault.jpg",
					get_post_modified_time( 'c', false, $post ),
					"https://www.youtube.com/embed/{$id}",
					'PT2M'
				);
			}
		}

		// 2. Vimeo Matchers
		// vimeo.com/ID or player.vimeo.com/video/ID
		if ( preg_match_all( '/(?:vimeo\.com\/|player\.vimeo\.com\/video\/)(\d+)/i', $content, $matches ) ) {
			$vimeoIds = array_unique( $matches[1] );
			foreach ( $vimeoIds as $idx => $id ) {
				$videoObjects[] = self::buildVideoObjectSchema(
					get_the_title( $postId ) . ' - Vimeo ' . ( $idx + 1 ),
					wp_strip_all_tags( get_the_excerpt( $postId ) ) ?: get_the_title( $postId ),
					get_the_post_thumbnail_url( $postId, 'full' ) ?: get_site_icon_url(),
					get_post_modified_time( 'c', false, $post ),
					"https://player.vimeo.com/video/{$id}",
					'PT2M'
				);
			}
		}

		// 3. HTML5 Video matches
		if ( preg_match_all( '/<video[^>]*src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			$srcs = array_unique( $matches[1] );
			foreach ( $srcs as $idx => $src ) {
				$videoObjects[] = self::buildVideoObjectSchema(
					get_the_title( $postId ) . ' - Video ' . ( $idx + 1 ),
					wp_strip_all_tags( get_the_excerpt( $postId ) ) ?: get_the_title( $postId ),
					get_the_post_thumbnail_url( $postId, 'full' ) ?: get_site_icon_url(),
					get_post_modified_time( 'c', false, $post ),
					$src,
					'PT1M'
				);
			}
		}

		return $videoObjects;
	}

	/**
	 * Builds standard JSON-LD VideoObject schema.
	 */
	private static function buildVideoObjectSchema( string $name, string $description, string $thumbnailUrl, string $uploadDate, string $embedUrl, string $duration ): array {
		return array(
			'@type'        => 'VideoObject',
			'name'         => $name,
			'description'  => $description,
			'thumbnailUrl' => $thumbnailUrl,
			'uploadDate'   => $uploadDate,
			'embedUrl'     => $embedUrl,
			'duration'     => $duration,
		);
	}
}
