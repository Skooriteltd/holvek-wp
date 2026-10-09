<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Onboarding\SetupWizard;
use PHPUnit\Framework\TestCase;

class AdminUxTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['mock_current_screen'],
			$GLOBALS['mock_user_caps'],
			$GLOBALS['mock_user_meta'],
			$GLOBALS['mock_flush_count']
		);
		unset( $GLOBALS['mock_wp_options']['holvek_setup_complete'] );
		parent::tearDown();
	}

	public function testWelcomeNoticeIsOnHolvekScreensAndStaysDismissed(): void {
		$wizard = new SetupWizard();
		$GLOBALS['mock_user_caps'] = array(
			'manage_seo'     => true,
			'manage_options' => true,
		);
		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'dashboard' );
		$this->assertSame( '', $this->capture( array( $wizard, 'showWelcomeNotice' ) ) );

		$GLOBALS['mock_current_screen'] = (object) array( 'id' => 'toplevel_page_holvek' );
		$html = $this->capture( array( $wizard, 'showWelcomeNotice' ) );
		$this->assertStringContainsString( 'Welcome to Holvek', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( '🚀', $html );

		$GLOBALS['mock_user_meta'][1]['holvek_setup_notice_dismissed'] = '1';
		$this->assertSame( '', $this->capture( array( $wizard, 'showWelcomeNotice' ) ) );
	}

	public function testSkipSetupKeepsDefaultsAndDoesNotFlush(): void {
		$GLOBALS['mock_flush_count'] = 0;
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'skip' => true ) );

		$result = ( new SetupWizard() )->completeSetup( $request )->get_data();

		$this->assertTrue( $result['skipped'] );
		$this->assertTrue( (bool) get_option( 'holvek_setup_complete' ) );
		$this->assertSame( 0, $GLOBALS['mock_flush_count'] );
		$this->assertSame( '1', get_user_meta( 1, 'holvek_setup_notice_dismissed', true ) );
	}

	public function testActivationDoesNotRedirect(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$start  = strpos( $plugin, 'public static function activate' );
		$end    = strpos( $plugin, 'private static function deactivateSite' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$body = substr( $plugin, (int) $start, (int) $end - (int) $start );
		$this->assertStringNotContainsString( 'wp_redirect(', $body );
		$this->assertStringNotContainsString( 'wp_safe_redirect(', $body );
	}

	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}
	}
}
