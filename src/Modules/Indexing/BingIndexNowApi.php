<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Queue\QueueManager;

/**
 * Submits URLs to IndexNow (Bing, Yandex, Seznam, and the shared hub).
 *
 * The verification file is the site path plus /{key}.txt, so a subdirectory
 * install and a query string still resolve. Publishing only queues URLs.
 * A cron callback sends them in small batches, and only after an
 * administrator has turned IndexNow on and saved a key. Holvek does not
 * create a key.
 */
class BingIndexNowApi {

	public const FLUSH_HOOK    = 'holvek_indexnow_flush';
	public const QUEUE_OPTION  = 'holvek_indexnow_queue';
	public const FLUSH_PENDING = 'holvek_indexnow_flush_pending';
	public const BATCH_SIZE    = 10;
	public const QUEUE_MAX     = 100;

	private const LOG_OPTION       = 'holvek_indexnow_log';
	private const LOG_MAX          = 100;
	private const KEY_OPTION       = 'holvek_indexnow_key';
	private const DEFAULT_ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * IndexNow hub plus the Bing, Yandex, and Seznam endpoints from their meta.json files.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ENDPOINTS = array(
		'https://api.indexnow.org/indexnow',
		'https://www.bing.com/indexnow',
		'https://yandex.com/indexnow',
		'https://search.seznam.cz/indexnow',
	);

