<?php

namespace Holvek\WordPress\Modules\ImageSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageFilenameEnforcer
 *
 * Intercepts image uploads and rewrites the filename to an SEO-friendly
 * slug (lowercase, hyphens, no generic prefixes). Existing attachment
 * files are not renamed, including during an upgrade.
 *
 * BL-004
 */
class ImageFilenameEnforcer {

	private const ENFORCE_OPTION = 'holvek_filename_enforce';
	private const PREFIX_OPTION  = 'holvek_filename_prefix';

	/** Generic prefixes that carry no SEO value */
	private const GENERIC_PREFIX_PATTERN = '/^(img|dsc|photo|pic|image|screenshot|capture|file|scan)([-_]?\d*)?[-_]?/i';

	public function register(): void {
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'enforceFilename' ) );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	// ── Upload filter ─────────────────────────────────────────────────────────

	public function enforceFilename( array $file ): array {
		if ( get_option( self::ENFORCE_OPTION, 'yes' ) !== 'yes' ) {
			return $file;
		}

		$info = pathinfo( $file['name'] );
		$ext  = strtolower( $info['extension'] ?? '' );
		if ( empty( $ext ) ) {
			return $file;
		}

		$newName = $this->cleanFilename( $info['filename'] );

		if ( empty( $newName ) ) {
			$newName = 'image';
		}

		$prefix = sanitize_text_field( get_option( self::PREFIX_OPTION, '' ) );
		if ( ! empty( $prefix ) ) {
			$newName = $prefix . '-' . $newName;
		}

		$file['name'] = $newName . '.' . $ext;

		return $file;
	}

	// ── Filename cleaner ──────────────────────────────────────────────────────

	public function cleanFilename( string $name ): string {
		// Transliterate accented characters to ASCII
		if ( function_exists( 'remove_accents' ) ) {
			$name = remove_accents( $name );
		} elseif ( function_exists( 'iconv' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv warns on invalid UTF-8. The false return is checked.
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name );
			if ( $converted !== false ) {
				$name = $converted;
			}
		}

		$name = mb_strtolower( $name );

		// Replace separators with hyphens
		$name = preg_replace( '/[\s_\.]+/', '-', $name ) ?? $name;

		// Remove anything that is not a-z, 0-9, or hyphen
		$name = preg_replace( '/[^a-z0-9\-]/', '', $name ) ?? $name;

		// Collapse multiple hyphens
		$name = preg_replace( '/-+/', '-', $name ) ?? $name;

		// Strip generic prefixes
		$name = preg_replace( self::GENERIC_PREFIX_PATTERN, '', $name ) ?? $name;

		// Strip WP size suffixes like -300x200
		$name = preg_replace( '/-\d+x\d+$/', '', $name ) ?? $name;

		return trim( $name, '-' );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/settings/filename-enforce',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveSettings' ),
					'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/images/bulk-rename',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulkRename' ),
				'permission_callback' => fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' ),
			)
		);
	}

	public function getSettings( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'enforce' => get_option( self::ENFORCE_OPTION, 'yes' ) === 'yes',
				'prefix'  => get_option( self::PREFIX_OPTION, '' ),
			)
		);
	}

	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();

		if ( isset( $params['enforce'] ) ) {
			update_option( self::ENFORCE_OPTION, $params['enforce'] ? 'yes' : 'no', true );
		}

		if ( isset( $params['prefix'] ) ) {
			// Prefix: alphanumeric + hyphens only, max 30 chars
			$prefix = preg_replace( '/[^a-z0-9\-]/', '', strtolower( sanitize_text_field( $params['prefix'] ) ) );
			update_option( self::PREFIX_OPTION, substr( (string) $prefix, 0, 30 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Existing media files stay as they are.
	 *
	 * Upload filtering is the only rename. An upgrade, or this endpoint,
	 * must not move files that are already in the library.
	 */
	public function bulkRename( \WP_REST_Request $request ): \WP_Error {
		unset( $request );

		return new \WP_Error(
			'existing_files_not_renamed',
			__( 'Filename enforcement renames new uploads only. Existing media files are left unchanged.', 'holvek' ),
			array( 'status' => 400 )
		);
	}
}
