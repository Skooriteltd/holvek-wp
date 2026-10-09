<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\TechnicalSeo\HtaccessEditor;
use PHPUnit\Framework\TestCase;

class HtaccessEditorTest extends TestCase {

	private HtaccessEditor $editor;

	private string $sandbox = '';

	protected function setUp(): void {
		parent::setUp();

		$this->sandbox = sys_get_temp_dir() . '/holvek-htaccess-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sandbox, 0700, true );
		$this->editor = $this->makeEditor( false );

		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => false,
			'manage_seo'     => true,
			'edit_posts'     => true,
		);
		update_option( 'holvek_htaccess_editor_enabled', 'no' );
		update_option( 'holvek_htaccess_backups', array() );
		update_option( 'holvek_htaccess_web_backups_retired', 'yes' );
	}

	protected function tearDown(): void {
		$this->removeTree( $this->sandbox );
		parent::tearDown();
	}

	public function testValidateSyntaxCleanContent(): void {
		$content = <<<HTACCESS
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS;

		$warnings = $this->editor->validateSyntax( $content );
		$this->assertEmpty( $warnings );
	}

	public function testValidateSyntaxDetectsUnmatchedTags(): void {
		$content = <<<HTACCESS
<IfModule mod_rewrite.c>
RewriteEngine On
# Missing closing tag
HTACCESS;

		$warnings = $this->editor->validateSyntax( $content );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'Unmatched directive tags detected', $warnings[0] );
	}

	public function testValidateSyntaxFlagsDenyFromAll(): void {
		$content = <<<HTACCESS
<Files "wp-config.php">
Deny from all
</Files>
HTACCESS;

		$warnings = $this->editor->validateSyntax( $content );
		$this->assertNotEmpty( $warnings );
		$found = false;
		foreach ( $warnings as $warning ) {
			if ( str_contains( $warning, "'Deny from all' detected" ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	public function testSaveEndpointRejectsDangerousDirectives(): void {
		$this->grantAdmin();
		$this->enableEditor();

		$dangerousPayloads = array(
			'AddHandler application/x-httpd-php .txt'   => 'AddHandler php execution',
			'AddType application/x-httpd-php .jpg'      => 'AddType php execution',
			'php_value disable_functions none'          => 'php_value disable_functions override',
			'php_value auto_prepend_file /malicious/file.php' => 'auto_prepend_file execution',
			'php_value auto_append_file /malicious/shell.php' => 'auto_append_file execution',
			'SetHandler application/x-httpd-php'        => 'SetHandler php',
			'php_flag engine on'                        => 'php_flag',
			"php_flag \\\nengine on"                    => 'continued php_flag',
			'php_admin_value open_basedir none'         => 'php_admin_value',
			'Options +ExecCGI'                          => 'ExecCGI',
			'AddHandler cgi-script .html'               => 'cgi-script handler',
			'RewriteRule .* /cgi-bin/php.cgi [L]'       => 'cgi-bin rewrite',
			'RewriteRule \.txt$ shell.php [L]'          => 'rewrite to php',
			'RewriteRule (.*) http://evil.example/$1 [P]' => 'proxy rewrite',
			'RewriteRule .* /wp-content/uploads/shell.phtml [L]' => 'phtml rewrite',
			'ScriptAlias /cgi-bin/ /var/www/cgi-bin/'   => 'ScriptAlias',
			'Action application/x-httpd-php /cgi-bin/php-cgi' => 'Action cgi wrapper',
		);

		foreach ( $dangerousPayloads as $payload => $description ) {
			$request = new \WP_REST_Request( 'POST', '/holvek/v1/htaccess' );
			$request->set_json_params( array( 'content' => $payload ) );

			$result = $this->editor->saveHtaccessEndpoint( $request );
			$this->assertInstanceOf( \WP_Error::class, $result, "Expected [{$description}] to be rejected." );
			$this->assertSame( 'unsafe_content', $result->get_error_code(), $description );
		}

		$this->assertFileDoesNotExist( $this->sandbox . '/.htaccess' );
	}

	public function testHardeningRulesAndWordPressFrontControllerAreAllowed(): void {
		$this->grantAdmin();
		$this->enableEditor();

		$allowed = array(
			"# php_flag engine on\nRewriteEngine On\n",
			"<Files \"*.php\">\nSetHandler default-handler\n</Files>\n",
			"php_value upload_max_filesize 64M\n",
			"Options -ExecCGI\n",
			"<IfModule mod_rewrite.c>\nRewriteRule ^index\\.php$ - [L]\nRewriteRule . /index.php [L]\n</IfModule>\n",
		);

		foreach ( $allowed as $content ) {
			$request = new \WP_REST_Request();
			$request->set_json_params( array( 'content' => $content ) );
			$result = $this->editor->saveHtaccessEndpoint( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $result, $content );
			$this->assertTrue( $result->get_data()['success'] );
		}
	}

	public function testRestoreHtaccessValidatesFilenameFormat(): void {
		$this->assertFalse( $this->editor->restoreHtaccess( '../../wp-config.php' ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'shell.php' ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'htaccess-malicious.exe' ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'htaccess-.txt' ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'htaccess-2026-09-18-120000.txt' ) );
	}

	public function testSeoManagerCannotReadOrWriteServerConfig(): void {
		file_put_contents( $this->sandbox . '/.htaccess', "AuthUserFile /etc/secret\n" );

		$save = new \WP_REST_Request();
		$save->set_json_params(
			array(
				'content' => "RewriteEngine On\n",
			)
		);
		$saved = $this->editor->saveHtaccessEndpoint( $save );
		$this->assertSame( 'forbidden', $saved->get_error_code() );
		$this->assertSame( 403, $saved->data['status'] );
		$this->assertStringNotContainsString( 'AuthUserFile', wp_json_encode( $saved ) );
		$this->assertSame( "AuthUserFile /etc/secret\n", (string) file_get_contents( $this->sandbox . '/.htaccess' ) );

		$read = $this->editor->readHtaccessEndpoint( new \WP_REST_Request() );
		$this->assertSame( 403, $read->data['status'] );
		$this->assertStringNotContainsString( 'AuthUserFile', wp_json_encode( $read ) );

		$enable = new \WP_REST_Request();
		$enable->set_json_params( array( 'enabled' => true ) );
		$enabled = $this->editor->updateAccessEndpoint( $enable );
		$this->assertSame( 'forbidden', $enabled->get_error_code() );
		$this->assertSame( 'no', get_option( 'holvek_htaccess_editor_enabled' ) );

		$this->assertFalse( $this->editor->checkPermission() );
	}

	public function testEditorStaysOffUntilAnAdministratorEnablesIt(): void {
		$this->grantAdmin();
		file_put_contents( $this->sandbox . '/.htaccess', "AuthUserFile /etc/secret\n" );

		$read = $this->editor->readHtaccessEndpoint( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $read );
		$data = $read->get_data();
		$this->assertFalse( $data['enabled'] );
		$this->assertFalse( $data['locked'] );
		$this->assertSame( '', $data['content'] );
		$this->assertArrayNotHasKey( 'path', $data );
		$this->assertStringNotContainsString( 'AuthUserFile', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( $this->sandbox, wp_json_encode( $data ) );

		$save = new \WP_REST_Request();
		$save->set_json_params( array( 'content' => "RewriteEngine On\n" ) );
		$saved = $this->editor->saveHtaccessEndpoint( $save );
		$this->assertSame( 'feature_disabled', $saved->get_error_code() );
		$this->assertSame( "AuthUserFile /etc/secret\n", (string) file_get_contents( $this->sandbox . '/.htaccess' ) );

		$enable = new \WP_REST_Request();
		$enable->set_json_params( array( 'enabled' => true ) );
		$enabled = $this->editor->updateAccessEndpoint( $enable );
		$this->assertTrue( $enabled->get_data()['enabled'] );
		$this->assertStringContainsString( 'AuthUserFile', $enabled->get_data()['content'] );
		$this->assertArrayNotHasKey( 'path', $enabled->get_data() );
		$this->assertStringNotContainsString( $this->sandbox, wp_json_encode( $enabled->get_data() ) );
	}

	public function testDisallowFileEditBlocksTheEditor(): void {
		$this->grantAdmin();
		$this->enableEditor();
		$locked = $this->makeEditor( true );

		$enable = new \WP_REST_Request();
		$enable->set_json_params( array( 'enabled' => true ) );
		$enabled = $locked->updateAccessEndpoint( $enable );
		$this->assertSame( 'file_editing_disabled', $enabled->get_error_code() );
		$this->assertSame( 403, $enabled->data['status'] );

		$save = new \WP_REST_Request();
		$save->set_json_params( array( 'content' => "RewriteEngine On\n" ) );
		$saved = $locked->saveHtaccessEndpoint( $save );
		$this->assertSame( 'file_editing_disabled', $saved->get_error_code() );
		$this->assertFileDoesNotExist( $this->sandbox . '/.htaccess' );

		$read = $locked->readHtaccessEndpoint( new \WP_REST_Request() );
		$this->assertTrue( $read->get_data()['locked'] );
		$this->assertFalse( $read->get_data()['enabled'] );
		$this->assertSame( '', $read->get_data()['content'] );
	}

	public function testBackupsStayOutOfTheWebRootAndRestoreRoundTrips(): void {
		$this->grantAdmin();
		$this->enableEditor();

		$first = new \WP_REST_Request();
		$first->set_json_params(
			array(
				'content' => "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n",
			)
		);
		$saved = $this->editor->saveHtaccessEndpoint( $first );
		$this->assertTrue( $saved->get_data()['success'] );
		$this->assertSame( array(), $saved->get_data()['backups'] );

		$second = new \WP_REST_Request();
		$second->set_json_params( array( 'content' => "RewriteEngine On\n" ) );
		$replaced = $this->editor->saveHtaccessEndpoint( $second );
		$this->assertCount( 1, $replaced->get_data()['backups'] );
		$this->assertStringNotContainsString( 'BEGIN WordPress', wp_json_encode( $replaced->get_data()['backups'] ) );
		$this->assertStringNotContainsString( $this->sandbox, wp_json_encode( $replaced->get_data() ) );
		$this->assertStringContainsString( 'BEGIN WordPress', (string) get_option( 'holvek_htaccess_backups' )[0]['content'] );
		$this->assertDirectoryDoesNotExist( $this->sandbox . '/public-backups' );

		$filename = $replaced->get_data()['backups'][0]['filename'];
		$restore  = new \WP_REST_Request();
		$restore->set_json_params( array( 'filename' => $filename ) );
		$restored = $this->editor->restoreEndpoint( $restore );
		$this->assertTrue( $restored->get_data()['success'] );
		$this->assertStringContainsString( 'BEGIN WordPress', $restored->get_data()['content'] );
		$this->assertStringContainsString( 'BEGIN WordPress', (string) file_get_contents( $this->sandbox . '/.htaccess' ) );
	}

	public function testRestoreRejectsUnsafeBackupWithoutWritingIt(): void {
		$this->grantAdmin();
		$this->enableEditor();
		file_put_contents( $this->sandbox . '/.htaccess', "RewriteEngine On\n" );
		update_option(
			'holvek_htaccess_backups',
			array(
				array(
					'filename'   => 'htaccess-2026-10-02-120000.txt',
					'created_at' => '2026-10-02 12:00:00',
					'size'       => 18,
					'content'    => 'php_flag engine on',
				),
			)
		);

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'filename' => 'htaccess-2026-10-02-120000.txt' ) );
		$result = $this->editor->restoreEndpoint( $request );

		$this->assertSame( 'unsafe_content', $result->get_error_code() );
		$this->assertSame( "RewriteEngine On\n", (string) file_get_contents( $this->sandbox . '/.htaccess' ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'htaccess-2026-10-02-120000.txt' ) );
	}

	public function testLegacyPublicBackupsAreImportedAndRemoved(): void {
		$this->grantAdmin();
		$this->enableEditor();
		update_option( 'holvek_htaccess_web_backups_retired', 'no' );

		$dir = $this->sandbox . '/public-backups/htaccess/';
		mkdir( $dir, 0700, true );
		file_put_contents( $dir . 'htaccess-2026-10-02-120000.txt', "RewriteEngine On\n# LEGACY-SAFE-RULE\n" );
		file_put_contents( $dir . 'htaccess-2026-10-02-120001.txt', "php_flag engine on\n" );
		file_put_contents( $dir . 'index.php', '<?php // Silence is golden' );
		file_put_contents( $dir . 'notes.txt', 'leave-me' );

		$read = $this->editor->readHtaccessEndpoint( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $read );
		$this->assertStringNotContainsString( 'LEGACY-SAFE-RULE', wp_json_encode( $read->get_data() ) );
		$this->assertStringNotContainsString( 'php_flag engine on', wp_json_encode( $read->get_data() ) );
		$this->assertStringNotContainsString( $this->sandbox, wp_json_encode( $read->get_data() ) );
		$this->assertFileDoesNotExist( $dir . 'htaccess-2026-10-02-120000.txt' );
		$this->assertFileDoesNotExist( $dir . 'htaccess-2026-10-02-120001.txt' );
		$this->assertFileDoesNotExist( $dir . 'index.php' );
		$this->assertFileExists( $dir . 'notes.txt' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '.htaccess' ) );
		$this->assertStringContainsString( 'LEGACY-SAFE-RULE', (string) get_option( 'holvek_htaccess_backups' )[0]['content'] );
		$this->assertSame( 'yes', get_option( 'holvek_htaccess_web_backups_retired' ) );
	}

	public function testDirectSaveRefusesUntilAnAdministratorTurnsTheEditorOn(): void {
		file_put_contents( $this->sandbox . '/.htaccess', "AuthUserFile /etc/secret\n" );
		update_option(
			'holvek_htaccess_backups',
			array(
				array(
					'filename'   => 'htaccess-2026-10-02-120000.txt',
					'created_at' => '2026-10-02 12:00:00',
					'size'       => 20,
					'content'    => "RewriteEngine On\n",
				),
			)
		);

		$this->assertFalse( $this->editor->saveHtaccess( "RewriteEngine On\n" ) );

		$this->grantAdmin();
		$this->assertFalse( $this->editor->saveHtaccess( "RewriteEngine On\n" ) );
		$this->assertFalse( $this->editor->restoreHtaccess( 'htaccess-2026-10-02-120000.txt' ) );

		$locked = $this->makeEditor( true );
		$this->enableEditor();
		$this->assertFalse( $locked->saveHtaccess( "RewriteEngine On\n" ) );
		$this->assertSame( "AuthUserFile /etc/secret\n", (string) file_get_contents( $this->sandbox . '/.htaccess' ) );

		$this->assertTrue( $this->editor->saveHtaccess( "RewriteEngine On\n" ) );
		$this->assertSame( "RewriteEngine On\n", (string) file_get_contents( $this->sandbox . '/.htaccess' ) );
	}

	public function testLegacyCleanupRunsOnceAndAlsoWhileTheEditorIsOff(): void {
		$dir  = $this->sandbox . '/public-backups/htaccess/';
		$file = $dir . 'htaccess-2026-10-02-120000.txt';
		mkdir( $dir, 0700, true );
		file_put_contents( $file, "RewriteEngine On\n# ONCE-RULE\n" );

		$this->editor->retirePublicBackupsOnce();
		$this->assertFileExists( $file );
		$this->assertSame( array(), get_option( 'holvek_htaccess_backups' ) );

		update_option( 'holvek_htaccess_web_backups_retired', 'no' );
		$this->editor->retirePublicBackupsOnce();
		$this->assertFileDoesNotExist( $file );
		$this->assertStringContainsString( 'ONCE-RULE', (string) get_option( 'holvek_htaccess_backups' )[0]['content'] );
		$this->assertSame( 'yes', get_option( 'holvek_htaccess_web_backups_retired' ) );

		mkdir( $dir, 0700, true );
		file_put_contents( $file, "RewriteEngine On\n# STAYS\n" );
		$this->editor->retirePublicBackupsOnce();
		$this->assertFileExists( $file );
		$this->assertStringNotContainsString( 'STAYS', (string) wp_json_encode( get_option( 'holvek_htaccess_backups' ) ) );

		unlink( $file );
		update_option( 'holvek_htaccess_web_backups_retired', 'no' );
		update_option( 'holvek_htaccess_backups', array() );
		file_put_contents( $file, "RewriteEngine On\n# READ-WHILE-OFF\n" );
		file_put_contents( $this->sandbox . '/.htaccess', "AuthUserFile /etc/secret\n" );
		$this->grantAdmin();

		$read = $this->editor->readHtaccessEndpoint( new \WP_REST_Request() );
		$this->assertFalse( $read->get_data()['enabled'] );
		$this->assertSame( '', $read->get_data()['content'] );
		$this->assertStringNotContainsString( 'READ-WHILE-OFF', (string) wp_json_encode( $read->get_data() ) );
		$this->assertStringNotContainsString( 'AuthUserFile', (string) wp_json_encode( $read->get_data() ) );
		$this->assertStringNotContainsString( $this->sandbox, (string) wp_json_encode( $read->get_data() ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertStringContainsString( 'READ-WHILE-OFF', (string) get_option( 'holvek_htaccess_backups' )[0]['content'] );
		$this->assertSame( 'yes', get_option( 'holvek_htaccess_web_backups_retired' ) );
	}

	public function testRoutesRequireManageOptions(): void {
		$GLOBALS['mock_rest_routes'] = array();
		$GLOBALS['mock_hooks']       = array();
		$this->editor->register();
		$this->assertContains( array( 'action', 'init' ), $GLOBALS['mock_hooks'] );
		$this->assertContains( array( 'action', 'rest_api_init' ), $GLOBALS['mock_hooks'] );
		$this->editor->registerRoutes();

		foreach ( $GLOBALS['mock_rest_routes']['holvek/v1/htaccess'] as $endpoint ) {
			$this->assertSame( array( $this->editor, 'checkPermission' ), $endpoint['permission_callback'] );
		}
		$this->assertSame(
			array( $this->editor, 'checkPermission' ),
			$GLOBALS['mock_rest_routes']['holvek/v1/htaccess/access']['permission_callback']
		);
		$this->assertSame(
			array( $this->editor, 'checkPermission' ),
			$GLOBALS['mock_rest_routes']['holvek/v1/htaccess/restore']['permission_callback']
		);
	}

	private function grantAdmin(): void {
		$GLOBALS['mock_user_caps'] = array(
			'manage_options' => true,
			'manage_seo'     => true,
			'edit_posts'     => true,
		);
	}

	private function enableEditor(): void {
		update_option( 'holvek_htaccess_editor_enabled', 'yes' );
	}

	private function makeEditor( bool $locked ): HtaccessEditor {
		$path   = $this->sandbox . '/.htaccess';
		$legacy = $this->sandbox . '/public-backups/htaccess/';

		return new class( $path, $legacy, $locked ) extends HtaccessEditor {
			public function __construct(
				private string $filePath,
				private string $legacyDir,
				private bool $locked
			) {}

			protected function htaccessPath(): string {
				return $this->filePath;
			}

			protected function legacyBackupDir(): string {
				return $this->legacyDir;
			}

			protected function fileEditingDisallowed(): bool {
				return $this->locked || parent::fileEditingDisallowed();
			}
		};
	}

	private function removeTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->removeTree( $path );
				continue;
			}
			unlink( $path );
		}
		rmdir( $dir );
	}
}
