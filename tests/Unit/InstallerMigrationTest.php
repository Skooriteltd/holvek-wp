<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Core\Database\Installer;
use PHPUnit\Framework\TestCase;

class InstallerMigrationTest extends TestCase {

	private LegacyMigrationWpdb $db;

	private object $originalWpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->originalWpdb                 = $GLOBALS['wpdb'];
		$this->db                           = new LegacyMigrationWpdb();
		$GLOBALS['wpdb']                    = $this->db;
		$GLOBALS['mock_wp_options']         = array();
		$GLOBALS['mock_cron_array']         = array();
		$GLOBALS['mock_cleared_hooks']      = array();
		unset( $GLOBALS['mock_wpdb_results'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']            = $this->originalWpdb;
		$GLOBALS['mock_wp_options'] = array();
		unset( $GLOBALS['mock_cron_array'], $GLOBALS['mock_cleared_hooks'], $GLOBALS['mock_wpdb_results'] );
		parent::tearDown();
	}

	public function testPlannerSkipsOccupiedTargetsAndASecondRowOnTheSameObject(): void {
		$plan = ( new Installer() )->planLegacyRenames(
			array(
				array(
					'id'        => 1,
					'object_id' => 0,
					'key'       => 'ameverywhere_shared',
				),
				array(
					'id'        => 2,
					'object_id' => 0,
					'key'       => 'ameverywhere_keep',
				),
				array(
					'id'        => 3,
					'object_id' => 6,
					'key'       => 'ameverywhere_focus',
				),
				array(
					'id'        => 4,
					'object_id' => 5,
					'key'       => 'ameverywhere_focus',
				),
				array(
					'id'        => 5,
					'object_id' => 5,
					'key'       => 'ameverywhere_focus',
				),
			),
			array(
				'0|holvek_shared' => true,
				'6|holvek_focus'  => true,
			)
		);

		$this->assertSame( array( 2, 4 ), array_column( $plan['rename'], 'id' ) );
		$this->assertSame( 'holvek_keep', $plan['rename'][0]['to'] );
		$this->assertSame( 'holvek_focus', $plan['rename'][1]['to'] );
		$this->assertSame( array( 1, 3, 5 ), array_column( $plan['collisions'], 'id' ) );
	}

	public function testRenameSkipsCollisionsAndUpdatesByPrimaryKey(): void {
		$this->db->optionsRows = array(
			array(
				'id'   => 1,
				'name' => 'ameverywhere_keep',
			),
			array(
				'id'   => 2,
				'name' => 'ameverywhere_shared',
			),
			array(
				'id'   => 3,
				'name' => 'holvek_shared',
			),
		);
		$this->db->postRows    = array(
			array(
				'meta_id'  => 10,
				'post_id'  => 5,
				'meta_key' => 'ameverywhere_focus',
			),
			array(
				'meta_id'  => 11,
				'post_id'  => 6,
				'meta_key' => 'ameverywhere_focus',
			),
			array(
				'meta_id'  => 12,
				'post_id'  => 6,
				'meta_key' => 'holvek_focus',
			),
		);
		$this->db->userRows    = array(
			array(
				'umeta_id' => 8,
				'user_id'  => 4,
				'meta_key' => 'ameverywhere_token',
			),
		);
		$this->db->tables      = array(
			'wp_ameverywhere_redirects',
			'wp_holvek_redirects',
			'wp_ameverywhere_links',
		);
		$GLOBALS['mock_cron_array'] = array(
			100 => array(
				'ameverywhere_old_job' => array( array( 'schedule' => 'daily' ) ),
				'holvek_keep_job'      => array( array( 'schedule' => 'daily' ) ),
			),
		);
		$GLOBALS['mock_wp_options']['holvek_migration_collisions'] = array( 'stale' );

		( new Installer() )->migrateLegacyNames();

		$this->assertSame( 'holvek_keep', $this->db->optionsRows[0]['name'] );
		$this->assertSame( 'ameverywhere_shared', $this->db->optionsRows[1]['name'] );
		$this->assertSame( 'holvek_shared', $this->db->optionsRows[2]['name'] );
		$this->assertSame( 'holvek_focus', $this->db->postRows[0]['meta_key'] );
		$this->assertSame( 'ameverywhere_focus', $this->db->postRows[1]['meta_key'] );
		$this->assertSame( 'holvek_focus', $this->db->postRows[2]['meta_key'] );
		$this->assertSame( 'holvek_token', $this->db->userRows[0]['meta_key'] );
		$this->assertContains( 'wp_holvek_redirects', $this->db->tables );
		$this->assertContains( 'wp_ameverywhere_redirects', $this->db->tables );
		$this->assertNotContains( 'wp_ameverywhere_links', $this->db->tables );
		$this->assertContains( 'wp_holvek_links', $this->db->tables );

		$sql = implode( "\n", $this->db->queries );
		$this->assertStringContainsString(
			"UPDATE wp_options SET option_name = 'holvek_keep' WHERE option_id = 1 AND option_name = 'ameverywhere_keep'",
			$sql
		);
		$this->assertStringContainsString(
			"UPDATE wp_postmeta SET meta_key = 'holvek_focus' WHERE meta_id = 10 AND meta_key = 'ameverywhere_focus'",
			$sql
		);
		$this->assertStringContainsString(
			"UPDATE wp_usermeta SET meta_key = 'holvek_token' WHERE umeta_id = 8 AND meta_key = 'ameverywhere_token'",
			$sql
		);
		$this->assertStringContainsString(
			'RENAME TABLE `wp_ameverywhere_links` TO `wp_holvek_links`',
			$sql
		);
		$this->assertStringNotContainsString( 'REPLACE(', $sql );
		$this->assertStringNotContainsString( "option_name = 'holvek_shared' WHERE option_id = 2", $sql );
		$this->assertStringNotContainsString( 'RENAME TABLE `wp_ameverywhere_redirects`', $sql );

		$selectAt = strpos( $sql, "SELECT option_id FROM wp_options WHERE option_name = 'holvek_shared'" );
		$updateAt = strpos( $sql, "UPDATE wp_options SET option_name = 'holvek_keep'" );
		$this->assertIsInt( $selectAt );
		$this->assertIsInt( $updateAt );
		$this->assertLessThan( $updateAt, $selectAt );

		$this->assertSame( 'ameverywhere', $GLOBALS['mock_wp_options']['holvek_migrated_from'] );
		$this->assertSame(
			array(
				'table:wp_ameverywhere_redirects',
				'option:ameverywhere_shared',
				'postmeta:6:ameverywhere_focus',
			),
			$GLOBALS['mock_wp_options']['holvek_migration_collisions']
		);
		$this->assertSame( array( 'ameverywhere_old_job' ), $GLOBALS['mock_cleared_hooks'] );
	}

	public function testOptionsAreRenamedInPrimaryKeyBatchesOfOneHundred(): void {
		for ( $id = 1; $id <= 101; $id++ ) {
			$this->db->optionsRows[] = array(
				'id'   => $id,
				'name' => 'ameverywhere_item_' . $id,
			);
		}

		( new Installer() )->migrateLegacyNames();

		$selects = array_values(
			array_filter(
				$this->db->queries,
				static function ( $query ): bool {
					return is_string( $query ) && str_contains( $query, 'SELECT option_id, option_name' );
				}
			)
		);

		$this->assertCount( 2, $selects );
		$this->assertStringContainsString( 'option_id > 0', $selects[0] );
		$this->assertStringContainsString( 'LIMIT 100', $selects[0] );
		$this->assertStringContainsString( 'option_id > 100', $selects[1] );
		$this->assertSame( 'holvek_item_1', $this->db->optionsRows[0]['name'] );
		$this->assertSame( 'holvek_item_101', $this->db->optionsRows[100]['name'] );
		$this->assertSame( 'ameverywhere', $GLOBALS['mock_wp_options']['holvek_migrated_from'] );
		$this->assertArrayNotHasKey( 'holvek_migration_collisions', $GLOBALS['mock_wp_options'] );
	}

	public function testBatchCapLeavesTheCompletionFlagUnset(): void {
		$total = ( Installer::MAX_BATCHES * Installer::BATCH_SIZE ) + 1;
		for ( $id = 1; $id <= $total; $id++ ) {
			$this->db->optionsRows[] = array(
				'id'   => $id,
				'name' => 'ameverywhere_n_' . $id,
			);
		}

		( new Installer() )->migrateLegacyNames();

		$this->assertSame( 'holvek_n_1', $this->db->optionsRows[0]['name'] );
		$this->assertSame( 'ameverywhere_n_' . $total, $this->db->optionsRows[ $total - 1 ]['name'] );
		$this->assertArrayNotHasKey( 'holvek_migrated_from', $GLOBALS['mock_wp_options'] );
	}

	public function testBacktickInThePrefixStaysInsideTheIdentifier(): void {
		$this->db->prefix = 'wp_`bad';
		$this->db->tables = array( 'wp_`badameverywhere_redirects' );

		( new Installer() )->migrateLegacyNames();

		$rename = '';
		foreach ( $this->db->queries as $query ) {
			if ( is_string( $query ) && str_starts_with( $query, 'RENAME TABLE ' ) ) {
				$rename = $query;
			}
		}

		$this->assertSame(
			'RENAME TABLE `wp_``badameverywhere_redirects` TO `wp_``badholvek_redirects`',
			$rename
		);
		$this->assertStringNotContainsString( '`wp_`bad', $rename );
		$this->assertSame( array( 'wp_`badholvek_redirects' ), $this->db->tables );
	}

	public function testASecondPassDoesNotQueryAfterTheFlagIsSet(): void {
		$this->db->optionsRows = array(
			array(
				'id'   => 1,
				'name' => 'ameverywhere_keep',
			),
		);

		$installer = new Installer();
		$installer->migrateLegacyNames();
		$this->db->queries = array();
		$this->db->optionsRows[] = array(
			'id'   => 9,
			'name' => 'ameverywhere_again',
		);

		$installer->migrateLegacyNames();

		$this->assertSame( array(), $this->db->queries );
		$this->assertSame( 'ameverywhere_again', $this->db->optionsRows[1]['name'] );
		$this->assertSame( 'ameverywhere', $GLOBALS['mock_wp_options']['holvek_migrated_from'] );
	}

	public function testAFailedUpdateDoesNotRecordCompletion(): void {
		$this->db->optionsRows   = array(
			array(
				'id'   => 1,
				'name' => 'ameverywhere_keep',
			),
		);
		$this->db->failNextQuery = true;

		( new Installer() )->migrateLegacyNames();

		$this->assertSame( 'ameverywhere_keep', $this->db->optionsRows[0]['name'] );
		$this->assertArrayNotHasKey( 'holvek_migrated_from', $GLOBALS['mock_wp_options'] );
	}
}

/**
 * In-memory tables, options, and meta for the legacy rename.
 */
class LegacyMigrationWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $postmeta = 'wp_postmeta';

