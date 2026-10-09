<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

/**
 * Optional Apache .htaccess editor.
 *
 * The editor stays off until an administrator enables it. Redirects and
 * robots.txt remain on their own WordPress APIs. Backups are stored in a
 * non-autoloaded option so the web server cannot serve them.
 */
class HtaccessEditor {

	private const BACKUP_LIMIT   = 20;
	private const MAX_BYTES      = 524288;
	private const ENABLED_OPTION = 'holvek_htaccess_editor_enabled';
	private const BACKUPS_OPTION = 'holvek_htaccess_backups';
	private const RETIRED_OPTION = 'holvek_htaccess_web_backups_retired';

	protected function htaccessPath(): string {
		return ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . '.htaccess';
	}

	/**
	 * Old public backup directory. Reads from here are only for one-time removal.
	 */
	protected function legacyBackupDir(): string {
		return ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/holvek-backups/htaccess/';
	}

	/**
	 * DISALLOW_FILE_EDIT is a hard stop. There is no separate capability that bypasses it.
	 */
	protected function fileEditingDisallowed(): bool {
		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'init', array( $this, 'retirePublicBackupsOnce' ) );
	}

	/**
	 * Remove legacy web-readable backups once. The flag is autoloaded so later requests do no filesystem work.
	 */
	public function retirePublicBackupsOnce(): void {
		if ( get_option( self::RETIRED_OPTION, 'no' ) === 'yes' ) {
			return;
		}
		$this->retireWebBackups();
	}

	public function registerRoutes(): void {
		$permission = array( $this, 'checkPermission' );

		register_rest_route(
			'holvek/v1',
			'/htaccess',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'readHtaccessEndpoint' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveHtaccessEndpoint' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			'holvek/v1',
			'/htaccess/access',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'updateAccessEndpoint' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			'holvek/v1',
			'/htaccess/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restoreEndpoint' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Administrators only. manage_seo is not sufficient.
	 */
	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function readHtaccessEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		return rest_ensure_response( $this->statusPayload( true ) );
	}

	public function updateAccessEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->accessError( false );
		if ( $denied ) {
			return $denied;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! array_key_exists( 'enabled', $params ) ) {
			return new \WP_Error(
				'invalid_enabled',
				__( 'Say whether the .htaccess editor should be on or off.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$enabled = $this->requestedEnabled( $params['enabled'] );
		if ( null === $enabled ) {
			return new \WP_Error(
				'invalid_enabled',
				__( 'Say whether the .htaccess editor should be on or off.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		update_option( self::ENABLED_OPTION, $enabled ? 'yes' : 'no', false );

		return rest_ensure_response( $this->statusPayload( true ) );
	}

	public function saveHtaccessEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->accessError( true );
		if ( $denied ) {
			return $denied;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! array_key_exists( 'content', $params ) || ! is_string( $params['content'] ) ) {
			return new \WP_Error(
				'invalid_content',
				__( 'Content must be a string.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$content = $params['content'];
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'too_large',
				__( 'That .htaccess file is too large to edit here.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $this->unsafeDirective( $content ) ) {
			return $this->unsafeContentError();
		}

		if ( ! $this->saveHtaccess( $content ) ) {
			return new \WP_Error(
				'save_failed',
				__( 'The .htaccess file could not be written. Check file permissions.', 'holvek' ),
				array( 'status' => 500 )
			);
		}

		$payload            = $this->statusPayload( true );
		$payload['success'] = true;
		$payload['notice']  = __( 'The .htaccess file was saved.', 'holvek' );
		return rest_ensure_response( $payload );
	}

	public function restoreEndpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$denied = $this->accessError( true );
		if ( $denied ) {
			return $denied;
		}

		$params   = $request->get_json_params();
		$filename = sanitize_file_name( is_array( $params ) ? (string) ( $params['filename'] ?? '' ) : '' );
		if ( '' === $filename ) {
			return new \WP_Error(
				'missing_filename',
				__( 'A backup filename is required.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isBackupFilename( $filename ) ) {
			return new \WP_Error(
				'invalid_filename',
				__( 'That backup filename is not valid.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$content = $this->backupContent( $filename );
		if ( null === $content ) {
			return new \WP_Error(
				'restore_failed',
				__( 'That backup was not found.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $this->unsafeDirective( $content ) ) {
			return $this->unsafeContentError();
		}

		if ( ! $this->saveHtaccess( $content ) ) {
			return new \WP_Error(
				'restore_failed',
				__( 'The backup could not be restored.', 'holvek' ),
				array( 'status' => 500 )
			);
		}

		$payload            = $this->statusPayload( true );
		$payload['success'] = true;
		$payload['notice']  = __( 'The backup was restored.', 'holvek' );
		return rest_ensure_response( $payload );
	}

	public function readHtaccess(): string {
		$path = $this->htaccessPath();
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		return $this->readLocalFile( $path );
	}

	public function saveHtaccess( string $content ): bool {
		if ( ! $this->checkPermission() || $this->fileEditingDisallowed() || ! $this->isFeatureEnabled() ) {
			return false;
		}

		if ( null !== $this->unsafeDirective( $content ) ) {
			return false;
		}

		$this->retireWebBackups();

		$path = $this->htaccessPath();
		if ( is_file( $path ) && is_readable( $path ) ) {
			$existing = $this->readHtaccess();
			$this->rememberBackup( $this->newBackupId(), $existing );
		}

		return $this->writeLocalFile( $path, $content );
	}

	public function restoreHtaccess( string $backupFilename ): bool {
		$backupFilename = basename( $backupFilename );
		if ( ! $this->isBackupFilename( $backupFilename ) ) {
			return false;
		}

		$content = $this->backupContent( $backupFilename );
		if ( null === $content || null !== $this->unsafeDirective( $content ) ) {
			return false;
		}

		return $this->saveHtaccess( $content );
	}

	/**
	 * @return array<int, array{filename: string, created_at: string, size: int}>
	 */
	public function listBackups(): array {
		$listed = array();
		foreach ( $this->storedBackups() as $backup ) {
			$listed[] = array(
				'filename'   => $backup['filename'],
				'created_at' => $backup['created_at'],
				'size'       => strlen( $backup['content'] ),
			);
		}

		return $listed;
	}

	/**
	 * Basic structural validation. Warnings do not block a save.
	 *
	 * @return array<int, string>
	 */
	public function validateSyntax( string $content ): array {
		$warnings    = array();
		$lines       = explode( "\n", $content );
		$openTags    = array();
		$closingTags = array();

		foreach ( $lines as $lineNum => $line ) {
			$trimmed = trim( $line );

			if ( preg_match( '/^<(IfModule|Directory|Files|Location|VirtualHost)[^>]*>/i', $trimmed, $matches ) ) {
				$openTags[] = array(
					'tag'  => $matches[1],
					'line' => $lineNum + 1,
				);
			}
			if ( preg_match( '/^<\/(IfModule|Directory|Files|Location|VirtualHost)>/i', $trimmed, $matches ) ) {
				$closingTags[] = array(
					'tag'  => $matches[1],
					'line' => $lineNum + 1,
				);
			}

			if ( stripos( $trimmed, 'Deny from all' ) !== false && ! str_starts_with( $trimmed, '#' ) ) {
				$warnings[] = sprintf(
					/* translators: %d: line number in the .htaccess file. */
					__( 'Line %d: \'Deny from all\' detected. Confirm this block is intentional.', 'holvek' ),
					$lineNum + 1
				);
			}
		}

		if ( count( $openTags ) !== count( $closingTags ) ) {
			$warnings[] = sprintf(
				/* translators: 1: number of opening tags, 2: number of closing tags. */
				__( 'Unmatched directive tags detected (open: %1$d, close: %2$d). Review block nesting.', 'holvek' ),
				count( $openTags ),
				count( $closingTags )
			);
		}

		return $warnings;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function statusPayload( bool $includeFile ): array {
		$locked  = $this->fileEditingDisallowed();
		$enabled = $this->isFeatureEnabled() && ! $locked;
		$payload = array(
			'enabled'  => $enabled,
			'locked'   => $locked,
			'success'  => $enabled,
			'message'  => $this->statusMessage( $locked, $enabled ),
			'content'  => '',
			'readable' => false,
			'writable' => false,
			'warnings' => array(),
			'backups'  => array(),
		);

		if ( $this->checkPermission() ) {
			$this->retireWebBackups();
		}

		if ( ! $includeFile || ! $enabled ) {
			return $payload;
		}

		$path                = $this->htaccessPath();
		$content             = $this->readHtaccess();
		$directory           = dirname( $path );
		$payload['content']  = $content;
		$payload['readable'] = is_file( $path ) && is_readable( $path );
		$payload['writable'] = ( is_file( $path ) && $this->pathIsWritable( $path ) ) || ( ! is_file( $path ) && is_dir( $directory ) && $this->pathIsWritable( $directory ) );
		$payload['warnings'] = $this->validateSyntax( $content );
		$payload['backups']  = $this->listBackups();

		return $payload;
	}

	private function statusMessage( bool $locked, bool $enabled ): string {
		if ( $locked ) {
			return __( 'WordPress file editing is disabled on this site, so the .htaccess editor cannot run.', 'holvek' );
		}

		if ( ! $enabled ) {
			return __( 'The .htaccess editor is off. Turn it on only for Apache rules that redirects and robots.txt cannot handle.', 'holvek' );
		}

		return __( 'This file applies to Apache. Nginx does not read .htaccess. Use Redirects and robots.txt for rules that should work on every server. A private backup is kept before each save.', 'holvek' );
	}

	private function permissionError(): ?\WP_Error {
		if ( $this->checkPermission() ) {
			return null;
		}

		return new \WP_Error(
			'forbidden',
			__( 'You do not have permission to edit the server configuration.', 'holvek' ),
			array( 'status' => 403 )
		);
	}

	private function accessError( bool $requireEnabled ): ?\WP_Error {
		$denied = $this->permissionError();
		if ( $denied ) {
			return $denied;
		}

		if ( $this->fileEditingDisallowed() ) {
			return new \WP_Error(
				'file_editing_disabled',
				__( 'WordPress file editing is disabled on this site, so the .htaccess editor cannot run.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		if ( $requireEnabled && ! $this->isFeatureEnabled() ) {
			return new \WP_Error(
				'feature_disabled',
				__( 'The .htaccess editor is off. Turn it on only for Apache rules that redirects and robots.txt cannot handle.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	private function unsafeContentError(): \WP_Error {
		return new \WP_Error(
			'unsafe_content',
			__( 'Save rejected: the .htaccess content contains a directive that can execute code.', 'holvek' ),
			array( 'status' => 422 )
		);
	}

	private function isFeatureEnabled(): bool {
		return get_option( self::ENABLED_OPTION, 'no' ) === 'yes';
	}

	/**
	 * @param mixed $value
	 */
	private function requestedEnabled( $value ): ?bool {
		if ( true === $value || 1 === $value || '1' === $value || 'yes' === $value ) {
			return true;
		}
		if ( false === $value || 0 === $value || '0' === $value || 'no' === $value ) {
			return false;
		}
		return null;
	}

	private function initFilesystem(): bool {
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) ) {
			return true;
		}

		$file = ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'wp-admin/includes/file.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}

		require_once $file;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return false;
		}

		WP_Filesystem();
		return ! empty( $wp_filesystem );
	}

	private function newBackupId(): string {
		return 'htaccess-' . gmdate( 'Y-m-d-His' ) . '.txt';
	}

	private function isBackupFilename( string $filename ): bool {
		return 1 === preg_match( '/^htaccess-\d{4}-\d{2}-\d{2}-\d{6}\.txt$/', $filename );
	}

	private function rememberBackup( string $filename, string $content ): void {
		if ( ! $this->isBackupFilename( $filename ) ) {
			return;
		}

		$backups   = array();
		$backups[] = array(
			'filename'   => $filename,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'size'       => strlen( $content ),
			'content'    => $content,
		);

		foreach ( $this->storedBackups() as $backup ) {
			if ( $backup['filename'] === $filename ) {
				continue;
			}
			$backups[] = $backup;
		}

		update_option( self::BACKUPS_OPTION, array_slice( $backups, 0, self::BACKUP_LIMIT ), false );
	}

	/**
	 * @return array<int, array{filename: string, created_at: string, size: int, content: string}>
	 */
	private function storedBackups(): array {
		$stored = get_option( self::BACKUPS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();
		foreach ( $stored as $backup ) {
			if ( ! is_array( $backup ) || ! is_string( $backup['filename'] ?? null ) || ! is_string( $backup['content'] ?? null ) ) {
				continue;
			}
			if ( ! $this->isBackupFilename( $backup['filename'] ) ) {
				continue;
			}
			$clean[] = array(
				'filename'   => $backup['filename'],
				'created_at' => is_string( $backup['created_at'] ?? null ) ? $backup['created_at'] : '',
				'size'       => strlen( $backup['content'] ),
				'content'    => $backup['content'],
			);
		}

		return $clean;
	}

	private function backupContent( string $filename ): ?string {
		foreach ( $this->storedBackups() as $backup ) {
			if ( $backup['filename'] === $filename ) {
				return $backup['content'];
			}
		}
		return null;
	}

	/**
	 * Move any old web-readable backups into the private option, then delete them.
	 */
	private function retireWebBackups(): void {
		if ( get_option( self::RETIRED_OPTION, 'no' ) === 'yes' ) {
			return;
		}

		$dir = $this->legacyBackupDir();
		if ( ! is_dir( $dir ) ) {
			update_option( self::RETIRED_OPTION, 'yes', true );
			return;
		}

		$files = glob( $dir . 'htaccess-*.txt' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$name = basename( (string) $file );
				if ( ! $this->isBackupFilename( $name ) || ! is_file( $file ) ) {
					continue;
				}
				$content = $this->readLocalFile( $file );
				if ( '' !== $content && null === $this->unsafeDirective( $content ) ) {
					$this->rememberBackup( $name, $content );
				}
				wp_delete_file( $file );
			}
		}

		$index = $dir . 'index.php';
		if ( is_file( $index ) && $this->isSilenceFile( $this->readLocalFile( $index ) ) ) {
			wp_delete_file( $index );
		}

		$remainingBackups = glob( $dir . 'htaccess-*.txt' );
		if ( is_array( $remainingBackups ) && array() !== $remainingBackups ) {
			$this->writeLegacyDenyRule( $dir );
			return;
		}

		$unknown = $this->unexpectedLegacyFiles( $dir );
		if ( array() === $unknown ) {
			$deny = $dir . '.htaccess';
			if ( is_file( $deny ) ) {
				wp_delete_file( $deny );
			}
			$this->removeEmptyDirectory( $dir );
			$parent = dirname( rtrim( $dir, '/\\' ) );
			if ( is_dir( $parent ) && array() === $this->directoryEntries( $parent ) ) {
				$this->removeEmptyDirectory( $parent );
			}
		} else {
			$this->writeLegacyDenyRule( $dir );
		}

		update_option( self::RETIRED_OPTION, 'yes', true );
	}

	private function isSilenceFile( string $body ): bool {
		$trimmed = trim( $body );
		return in_array(
			$trimmed,
			array(
				'<?php // Silence is golden',
				'<?php // Silence is golden.',
				"<?php\n// Silence is golden.",
				"<?php\r\n// Silence is golden.",
			),
			true
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function unexpectedLegacyFiles( string $dir ): array {
		$unknown = array();
		foreach ( $this->directoryEntries( $dir ) as $entry ) {
			if ( '.htaccess' === $entry || $this->isBackupFilename( $entry ) ) {
				continue;
			}
			$unknown[] = $entry;
		}
		return $unknown;
	}

	/**
	 * @return array<int, string>
	 */
	private function directoryEntries( string $dir ): array {
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_values( array_diff( $entries, array( '.', '..' ) ) );
	}

	private function writeLegacyDenyRule( string $dir ): void {
		$rule = $dir . '.htaccess';
		if ( is_file( $rule ) ) {
			return;
		}
		$this->writeLocalFile( $rule, "Require all denied\nDeny from all\n" );
	}

	private function readLocalFile( string $path ): string {
		if ( $this->initFilesystem() ) {
			global $wp_filesystem;
			$contents = $wp_filesystem->get_contents( $path );
			if ( false !== $contents ) {
				return (string) $contents;
			}
		}

		// Local file. wp_remote_get() is for HTTP, and WP_Filesystem is unavailable here.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		return false === $contents ? '' : (string) $contents;
	}

	private function writeLocalFile( string $path, string $content ): bool {
		if ( $this->initFilesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem->put_contents( $path, $content ) ) {
				return true;
			}
		}

		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! $this->pathIsWritable( $directory ) ) {
			return false;
		}

		// Local file write when the WordPress filesystem API cannot be initialized.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $content );
	}

	private function pathIsWritable( string $path ): bool {
		if ( $this->initFilesystem() ) {
			global $wp_filesystem;
			return (bool) $wp_filesystem->is_writable( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is unavailable.
		return is_writable( $path );
	}

	private function removeEmptyDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		if ( $this->initFilesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->rmdir( $dir, false );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is unavailable.
		rmdir( $dir );
	}

	/**
	 * @return string|null Short reason code, or null when the content is allowed.
	 */
	private function unsafeDirective( string $content ): ?string {
		if ( str_contains( $content, "\0" ) ) {
			return 'null_byte';
		}

		foreach ( $this->directiveLines( $content ) as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
				continue;
			}
			$code   = $this->stripInlineComment( $trimmed );
			$reason = $this->matchUnsafe( $code );
			if ( null !== $reason ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * @return array<int, string>
	 */
	private function directiveLines( string $content ): array {
		$raw    = preg_split( "/\r\n|\n|\r/", $content );
		$raw    = is_array( $raw ) ? $raw : array();
		$lines  = array();
		$buffer = '';

		foreach ( $raw as $rawLine ) {
			$piece = rtrim( (string) $rawLine, "\r" );
			if ( str_ends_with( rtrim( $piece ), '\\' ) ) {
				$buffer .= substr( rtrim( $piece ), 0, -1 ) . ' ';
				continue;
			}
			$lines[] = $buffer . $piece;
			$buffer  = '';
		}

		if ( '' !== $buffer ) {
			$lines[] = $buffer;
		}

		return $lines;
	}

	private function stripInlineComment( string $line ): string {
		$length = strlen( $line );
		$quote  = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $line[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}
			if ( '#' === $char ) {
				return trim( substr( $line, 0, $i ) );
			}
		}
		return $line;
	}

	private function matchUnsafe( string $code ): ?string {
		if ( preg_match( '/\b(?:php_admin_value|php_admin_flag|php_flag)\b/i', $code ) ) {
			return 'php_engine';
		}

		if ( preg_match( '/\b(?:auto_prepend_file|auto_append_file)\b/i', $code ) ) {
			return 'auto_prepend';
		}

		if ( preg_match( '/\bphp_value\s+(?:disable_functions|allow_url_include|open_basedir|include_path|doc_root|user_ini\.filename|mail\.force_extra_parameters)\b/i', $code ) ) {
			return 'php_value';
		}

		if ( preg_match( '/\bSetHandler\b/i', $code ) && ! preg_match( '/\bSetHandler\s+(?:default-handler|none)\b/i', $code ) ) {
			return 'sethandler';
		}

		if ( preg_match( '/\b(?:AddHandler|AddType|AddOutputFilter|AddInputFilter|AddOutputFilterByType)\b/i', $code ) ) {
			if ( preg_match( '/php|cgi|fastcgi|fcgid|fpm|proxy|lsapi|perl|httpd-php|cgi-script|\bINCLUDES\b/i', $code ) ) {
				return 'handler';
			}
		}

		if ( preg_match( '/\bOptions\b/i', $code ) && preg_match( '/(?:^|\s)\+?ExecCGI\b/i', $code ) && ! preg_match( '/(?:^|\s)-ExecCGI\b/i', $code ) ) {
			return 'execcgi';
		}

		if ( preg_match( '/\bOptions\b/i', $code ) && preg_match( '/(?:^|[\s+])Includes\b/i', $code ) && ! preg_match( '/-Includes\b/i', $code ) ) {
			return 'ssi';
		}

		if ( preg_match( '/\b(?:ScriptAlias(?:Match)?|Action|FcgidWrapper|FastCgiExternalServer|ProxyPass|SuexecUserGroup)\b/i', $code ) ) {
			return 'cgi_wrapper';
		}

		if ( preg_match( '/\bRewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $code, $matches ) ) {
			$target = $matches[2];
			$flags  = $matches[3] ?? '';
			if ( $this->flagsIncludeProxy( $flags ) || $this->rewriteTargetExecutesCode( $target ) ) {
				return 'rewrite_exec';
			}
		}

		return null;
	}

	private function flagsIncludeProxy( string $flags ): bool {
		if ( '' === $flags ) {
			return false;
		}
		foreach ( explode( ',', $flags ) as $flag ) {
			$name = strtoupper( trim( explode( '=', $flag, 2 )[0] ) );
			if ( 'P' === $name ) {
				return true;
			}
		}
		return false;
	}

	private function rewriteTargetExecutesCode( string $target ): bool {
		if ( preg_match( '#cgi-bin|proxy:|fcgi://|cgi://|unix:#i', $target ) ) {
			return true;
		}
		if ( preg_match( '/\.(?:php\d*|phtml|phar|cgi|pl|py|rb|sh|exe|bat)(?:$|\?)/i', $target ) ) {
			return ! preg_match( '#(?:^|/)index\.php(?:$|\?)#i', $target );
		}
		return false;
	}
}
