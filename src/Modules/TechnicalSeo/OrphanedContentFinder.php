<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OrphanedContentFinder
 *
 * Identifies published posts/pages that receive zero inbound internal links
 * from other published content. Results are stored in post-meta and refreshed
 * via a background WP-Cron job so the scan never blocks a page request.
 *
 * BL-002
 */
class OrphanedContentFinder {

	private const INBOUND_META_KEY = '_holvek_inbound_links';
	private const LAST_RUN_OPTION  = 'holvek_orphan_scan_last_run';
	private const PENDING_OPTION   = 'holvek_orphan_scan_pending';
	private const CRON_HOOK        = 'holvek_scan_orphaned';
	private const POST_BATCH       = 20;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( self::CRON_HOOK, array( $this, 'enqueueFromCron' ) );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/orphaned-content',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getOrphanedContent' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'page'      => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'post_type' => array(
						'default'           => 'any',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/orphaned-content/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refreshScan' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);
	}

	// ── REST callbacks ────────────────────────────────────────────────────────

	public function getOrphanedContent( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$perPage  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$postType = $request->get_param( 'post_type' );

		$args = array(
			'post_type'      => ( $postType === 'any' ) ? get_post_types( array( 'public' => true ) ) : array( $postType ),
			'post_status'    => 'publish',
			'posts_per_page' => $perPage,
			'paged'          => $page,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => self::INBOUND_META_KEY,
					'value'   => '0',
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => self::INBOUND_META_KEY,
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'url'           => get_permalink( $post->ID ),
				'post_type'     => $post->post_type,
				'date'          => $post->post_date,
				'inbound_links' => (int) get_post_meta( $post->ID, self::INBOUND_META_KEY, true ),
			);
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $perPage,
				'last_scan'   => get_option( self::LAST_RUN_OPTION, null ),
			)
		);
	}

	public function refreshScan( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $this->scanIsPending() ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'queued'  => true,
					'message' => __( 'An orphaned content scan is already queued.', 'holvek' ),
				)
			);
		}

		$this->enqueueScan( 0 );

		return rest_ensure_response(
			array(
				'success' => true,
				'queued'  => true,
				'message' => __( 'Orphaned content scan scheduled. Results will be available within a few minutes.', 'holvek' ),
			)
		);
	}

	/**
	 * An older cron event only enqueues the first page.
	 */
	public function enqueueFromCron(): void {
		if ( $this->scanIsPending() ) {
			return;
		}

		$this->enqueueScan( 0 );
	}

	/**
	 * Count inbound links for one page of posts. Another job is queued when
	 * this page is full, so one cron run cannot walk the whole library.
	 */
	public function scanBatch( int $offset ): void {
		$postTypes = array_values( get_post_types( array( 'public' => true ) ) );
		if ( array() === $postTypes ) {
			$this->completeScan();
			return;
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $postTypes,
				'post_status'         => 'publish',
				'posts_per_page'      => self::POST_BATCH,
				'offset'              => max( 0, $offset ),
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);

		$ids = is_array( $query->posts ) ? $query->posts : array();
		foreach ( $ids as $postId ) {
			$postId = (int) $postId;
			if ( $postId < 1 ) {
				continue;
			}
			$this->storeInboundCount( $postId );
		}

		$found = count( $ids );
		if ( self::POST_BATCH === $found ) {
			$this->enqueueScan( $offset + $found );
			return;
		}

		$this->completeScan();
	}

	private function enqueueScan( int $offset ): void {
		update_option( self::PENDING_OPTION, time(), false );
		( new \Holvek\WordPress\Core\Queue\QueueManager() )->push(
			OrphanScanJob::class,
			array( 'offset' => max( 0, $offset ) )
		);
	}

	private function scanIsPending(): bool {
		$pending = get_option( self::PENDING_OPTION, 0 );
		if ( ! is_numeric( $pending ) ) {
			return false;
		}

		return ( time() - (int) $pending ) < 15 * MINUTE_IN_SECONDS;
	}

	private function completeScan(): void {
		delete_option( self::PENDING_OPTION );
		update_option( self::LAST_RUN_OPTION, current_time( 'mysql' ), false );
	}

	private function storeInboundCount( int $postId ): void {
		global $wpdb;

		$permalink = get_permalink( $postId );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			update_post_meta( $postId, self::INBOUND_META_KEY, 0 );
			return;
		}

		$relative = wp_make_link_relative( $permalink );
		if ( ! is_string( $relative ) ) {
			$relative = '';
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID != %d AND (post_content LIKE %s OR post_content LIKE %s)",
				$postId,
				'%' . $wpdb->esc_like( $permalink ) . '%',
				'%' . $wpdb->esc_like( $relative ) . '%'
			)
		);

		update_post_meta( $postId, self::INBOUND_META_KEY, $count );
	}
}
