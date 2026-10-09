<?php

namespace Holvek\WordPress\Modules\Breadcrumbs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Modules\Schema\JsonLd;

/**
 * Renders front-end breadcrumb navigation and registers:
 * - Shortcode: [holvek_breadcrumbs]
 * - PHP function: holvek_breadcrumbs()
 *
 * Also outputs BreadcrumbList JSON-LD schema automatically.
 */
class BreadcrumbRenderer {

	private string $separator;
	private string $homeLabel;

	public function __construct() {
		$this->separator = get_option( 'holvek_breadcrumb_separator', '›' );
		$this->homeLabel = get_option( 'holvek_breadcrumb_home_label', 'Home' );
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_shortcode( 'holvek_breadcrumbs', array( $this, 'renderShortcode' ) );
		add_action( 'wp_footer', array( $this, 'outputSchema' ) );

		$autoInsert = get_option( 'holvek_breadcrumb_auto_insert', 'none' );
		if ( $autoInsert === 'before_content' ) {
			add_filter( 'the_content', array( $this, 'autoInsertBeforeContent' ), 9 );
		}
	}

	/**
	 * Filter callback to automatically insert breadcrumbs before content.
	 */
	public function autoInsertBeforeContent( string $content ): string {
		if ( is_singular() && in_the_loop() && is_main_query() ) {
			return $this->render() . $content;
		}
		return $content;
	}

	/**
	 * Shortcode callback.
	 */
	public function renderShortcode( $atts ): string {
		return $this->render();
	}

	/**
	 * Build the breadcrumb trail and return HTML.
	 */
	public function render(): string {
		if ( is_front_page() ) {
			return '';
		}

		$items = $this->items();
		if ( empty( $items ) ) {
			return '';
		}

		$sep   = '<span class="holvek-breadcrumb-sep" aria-hidden="true"> ' . esc_html( $this->separator ) . ' </span>';
		$parts = array();

		foreach ( $items as $i => $item ) {
			$isLast = ( $i === count( $items ) - 1 );

			if ( $isLast ) {
				$parts[] = '<span class="holvek-breadcrumb-current" aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			} else {
				$parts[] = '<a href="' . esc_url( $item['url'] ) . '" class="holvek-breadcrumb-link">' . esc_html( $item['name'] ) . '</a>';
			}
		}

		$html  = '<nav class="holvek-breadcrumbs" aria-label="Breadcrumb">';
		$html .= '<ol class="holvek-breadcrumb-list">';
		$html .= '<li>' . implode( '</li><li>' . $sep, $parts ) . '</li>';
		$html .= '</ol>';
		$html .= '</nav>';

		// Inline minimal styles
		$html .= '<style>.holvek-breadcrumbs{font-size:14px;line-height:1.4;margin:8px 0 16px;color:#64748b}.holvek-breadcrumb-list{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:0}.holvek-breadcrumb-list li{display:inline-flex;align-items:center}.holvek-breadcrumb-link{color:#3b82f6;text-decoration:none}.holvek-breadcrumb-link:hover{text-decoration:underline}.holvek-breadcrumb-sep{margin:0 6px;color:#94a3b8}.holvek-breadcrumb-current{color:#334155;font-weight:500}</style>';

		return $html;
	}

	/**
	 * Visible trail for the current request.
	 *
	 * @return array<int, array{name:string,url:string}>
	 */
	public function items(): array {
		return $this->buildTrail();
	}

	/**
	 * Visible trail for one post. Schema uses this same list.
	 *
	 * @return array<int, array{name:string,url:string}>
	 */
	public function itemsForPost( int $postId ): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		return $this->trailForPost( $post );
	}

	/**
	 * Build the breadcrumb trail as an array of ['name' => ..., 'url' => ...].
	 *
	 * @return array<int, array{name:string,url:string}>
	 */
	private function buildTrail(): array {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				return $this->trailForPost( $post );
			}
		}

		$trail = array(
			array(
				'name' => $this->homeLabel,
				'url'  => home_url( '/' ),
			),
		);

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( is_category() ) {
				$parents = $this->getCategoryParents( $term );
				$trail   = array_merge( $trail, $parents );
			}

			$trail[] = array(
				'name' => $term->name,
				'url'  => get_term_link( $term ),
			);
		} elseif ( is_post_type_archive() ) {
			$trail[] = array(
				'name' => post_type_archive_title( '', false ),
				'url'  => get_post_type_archive_link( get_query_var( 'post_type' ) ),
			);
		} elseif ( is_search() ) {
			$trail[] = array(
				'name' => 'Search: ' . get_search_query(),
				'url'  => get_search_link(),
			);
		} elseif ( is_404() ) {
			$trail[] = array(
				'name' => 'Page Not Found',
				'url'  => '',
			);
		}

		return $trail;
	}

	/**
	 * @return array<int, array{name:string,url:string}>
	 */
	private function trailForPost( \WP_Post $post ): array {
		$trail = array(
			array(
				'name' => $this->homeLabel,
				'url'  => home_url( '/' ),
			),
		);

		if ( $post->post_type === 'post' ) {
			$categories = get_the_category( $post->ID );
			if ( ! empty( $categories ) ) {
				$cat     = $categories[0];
				$parents = $this->getCategoryParents( $cat );
				$trail   = array_merge( $trail, $parents );
				$trail[] = array(
					'name' => $cat->name,
					'url'  => get_category_link( $cat->term_id ),
				);
			}
		}

		if ( function_exists( 'is_post_type_hierarchical' ) && is_post_type_hierarchical( $post->post_type ) && function_exists( 'get_post_ancestors' ) ) {
			$ancestors = array_reverse( get_post_ancestors( $post->ID ) );
			foreach ( $ancestors as $ancestorId ) {
				$trail[] = array(
					'name' => get_the_title( $ancestorId ),
					'url'  => get_permalink( $ancestorId ),
				);
			}
		}

		$trail[] = array(
			'name' => get_the_title( $post->ID ),
			'url'  => get_permalink( $post->ID ),
		);

		return $trail;
	}

	/**
	 * Get parent categories recursively.
	 */
	private function getCategoryParents( $category ): array {
		$parents = array();

		if ( $category->parent ) {
			$parent = get_category( $category->parent );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$parents   = array_merge( $this->getCategoryParents( $parent ), $parents );
				$parents[] = array(
					'name' => $parent->name,
					'url'  => get_category_link( $parent->term_id ),
				);
			}
		}

		return $parents;
	}

	/**
	 * Output BreadcrumbList JSON-LD schema in the footer.
	 */
	public function outputSchema(): void {
		if ( is_front_page() || is_admin() ) {
			return;
		}

		$trail = $this->items();
		if ( count( $trail ) < 2 ) {
			return;
		}

		$items = array();
		foreach ( $trail as $i => $item ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'] ?: null,
			);
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);

		JsonLd::printScript( $schema );
	}
}