	/**
	 * IndexNow keys are 8 to 128 characters from this set. Anything else is
	 * refused so a stored value cannot change the verification path.
	 */
	public static function isValidKey( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key );
	}

	/**
	 * True when the request path is exactly the home path plus /{key}.txt.
	 * The query string is ignored. A key that fails isValidKey() never matches.
	 */
	public static function keyFilePathMatches( string $requestUri, string $homeUrl, string $key ): bool {
		if ( ! self::isValidKey( $key ) ) {
			return false;
		}

		$requestPath = wp_parse_url( $requestUri, PHP_URL_PATH );
		if ( ! is_string( $requestPath ) || '' === $requestPath ) {
			return false;
		}

		$homePath = wp_parse_url( $homeUrl, PHP_URL_PATH );
		$prefix   = is_string( $homePath ) ? untrailingslashit( $homePath ) : '';
		if ( '/' === $prefix ) {
			$prefix = '';
		}

		return $requestPath === $prefix . '/' . $key . '.txt';
	}

	public static function sendKeyFile( string $key ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		echo esc_html( $key );
		exit;
	}

	private function getEndpoint(): string {
		$configured = get_option( 'holvek_indexnow_endpoint', self::DEFAULT_ENDPOINT );
		if ( ! is_string( $configured ) ) {
			return self::DEFAULT_ENDPOINT;
		}

		$normalized = $this->normalizeEndpoint( $configured );
		if ( ! in_array( $normalized, self::ALLOWED_ENDPOINTS, true ) ) {
			return self::DEFAULT_ENDPOINT;
		}

		return $normalized;
	}

	private function normalizeEndpoint( string $endpoint ): string {
		$parts = wp_parse_url( trim( $endpoint ) );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['port'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = untrailingslashit( (string) ( $parts['path'] ?? '' ) );
		if ( 'https' !== $scheme || '' === $host || '' === $path ) {
			return '';
		}

		return $scheme . '://' . $host . $path;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'serveKeyFile' ) );
		add_action( 'transition_post_status', array( $this, 'autoSubmitOnPublish' ), 10, 3 );
		add_action( self::FLUSH_HOOK, array( $this, 'enqueueFlush' ) );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Serve GET {home path}/{key}.txt. A normal front-end path returns before
	 * the key option is read.
	 */
	public function serveKeyFile(): void {
		$requestUri = $this->currentRequestUri();
		$path       = wp_parse_url( $requestUri, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! str_ends_with( $path, '.txt' ) ) {
			return;
		}

		$key = $this->storedKey();
		if ( '' === $key || ! self::keyFilePathMatches( $requestUri, home_url(), $key ) ) {
			return;
		}

		self::sendKeyFile( $key );
	}

	/**
	 * Queue a published public URL. This does not call IndexNow.
	 */
	public function autoSubmitOnPublish( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( 'publish' !== $newStatus || ! $this->isEnabled() ) {
			return;
		}

		$postType = get_post_type_object( $post->post_type );
		if ( ! is_object( $postType ) || empty( $postType->public ) ) {
			return;
		}

		if ( 'yes' === get_post_meta( $post->ID, '_holvek_noindex', true ) ) {
			return;
		}

		$url = get_permalink( $post->ID );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		$this->enqueueUrl( $url );
	}

	/**
	 * IndexNow is on only when an administrator enabled it and saved a valid key.
	 */
	public function isEnabled(): bool {
		return 'yes' === get_option( 'holvek_auto_index', 'no' ) && '' !== $this->storedKey();
	}

	public function storedKey(): string {
		$stored = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $stored ) ) {
			return '';
		}

		$key = sanitize_text_field( $stored );
		return self::isValidKey( $key ) ? $key : '';
	}

	/**
	 * Add one same-site URL to the pending list and schedule a single flush.
	 */
	public function enqueueUrl( string $url ): bool {
		if ( ! $this->isEnabled() ) {
			return false;
		}

		$url = esc_url_raw( $url );
		if ( ! $this->isSameSiteHttpUrl( $url ) ) {
			return false;
		}

		$queue = $this->getQueue();
		if ( ! in_array( $url, $queue, true ) ) {
			if ( count( $queue ) >= self::QUEUE_MAX ) {
				return false;
			}
			$queue[] = $url;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		$this->scheduleFlush();
		return true;
	}

	/**
	 * Schedule one queue job. A publish or an older cron event stops here.
	 */
	public function enqueueFlush(): void {
		$this->scheduleFlush();
	}

	/**
	 * Send one batch. Remaining URLs wait for a later queue job.
	 */
	public function flushQueue(): void {
		delete_option( self::FLUSH_PENDING );

		if ( ! $this->isEnabled() ) {
			return;
		}

		$quota = new QuotaManager();
		if ( ! $quota->canPingBing() ) {
			return;
		}

		$queue = $this->getQueue();
		if ( array() === $queue ) {
			return;
		}

		$batch = array_slice( $queue, 0, self::BATCH_SIZE );
		if ( ! $this->submitUrls( $batch ) ) {
			$this->scheduleFlush( MINUTE_IN_SECONDS );
			return;
		}

		update_option( self::QUEUE_OPTION, array_slice( $queue, count( $batch ) ), false );
		foreach ( $batch as $unusedUrl ) {
			$quota->incrementBing();
		}

		if ( array() !== $this->getQueue() ) {
			$this->scheduleFlush( 15 );
		}
	}

	/**
	 * @return array<int, string>
	 */
	public function getQueue(): array {
		$stored = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$urls = array();
		foreach ( $stored as $url ) {
			if ( is_string( $url ) && $this->isSameSiteHttpUrl( $url ) ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	public function ping( string $url ): bool {
		if ( ! $this->isEnabled() || ! $this->isSameSiteHttpUrl( $url ) ) {
			return false;
		}

		return $this->submitUrls( array( $url ) );
	}

	/**
	 * @param array<int, string> $urls
	 */
	private function submitUrls( array $urls ): bool {
		$key  = $this->storedKey();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_values(
			array_filter(
				$urls,
				fn( $url ) => is_string( $url ) && $this->isSameSiteHttpUrl( $url )
			)
		);

		if ( '' === $key || ! is_string( $host ) || '' === $host || array() === $urls ) {
			return false;
		}

		$body = wp_json_encode(
			array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => $urls,
			)
		);
		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}

		$response = wp_safe_remote_post(
			$this->getEndpoint(),
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => $body,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			foreach ( $urls as $url ) {
				$this->logSubmission( $url, 0 );
			}
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		foreach ( $urls as $url ) {
			$this->logSubmission( $url, $code );
		}

		return 200 === $code || 202 === $code;
	}

	public function logSubmission( string $url, int $statusCode ): void {
		$log = $this->getSubmissionLog();

		array_unshift(
			$log,
			array(
				'url'          => $url,
				'submitted_at' => current_time( 'mysql' ),
				'status_code'  => $statusCode,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getSubmissionLog(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/indexing/indexnow-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response( $this->getSubmissionLog() ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/indexing/indexnow-submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'manualSubmit' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);
	}

	public function manualSubmit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->isEnabled() ) {
			return new \WP_Error(
				'indexnow_disabled',
				__( 'Turn on IndexNow and save a key before submitting a URL. Holvek does not create a key.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$params = $request->get_json_params();
		$url    = '';
		$postId = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( $postId > 0 ) {
			$permalink = get_permalink( $postId );
			$url       = is_string( $permalink ) ? $permalink : '';
		} elseif ( isset( $params['url'] ) && is_string( $params['url'] ) ) {
			$url = esc_url_raw( $params['url'] );
		}

		if ( '' === $url || ! $this->enqueueUrl( $url ) ) {
			return new \WP_Error(
				'indexnow_not_queued',
				__( 'Provide a URL on this site, or a post ID.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'queued'  => true,
				'url'     => $url,
			)
		);
	}

	private function currentRequestUri(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( ! is_string( $uri ) ) {
			return '';
		}

		return sanitize_text_field( $uri );
	}

	private function isSameSiteHttpUrl( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		$homeHost = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $homeHost ) && '' !== $homeHost && $host === strtolower( $homeHost );
	}

	private function scheduleFlush( int $delay = 0 ): void {
		if ( 'yes' === get_option( self::FLUSH_PENDING, '' ) ) {
			return;
		}

		update_option( self::FLUSH_PENDING, 'yes', false );
		( new QueueManager() )->push( IndexNowFlushJob::class, array(), max( 0, $delay ) );
	}
}
