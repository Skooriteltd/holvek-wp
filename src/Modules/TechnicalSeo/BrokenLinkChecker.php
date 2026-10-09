<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Queue\QueueManager;

/**
 * BrokenLinkChecker
 *
 * Scans all published post/page content for dead outbound links.
 * Batches posts across staggered WP-Cron events so it never bogs
 * down the server.
 *
 * Results table: holvek_broken_links
 */
class BrokenLinkChecker {

	private const RESULTS_TABLE   = 'holvek_broken_links';
	private const PROGRESS_OPTION = 'holvek_blc_progress';
	private const SCAN_HOOK       = 'holvek_blc_scan_batch';
	private const LOCK_OPTION     = 'holvek_blc_scan_lock';
	private const BATCH_SIZE      = 10;
	private const URL_BATCH       = 10;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( self::SCAN_HOOK, array( $this, 'enqueueBatch' ) );
	}

	public static function createTable(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::RESULTS_TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id      BIGINT UNSIGNED NOT NULL,
            url          TEXT NOT NULL,
            http_code    SMALLINT UNSIGNED DEFAULT NULL,
            anchor_text  VARCHAR(255) DEFAULT '',
            last_checked DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved     TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_resolved (resolved)
        ) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function registerRoutes(): void {
		$adminCap  = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
		$reportCap = fn() => current_user_can( 'view_seo_reports' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/broken-links',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getResults' ),
				'permission_callback' => $reportCap,
				'args'                => array(
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'resolved' => array( 'default' => '0' ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/broken-links/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'startScan' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/broken-links/progress',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response(
					get_option(
						self::PROGRESS_OPTION,
						array(
							'status'  => 'idle',
							'scanned' => 0,
							'total'   => 0,
							'found'   => 0,
						)
					)
				),
				'permission_callback' => $reportCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/broken-links/(?P<id>\d+)/resolve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolveLink' ),
				'permission_callback' => $adminCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/broken-links/recheck',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recheckLink' ),
				'permission_callback' => $adminCap,
			)
		);
	}

	public function startScan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . self::RESULTS_TABLE;

		if ( ! add_option( self::LOCK_OPTION, (string) time(), '', 'no' ) ) {
			return new \WP_Error( 'scan_in_progress', __( 'A broken-link scan is already running.', 'holvek' ), array( 'status' => 409 ) );
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}holvek_broken_links WHERE resolved = 0" );
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option(
			self::PROGRESS_OPTION,
			array(
				'status'  => 'running',
				'scanned' => 0,
				'total'   => $total,
				'found'   => 0,
				'cursor'  => 0,
				'started' => current_time( 'mysql' ),
			)
		);

		if ( $total === 0 ) {
			delete_option( self::LOCK_OPTION );
			update_option(
				self::PROGRESS_OPTION,
				array(
					'status'  => 'done',
					'scanned' => 0,
					'total'   => 0,
					'found'   => 0,
					'done_at' => current_time( 'mysql' ),
				)
			);
			return rest_ensure_response(
				array(
					'success'     => true,
					'total_posts' => 0,
					'batches'     => 0,
					'message'     => __( 'No published posts or pages to scan.', 'holvek' ),
				)
			);
		}

		wp_clear_scheduled_hook( self::SCAN_HOOK );
		$this->scheduleNextBatch( 2 );

		return rest_ensure_response(
			array(
				'success'     => true,
				'total_posts' => $total,
				'batches'     => (int) ceil( $total / self::BATCH_SIZE ),
				'message'     => sprintf( /* translators: %d: Number of posts to process */ __( 'Scan started — %d posts will be processed in bounded batches.', 'holvek' ), $total ),
			)
		);
	}

	/**
	 * Older cron events only enqueue. The queue job calls processBatch().
	 */
	public function enqueueBatch(): void {
		$this->scheduleNextBatch( 2 );
	}

	/**
	 * Check at most URL_BATCH links and BATCH_SIZE posts, then reschedule.
	 */
	public function processBatch(): void {
		global $wpdb;
		$table    = $wpdb->prefix . self::RESULTS_TABLE;
		$progress = get_option( self::PROGRESS_OPTION, array() );
		if ( ! is_array( $progress ) || ( $progress['status'] ?? '' ) !== 'running' || ! get_option( self::LOCK_OPTION ) ) {
			return;
		}

		$cursor       = max( 0, (int) ( $progress['cursor'] ?? 0 ) );
		$linkOffset   = max( 0, (int) ( $progress['link_offset'] ?? 0 ) );
		$found        = (int) ( $progress['found'] ?? 0 );
		$scanned      = (int) ( $progress['scanned'] ?? 0 );
		$total        = (int) ( $progress['total'] ?? 0 );
		$checks       = 0;
		$postsThisRun = 0;

		while ( $postsThisRun < self::BATCH_SIZE && $checks < self::URL_BATCH ) {
			$postId = $this->nextPostId( $cursor );
			if ( $postId < 1 ) {
				$this->saveProgress( $scanned, $total, $found, $cursor, 0 );
				$this->finishScan(
					array(
						'scanned' => $scanned,
						'total'   => $total,
						'found'   => $found,
					)
				);
				return;
			}

			$content      = (string) get_post_field( 'post_content', $postId );
			$links        = '' === $content ? array() : $this->extractLinks( $content );
			$linkCount    = count( $links );
			$start        = min( $linkOffset, $linkCount );
			$finishedPost = true;

			for ( $i = $start; $i < $linkCount; $i++ ) {
				if ( $checks >= self::URL_BATCH ) {
					$finishedPost = false;
					$linkOffset   = $i;
					break;
				}

				$url    = $links[ $i ]['url'];
				$anchor = $links[ $i ]['anchor'];
				$code   = $this->checkUrl( $url );
				++$checks;

				if ( null === $code || $code < 400 ) {
					continue;
				}

				$wpdb->insert(
					$table,
					array(
						'post_id'      => $postId,
						'url'          => $url,
						'http_code'    => $code,
						'anchor_text'  => mb_substr( $anchor, 0, 255 ),
						'last_checked' => current_time( 'mysql' ),
						'resolved'     => 0,
					)
				);
				++$found;
			}

			if ( ! $finishedPost ) {
				$this->saveProgress( $scanned, $total, $found, $cursor, $linkOffset );
				$this->scheduleNextBatch( 15 );
				return;
			}

			$cursor     = $postId;
			$linkOffset = 0;
			++$scanned;
			++$postsThisRun;
		}

		$this->saveProgress( $scanned, $total, $found, $cursor, 0 );
		if ( $this->nextPostId( $cursor ) > 0 ) {
			$this->scheduleNextBatch( 15 );
			return;
		}

		$this->finishScan(
			array(
				'scanned' => $scanned,
				'total'   => $total,
				'found'   => $found,
			)
		);
	}

	public function getResults( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$table    = $wpdb->prefix . self::RESULTS_TABLE;
		$perPage  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$resolved = $request->get_param( 'resolved' ) === '1' ? 1 : 0;
		$offset   = ( $page - 1 ) * $perPage;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return rest_ensure_response(
				array(
					'items' => array(),
					'total' => 0,
				)
			);
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_broken_links WHERE resolved = %d",
				$resolved
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, p.post_title
             FROM {$wpdb->prefix}holvek_broken_links b
             LEFT JOIN {$wpdb->posts} p ON p.ID = b.post_id
             WHERE b.resolved = %d
             ORDER BY b.http_code DESC, b.last_checked DESC
             LIMIT %d OFFSET %d",
				$resolved,
				$perPage,
				$offset
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['post_url'] = get_permalink( (int) $row['post_id'] );
			$row['edit_url'] = get_edit_post_link( (int) $row['post_id'], 'raw' );
		}

		return rest_ensure_response(
			array(
				'items' => $rows,
				'total' => $total,
			)
		);
	}

	public function resolveLink( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$wpdb->update( $wpdb->prefix . self::RESULTS_TABLE, array( 'resolved' => 1 ), array( 'id' => $id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function recheckLink( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$id     = (int) ( $params['id'] ?? 0 );
		$rawUrl = $params['url'] ?? '';
		$url    = is_string( $rawUrl ) ? esc_url_raw( $rawUrl ) : '';

		if ( ! $id || ! $url ) {
			return new \WP_Error( 'missing_params', __( 'A link ID and URL are required.', 'holvek' ), array( 'status' => 400 ) );
		}

		( new QueueManager() )->push(
			BrokenLinkRecheckJob::class,
			array(
				'id'  => $id,
				'url' => $url,
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'queued'  => true,
				'message' => __( 'Recheck queued. Refresh the list after the background check finishes.', 'holvek' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function recheckQueued( array $data ): void {
		global $wpdb;

		$id  = (int) ( $data['id'] ?? 0 );
		$url = isset( $data['url'] ) && is_string( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		if ( $id < 1 || '' === $url ) {
			return;
		}

		$code = $this->checkUrl( $url );
		$wpdb->update(
			$wpdb->prefix . self::RESULTS_TABLE,
			array(
				'http_code'    => $code,
				'last_checked' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	private function scheduleNextBatch( int $delay ): void {
		( new QueueManager() )->push( BrokenLinkScanJob::class, array(), max( 0, $delay ) );
	}

	private function nextPostId( int $afterId ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('post', 'page') AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
				$afterId,
				1
			)
		);

		if ( ! is_array( $ids ) || ! isset( $ids[0] ) ) {
			return 0;
		}

		return (int) $ids[0];
	}

	private function saveProgress( int $scanned, int $total, int $found, int $cursor, int $linkOffset ): void {
		update_option(
			self::PROGRESS_OPTION,
			array(
				'status'      => 'running',
				'scanned'     => $scanned,
				'total'       => $total,
				'found'       => $found,
				'cursor'      => $cursor,
				'link_offset' => $linkOffset,
			),
			false
		);
	}

	private function extractLinks( string $html ): array {
		$links = array();
		$dom   = new \DOMDocument();
		$prev  = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$anchors  = $dom->getElementsByTagName( 'a' );
		$siteHost = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $anchors as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( empty( $href ) || ! filter_var( $href, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			// Skip internal links
			if ( $host && str_contains( $siteHost ?? '', str_replace( 'www.', '', $host ) ) ) {
				continue;
			}
			$links[] = array(
				'url'    => $href,
				'anchor' => trim( $a->textContent ),
			);
		}

		return $links;
	}

	private function checkUrl( string $url ): ?int {
		static $cache = array();
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		if ( ! $this->isSafeExternalUrl( $url ) ) {
			return null;
		}

		$args = array(
			'timeout'             => 10,
			'redirection'         => 3,
			'user-agent'          => 'Mozilla/5.0 (compatible; Holvek-BLC/1.0)',
			'limit_response_size' => 1024,
		);

		$response = wp_safe_remote_head( $url, $args );
		if ( is_wp_error( $response ) ) {
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$cache[ $url ] = null;
			return null;
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$cache[ $url ] = $code;
		return $code;
	}

	private function finishScan( array $progress ): void {
		delete_option( self::LOCK_OPTION );
		update_option(
			self::PROGRESS_OPTION,
			array(
				'status'  => 'done',
				'scanned' => (int) ( $progress['scanned'] ?? 0 ),
				'total'   => (int) ( $progress['total'] ?? 0 ),
				'found'   => (int) ( $progress['found'] ?? 0 ),
				'done_at' => current_time( 'mysql' ),
			)
		);
	}

	private function isSafeExternalUrl( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || strtolower( $host ) === 'localhost' ) {
			return false;
		}

		$ipAddresses = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : ( gethostbynamel( $host ) ?: array() );
		foreach ( $ipAddresses as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return ! empty( $ipAddresses );
	}
}
