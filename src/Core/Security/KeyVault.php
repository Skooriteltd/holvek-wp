<?php

namespace Holvek\WordPress\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets stored in wp_options with AES-256-CBC and an HMAC.
 *
 * The key comes only from the site-specific salt in the option
 * `holvek_encryption_salt`. There is no fallback key. Settings export omits
 * that option. A full database copy still includes the salt and the
 * ciphertext, so that copy can be decrypted. Encryption protects a leaked
 * settings export, not a stolen database.
 *
 * New ciphertext uses the prefix `holvek_enc::`. Decrypt still accepts the
 * previous prefix `aew_enc::`. Ciphertext written before a salt existed used
 * a fallback key and can no longer be decrypted.
 */
class KeyVault {

	private const CIPHER        = 'aes-256-cbc';
	private const PREFIX        = 'holvek_enc::';
	private const LEGACY_PREFIX = 'aew_enc::';
	private const SALT_OPTION   = 'holvek_encryption_salt';
	private const HMAC_LENGTH   = 32;

	/**
	 * Test seam. PHPUnit cannot unload the OpenSSL extension.
	 */
	private static bool $blockSaltCreation = false;

	/**
	 * Test seam. PHPUnit cannot unload the OpenSSL extension.
	 */
	private static bool $forceCipherUnavailable = false;

