<?php

namespace Holvek\WordPress\Modules\ContentAssistant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InclusiveLanguageChecker
 *
 * Flags non-inclusive terminology in post content and suggests modern
 * alternatives. Fully configurable exceptions list. Non-blocking — raises
 * warnings only, never prevents publishing.
 *
 * BL-028
 */
class InclusiveLanguageChecker {

	private const EXCEPTIONS_OPTION = 'holvek_inclusive_exceptions';
	private const CACHE_TTL         = 30 * MINUTE_IN_SECONDS;

	/**
	 * Default problematic terms mapped to suggested alternatives.
	 */
	private const PROBLEMATIC_TERMS = array(
		'whitelist'     => 'allowlist',
		'blacklist'     => 'blocklist',
		'master'        => 'primary',
		'slave'         => 'secondary',
		'grandfathered' => 'legacy',
		'sanity check'  => 'confidence check',
		'dummy'         => 'placeholder',
		'crazy'         => 'unexpected',
		'insane'        => 'unreasonable',
		'guys'          => 'everyone',
		'manpower'      => 'workforce',
		'chairman'      => 'chair',
		'mankind'       => 'humanity',
		'native'        => 'built-in',
		'cripple'       => 'limit',
		'crippled'      => 'limited',
		'kill'          => 'stop',
		'execute'       => 'run',
		'abort'         => 'cancel',
		'man hours'     => 'person-hours',
		'man-hours'     => 'person-hours',
	);

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$editorCap = fn() => current_user_can( 'edit_posts' );
		$adminCap  = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/content/inclusive-check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'checkContent' ),
				'permission_callback' => $editorCap,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/content/inclusive-exceptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getExceptions' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveExceptions' ),
					'permission_callback' => $adminCap,
				),
			)
		);
	}

	public function checkContent( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = $request->get_json_params();
		$postId     = absint( $params['post_id'] ?? 0 );
		$rawContent = $params['content'] ?? '';

		if ( $postId > 0 ) {
			$cacheKey = 'holvek_inclusive_' . $postId;
			$cached   = get_transient( $cacheKey );
			if ( $cached !== false ) {
				return rest_ensure_response( $cached );
			}
			$rawContent = get_post_field( 'post_content', $postId );
		}

		$text       = wp_strip_all_tags( $rawContent );
		$exceptions = $this->getExceptionsList();
		$issues     = $this->findIssues( $text, $exceptions );

		$result = array(
			'post_id'      => $postId ?: null,
			'issues_found' => count( $issues ),
			'issues'       => $issues,
			'clean'        => empty( $issues ),
		);

		if ( $postId > 0 ) {
			set_transient( $cacheKey, $result, self::CACHE_TTL );
		}

		return rest_ensure_response( $result );
	}

	public function getExceptions( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'exceptions'    => $this->getExceptionsList(),
				'default_terms' => array_keys( self::PROBLEMATIC_TERMS ),
			)
		);
	}

	public function saveExceptions( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = $request->get_json_params();
		$exceptions = array_map( 'strtolower', array_map( 'sanitize_text_field', $params['exceptions'] ?? array() ) );
		update_option( self::EXCEPTIONS_OPTION, array_values( array_unique( $exceptions ) ) );
		return rest_ensure_response(
			array(
				'success'    => true,
				'exceptions' => $exceptions,
			)
		);
	}

	// ── Analysis ──────────────────────────────────────────────────────────────

	private function findIssues( string $text, array $exceptions ): array {
		$issues  = array();
		$textLow = mb_strtolower( $text );

		foreach ( self::PROBLEMATIC_TERMS as $term => $suggestion ) {
			if ( in_array( strtolower( $term ), $exceptions, true ) ) {
				continue;
			}

			// Whole-word matching
			$pattern = '/\b' . preg_quote( $term, '/' ) . '\b/i';
			$matches = array();
			if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$offset  = $match[1];
				$start   = max( 0, $offset - 30 );
				$end     = min( mb_strlen( $text ), $offset + mb_strlen( $term ) + 30 );
				$context = '…' . substr( $text, $start, $end - $start ) . '…';

				$issues[] = array(
					'term'       => $term,
					'suggestion' => $suggestion,
					'context'    => $context,
					'offset'     => $offset,
				);
			}
		}

		return $issues;
	}

	private function getExceptionsList(): array {
		return (array) get_option( self::EXCEPTIONS_OPTION, array() );
	}
}
