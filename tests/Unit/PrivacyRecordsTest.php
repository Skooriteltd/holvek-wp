<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools;
use PHPUnit\Framework\TestCase;

class PrivacyRecordsTest extends TestCase {

	private \MockWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new \MockWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['mock_users'] = array(
			(object) array(
				'ID'         => 7,
				'user_email' => 'ada@example.com',
				'user_login' => 'adaexample',
			),
		);
		$GLOBALS['mock_user_meta']    = array( 7 => array() );
		$GLOBALS['mock_wpdb_results'] = static function ( $query ) {
			if ( ! is_string( $query ) || ! str_contains( $query, 'holvek_audit_log' ) || ! str_contains( $query, 'user_id = 7' ) ) {
				return array();
			}
			return array(
				(object) array(
					'id'         => 4,
					'user_id'    => 7,
					'post_id'    => 15,
					'action'     => 'update',
					'field'      => '_holvek_meta_title',
					'created_at' => '2026-10-01 00:00:00',
				),
			);
		};
		$GLOBALS['mock_wpdb_var'] = static function ( $query ) {
			if ( ! is_string( $query ) ) {
				return null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 'audit' ) ) {
				return 'wp_holvek_audit_log';
			}
			if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'holvek_audit_log' ) ) {
				return 1;
			}
			return null;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mock_wpdb_var'], $GLOBALS['mock_wpdb_results'], $GLOBALS['mock_users'], $GLOBALS['mock_user_meta'] );
		parent::tearDown();
	}

	public function testAuditRowsForTheUserAreExportedAndErased(): void {
		$tools  = new CcpaPrivacyTools();
		$export = $tools->wpPrivacyExporter( 'ada@example.com', 1 );
		$encoded = wp_json_encode( $export );

		$this->assertStringContainsString( 'holvek_audit_log', $encoded );
		$this->assertStringContainsString( '_holvek_meta_title', $encoded );
		$this->assertStringNotContainsString( 'holvek_ga4_credentials', $encoded );
		$this->assertStringNotContainsString( 'holvek_gsc_token', $encoded );

		$erased = $tools->wpPrivacyEraser( 'ada@example.com', 1 );
		$this->assertTrue( $erased['items_removed'] );
		$deleted = implode( "\n", $this->db->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_holvek_audit_log WHERE user_id = 7', $deleted );
	}

	public function testPolicyTextLeavesTheLegalBasisToTheSiteOwner(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Compliance/CcpaPrivacyTools.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'The site owner decides the legal basis.', $source );
		$this->assertStringContainsString( 'does not make a site compliant with a privacy law', $source );
		$this->assertStringContainsString( 'not included in a visitor or account export', $source );
		$this->assertStringContainsString( 'holvek_consent', $source );
		$this->assertStringNotContainsString( 'CCPA compliant', $source );
		$this->assertStringNotContainsString( 'GDPR compliant', $source );
	}
}