	/**
	 * Create the site salt when it is missing.
	 *
	 * An existing salt is left unchanged. An empty stored value is replaced.
	 * The option is not autoloaded.
	 */
	public static function ensureSaltExists(): bool {
		if ( self::saltValue() !== '' ) {
			return true;
		}

		if ( self::$blockSaltCreation ) {
			return false;
		}

		try {
			$salt = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return false;
		}

		// Missing options come back as the default. An empty string is stored.
		if ( false === get_option( self::SALT_OPTION, false ) ) {
			if ( add_option( self::SALT_OPTION, $salt, '', false ) ) {
				return true;
			}
		}

		if ( self::saltValue() !== '' ) {
			return true;
		}

		return update_option( self::SALT_OPTION, $salt, false );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * An empty string stays empty. A value that already uses the current or
	 * legacy prefix is returned unchanged. Any other failure returns
	 * WP_Error. Callers must not persist that error or the original plaintext.
	 *
	 * @return string|\WP_Error
	 */
	public static function encrypt( string $plaintext ): string|\WP_Error {
		if ( $plaintext === '' ) {
			return '';
		}

		if ( self::isCiphertext( $plaintext ) ) {
			return $plaintext;
		}

		if ( ! self::cipherAvailable() ) {
			return self::encryptionError(
				'holvek_cipher_unavailable',
				__( 'Holvek cannot store this secret because the PHP OpenSSL extension is unavailable. The value was not saved.', 'holvek' )
			);
		}

		if ( ! self::ensureSaltExists() ) {
			return self::encryptionError(
				'holvek_salt_missing',
				__( 'Holvek cannot store this secret because the encryption salt is missing. The value was not saved.', 'holvek' )
			);
		}

		$key = self::deriveMasterKey();
		if ( $key === '' ) {
			return self::encryptionError(
				'holvek_salt_missing',
				__( 'Holvek cannot store this secret because the encryption salt is missing. The value was not saved.', 'holvek' )
			);
		}

		$ivLength = openssl_cipher_iv_length( self::CIPHER );
		if ( ! is_int( $ivLength ) || $ivLength < 1 ) {
			return self::encryptionError(
				'holvek_encryption_failed',
				__( 'Holvek could not encrypt this secret. The value was not saved.', 'holvek' )
			);
		}

		try {
			$iv = random_bytes( $ivLength );
		} catch ( \Throwable ) {
			return self::encryptionError(
				'holvek_encryption_failed',
				__( 'Holvek could not encrypt this secret. The value was not saved.', 'holvek' )
			);
		}

		$cipherRaw = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( ! is_string( $cipherRaw ) || $cipherRaw === '' ) {
			return self::encryptionError(
				'holvek_encryption_failed',
				__( 'Holvek could not encrypt this secret. The value was not saved.', 'holvek' )
			);
		}

		$hmac = hash_hmac( 'sha256', $cipherRaw, $key, true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- AES payload encoding, not obfuscation.
		return self::PREFIX . base64_encode( $iv . $hmac . $cipherRaw );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * Values without a Holvek prefix are returned unchanged so older plaintext
	 * keys keep working. Ciphertext cannot be decrypted without the salt and
	 * OpenSSL, and then the result is an empty string.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( $encrypted === '' ) {
			return '';
		}

		$prefix = self::matchedPrefix( $encrypted );
		if ( $prefix === '' ) {
			return $encrypted;
		}

		if ( ! self::cipherAvailable() ) {
			return '';
		}

		$key = self::deriveMasterKey();
		if ( $key === '' ) {
			return '';
		}

		$ivLength = openssl_cipher_iv_length( self::CIPHER );
		if ( ! is_int( $ivLength ) || $ivLength < 1 ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- AES payload encoding, not obfuscation.
		$decoded = base64_decode( substr( $encrypted, strlen( $prefix ) ), true );
		if ( ! is_string( $decoded ) || strlen( $decoded ) < $ivLength + self::HMAC_LENGTH ) {
			return '';
		}

		$iv        = substr( $decoded, 0, $ivLength );
		$hmac      = substr( $decoded, $ivLength, self::HMAC_LENGTH );
		$cipherRaw = substr( $decoded, $ivLength + self::HMAC_LENGTH );
		$expected  = hash_hmac( 'sha256', $cipherRaw, $key, true );

		if ( ! hash_equals( $expected, $hmac ) ) {
			return '';
		}

		$plaintext = openssl_decrypt( $cipherRaw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Encrypt known secret fields before they are stored.
	 *
	 * A field that cannot be encrypted is removed so the caller does not
	 * persist the plaintext.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function encryptSettings( array $settings ): array {
		foreach ( self::sensitiveKeys() as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || $settings[ $key ] === '' ) {
				continue;
			}

			$encrypted = self::encrypt( $settings[ $key ] );
			if ( is_wp_error( $encrypted ) || ! self::isCiphertext( $encrypted ) ) {
				unset( $settings[ $key ] );
				continue;
			}

			$settings[ $key ] = $encrypted;
		}

		return $settings;
	}

	/**
	 * Decrypt known secret fields after reading them.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function decryptSettings( array $settings ): array {
		foreach ( self::sensitiveKeys() as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || $settings[ $key ] === '' ) {
				continue;
			}

			$settings[ $key ] = self::decrypt( $settings[ $key ] );
		}

		return $settings;
	}

	/**
	 * Option names that must not appear in a settings export or import.
	 *
	 * The salt is in the same table as the ciphertext. Exporting it would
	 * make the exported file decryptable. A full database copy still is.
	 *
	 * @return array<int, string>
	 */
	public static function neverExportOptions(): array {
		return array(
			self::SALT_OPTION,
		);
	}

	/**
	 * Whether a value is already Holvek ciphertext.
	 */
	public static function isCiphertext( string $value ): bool {
		return self::matchedPrefix( $value ) !== '';
	}

	/**
	 * Show one admin notice when secrets cannot be encrypted.
	 */
	public static function registerAdminNotice(): void {
		add_action( 'admin_notices', array( self::class, 'renderMissingCipherNotice' ) );
		add_action( 'wp_ajax_holvek_dismiss_cipher_notice', array( self::class, 'dismissMissingCipherNotice' ) );
	}

	/**
	 * Tell administrators that secret storage is off until OpenSSL is available.
	 *
	 * The notice is limited to Holvek screens when the current screen is known.
	 */
	public static function renderMissingCipherNotice(): void {
		if ( self::cipherAvailable() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'holvek_cipher_notice_dismissed', true ) ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || ! isset( $screen->id ) || ! str_contains( (string) $screen->id, 'holvek' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'holvek_dismiss_cipher_notice' );
		printf(
			'<div class="notice notice-error is-dismissible holvek-cipher-notice" data-nonce="%1$s"><p>%2$s</p></div>',
			esc_attr( $nonce ),
			esc_html(
				__( 'Holvek cannot encrypt API keys or access tokens because the PHP OpenSSL extension is missing. Saving those secrets is turned off until OpenSSL is enabled. The rest of the plugin keeps working.', 'holvek' )
			)
		);
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var el=document.querySelector(".holvek-cipher-notice");if(!el){return;}el.addEventListener("click",function(e){if(!e.target.classList.contains("notice-dismiss")){return;}var body=new FormData();body.append("action","holvek_dismiss_cipher_notice");body.append("_ajax_nonce",el.dataset.nonce);fetch(window.ajaxurl,{method:"POST",body:body});});});</script>';
	}

	/**
	 * Remember that this administrator dismissed the OpenSSL notice.
	 */
	public static function dismissMissingCipherNotice(): void {
		check_ajax_referer( 'holvek_dismiss_cipher_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sorry, you are not allowed to do that.', 'holvek' ) ),
				403
			);
		}
		update_user_meta( get_current_user_id(), 'holvek_cipher_notice_dismissed', '1' );
		wp_send_json_success();
	}

	/**
	 * Force the failure paths that PHPUnit cannot reach by unloading OpenSSL.
	 *
	 * Production code must not call this.
	 */
	public static function setTestConstraints( bool $blockSaltCreation = false, bool $forceCipherUnavailable = false ): void {
		self::$blockSaltCreation      = $blockSaltCreation;
		self::$forceCipherUnavailable = $forceCipherUnavailable;
	}

	/**
	 * @return array<int, string>
	 */
	private static function sensitiveKeys(): array {
		return array(
			'holvek_api_key',
			'openai_api_key',
			'anthropic_api_key',
			'gsc_api_key',
			'bing_api_key',
			'holvek_google_indexing_key',
			'holvek_ga4_credentials',
			'google_indexing_key',
			'ga4_credentials',
			'holvek_license_key',
		);
	}

	private static function saltValue(): string {
		$salt = get_option( self::SALT_OPTION, '' );
		return is_string( $salt ) ? $salt : '';
	}

	private static function deriveMasterKey(): string {
		$salt = self::saltValue();
		if ( $salt === '' ) {
			return '';
		}

		return hash( 'sha256', $salt, true );
	}

	private static function matchedPrefix( string $value ): string {
		if ( str_starts_with( $value, self::PREFIX ) ) {
			return self::PREFIX;
		}

		if ( str_starts_with( $value, self::LEGACY_PREFIX ) ) {
			return self::LEGACY_PREFIX;
		}

		return '';
	}

	private static function cipherAvailable(): bool {
		if ( self::$forceCipherUnavailable ) {
			return false;
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return false;
		}

		return in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	private static function encryptionError( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 500 ) );
	}
}
