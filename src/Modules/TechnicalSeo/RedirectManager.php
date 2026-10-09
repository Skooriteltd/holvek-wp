<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles, matches, and performs 301/302/307 redirects.
 * Exposes a production-grade REST API for Redirect CRUD.
 *
 * Performance Architecture:
 *   - Counts live in the autoloaded option holvek_redirect_counts. A page with
 *     no rules returns before any redirect query.
 *   - Exact matches: Direct SQL lookup by source URL (indexed column).
 *   - Regex rules: Object cache, then a transient, then one bounded query.
 */
class RedirectManager {

	private const OPTION_KEY         = 'holvek_redirects';
	private const COUNTS_OPTION      = 'holvek_redirect_counts';
	private const REGEX_CACHE_KEY    = 'holvek_regex_redirects';
	private const REGEX_TRANSIENT    = 'holvek_regex_redirects';
	private const REGEX_CACHE_GROUP  = 'holvek';
	private const ADMIN_PAGE_MAX     = 100;
	private const IMPORT_QUEUE       = 'holvek_redirect_import_queue';
	private const IMPORT_BATCH       = 50;
	private const IMPORT_LIMIT       = 1000;
	private const IMPORT_BODY_MAX    = 524288;
	private const MAX_PATTERN_LENGTH = 180;
	private const PREG_MATCH_CAP     = 10000;

	/**
	 * Intercept front-end requests and perform redirects if a rule matches.
	 */
	public function handleRedirects(): void {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$counts = $this->redirectCounts();
		if ( $counts['exact'] === 0 && $counts['regex'] === 0 ) {
			return;
		}

		$currentPath  = $this->getCurrentPath();
		$cleanCurrent = $this->normalizePath( $currentPath );

		if ( $counts['exact'] > 0 ) {
			$exactMatch = $this->findExactMatch( $cleanCurrent );
			if ( $exactMatch ) {
				$this->performRedirect( $exactMatch->target, (int) $exactMatch->code );
			}
		}

		if ( $counts['regex'] < 1 ) {
			return;
		}

		foreach ( $this->getRegexRules() as $rule ) {
			$pattern = $this->compiledPattern( (string) $rule->source );
			if ( $pattern === null ) {
				continue;
			}

			$newTarget = $this->replaceRegexTarget( $pattern, (string) $rule->target, $currentPath );
			if ( is_string( $newTarget ) && $newTarget !== '' ) {
				$this->performRedirect( $newTarget, (int) $rule->code );
			}
		}
	}