	public string $usermeta = 'wp_usermeta';

	public array $queries = array();

	public array $tables = array();

	public array $optionsRows = array();

	public array $postRows = array();

	public array $userRows = array();

	public bool $failNextQuery = false;

	private array $optionIdsByName = array();

	private array $optionIndexById = array();

	private bool $optionIndexReady = false;

	private int $optionScanFrom = 0;

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->queries[] = $query;
		$sql             = (string) $query;

		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $sql, $matches ) ) {
			$name = stripcslashes( $matches[1] );
			return in_array( $name, $this->tables, true ) ? $name : null;
		}

		if ( str_starts_with( $sql, 'SELECT option_id FROM ' ) ) {
			$name = $this->between( $sql, "option_name = '", "' LIMIT 1" );
			if ( ! is_string( $name ) ) {
				throw new \RuntimeException( 'Unrecognized get_var SQL: ' . $sql );
			}
			$this->ensureOptionIndex();
			return isset( $this->optionIdsByName[ $name ] ) ? (string) $this->optionIdsByName[ $name ] : null;
		}

		if ( preg_match( '/^SELECT meta_id FROM ' . preg_quote( $this->postmeta, '/' ) . " WHERE post_id = (\d+) AND meta_key = '([^']*)' LIMIT 1$/", $sql, $matches ) ) {
			foreach ( $this->postRows as $row ) {
				if ( (int) $row['post_id'] === (int) $matches[1] && $row['meta_key'] === $matches[2] ) {
					return (string) $row['meta_id'];
				}
			}
			return null;
		}

		if ( preg_match( '/^SELECT umeta_id FROM ' . preg_quote( $this->usermeta, '/' ) . " WHERE user_id = (\d+) AND meta_key = '([^']*)' LIMIT 1$/", $sql, $matches ) ) {
			foreach ( $this->userRows as $row ) {
				if ( (int) $row['user_id'] === (int) $matches[1] && $row['meta_key'] === $matches[2] ) {
					return (string) $row['umeta_id'];
				}
			}
			return null;
		}

		throw new \RuntimeException( 'Unrecognized get_var SQL: ' . $sql );
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		$this->queries[] = $query;
		$sql             = (string) $query;

		if ( str_starts_with( $sql, 'SELECT option_id, option_name FROM ' ) ) {
			$after = $this->between( $sql, 'option_id > ', ' ORDER BY' );
			$limit = $this->between( $sql, 'LIMIT ', '' );
			if ( ! is_string( $after ) || ! is_string( $limit ) ) {
				throw new \RuntimeException( 'Unrecognized get_results SQL: ' . $sql );
			}
			return $this->nextRows( $this->optionsRows, 'name', 'option_id', 'option_name', (int) $after, (int) $limit, true );
		}

		if ( preg_match( '/^SELECT meta_id, post_id, meta_key FROM ' . preg_quote( $this->postmeta, '/' ) . " WHERE meta_key LIKE '([^']*)' AND meta_id > (\d+) ORDER BY meta_id ASC LIMIT (\d+)$/", $sql, $matches ) ) {
			return $this->nextMeta( $this->postRows, 'meta_id', 'post_id', (int) $matches[2], (int) $matches[3] );
		}

		if ( preg_match( '/^SELECT umeta_id, user_id, meta_key FROM ' . preg_quote( $this->usermeta, '/' ) . " WHERE meta_key LIKE '([^']*)' AND umeta_id > (\d+) ORDER BY umeta_id ASC LIMIT (\d+)$/", $sql, $matches ) ) {
			return $this->nextMeta( $this->userRows, 'umeta_id', 'user_id', (int) $matches[2], (int) $matches[3] );
		}

		throw new \RuntimeException( 'Unrecognized get_results SQL: ' . $sql );
	}

	public function query( string $query ) {
		$this->queries[] = $query;
		if ( $this->failNextQuery ) {
			$this->failNextQuery = false;
			return false;
		}

		if ( str_starts_with( $query, 'RENAME TABLE ' ) ) {
			return $this->applyRename( $query );
		}

		if ( str_starts_with( $query, 'UPDATE ' . $this->options . ' ' ) ) {
			$to   = $this->between( $query, "option_name = '", "' WHERE" );
			$id   = $this->between( $query, 'option_id = ', ' AND' );
			$from = $this->between( $query, "AND option_name = '", "'" );
			if ( ! is_string( $to ) || ! is_string( $id ) || ! is_string( $from ) ) {
				return false;
			}
			return $this->renameOption( (int) $id, $from, $to );
		}

		if ( preg_match( '/^UPDATE ' . preg_quote( $this->postmeta, '/' ) . " SET meta_key = '([^']*)' WHERE meta_id = (\d+) AND meta_key = '([^']*)'$/", $query, $matches ) ) {
			return $this->renameMeta( $this->postRows, 'meta_id', (int) $matches[2], $matches[3], $matches[1] );
		}

		if ( preg_match( '/^UPDATE ' . preg_quote( $this->usermeta, '/' ) . " SET meta_key = '([^']*)' WHERE umeta_id = (\d+) AND meta_key = '([^']*)'$/", $query, $matches ) ) {
			return $this->renameMeta( $this->userRows, 'umeta_id', (int) $matches[2], $matches[3], $matches[1] );
		}

		return false;
	}

	private function nextRows( array $rows, string $nameKey, string $idField, string $nameField, int $after, int $limit, bool $resume ): array {
		$out   = array();
		$count = count( $rows );
		$start = $resume ? $this->optionScanFrom : 0;
		for ( $index = $start; $index < $count; $index++ ) {
			$row = $rows[ $index ];
			if ( (int) $row['id'] <= $after || ! str_starts_with( (string) $row[ $nameKey ], 'ameverywhere_' ) ) {
				continue;
			}
			$out[] = (object) array(
				$idField   => (int) $row['id'],
				$nameField => (string) $row[ $nameKey ],
			);
			if ( count( $out ) >= $limit ) {
				if ( $resume ) {
					$this->optionScanFrom = $index + 1;
				}
				break;
			}
		}
		return $out;
	}

	private function between( string $sql, string $start, string $end ): ?string {
		$from = strpos( $sql, $start );
		if ( false === $from ) {
			return null;
		}
		$from += strlen( $start );
		if ( '' === $end ) {
			return substr( $sql, $from );
		}
		$to = strpos( $sql, $end, $from );
		if ( false === $to ) {
			return null;
		}
		return substr( $sql, $from, $to - $from );
	}

	private function nextMeta( array $rows, string $idKey, string $objectKey, int $after, int $limit ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) $row[ $idKey ] <= $after || ! str_starts_with( (string) $row['meta_key'], 'ameverywhere_' ) ) {
				continue;
			}
			$out[] = (object) array(
				$idKey     => (int) $row[ $idKey ],
				$objectKey => (int) $row[ $objectKey ],
				'meta_key' => (string) $row['meta_key'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	private function renameOption( int $id, string $from, string $to ): int|false {
		$this->ensureOptionIndex();
		if ( ! isset( $this->optionIndexById[ $id ] ) ) {
			return 0;
		}
		$index = $this->optionIndexById[ $id ];
		if ( $this->optionsRows[ $index ]['name'] !== $from ) {
			return 0;
		}
		$this->optionsRows[ $index ]['name'] = $to;
		unset( $this->optionIdsByName[ $from ] );
		$this->optionIdsByName[ $to ] = $id;
		return 1;
	}

	private function ensureOptionIndex(): void {
		if ( $this->optionIndexReady ) {
			return;
		}
		$this->optionIdsByName = array();
		$this->optionIndexById = array();
		foreach ( $this->optionsRows as $index => $row ) {
			$id                          = (int) $row['id'];
			$this->optionIdsByName[ $row['name'] ] = $id;
			$this->optionIndexById[ $id ]          = $index;
		}
		$this->optionIndexReady = true;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Meta rows, updated in place.
	 */
	private function renameMeta( array &$rows, string $idKey, int $id, string $from, string $to ): int|false {
		foreach ( $rows as $index => $row ) {
			if ( (int) $row[ $idKey ] === $id && $row['meta_key'] === $from ) {
				$rows[ $index ]['meta_key'] = $to;
				return 1;
			}
		}
		return 0;
	}

	private function applyRename( string $query ): bool {
		$rest = substr( $query, strlen( 'RENAME TABLE ' ) );
		$from = $this->readIdentifier( $rest );
		if ( null === $from || ! str_starts_with( substr( $rest, $from['length'] ), ' TO ' ) ) {
			return false;
		}
		$rest = substr( $rest, $from['length'] + 4 );
		$to   = $this->readIdentifier( $rest );
		if ( null === $to || strlen( $rest ) !== $to['length'] ) {
			return false;
		}

		$index = array_search( $from['name'], $this->tables, true );
		if ( false === $index || in_array( $to['name'], $this->tables, true ) ) {
			return false;
		}
		$this->tables[ $index ] = $to['name'];
		return true;
	}

	/**
	 * @return array{name:string, length:int}|null
	 */
	private function readIdentifier( string $sql ): ?array {
		if ( ! str_starts_with( $sql, '`' ) ) {
			return null;
		}

		$name   = '';
		$length = strlen( $sql );
		$index  = 1;
		while ( $index < $length ) {
			if ( '`' === $sql[ $index ] ) {
				if ( $index + 1 < $length && '`' === $sql[ $index + 1 ] ) {
					$name .= '`';
					$index += 2;
					continue;
				}
				return array(
					'name'   => $name,
					'length' => $index + 1,
				);
			}
			$name .= $sql[ $index ];
			++$index;
		}

		return null;
	}
}
