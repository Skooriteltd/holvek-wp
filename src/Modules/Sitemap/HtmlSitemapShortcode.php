<?php

declare(strict_types=1);

namespace Holvek\WordPress\Modules\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HtmlSitemapShortcode
 *
 * Registers and renders the [holvek_sitemap] shortcode.
 * Configuration is read from WordPress options set via the admin UI.
 */
class HtmlSitemapShortcode {

	private bool $stylesPrinted = false;

	public function register(): void {
		add_shortcode( 'holvek_sitemap', array( $this, 'render' ) );
	}

	public function render( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'post_types'  => get_option( 'holvek_html_sitemap_post_types', 'post,page' ),
				'exclude_ids' => get_option( 'holvek_html_sitemap_exclude_ids', '' ),
				'order'       => get_option( 'holvek_html_sitemap_order', 'menu_order' ),
				'show_count'  => get_option( 'holvek_html_sitemap_show_count', 'no' ),
			),
			$atts,
			'holvek_sitemap'
		);

		$postTypes  = array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) );
		$excludeIds = array_slice(
			array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['exclude_ids'] ) ) ) ),
			0,
			500
		);
		$showCount  = $atts['show_count'] === 'yes';

		$orderby = match ( $atts['order'] ) {
			'title'    => 'title',
			'date'     => 'date',
			'modified' => 'modified',
			default    => 'menu_order',
		};

		if ( empty( $postTypes ) ) {
			$postTypes = array( 'post', 'page' );
		}

		ob_start(); ?>
		<nav class="holvek-sitemap" aria-label="<?php echo esc_attr__( 'Site Map', 'holvek' ); ?>">
		<?php
		foreach ( $postTypes as $postType ) :
			$ptObj = get_post_type_object( $postType );
			if ( ! $ptObj || ! $ptObj->public ) {
				continue;
			}

			$posts = get_posts(
				array(
					'post_type'      => $postType,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'post__not_in'   => $excludeIds,
					'orderby'        => $orderby,
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}
			?>
			<section class="holvek-sitemap__section">
				<h3 class="holvek-sitemap__heading">
					<?php echo esc_html( $ptObj->labels->name ); ?>
					<?php
					if ( $showCount ) :
						?>
						<span class="holvek-sitemap__count">(<?php echo esc_html( (string) count( $posts ) ); ?>)</span><?php endif; ?>
				</h3>
				<ul class="holvek-sitemap__list">
					<?php foreach ( $posts as $post ) : ?>
					<li class="holvek-sitemap__item">
						<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ?: '(no title)' ); ?></a>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
		</nav>
		<?php
		return $this->styles() . ob_get_clean();
	}

	/**
	 * Print the sitemap rules with the shortcode, not on every front-end URL.
	 */
	private function styles(): string {
		if ( $this->stylesPrinted ) {
			return '';
		}

		$this->stylesPrinted = true;

		return '<style id="holvek-sitemap-css">
.holvek-sitemap{font-family:inherit;margin:0;padding:0}
.holvek-sitemap__section{margin-bottom:2rem}
.holvek-sitemap__heading{font-size:1.1em;font-weight:600;margin:0 0 .5rem;border-bottom:2px solid currentColor;padding-bottom:.25rem}
.holvek-sitemap__count{font-weight:400;font-size:.85em;opacity:.65;margin-left:.4em}
.holvek-sitemap__list{list-style:none;margin:.5rem 0 0;padding:0;display:flex;flex-wrap:wrap;gap:.35rem .75rem}
.holvek-sitemap__item a{color:inherit;text-decoration:none;font-size:.95em}
.holvek-sitemap__item a:hover{text-decoration:underline}
</style>' . "\n";
	}
}