	/**
	 * Find an exact-match redirect by normalized source path.
	 * Uses a direct indexed query — O(1) lookup instead of loading all rows.
	 */
	private function findExactMatch( string $normalizedPath ): ?object {
		global $wpdb;

		// Look up both the normalized path and common path variations
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT target, code FROM {$wpdb->prefix}holvek_redirects WHERE source = %s AND is_regex = 0 LIMIT 1",
				$normalizedPath
			)
		);

		// Also try with trailing slash stripped/added for flexibility
		if ( ! $result ) {
			$alt = ( substr( $normalizedPath, -1 ) === '/' )
				? rtrim( $normalizedPath, '/' )
				: $normalizedPath . '/';

			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT target, code FROM {$wpdb->prefix}holvek_redirects WHERE source = %s AND is_regex = 0 LIMIT 1",
					$alt
				)
			);
		}

		return $result ?: null;
	}

	/**
	 * Regex rules are read on front-end requests that have at least one regex redirect.
	 * The transient covers hosts with no persistent object cache.
	 */
	private function getRegexRules(): array {
		$cached = wp_cache_get( self::REGEX_CACHE_KEY, self::REGEX_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stored = get_transient( self::REGEX_TRANSIENT );
		if ( is_array( $stored ) ) {
			wp_cache_set( self::REGEX_CACHE_KEY, $stored, self::REGEX_CACHE_GROUP, HOUR_IN_SECONDS );
			return $stored;
		}

		global $wpdb;
		$rules = $wpdb->get_results(
			"SELECT source, target, code FROM {$wpdb->prefix}holvek_redirects WHERE is_regex = 1 LIMIT 500"
		);

		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		set_transient( self::REGEX_TRANSIENT, $rules, HOUR_IN_SECONDS );
		wp_cache_set( self::REGEX_CACHE_KEY, $rules, self::REGEX_CACHE_GROUP, HOUR_IN_SECONDS );

		return $rules;
	}

	/**
	 * Drop cached regex rules after a redirect is created, imported, or deleted.
	 */
	private function flushRegexCache(): void {
		wp_cache_delete( self::REGEX_CACHE_KEY, self::REGEX_CACHE_GROUP );
		delete_transient( self::REGEX_TRANSIENT );
	}

	/**
	 * Front-end gate. The option is autoloaded because every HTML request reads it.
	 * A missing option is counted once from the table so existing rules keep working.
	 *
	 * @return array{exact:int,regex:int}
	 */
	private function redirectCounts(): array {
		$stored = get_option( self::COUNTS_OPTION, null );
		if ( $this->isCountShape( $stored ) ) {
			return array(
				'exact' => (int) $stored['exact'],
				'regex' => (int) $stored['regex'],
			);
		}

		return $this->rebuildRedirectCounts();
	}

	/**
	 * @param mixed $stored Stored option value.
	 */
	private function isCountShape( $stored ): bool {
		return is_array( $stored )
			&& array_key_exists( 'exact', $stored )
			&& array_key_exists( 'regex', $stored )
			&& is_numeric( $stored['exact'] )
			&& is_numeric( $stored['regex'] );
	}

	/**
	 * @return array{exact:int,regex:int}
	 */
	private function rebuildRedirectCounts(): array {
		global $wpdb;
		$counts = array(
			'exact' => max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects WHERE is_regex = 0" ) ),
			'regex' => max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects WHERE is_regex = 1" ) ),
		);
		update_option( self::COUNTS_OPTION, $counts, true );
		return $counts;
	}

	private function recordNewRedirects( int $exactAdded, int $regexAdded ): void {
		if ( $exactAdded === 0 && $regexAdded === 0 ) {
			return;
		}

		$stored = get_option( self::COUNTS_OPTION, null );
		if ( ! $this->isCountShape( $stored ) ) {
			$this->rebuildRedirectCounts();
			return;
		}

		update_option(
			self::COUNTS_OPTION,
			array(
				'exact' => (int) $stored['exact'] + $exactAdded,
				'regex' => (int) $stored['regex'] + $regexAdded,
			),
			true
		);
	}

	private function recordDeletedRedirect( bool $isRegex ): void {
		$stored = get_option( self::COUNTS_OPTION, null );
		if ( ! $this->isCountShape( $stored ) ) {
			$this->rebuildRedirectCounts();
			return;
		}

		$exact = (int) $stored['exact'];
		$regex = (int) $stored['regex'];
		if ( $isRegex ) {
			$regex = max( 0, $regex - 1 );
		} else {
			$exact = max( 0, $exact - 1 );
		}

		update_option(
			self::COUNTS_OPTION,
			array(
				'exact' => $exact,
				'regex' => $regex,
			),
			true
		);
	}

	/**
	 * A pattern that does not compile, is longer than the cap, or overruns a
	 * capped preg_match is rejected at save time. An older stored pattern that
	 * does not compile is skipped. The front-end match does not change PCRE limits.
	 */
	private function compiledPattern( string $source ): ?string {
		if ( $source === '' || strlen( $source ) > self::MAX_PATTERN_LENGTH || str_contains( $source, "\0" ) ) {
			return null;
		}

		$body = str_replace( '/', '\\/', ltrim( $source, '/' ) );
		if ( $body === '' ) {
			return null;
		}

		$pattern  = '/' . $body . '/i';
		$sample   = str_repeat( 'a', 24 );
		$limits   = array(
			'pcre.backtrack_limit' => (string) self::PREG_MATCH_CAP,
			'pcre.recursion_limit' => '50',
		);
		$previous = array();
		foreach ( $limits as $name => $value ) {
			$previous[ $name ] = ini_get( $name );
			// The lowered limit is the validation. A pattern that overruns it is rejected.
			// phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( $name, $value );
		}

		try {
			$compiled = $this->safePregMatch( $pattern, '' );
			$bounded  = $this->safePregMatch( $pattern, $sample );
		} finally {
			foreach ( $previous as $name => $value ) {
				if ( false !== $value ) {
					// phpcs:ignore WordPress.PHP.IniSet.Risky
					ini_set( $name, (string) $value );
				}
			}
		}

		if ( false === $compiled || false === $bounded ) {
			return null;
		}

		return $pattern;
	}

	private function replaceRegexTarget( string $pattern, string $replacement, string $path ): ?string {
		if ( $this->safePregMatch( $pattern, $path ) !== 1 ) {
			return null;
		}

		$replaced = $this->safePregReplace( $pattern, $replacement, $path );
		return is_string( $replaced ) ? $replaced : null;
	}

	/**
	 * @return int|false
	 */
	private function safePregMatch( string $pattern, string $subject ) {
		// A stored pattern may not compile. The silence is the validation result.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = @preg_match( $pattern, $subject );
		return $result === false ? false : $result;
	}

	private function safePregReplace( string $pattern, string $replacement, string $subject ): ?string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = @preg_replace( $pattern, $replacement, $subject );
		return is_string( $result ) ? $result : null;
	}

	/**
	 * First page of redirect rules. The admin list uses getRedirectsEndpoint().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getRedirectRules(): array {
		$this->migrateLegacyRedirects();
		return $this->queryRedirectPage( 1, self::ADMIN_PAGE_MAX );
	}

	/**
	 * Move a legacy option into the table once, without reading every row back.
	 */
	private function migrateLegacyRedirects(): void {
		$legacyRedirects = get_option( self::OPTION_KEY );
		if ( empty( $legacyRedirects ) || ! is_array( $legacyRedirects ) ) {
			return;
		}

		global $wpdb;
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects" );
		if ( $existing > 0 ) {
			delete_option( self::OPTION_KEY );
			return;
		}

		foreach ( $legacyRedirects as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->prefix . 'holvek_redirects',
				array(
					'id'       => $rule['id'] ?? ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'redir_', true ) ),
					'source'   => $rule['source'] ?? '',
					'target'   => $rule['target'] ?? '',
					'code'     => intval( $rule['code'] ?? 301 ),
					'is_regex' => ! empty( $rule['is_regex'] ) ? 1 : 0,
				)
			);
		}

		delete_option( self::OPTION_KEY );
		$this->rebuildRedirectCounts();
		$this->flushRegexCache();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function queryRedirectPage( int $page, int $perPage ): array {
		global $wpdb;

		$page    = max( 1, $page );
		$perPage = min( self::ADMIN_PAGE_MAX, max( 1, $perPage ) );
		$offset  = ( $page - 1 ) * $perPage;
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source, target, code, is_regex FROM {$wpdb->prefix}holvek_redirects ORDER BY id ASC LIMIT %d OFFSET %d",
				$perPage,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['is_regex'] = ! empty( $row['is_regex'] );
			$row['code']     = intval( $row['code'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Expose REST routes for Redirect CRUD.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/redirects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getRedirectsEndpoint' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'addRedirectEndpoint' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/redirects/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deleteRedirectEndpoint' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		// BL-005: CSV import/export
		register_rest_route(
			'holvek/v1',
			'/redirects/import-csv',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'importCsvEndpoint' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/redirects/export-csv',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'exportCsvEndpoint' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);
	}

	// ── BL-005: CSV import ────────────────────────────────────────────────────

	/**
	 * Import redirects from a CSV body.
	 *
	 * The request body should be raw CSV text with a header row:
	 *   source,target,code,is_regex
	 */
	public function importCsvEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$rawCsv = $request->get_body();

		if ( strlen( $rawCsv ) > self::IMPORT_BODY_MAX ) {
			return new \WP_Error(
				'csv_too_large',
				__( 'The CSV is too large. Import at most 1000 rows.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( $rawCsv === '' ) {
			return new \WP_Error( 'empty_body', __( 'CSV body is empty.', 'holvek' ), array( 'status' => 400 ) );
		}

		$rows = $this->parseCsvBody( $rawCsv );

		if ( array() === $rows ) {
			return new \WP_Error(
				'parse_error',
				__( 'Could not parse CSV. Ensure headers: source,target,code,is_regex', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$rows     = array_slice( $rows, 0, self::IMPORT_LIMIT );
		$accepted = array();
		$skipped  = 0;
		$errors   = array();

		foreach ( $rows as $i => $row ) {
			$checked = $this->checkedImportRow( $row, $i + 2 );
			if ( is_string( $checked ) ) {
				$errors[] = $checked;
				++$skipped;
				continue;
			}
			$accepted[] = $checked;
		}

		$pending = get_option( self::IMPORT_QUEUE, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$room = max( 0, self::IMPORT_LIMIT - count( $pending ) );
		if ( count( $accepted ) > $room ) {
			$skipped += count( $accepted ) - $room;
			$accepted = array_slice( $accepted, 0, $room );
			$errors[] = __( 'The import queue is full. Try again after the current import finishes.', 'holvek' );
		}

		if ( array() !== $accepted ) {
			update_option( self::IMPORT_QUEUE, array_merge( $pending, $accepted ), false );
			( new \Holvek\WordPress\Core\Queue\QueueManager() )->push( RedirectImportJob::class, array(), 0 );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'queued'   => count( $accepted ),
				'imported' => 0,
				'skipped'  => $skipped,
				'errors'   => $errors,
			)
		);
	}

	/**
	 * Insert one batch from the import queue. Schedules another batch while rows remain.
	 */
	public function importQueuedBatch(): void {
		$pending = get_option( self::IMPORT_QUEUE, array() );
		if ( ! is_array( $pending ) || array() === $pending ) {
			delete_option( self::IMPORT_QUEUE );
			return;
		}

		$batch   = array_splice( $pending, 0, self::IMPORT_BATCH );
		$exact   = 0;
		$regex   = 0;
		$written = 0;

		global $wpdb;

		foreach ( $batch as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$source  = sanitize_text_field( (string) ( $row['source'] ?? '' ) );
			$target  = sanitize_text_field( (string) ( $row['target'] ?? '' ) );
			$code    = (int) ( $row['code'] ?? 301 );
			$isRegex = ! empty( $row['is_regex'] ) ? 1 : 0;

			if ( $source === '' || $target === '' || ! $this->isSafeTarget( $target, (bool) $isRegex ) ) {
				continue;
			}
			if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
				continue;
			}
			if ( $isRegex && null === $this->compiledPattern( $source ) ) {
				continue;
			}

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects WHERE source = %s",
					$source
				)
			);
			if ( $exists > 0 || null !== $this->detectRedirectLoop( $source, $target ) ) {
				continue;
			}

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'holvek_redirects',
				array(
					'id'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'redir_', true ),
					'source'   => $source,
					'target'   => $target,
					'code'     => $code,
					'is_regex' => $isRegex,
				)
			);
			if ( false === $inserted ) {
				continue;
			}

			++$written;
			if ( $isRegex ) {
				++$regex;
			} else {
				++$exact;
			}
		}

		if ( array() === $pending ) {
			delete_option( self::IMPORT_QUEUE );
		} else {
			update_option( self::IMPORT_QUEUE, $pending, false );
			( new \Holvek\WordPress\Core\Queue\QueueManager() )->push( RedirectImportJob::class, array(), 1 );
		}

		$this->recordNewRedirects( $exact, $regex );
		if ( $written > 0 ) {
			$this->flushRegexCache();
		}
	}

	/**
	 * @param array<string, mixed> $row CSV row.
	 * @return array{source:string,target:string,code:int,is_regex:int}|string An error message when the row is rejected.
	 */
	private function checkedImportRow( array $row, int $line ) {
		$source  = sanitize_text_field( (string) ( $row['source'] ?? '' ) );
		$target  = sanitize_text_field( (string) ( $row['target'] ?? '' ) );
		$code    = (int) ( $row['code'] ?? 301 );
		$isRegex = ! empty( $row['is_regex'] ) && (string) $row['is_regex'] !== '0' ? 1 : 0;

		if ( $source === '' || $target === '' ) {
			return sprintf(
				/* translators: %d: CSV line number. */
				__( 'Row %d: source and target are required.', 'holvek' ),
				$line
			);
		}

		if ( ! $this->isSafeTarget( $target, (bool) $isRegex ) ) {
			return sprintf(
				/* translators: %d: CSV line number. */
				__( 'Row %d: target must be a site-relative path or an absolute URL on this site.', 'holvek' ),
				$line
			);
		}

		if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
			return sprintf(
				/* translators: 1: CSV line number, 2: rejected status code. */
				__( 'Row %1$d: invalid code %2$d. Use 301, 302, 307, 410, or 451.', 'holvek' ),
				$line,
				$code
			);
		}

		if ( $isRegex && null === $this->compiledPattern( $source ) ) {
			return sprintf(
				/* translators: %d: CSV line number. */
				__( 'Row %d: regex pattern is invalid.', 'holvek' ),
				$line
			);
		}

		return array(
			'source'   => $source,
			'target'   => $target,
			'code'     => $code,
			'is_regex' => $isRegex,
		);
	}

	/**
	 * Export all redirect rules as a CSV file download.
	 */
	public function exportCsvEndpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$this->migrateLegacyRedirects();

		$lines   = array();
		$lines[] = 'source,target,code,is_regex';
		$page    = 1;
		$fetched = 0;

		do {
			$rules   = $this->queryRedirectPage( $page, self::ADMIN_PAGE_MAX );
			$fetched = count( $rules );
			foreach ( $rules as $rule ) {
				$lines[] = implode(
					',',
					array(
						'"' . str_replace( '"', '""', (string) $rule['source'] ) . '"',
						'"' . str_replace( '"', '""', (string) $rule['target'] ) . '"',
						(int) $rule['code'],
						(int) ( $rule['is_regex'] ?? 0 ),
					)
				);
			}
			++$page;
		} while ( $fetched === self::ADMIN_PAGE_MAX && $page <= 200 );

		$csv = implode( "\n", $lines );

		$response = rest_ensure_response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename=holvek-redirects.csv' );

		return $response;
	}

	/**
	 * Parse raw CSV string into array of associative arrays.
	 * First row is treated as the header.
	 */
	public function parseCsvBody( string $csv ): array {
		$lines  = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		$result = array();

		if ( count( $lines ) < 2 ) {
			return array();
		}

		$header = str_getcsv( array_shift( $lines ) );
		$header = array_map( 'strtolower', array_map( 'trim', $header ) );

		// Require at minimum source and target columns
		if ( ! in_array( 'source', $header, true ) || ! in_array( 'target', $header, true ) ) {
			return array();
		}

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}
			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $header ) ) {
				continue;
			}
			$result[] = array_combine( $header, $values );
		}

		return $result;
	}

	public function checkPermission(): bool {
		return current_user_can( 'manage_redirects' ) || current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
	}

	public function getRedirectsEndpoint( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$this->migrateLegacyRedirects();

		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = (int) $request->get_param( 'per_page' );
		if ( $perPage < 1 ) {
			$perPage = self::ADMIN_PAGE_MAX;
		}
		$perPage = min( self::ADMIN_PAGE_MAX, $perPage );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects" );
		$rows  = $this->queryRedirectPage( $page, $perPage );

		return rest_ensure_response(
			array(
				'items'       => $rows,
				'total'       => $total,
				'total_pages' => ceil( $total / $perPage ),
				'page'        => $page,
			)
		);
	}

	public function addRedirectEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();

		$source  = sanitize_text_field( $params['source'] ?? '' );
		$target  = sanitize_text_field( $params['target'] ?? '' );
		$code    = intval( $params['code'] ?? 301 );
		$isRegex = ! empty( $params['is_regex'] );

		if ( empty( $source ) || empty( $target ) ) {
			return new \WP_Error( 'invalid_fields', __( 'Source and Target URLs are required.', 'holvek' ), array( 'status' => 400 ) );
		}

		if ( ! $this->isSafeTarget( $target, $isRegex ) ) {
			return new \WP_Error(
				'unsafe_target',
				__( 'Redirect targets must be site-relative paths or absolute URLs on this site.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
			$code = 301;
		}

		if ( $isRegex && $this->compiledPattern( $source ) === null ) {
			return new \WP_Error( 'invalid_regex', __( 'The provided regex pattern is invalid.', 'holvek' ), array( 'status' => 400 ) );
		}

		global $wpdb;

		// Check for duplicate source
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}holvek_redirects WHERE source = %s",
				$source
			)
		);
		if ( $existing > 0 ) {
			return new \WP_Error( 'duplicate', __( 'A redirect with this source already exists.', 'holvek' ), array( 'status' => 400 ) );
		}

		// Walk the chain: target → target's target → … up to 10 hops.
		// If the new source appears anywhere in that chain it would create a loop.
		$loopError = $this->detectRedirectLoop( $source, $target );
		if ( $loopError !== null ) {
			return new \WP_Error( 'redirect_loop', $loopError, array( 'status' => 400 ) );
		}

		$id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'redir_', true );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'holvek_redirects',
			array(
				'id'       => $id,
				'source'   => $source,
				'target'   => $target,
				'code'     => $code,
				'is_regex' => $isRegex ? 1 : 0,
			)
		);

		if ( $inserted === false ) {
			return new \WP_Error( 'db_error', __( 'Failed to save redirect inside the database.', 'holvek' ), array( 'status' => 500 ) );
		}

		$this->recordNewRedirects( $isRegex ? 0 : 1, $isRegex ? 1 : 0 );
		$this->flushRegexCache();

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Walk the redirect chain starting from $firstTarget and check whether
	 * $newSource appears anywhere in it (which would create a loop).
	 *
	 * Only exact-match (non-regex) rules are evaluated — regex rules are
	 * intentionally excluded because their patterns cannot be reliably
	 * compared against plain URL strings without executing the regex.
	 *
	 * @param string $newSource   The source URL being added.
	 * @param string $firstTarget The target URL the new rule points to.
	 * @return string|null        Error message on loop detected, null if safe.
	 */
	private function detectRedirectLoop( string $newSource, string $firstTarget ): ?string {
		global $wpdb;

		$normalizedSource = $this->normalizePath( $newSource );
		$visited          = array( $normalizedSource => true ); // treat source as already "seen"
		$current          = $this->normalizePath( $firstTarget );

		// Cap walk depth — prevents runaway DB queries on pathological chains
		$maxDepth = 10;

		for ( $depth = 0; $depth < $maxDepth; $depth++ ) {
			// Is this hop back to the source we're trying to add?
			if ( isset( $visited[ $current ] ) ) {
				return sprintf(
					'Redirect loop detected: "%s" → "%s" creates a circular chain.',
					$newSource,
					$firstTarget
				);
			}

			$visited[ $current ] = true;

			// Look up whether this URL is itself a redirect source
			$nextTarget = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT target FROM {$wpdb->prefix}holvek_redirects WHERE source = %s AND is_regex = 0 LIMIT 1",
					$current
				)
			);

			if ( empty( $nextTarget ) ) {
				break; // End of chain — no loop
			}

			$current = $this->normalizePath( $nextTarget );
		}

		return null; // Safe to insert
	}

	public function deleteRedirectEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		$id     = sanitize_text_field( $params['id'] ?? '' );

		if ( empty( $id ) ) {
			return new \WP_Error( 'missing_id', __( 'Redirect ID is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT is_regex FROM {$wpdb->prefix}holvek_redirects WHERE id = %s",
				$id
			)
		);
		$deleted = $wpdb->delete( $wpdb->prefix . 'holvek_redirects', array( 'id' => $id ) );

		if ( $deleted === false ) {
			return new \WP_Error( 'db_error', __( 'Failed to delete redirect from the database.', 'holvek' ), array( 'status' => 500 ) );
		}

		if ( $deleted > 0 ) {
			$this->recordDeletedRedirect( is_object( $row ) && ! empty( $row->is_regex ) );
			$this->flushRegexCache();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Get the relative request path.
	 */
	private function getCurrentPath(): string {
		$path      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$pathParts = explode( '?', $path );
		return $pathParts[0];
	}

	/**
	 * Clean and normalize a path.
	 */
	private function normalizePath( string $url ): string {
		// If absolute URL, extract path
		if ( strpos( $url, 'http://' ) === 0 || strpos( $url, 'https://' ) === 0 ) {
			$parsed = wp_parse_url( $url );
			$url    = $parsed['path'] ?? '/';
		}

		return '/' . trim( $url, '/' );
	}

	/**
	 * Redirect and exit cleanly.
	 */
	private function performRedirect( string $target, int $code ): void {
		// Support relative targets
		if ( strpos( $target, 'http://' ) !== 0 && strpos( $target, 'https://' ) !== 0 ) {
			$target = home_url( $target );
		}

		if ( ! $this->isSafeTarget( $target, false ) ) {
			return;
		}

		wp_safe_redirect( $target, $code );
		exit;
	}

	/**
	 * This plugin ships local redirects only. Restricting targets to the site
	 * host prevents an administrator typo, imported CSV, or compromised admin
	 * account from turning the site into an open redirector.
	 */
	private function isSafeTarget( string $target, bool $isRegex ): bool {
		if ( $isRegex && str_contains( $target, '$' ) ) {
			// Substitution values are evaluated at request time. Keep the
			// static prefix local; capture groups may only extend the path.
			// A leading "//" is protocol-relative and must not pass.
			return str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' );
		}

		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			return true;
		}

		$parts    = wp_parse_url( $target );
		$siteHost = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_array( $parts )
			&& in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			&& ! empty( $parts['host'] )
			&& strtolower( $parts['host'] ) === strtolower( (string) $siteHost );
	}
}
