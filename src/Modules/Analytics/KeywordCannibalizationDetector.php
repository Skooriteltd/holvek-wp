<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KeywordCannibalizationDetector
 *
 * BL-038: Finds posts that compete for the same focus keyword,
 * which splits PageRank and confuses search engines about which
 * page should rank.
 */
class KeywordCannibalizationDetector {

	private const CACHE_KEY   = 'holvek_cannibalization_report';
	private const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
	private const REPORT_ROWS = 500;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/analytics/cannibalization',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getReport' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/analytics/cannibalization/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refreshReport' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	public function getReport( \WP_REST_Request $request ): \WP_REST_Response {
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			return rest_ensure_response(
				array(
					'cached' => true,
					'report' => $cached,
				)
			);
		}

		$report = $this->buildReport();
		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return rest_ensure_response(
			array(
				'cached' => false,
				'report' => $report,
			)
		);
	}

	public function refreshReport( \WP_REST_Request $request ): \WP_REST_Response {
		delete_transient( self::CACHE_KEY );
		$report = $this->buildReport();
		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return rest_ensure_response(
			array(
				'success' => true,
				'report'  => $report,
			)
		);
	}

	public function buildReport(): array {
		global $wpdb;

		// The report compares keywords, so the read is capped. A site with more
		// focus keywords than this limit is reported from the first rows only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_modified, pm.meta_value AS keyword
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_holvek_focus_keyword'
             WHERE p.post_status = 'publish'
               AND p.post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
               AND pm.meta_value != ''
             ORDER BY pm.meta_value, p.ID
             LIMIT %d",
				self::REPORT_ROWS
			),
			ARRAY_A
		);

		// Group by normalised keyword
		$groups = array();
		foreach ( $rows as $row ) {
			$kw              = mb_strtolower( trim( $row['keyword'] ) );
			$groups[ $kw ][] = array(
				'post_id'   => (int) $row['ID'],
				'title'     => $row['post_title'],
				'post_type' => $row['post_type'],
				'url'       => get_permalink( (int) $row['ID'] ),
				'keyword'   => $row['keyword'],
				'modified'  => $row['post_modified'],
				'edit_url'  => get_edit_post_link( (int) $row['ID'], 'raw' ),
			);
		}

		// Only keep groups with 2+ posts (cannibalization = multiple pages, same keyword)
		$conflicts = array();
		foreach ( $groups as $kw => $posts ) {
			if ( count( $posts ) >= 2 ) {
				$conflicts[] = array(
					'keyword'    => $kw,
					'post_count' => count( $posts ),
					'severity'   => count( $posts ) >= 4 ? 'critical' : ( count( $posts ) >= 3 ? 'high' : 'medium' ),
					'posts'      => $posts,
					'suggestion' => 'Choose the primary page and redirect or noindex the others, or consolidate content.',
				);
			}
		}

		usort( $conflicts, fn( $a, $b ) => $b['post_count'] - $a['post_count'] );

		return array(
			'generated_at'    => current_time( 'mysql' ),
			'total_conflicts' => count( $conflicts ),
			'conflicts'       => $conflicts,
		);
	}
}
