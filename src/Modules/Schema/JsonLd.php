<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encodes JSON-LD for a script tag.
 *
 * JSON_HEX_TAG turns "<" into "\u003C", so a value that contains "</script>"
 * cannot close the tag. Custom JSON is accepted only when it is one Schema.org
 * node, a list of nodes, a @graph document, or the editor's key/value list.
 */
class JsonLd {

	public const ENCODE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	private const MAX_BYTES      = 20000;
	private const MAX_NODES      = 20;
	private const MAX_PROPERTIES = 40;
	private const MAX_VALUE      = 5000;

	/**
	 * @param mixed $data
	 */
	public static function encode( $data ): string {
		$json = wp_json_encode( $data, self::ENCODE_FLAGS );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		return $json;
	}

	/**
	 * Print one JSON-LD script. An empty payload prints nothing.
	 *
	 * @param mixed $data
	 */
	public static function printScript( $data ): void {
		$json = self::encode( $data );
		if ( '' === $json ) {
			return;
		}

		// JSON_HEX_TAG, JSON_HEX_AMP, and JSON_HEX_APOS already encode the payload. esc_html() would break the JSON.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_get_inline_script_tag(
			$json,
			array(
				'type' => 'application/ld+json',
			)
		);
	}

	/**
	 * Sanitize the custom-schema meta value before it is stored.
	 *
	 * @param mixed $value Stored meta value.
	 * @return string|\WP_Error
	 */
	public static function sanitizeCustomMeta( $value ) {
		if ( ! is_string( $value ) ) {
			return self::invalidCustomSchema();
		}

		$parsed = self::parseCustomJson( $value );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return $value;
	}

	/**
	 * Read custom schema JSON.
	 *
	 * Empty input is valid and adds nothing. Any other shape is rejected.
	 *
	 * @return array{kind: string, properties?: array<string, bool|float|int|string>, nodes?: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function parseCustomJson( string $json ) {
		$json = trim( $json );
		if ( '' === $json || '[]' === $json ) {
			return array( 'kind' => 'empty' );
		}

		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'invalid_custom_schema',
				__( 'Custom schema JSON is too large.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$decoded = json_decode( $json, true, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::invalidCustomSchema();
		}

		if ( self::isNode( $decoded ) ) {
			return array(
				'kind'  => 'nodes',
				'nodes' => array( $decoded ),
			);
		}

		$graph = self::nodesFromGraph( $decoded );
		if ( is_array( $graph ) ) {
			if ( array() === $graph ) {
				return array( 'kind' => 'empty' );
			}
			return array(
				'kind'  => 'nodes',
				'nodes' => $graph,
			);
		}

		$properties = self::propertyMap( $decoded );
		if ( is_array( $properties ) ) {
			return array(
				'kind'       => 'properties',
				'properties' => $properties,
			);
		}

		if ( self::isListOfNodes( $decoded ) ) {
			return array(
				'kind'  => 'nodes',
				'nodes' => $decoded,
			);
		}

		return self::invalidCustomSchema();
	}

	/**
	 * A sequential list of Schema.org nodes, as opposed to one node.
	 *
	 * @param mixed $value Value returned by a schema generator.
	 */
	public static function isListOfNodes( $value ): bool {
		if ( ! is_array( $value ) || array() === $value || ! array_is_list( $value ) || count( $value ) > self::MAX_NODES ) {
			return false;
		}

		foreach ( $value as $node ) {
			if ( ! self::isNode( $node ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $value Candidate node.
	 */
	public static function isNode( $value ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}

		if ( ! isset( $value['@type'] ) || ! self::isTypeValue( $value['@type'] ) ) {
			return false;
		}

		if ( array_key_exists( '@context', $value ) && ! self::isAllowedContext( $value['@context'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param mixed $type Schema.org @type.
	 */
	public static function isSchemaType( $type ): bool {
		return is_string( $type ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9]{0,63}$/', $type );
	}

	/**
	 * @return \WP_Error
	 */
	private static function invalidCustomSchema() {
		return new \WP_Error(
			'invalid_custom_schema',
			__( 'Custom schema JSON must be a Schema.org object, a list of Schema.org objects, or custom properties with a key and a value.', 'holvek' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * @param array<mixed> $decoded Decoded JSON object.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function nodesFromGraph( array $decoded ): ?array {
		if ( array_is_list( $decoded ) || ! isset( $decoded['@graph'] ) || ! is_array( $decoded['@graph'] ) ) {
			return null;
		}

		if ( array_key_exists( '@context', $decoded ) && ! self::isAllowedContext( $decoded['@context'] ) ) {
			return null;
		}

		if ( array() === $decoded['@graph'] ) {
			return array();
		}

		if ( ! self::isListOfNodes( $decoded['@graph'] ) ) {
			return null;
		}

		return $decoded['@graph'];
	}

	/**
	 * @param array<mixed> $decoded Decoded JSON array.
	 * @return array<string, bool|float|int|string>|null
	 */
	private static function propertyMap( array $decoded ): ?array {
		if ( ! array_is_list( $decoded ) || array() === $decoded || count( $decoded ) > self::MAX_PROPERTIES ) {
			return null;
		}

		$map = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'], $item['value'] ) || ! is_string( $item['key'] ) ) {
				return null;
			}

			if ( ! self::isPropertyName( $item['key'] ) || ! self::isPropertyValue( $item['value'] ) ) {
				return null;
			}

			$map[ $item['key'] ] = $item['value'];
		}

		return $map;
	}

	/**
	 * @param mixed $value Property value.
	 */
	private static function isPropertyValue( $value ): bool {
		if ( is_string( $value ) ) {
			return strlen( $value ) <= self::MAX_VALUE;
		}

		return is_int( $value ) || is_float( $value ) || is_bool( $value );
	}

	private static function isPropertyName( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9]{0,63}$/', $name );
	}

	/**
	 * @param mixed $type @type value.
	 */
	private static function isTypeValue( $type ): bool {
		if ( self::isSchemaType( $type ) ) {
			return true;
		}

		if ( ! is_array( $type ) || ! array_is_list( $type ) || array() === $type || count( $type ) > 4 ) {
			return false;
		}

		foreach ( $type as $part ) {
			if ( ! self::isSchemaType( $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $context @context value.
	 */
	private static function isAllowedContext( $context ): bool {
		return is_string( $context ) && in_array( $context, array( 'https://schema.org', 'http://schema.org' ), true );
	}
}
