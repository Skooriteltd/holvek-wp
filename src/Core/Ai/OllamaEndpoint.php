<?php

namespace Holvek\WordPress\Core\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the Ollama base URL.
 *
 * The default is a loopback address. A remote host is stored only after the
 * administrator confirms it, because that host receives post content.
 */
class OllamaEndpoint {

	public const DEFAULT_URL = 'http://localhost:11434';
	public const URL_OPTION  = 'holvek_ollama_url';
	public const ACK_OPTION  = 'holvek_ollama_remote_ack';

	public static function displayUrl(): string {
		$stored = get_option( self::URL_OPTION, self::DEFAULT_URL );
		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			return self::DEFAULT_URL;
		}
		return trim( $stored );
	}

	public static function remoteIsConfirmed( string $url ): bool {
		$normalized = self::normalize( $url );
		if ( null === $normalized || self::isLoopbackUrl( $normalized ) ) {
			return false;
		}
		return get_option( self::ACK_OPTION, 'no' ) === $normalized;
	}

	/**
	 * URL that may receive a prompt, or null when the stored value is not allowed.
	 */
	public static function requestUrl(): ?string {
		$normalized = self::normalize( self::displayUrl() );
		if ( null === $normalized || self::isRejectedHost( $normalized ) ) {
			return null;
		}
		if ( self::isLoopbackUrl( $normalized ) ) {
			return $normalized;
		}
		if ( get_option( self::ACK_OPTION, 'no' ) !== $normalized ) {
			return null;
		}
		return $normalized;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function save( string $url, bool $remoteConfirmed ): bool|\WP_Error {
		$normalized = self::normalize( $url );
		if ( null === $normalized || self::isRejectedHost( $normalized ) ) {
			return new \WP_Error(
				'invalid_ollama_url',
				__( 'Enter an http or https Ollama address, such as http://localhost:11434.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$loopback = self::isLoopbackUrl( $normalized );
		if ( ! $loopback && ! $remoteConfirmed ) {
			return new \WP_Error(
				'ollama_remote_unconfirmed',
				__( 'A remote Ollama host receives the post content you send for suggestions. Confirm that before saving.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		update_option( self::URL_OPTION, $normalized );
		update_option( self::ACK_OPTION, $loopback ? 'no' : $normalized );
		return true;
	}

	/**
	 * Settings import may restore a loopback address only.
	 */
	public static function import( string $url ): bool {
		$normalized = self::normalize( $url );
		if ( null === $normalized || ! self::isLoopbackUrl( $normalized ) ) {
			return false;
		}

		update_option( self::URL_OPTION, $normalized );
		update_option( self::ACK_OPTION, 'no' );
		return true;
	}

	public static function normalize( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url || str_contains( $url, '\\' ) || preg_match( '/[[:cntrl:][:space:]]/', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( ! self::isAcceptableHost( $host ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' !== $path && '/' !== $path ) {
			return null;
		}

		$port = '';
		if ( isset( $parts['port'] ) ) {
			$portNumber = (int) $parts['port'];
			if ( $portNumber < 1 || $portNumber > 65535 ) {
				return null;
			}
			$port = ':' . $portNumber;
		}

		$displayHost = str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
		return $scheme . '://' . $displayHost . $port;
	}

	public static function isLoopbackUrl( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		return self::isLoopbackHost( (string) $parts['host'] );
	}

	private static function isLoopbackHost( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}

		$host = self::unwrapMappedIpv4( $host );
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( '::1' === $host ) {
			return true;
		}
		if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$parts = array_map( 'intval', explode( '.', $host ) );
		return 4 === count( $parts ) && 127 === $parts[0];
	}

	/**
	 * Link-local, unspecified, and metadata hosts are not Ollama targets.
	 */
	private static function isRejectedHost( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return true;
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( 'metadata.google.internal' === $host ) {
			return true;
		}

		$ip = self::unwrapMappedIpv4( trim( $host, '[]' ) );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( self::isLoopbackHost( $ip ) ) {
			return false;
		}

		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE );
	}

	private static function unwrapMappedIpv4( string $ip ): string {
		$ip = strtolower( $ip );
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$mapped = substr( $ip, 7 );
			if ( filter_var( $mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $mapped;
			}
		}
		return $ip;
	}

	private static function isAcceptableHost( string $host ): bool {
		if ( '' === $host || str_contains( $host, '%' ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		return 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host );
	}
}
