<?php

namespace Holvek\WordPress\Modules\Schema\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BreadcrumbSchema {

	public function isApplicable( ?int $postId = null ): bool {
		if ( $postId !== null ) {
			return true; // We can generate breadcrumbs for any valid post
		}
		return ! is_front_page();
	}

	public function generate( ?int $postId = null ): array {
		$renderer = new \Holvek\WordPress\Modules\Breadcrumbs\BreadcrumbRenderer();
		$trail    = $postId !== null ? $renderer->itemsForPost( $postId ) : $renderer->items();
		if ( count( $trail ) < 2 ) {
			return array();
		}

		$itemListElement = array();
		foreach ( $trail as $index => $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $item['name'],
			);
			if ( $item['url'] !== '' ) {
				$entry['item'] = $item['url'];
			}
			$itemListElement[] = $entry;
		}

		$last    = $trail[ count( $trail ) - 1 ];
		$pageUrl = $last['url'] !== '' ? $last['url'] : home_url( '/' );

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $pageUrl . '#breadcrumb',
			'itemListElement' => $itemListElement,
		);
	}
}
