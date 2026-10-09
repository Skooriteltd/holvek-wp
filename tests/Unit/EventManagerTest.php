<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Event\EventManager;
use PHPUnit\Framework\TestCase;

class EventManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_did_actions'] = array();
	}

	public function testFiredHooksUseOneHolvekPrefix(): void {
		$events = new EventManager();
		$events->doAction( 'holvek_activation' );
		$events->doAction( 'Audit_Done' );
		$events->doAction( 'wp-head' );
		$events->doAction( 'holvek_' );

		$this->assertSame(
			array(
				'holvek_activation',
				'holvek_audit_done',
			),
			$GLOBALS['mock_did_actions']
		);
	}
}
