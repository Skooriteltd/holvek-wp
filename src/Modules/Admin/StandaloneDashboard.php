<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local answers for the first admin screen.
 *
 * The snapshot is read only when that screen asks for it. It does not run on
 * a front-end page view and it does not call Holvek, Google, or SerpApi.
 */
class StandaloneDashboard {

	private const COUNTS_TRANSIENT = 'holvek_standalone_counts';
	private const COUNTS_TTL       = 15 * MINUTE_IN_SECONDS;

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/dashboard/standalone',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'snapshot' ),
				'permission_callback' => array( $this, 'canRead' ),
			)
		);
	}

	/**
	 * The Holvek screen is open to edit_seo_meta. Administrators also pass.
	 */
	public function canRead(): bool {
		return current_user_can( 'edit_seo_meta' ) || current_user_can( 'manage_options' );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function snapshot( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! $this->canRead() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view Holvek results for this site.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		$counts    = $this->measuredCounts();
		$redirects = $this->redirectTotal();
		$indexable = (string) get_option( 'blog_public', '1' ) === '1';
		$setupDone = (bool) get_option( 'holvek_setup_complete', false );

		return rest_ensure_response(
			array(
				'summary'              => __( 'Holvek writes titles, meta descriptions, schema, the sitemap, and robots.txt on this site. Redirects and 404 logs stay on this site. A Holvek account is optional.', 'holvek' ),
				'next_step'            => $setupDone ? 'active' : 'setup',
				'next_step_message'    => $setupDone
					? __( 'Your titles and sitemap are already active.', 'holvek' )
					: __( 'Set up Holvek to choose a site type. Titles and the sitemap already work.', 'holvek' ),
				'indexable'            => $indexable,
				'indexability'         => $indexable
					? __( 'Search engines may index this site.', 'holvek' )
					: __( 'Search engines are discouraged from indexing this site.', 'holvek' ),
				'sitemap_url'          => esc_url_raw( home_url( '/sitemap.xml' ) ),
				'missing_titles'       => $counts['missing_titles'],
				'missing_alt'          => $counts['missing_alt'],
				'redirects'            => $redirects,
				'not_found'            => $counts['not_found'],
				'missing_titles_label' => sprintf(
					/* translators: %d: number of published posts and pages with an empty title. */
					__( '%d published posts or pages have an empty title.', 'holvek' ),
					$counts['missing_titles']
				),
				'missing_alt_label'    => sprintf(
					/* translators: %d: number of images with no alternative text. */
					__( '%d images have no alternative text.', 'holvek' ),
					$counts['missing_alt']
				),
				'redirects_label'      => sprintf(
					/* translators: %d: number of saved redirects. */
					__( '%d redirects are saved on this site.', 'holvek' ),
					$redirects
				),
				'not_found_label'      => sprintf(
					/* translators: %d: number of logged not-found requests. */
					__( '%d not-found requests are logged on this site.', 'holvek' ),
					$counts['not_found']
				),
			)
		);
	}

	/**
	 * @return array{missing_titles:int,missing_alt:int,not_found:int}
	 */
	private function measuredCounts(): array {
		$cached = get_transient( self::COUNTS_TRANSIENT );
		if ( $this->isCountCache( $cached ) ) {
			return array(
				'missing_titles' => (int) $cached['missing_titles'],
				'missing_alt'    => (int) $cached['missing_alt'],
				'not_found'      => (int) $cached['not_found'],
			);
		}

		$counts = array(
			'missing_titles' => $this->countMissingTitles(),
			'missing_alt'    => $this->countMissingAlt(),
			'not_found'      => $this->countNotFound(),
		);
		set_transient( self::COUNTS_TRANSIENT, $counts, self::COUNTS_TTL );

		return $counts;
	}

	/**
	 * @param mixed $cached Stored transient value.
	 */
	private function isCountCache( $cached ): bool {
		return is_array( $cached )
			&& isset( $cached['missing_titles'], $cached['missing_alt'], $cached['not_found'] )
			&& is_numeric( $cached['missing_titles'] )
			&& is_numeric( $cached['missing_alt'] )
			&& is_numeric( $cached['not_found'] );
	}

	/**
	 * Published posts and pages whose title is empty. One aggregate, not a full scan of rows.
	 */
	private function countMissingTitles(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One admin aggregate, cached in a transient by the caller.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN (%s, %s) AND post_title = %s",
				'publish',
				'post',
				'page',
				''
			)
		);

		return max( 0, (int) $count );
	}

	/**
	 * Images with no non-empty alternative text. NOT EXISTS keeps duplicate meta rows from inflating the count.
	 */
	private function countMissingAlt(): int {
		global $wpdb;

		$mime = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One admin aggregate, cached in a transient by the caller.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = %s AND p.post_mime_type LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> %s )",
				'attachment',
				'inherit',
				$mime,
				'_wp_attachment_image_alt',
				''
			)
		);

		return max( 0, (int) $count );
	}

	/**
	 * Logged 404s. The table is not probed when activation has not marked it.
	 */
	private function countNotFound(): int {
		if ( get_option( 'holvek_404_table_exists', '' ) !== 'yes' ) {
			return 0;
		}

		global $wpdb;

		$table = str_replace( '`', '``', $wpdb->prefix . 'holvek_404_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is the site prefix plus a fixed suffix, with backticks doubled.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return max( 0, (int) $count );
	}

	/**
	 * Read the autoloaded counters. A missing option is zero here so the dashboard does not scan the redirects table.
	 */
	private function redirectTotal(): int {
		$stored = get_option( 'holvek_redirect_counts', null );
		if ( ! is_array( $stored ) || ! isset( $stored['exact'], $stored['regex'] ) ) {
			return 0;
		}
		if ( ! is_numeric( $stored['exact'] ) || ! is_numeric( $stored['regex'] ) ) {
			return 0;
		}

		return max( 0, (int) $stored['exact'] ) + max( 0, (int) $stored['regex'] );
	}
}
