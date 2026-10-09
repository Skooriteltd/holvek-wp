<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchemaOutputValidator
 *
 * Validates the JSON-LD structured data blocks found on a given page
 * by fetching the page content and parsing the schema locally (no
 * external API quota). Returns rich result eligibility assessment and
 * links to Google's online Rich Results Test for manual verification.
 *
 * BL-020
 */
class SchemaOutputValidator {

	private const CACHE_TTL = HOUR_IN_SECONDS;

	/** Minimum required properties per schema @type */
	private const REQUIRED_PROPS = array(
		'Article'        => array( 'headline', 'author', 'datePublished' ),
		'NewsArticle'    => array( 'headline', 'author', 'datePublished' ),
		'BlogPosting'    => array( 'headline', 'author', 'datePublished' ),
		'Product'        => array( 'name', 'offers' ),
		'FAQPage'        => array( 'mainEntity' ),
		'HowTo'          => array( 'name', 'step' ),
		'LocalBusiness'  => array( 'name', 'address' ),
		'Recipe'         => array( 'name', 'recipeIngredient', 'recipeInstructions' ),
		'Event'          => array( 'name', 'startDate', 'location' ),
		'Person'         => array( 'name' ),
		'Organization'   => array( 'name' ),
		'BreadcrumbList' => array( 'itemListElement' ),
		'VideoObject'    => array( 'name', 'description', 'thumbnailUrl', 'uploadDate' ),
		'ImageObject'    => array( 'url' ),
		'Review'         => array( 'reviewRating', 'author' ),
		'JobPosting'     => array( 'title', 'description', 'datePosted', 'hiringOrganization' ),
	);

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/schema/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validateSchema' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	public function validateSchema( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_json_params();
		$postId = absint( $params['post_id'] ?? 0 );
		$url    = sanitize_url( $params['url'] ?? '' );

		if ( $postId > 0 ) {
			$url = get_permalink( $postId );
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_target', __( 'Provide post_id or url.', 'holvek' ), array( 'status' => 400 ) );
		}

		if ( ! $this->isLocalUrl( $url ) ) {
			return new \WP_Error( 'invalid_target', __( 'Schema validation accepts only URLs on this WordPress site.', 'holvek' ), array( 'status' => 400 ) );
		}

		$cacheKey = 'holvek_schema_validation_' . md5( $url );
		$cached   = get_transient( $cacheKey );
		if ( $cached !== false ) {
			return rest_ensure_response( array_merge( $cached, array( 'cached' => true ) ) );
		}

		$result = $this->runValidation( $url );
		set_transient( $cacheKey, $result, self::CACHE_TTL );

		return rest_ensure_response( array_merge( $result, array( 'cached' => false ) ) );
	}

	// ── Validation engine ─────────────────────────────────────────────────────

	private function runValidation( string $url ): array {
		// Fetch the rendered page
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'Holvek Schema Validator/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'url'             => $url,
				'error'           => 'Could not fetch page: ' . $response->get_error_message(),
				'schemas_found'   => array(),
				'google_test_url' => $this->googleTestUrl( $url ),
				'validated_at'    => current_time( 'mysql' ),
			);
		}

		$html   = wp_remote_retrieve_body( $response );
		$blocks = $this->extractJsonLdBlocks( $html );

		$schemasFound = array();
		foreach ( $blocks as $json ) {
			$decoded = json_decode( $json, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$schemasFound[] = array(
					'type'        => 'unknown',
					'valid'       => false,
					'errors'      => array(
						sprintf(
							/* translators: %s: JSON parser error. */
							__( 'Invalid JSON: %s', 'holvek' ),
							json_last_error_msg()
						),
					),
					'warnings'    => array(),
					'raw_snippet' => mb_substr( $json, 0, 200 ),
				);
				continue;
			}

			// Handle @graph arrays
			if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
				foreach ( $decoded['@graph'] as $item ) {
					$schemasFound[] = $this->validateBlock( $item );
				}
			} else {
				$schemasFound[] = $this->validateBlock( $decoded );
			}
		}

		return array(
			'url'             => $url,
			'schemas_found'   => $schemasFound,
			'total_schemas'   => count( $schemasFound ),
			'total_errors'    => array_sum( array_map( fn( $s ) => count( $s['errors'] ), $schemasFound ) ),
			'google_test_url' => $this->googleTestUrl( $url ),
			'validated_at'    => current_time( 'mysql' ),
		);
	}

	private function validateBlock( array $block ): array {
		$type     = $block['@type'] ?? 'unknown';
		$errors   = array();
		$warnings = array();

		// Check @context
		if ( empty( $block['@context'] ) ) {
			$errors[] = 'Missing @context. Should be "https://schema.org".';
		} elseif ( ! in_array( $block['@context'], array( 'https://schema.org', 'http://schema.org' ), true ) ) {
			$warnings[] = '@context "' . $block['@context'] . '" is non-standard. Use "https://schema.org".';
		}

		// Check @type
		if ( empty( $type ) || $type === 'unknown' ) {
			$errors[] = 'Missing or invalid @type.';
		}

		// Check required properties
		if ( isset( self::REQUIRED_PROPS[ $type ] ) ) {
			foreach ( self::REQUIRED_PROPS[ $type ] as $prop ) {
				if ( empty( $block[ $prop ] ) ) {
					$errors[] = "Required property \"{$prop}\" is missing for {$type}.";
				}
			}
		}

		// Type-specific warnings
		if ( in_array( $type, array( 'Article', 'NewsArticle', 'BlogPosting' ), true ) ) {
			if ( ! empty( $block['datePublished'] ) && empty( $block['dateModified'] ) ) {
				$warnings[] = 'Consider adding "dateModified" for freshness signals.';
			}
			if ( empty( $block['image'] ) ) {
				$warnings[] = '"image" property recommended for Article rich results.';
			}
		}

		if ( $type === 'Product' && empty( $block['offers']['price'] ) ) {
			$warnings[] = 'Price is missing from the offers object. Required for price-eligible rich snippets.';
		}

		return array(
			'type'     => $type,
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	private function extractJsonLdBlocks( string $html ): array {
		preg_match_all(
			'/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
			$html,
			$matches
		);

		return array_map( 'trim', $matches[1] ?? array() );
	}

	private function googleTestUrl( string $url ): string {
		return 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url );
	}

	private function isLocalUrl( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) )
			=== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
