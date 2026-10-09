<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Api\HolvekApiGateway;
use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Social\SocialModule;
use PHPUnit\Framework\TestCase;

class KeyVaultTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		KeyVault::setTestConstraints( false, false );
		delete_option( 'holvek_encryption_salt' );
		delete_option( 'holvek_license_key' );
		delete_option( 'holvek_openai_key' );
		delete_option( 'holvek_social_accounts' );
	}

	protected function tearDown(): void {
		KeyVault::setTestConstraints( false, false );
		unset( $GLOBALS['mock_user_caps'], $GLOBALS['mock_http_response'], $GLOBALS['mock_http_log'] );
		parent::tearDown();
	}

	public function testEncryptWritesTheNewPrefixAndRoundTrips(): void {
		$secret    = 'sk-proj-test1234567890abcdefghijklmnopqrstuvwxyz';
		$encrypted = KeyVault::encrypt( $secret );

		$this->assertIsString( $encrypted );
		$this->assertStringStartsWith( 'holvek_enc::', $encrypted );
		$this->assertStringNotContainsString( 'aew_enc::', $encrypted );
		$this->assertNotSame( $secret, $encrypted );
		$this->assertSame( $secret, KeyVault::decrypt( $encrypted ) );
	}

	public function testEncryptCreatesASaltAndDoesNotReplaceAnExistingOne(): void {
		$encrypted = KeyVault::encrypt( 'created-with-new-salt' );

		$this->assertIsString( $encrypted );
		$salt = get_option( 'holvek_encryption_salt' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $salt );
		$this->assertSame( 'created-with-new-salt', KeyVault::decrypt( $encrypted ) );

		update_option( 'holvek_encryption_salt', '' );
		$replaced = KeyVault::encrypt( 'replaced-empty-salt' );
		$this->assertIsString( $replaced );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) get_option( 'holvek_encryption_salt' ) );
		$this->assertSame( 'replaced-empty-salt', KeyVault::decrypt( $replaced ) );

		update_option( 'holvek_encryption_salt', 'keep-this-salt' );
		KeyVault::encrypt( 'value' );
		$this->assertSame( 'keep-this-salt', get_option( 'holvek_encryption_salt' ) );
		$this->assertSame( 'value', KeyVault::decrypt( KeyVault::encrypt( 'value' ) ) );
	}

	public function testMissingSaltRefusesToEncryptAndDoesNotUseTheFallbackKey(): void {
		$this->assertFalse( defined( 'AUTH_KEY' ) );
		KeyVault::setTestConstraints( true, false );

		$fallback = $this->ciphertext( 'fallback-secret', 'holvek-fallback-key-change-me', 'aew_enc::' );
		$this->assertSame( '', KeyVault::decrypt( $fallback ) );

		$result = KeyVault::encrypt( 'new-secret' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_salt_missing', $result->get_error_code() );
		$this->assertFalse( get_option( 'holvek_encryption_salt', false ) );
		$this->assertStringNotContainsString( 'new-secret', $result->get_error_message() );
		$this->assertStringNotContainsString( 'holvek-fallback-key-change-me', $result->get_error_message() );
	}

	public function testDecryptsTheLegacyPrefixAndDoesNotWrapItAgain(): void {
		update_option( 'holvek_encryption_salt', 'site-salt-value' );
		$legacy = $this->ciphertext( 'legacy-secret', 'site-salt-value', 'aew_enc::' );

		$this->assertSame( 'legacy-secret', KeyVault::decrypt( $legacy ) );
		$this->assertSame( $legacy, KeyVault::encrypt( $legacy ) );
	}

	public function testEmptyStringHandling(): void {
		$this->assertSame( '', KeyVault::encrypt( '' ) );
		$this->assertSame( '', KeyVault::decrypt( '' ) );
	}

	public function testDoesNotDoubleEncrypt(): void {
		$secret         = 'sk-proj-single-encryption';
		$encryptedOnce  = KeyVault::encrypt( $secret );
		$encryptedTwice = KeyVault::encrypt( $encryptedOnce );

		$this->assertSame( $encryptedOnce, $encryptedTwice );
	}

	public function testLegacyUnencryptedKeyPassThrough(): void {
		$legacyKey = 'plain-text-api-key-without-prefix';
		$this->assertSame( $legacyKey, KeyVault::decrypt( $legacyKey ) );
	}

	public function testTamperedCiphertextFailsDecryption(): void {
		$secret    = 'my-super-confidential-service-account-key';
		$encrypted = KeyVault::encrypt( $secret );
		$this->assertIsString( $encrypted );

		$payload = substr( $encrypted, strlen( 'holvek_enc::' ) );
		$decoded = base64_decode( $payload );
		$decoded[ strlen( $decoded ) - 1 ] = chr( ord( $decoded[ strlen( $decoded ) - 1 ] ) ^ 0xFF );
		$tampered = 'holvek_enc::' . base64_encode( $decoded );

		$this->assertSame( '', KeyVault::decrypt( $tampered ) );

		delete_option( 'holvek_encryption_salt' );
		KeyVault::setTestConstraints( true, false );
		$this->assertSame( '', KeyVault::decrypt( $encrypted ) );
	}

	public function testUnavailableCipherDoesNotReturnOrStorePlaintext(): void {
		$encrypted = KeyVault::encrypt( 'stored-secret' );
		$this->assertIsString( $encrypted );

		KeyVault::setTestConstraints( false, true );
		$this->assertSame( '', KeyVault::decrypt( $encrypted ) );

		$result = KeyVault::encrypt( 'plain-secret' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_cipher_unavailable', $result->get_error_code() );
		$this->assertStringNotContainsString( 'plain-secret', $result->get_error_message() );

		$kept = KeyVault::encrypt( $encrypted );
		$this->assertSame( $encrypted, $kept );

		update_option( 'holvek_openai_key', 'already-stored' );
		$admin   = new AdminModule( new EventManager(), new AdminMenu() );
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'openai_key' => 'sk-new-plaintext' ) );
		$saved = $admin->updateSettings( $request );

		$this->assertInstanceOf( \WP_Error::class, $saved );
		$this->assertSame( 'holvek_cipher_unavailable', $saved->get_error_code() );
		$this->assertSame( 'already-stored', get_option( 'holvek_openai_key' ) );

		update_option( 'holvek_social_accounts', array() );
		$module  = new SocialModule( new EventManager() );
		$account = new \WP_REST_Request();
		$account->set_json_params(
			array(
				'network'      => 'twitter',
				'access_token' => 'plain-token-must-not-store',
			)
		);
		$social = $module->saveAccount( $account );
		$this->assertInstanceOf( \WP_Error::class, $social );
		$this->assertSame( array(), get_option( 'holvek_social_accounts', array() ) );
	}

	public function testLicenseKeyIsEncryptedAndAStoredStringStillCountsAsPresent(): void {
		$stored = KeyVault::encryptSettings(
			array(
				'holvek_license_key' => 'lic-plain-value',
				'holvek_ai_provider' => 'openai',
			)
		);

		$this->assertStringStartsWith( 'holvek_enc::', $stored['holvek_license_key'] );
		$this->assertSame( 'lic-plain-value', KeyVault::decryptSettings( $stored )['holvek_license_key'] );
		$this->assertSame( 'openai', $stored['holvek_ai_provider'] );

		update_option( 'holvek_license_key', $stored['holvek_license_key'] );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );

		$GLOBALS['mock_http_log'] = array();
		$result                   = HolvekApiGateway::proxyRequest( 'pagespeed', array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'holvek_not_connected', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['mock_http_log'] );

		update_option( 'holvek_license_key', 'plain-license' );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );

		update_option( 'holvek_license_key', '' );
		$this->assertFalse( HolvekApiGateway::hasActiveLicense() );

		KeyVault::setTestConstraints( false, true );
		$dropped = KeyVault::encryptSettings(
			array(
				'holvek_license_key' => 'super-secret-license',
				'holvek_ai_provider' => 'openai',
			)
		);
		$this->assertArrayNotHasKey( 'holvek_license_key', $dropped );
		$this->assertSame( 'openai', $dropped['holvek_ai_provider'] );
		$this->assertSame( $stored['holvek_license_key'], KeyVault::encrypt( $stored['holvek_license_key'] ) );
	}

	public function testSettingsTransferOmitsTheSalt(): void {
		update_option( 'holvek_encryption_salt', 'salt-must-stay-on-the-site' );
		update_option( 'holvek_breadcrumb_home_label', 'Start' );

		$admin    = new AdminModule( new EventManager(), new AdminMenu() );
		$exported = $admin->exportSettings( new \WP_REST_Request() );
		$data     = $exported->get_data()['data'];
		$encoded  = wp_json_encode( $data );

		$this->assertArrayNotHasKey( 'holvek_encryption_salt', $data['settings'] );
		$this->assertStringNotContainsString( 'salt-must-stay-on-the-site', $encoded );
		$this->assertSame( 'Start', $data['settings']['holvek_breadcrumb_home_label'] );

		$import = new \WP_REST_Request();
		$import->set_json_params(
			array(
				'_plugin'  => 'holvek',
				'settings' => array(
					'holvek_encryption_salt'       => 'imported-salt-must-not-replace',
					'holvek_breadcrumb_home_label' => 'Home',
				),
			)
		);
		$result = $admin->importSettings( $import );

		$this->assertSame( 1, $result->get_data()['imported'] );
		$this->assertSame( 'salt-must-stay-on-the-site', get_option( 'holvek_encryption_salt' ) );
		$this->assertSame( 'Home', get_option( 'holvek_breadcrumb_home_label' ) );
	}

	public function testMissingCipherNoticeIsLimitedToAdministrators(): void {
		KeyVault::setTestConstraints( false, true );
		$GLOBALS['mock_hooks'] = array();
		KeyVault::registerAdminNotice();

		$hooks = array_column( $GLOBALS['mock_hooks'], 1 );
		$this->assertContains( 'admin_notices', $hooks );

		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'dashboard' );
		$this->assertSame( '', $this->captureNotice() );

		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'toplevel_page_holvek' );
		$html = $this->captureNotice();
		$this->assertStringContainsString( 'OpenSSL', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( 'holvek-fallback-key-change-me', $html );

		$GLOBALS['mock_user_caps'] = array( 'manage_options' => false );
		$this->assertSame( '', $this->captureNotice() );

		unset( $GLOBALS['mock_user_caps'], $GLOBALS['mock_current_screen'] );
		KeyVault::setTestConstraints( false, false );
		$this->assertSame( '', $this->captureNotice() );
	}

	private function captureNotice(): string {
		ob_start();
		try {
			KeyVault::renderMissingCipherNotice();
			return (string) ob_get_clean();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}
	}

	private function ciphertext( string $secret, string $salt, string $prefix ): string {
		$key    = hash( 'sha256', $salt, true );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$hmac   = hash_hmac( 'sha256', $cipher, $key, true );

		return $prefix . base64_encode( $iv . $hmac . $cipher );
	}
}
