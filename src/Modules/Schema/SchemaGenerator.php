<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles schema graph building, automated video markup inclusion,
 * and handles global conditional schema templates.
 */
class SchemaGenerator {

	private array $schemaTypes = array();

	public function __construct() {
		$this->schemaTypes[] = new Types\ArticleSchema();
		$this->schemaTypes[] = new Types\BreadcrumbSchema();
		$this->schemaTypes[] = new Types\CustomSchema();
	}

	/**
	 * Echoes the compiled JSON-LD graph to the footer of the singular post page.
	 */
	public function outputSchema(): void {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		$postId = get_the_ID();
		if ( ! $postId ) {
			return;
		}

		$schema = $this->getSchemaForPost( $postId );
		if ( empty( $schema ) || empty( $schema['@graph'] ) ) {
			return;
		}

		$json = JsonLd::encode( $schema );
		if ( '' === $json ) {
			return;
		}

		echo "<!-- Holvek AI-Ready Schema -->\n";
		// JSON_HEX_TAG, JSON_HEX_AMP, and JSON_HEX_APOS already encode the payload. esc_html() would break the JSON.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_get_inline_script_tag(
			$json,
			array(
				'type' => 'application/ld+json',
			)
		);
		echo "<!-- /Holvek Schema -->\n";
	}

	/**
	 * Compiles the full schema graph for a specific post.
	 */
	public function getSchemaForPost( int $postId ): array {
		$graphs = array();

		// Loop through standard schema types (Article, Breadcrumbs, Custom)
		foreach ( $this->schemaTypes as $type ) {
			if ( $type->isApplicable( $postId ) ) {
				$payload = $type->generate( $postId );
				if ( JsonLd::isListOfNodes( $payload ) ) {
					foreach ( $payload as $node ) {
						$graphs[] = $node;
					}
				} elseif ( ! empty( $payload ) ) {
					$graphs[] = $payload;
				}
			}
		}

		// Task 2.15: Automated VideoObject Schema scraper integration
		$videoObjects = VideoExtractor::extractVideoObjects( $postId );
		if ( ! empty( $videoObjects ) ) {
			foreach ( $videoObjects as $videoObj ) {
				$graphs[] = $videoObj;
			}
		}

		if ( empty( $graphs ) ) {
			return array();
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graphs,
		);
	}

	/**
	 * Resolves effective primary schema based on local or global templates.
	 */
	public static function getEffectivePrimarySchema( int $postId ): string {
		$primarySchema = get_post_meta( $postId, '_holvek_primary_schema', true );
		if ( ! empty( $primarySchema ) && $primarySchema !== 'none' ) {
			return $primarySchema;
		}

		// Apply global conditional display rules
		$globalRulesJson = get_option( 'holvek_global_schema_rules' );
		if ( ! empty( $globalRulesJson ) ) {
			$rules = json_decode( $globalRulesJson, true );
			if ( is_array( $rules ) ) {
				$post = get_post( $postId );
				if ( $post ) {
					foreach ( $rules as $rule ) {
						if ( isset( $rule['post_type'] ) && $rule['post_type'] === $post->post_type ) {
							if ( isset( $rule['category'] ) && $rule['category'] !== 'all' ) {
								$cats = wp_get_post_categories( $postId );
								if ( in_array( intval( $rule['category'] ), $cats, true ) ) {
									return $rule['schema_type'];
								}
							} else {
								return $rule['schema_type'];
							}
						}
					}
				}
			}
		}

		return 'none';
	}
}
