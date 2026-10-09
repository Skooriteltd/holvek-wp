<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PluginBootOnceTest extends TestCase {

	private bool $createdUpgrade = false;

	protected function setUp(): void {
		parent::setUp();

		update_option( 'holvek_db_version', HOLVEK_VERSION );
		$GLOBALS['mock_hooks'] = array();

		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		$this->removeUpgradeStub();
		parent::tearDown();
	}

	public function testBootRegistersHooksOnce(): void {
		$plugin = Plugin::getInstance();
		$plugin->boot();
		$once = $GLOBALS['mock_hooks'];

		$this->assertGreaterThan( 10, count( $once ) );

		$plugin->boot();

		$this->assertSame( $once, $GLOBALS['mock_hooks'] );
	}

	public function testHolvekInitDoesNotRegisterHooksAgain(): void {
		require_once dirname( __DIR__, 2 ) . '/holvek.php';

		holvek_init();
		$once = $GLOBALS['mock_hooks'];
		$this->assertGreaterThan( 10, count( $once ) );

		holvek_init();

		$this->assertSame( $once, $GLOBALS['mock_hooks'] );
	}

	public function testBootInstallsSchemaWhenStoredVersionIsBehind(): void {
		$this->stubWordPressUpgrade();
		update_option( 'holvek_db_version', '0' );
		$GLOBALS['mock_db_delta'] = array();

		Plugin::getInstance()->boot();

		$this->assertSame( HOLVEK_VERSION, get_option( 'holvek_db_version' ) );
		$created = implode( "\n", array_map( 'strval', $GLOBALS['mock_db_delta'] ) );
		$this->assertStringContainsString( 'wp_holvek_redirects', $created );
		$this->assertStringContainsString( 'wp_holvek_404_logs', $created );
	}

	private function stubWordPressUpgrade(): void {
		$dir = dirname( __DIR__, 2 ) . '/wp-admin/includes';
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			$this->fail( 'Could not create the upgrade.php stub directory.' );
		}
		$file = $dir . '/upgrade.php';
		if ( ! is_file( $file ) ) {
			file_put_contents( $file, "<?php\n" );
			$this->createdUpgrade = true;
		}
	}

	private function removeUpgradeStub(): void {
		if ( ! $this->createdUpgrade ) {
			return;
		}
		$root = dirname( __DIR__, 2 );
		$file = $root . '/wp-admin/includes/upgrade.php';
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		foreach ( array( $root . '/wp-admin/includes', $root . '/wp-admin' ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$left = scandir( $dir );
				if ( is_array( $left ) && array( '.', '..' ) === $left ) {
					rmdir( $dir );
				}
			}
		}
		$this->createdUpgrade = false;
	}
}
