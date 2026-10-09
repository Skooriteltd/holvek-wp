<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PluginBootOnceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option( 'holvek_db_version', HOLVEK_VERSION );
		$GLOBALS['mock_hooks'] = array();

		$instance = ( new ReflectionClass( Plugin::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
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
}
