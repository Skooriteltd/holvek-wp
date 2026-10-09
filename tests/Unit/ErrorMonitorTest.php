<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools;
use Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor;
use PHPUnit\Framework\TestCase;

class ErrorMonitorTest extends TestCase {

	private \MockWpdb $db;

	private $previousWpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previousWpdb = $GLOBALS['wpdb'] ?? null;
		$this->db           = new \MockWpdb();
		$GLOBALS['wpdb']    = $this->db;
		$GLOBALS['mock_is_404']       = false;
		$GLOBALS['mock_transients']   = array();
		$GLOBALS['mock_users']        = array();
		$GLOBALS['mock_user_meta']    = array();
		unset( $GLOBALS['mock_wpdb_var'], $GLOBALS['mock_wpdb_results'], $GLOBALS['mock_wpdb_delete'] );
		$_SERVER['REQUEST_URI']     = '/';
		$_SERVER['HTTP_REFERER']    = '';
		$_SERVER['HTTP_USER_AGENT'] = '';
	}

	protected function tearDown(): void {
		if ( null !== $this->previousWpdb ) {
			$GLOBALS['wpdb'] = $this->previousWpdb;
		}
		$GLOBALS['mock_is_404'] = false;
		unset( $GLOBALS['mock_wpdb_var'], $GLOBALS['mock_wpdb_results'], $GLOBALS['mock_users'], $GLOBALS['mock_user_meta'] );
		parent::tearDown();
	}

	public function testCleanupCronIsScheduledOnceOnActivation(): void {
		$GLOBALS['mock_recurring_events'] = array();

		ErrorMonitor::schedule();
		ErrorMonitor::schedule();

		$hooks = array();
		foreach ( $GLOBALS['mock_recurring_events'] as $event ) {
			$hooks[ $event['hook'] ] = $event['recurrence'];
		}

		$this->assertSame(
			array(
				'holvek_flush_404_buffer' => 'hourly',
				'holvek_404_cleanup'      => 'daily',
			),
			$hooks
		);
	}

	public function testLogged404StripsQueryStringsAndTruncates(): void {
		$GLOBALS['mock_is_404']     = true;
		$_SERVER['REQUEST_URI']     = '/missing?session=secret-token';
		$_SERVER['HTTP_REFERER']    = 'https://user:secret@example.com/wp-admin/post.php?post=9&action=edit';
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'A', 300 );

		( new ErrorMonitor() )->log404Errors();

		$buffer = get_transient( 'holvek_404_buffer' );
		$this->assertCount( 1, $buffer );
		$this->assertSame( '/missing', $buffer[0]['uri'] );
		$this->assertSame( 'https://example.com/wp-admin/post.php', $buffer[0]['referer'] );
		$this->assertSame( 160, strlen( $buffer[0]['user_agent'] ) );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $buffer ) );
		$this->assertStringNotContainsString( 'post=9', wp_json_encode( $buffer ) );
	}

	public function testProbesAreStillSkipped(): void {
		$GLOBALS['mock_is_404'] = true;
		$_SERVER['REQUEST_URI'] = '/wp-login.php?redirect=secret';

		( new ErrorMonitor() )->log404Errors();

		$this->assertFalse( get_transient( 'holvek_404_buffer' ) );
	}

	public function testPrivacyExportAndEraseCoverMatching404Rows(): void {
		$GLOBALS['mock_users']     = array(
			(object) array(
				'ID'         => 7,
				'user_email' => 'ada@example.com',
				'user_login' => 'adaexample',
			),
		);
		$GLOBALS['mock_user_meta'] = array( 7 => array() );
		$GLOBALS['mock_wpdb_var']  = static function ( $query ) {
			if ( ! is_string( $query ) ) {
				return null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, '404' ) ) {
				return 'wp_holvek_404_logs';
			}
			if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'holvek_404_logs' ) ) {
				return 1;
			}
			return null;
		};
		$GLOBALS['mock_wpdb_results'] = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, 'holvek_404_logs' ) && str_contains( $query, 'ada@example.com' ) ) {
				return array(
					(object) array(
						'id'         => 'log-9',
						'uri'        => '/missing',
						'referer'    => 'https://example.com/people/ada@example.com',
						'user_agent' => 'Browser',
						'last_hit'   => '2026-10-01 00:00:00',
					),
				);
			}
			return array();
		};

		$tools  = new CcpaPrivacyTools();
		$export = $tools->wpPrivacyExporter( 'ada@example.com', 1 );
		$groups = array();
		foreach ( $export['data'] as $item ) {
			$groups[] = $item['group_id'];
		}
		$this->assertContains( 'holvek_404_logs', $groups );

		$erased = $tools->wpPrivacyEraser( 'ada@example.com', 1 );
		$this->assertTrue( $erased['items_removed'] );
		$deleted = implode( "\n", $this->db->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_holvek_404_logs', $deleted );
		$this->assertStringContainsString( 'ada@example.com', $deleted );
	}
}
