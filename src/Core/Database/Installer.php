<?php

namespace Holvek\WordPress\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates Holvek tables and rewrites legacy ameverywhere_* names once.
 *
 * Table renames run before dbDelta so a new empty holvek_* table cannot
 * block the rename of a table that still holds data.
 */
class Installer {

	public const MIGRATED_FROM_OPTION = 'holvek_migrated_from';

	public const COLLISION_OPTION = 'holvek_migration_collisions';

	public const SOURCE = 'ameverywhere';

	public const BATCH_SIZE = 100;

	public const MAX_BATCHES = 200;

	/**
	 * Run the schema installation.
	 */
	public function install(): void {
		global $wpdb;

		$charsetCollate = $wpdb->get_charset_collate();

		$tableRedirects       = $wpdb->prefix . 'holvek_redirects';
		$table404Logs         = $wpdb->prefix . 'holvek_404_logs';
		$tableSitemapSettings = $wpdb->prefix . 'holvek_sitemap_settings';
		$tableLinks           = $wpdb->prefix . 'holvek_links';

		// 1. Redirects table schema
		$sqlRedirects = "CREATE TABLE $tableRedirects (
            id varchar(50) NOT NULL,
            source text NOT NULL,
            target text NOT NULL,
            code int NOT NULL DEFAULT 301,
            is_regex tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY source (source(191)),
            KEY is_regex (is_regex)
        ) $charsetCollate;";

		// 2. 404 logs table schema
		$sql404Logs = "CREATE TABLE $table404Logs (
            id varchar(50) NOT NULL,
            uri varchar(255) NOT NULL,
            hits int NOT NULL DEFAULT 1,
            referer text DEFAULT NULL,
            user_agent text DEFAULT NULL,
            last_hit datetime NOT NULL,
            resolved tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY uri (uri(191))
        ) $charsetCollate;";

		// 3. Sitemap settings table schema
		$sqlSitemapSettings = "CREATE TABLE $tableSitemapSettings (
            setting_key varchar(100) NOT NULL,
            setting_value text NOT NULL,
            PRIMARY KEY  (setting_key)
        ) $charsetCollate;";

		// 4. Internal Link Index table
		$sqlLinks = "CREATE TABLE $tableLinks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            target_url varchar(255) NOT NULL,
            anchor_text varchar(255) DEFAULT '',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY target_url (target_url(191))
        ) $charsetCollate;";

		// Rename legacy storage before dbDelta creates the holvek_* tables.
		$this->migrateLegacyNames();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sqlRedirects );
		dbDelta( $sql404Logs );
		dbDelta( $sqlSitemapSettings );
		dbDelta( $sqlLinks );

		update_option( 'holvek_db_version', HOLVEK_VERSION, false );
	}

	/**
	 * Rewrite legacy names once.
	 *
	 * A finished pass records holvek_migrated_from even when some names were
	 * left in place because the holvek_* target already existed. A failed
	 * query, or a full batch still waiting after the batch cap, leaves the
	 * flag unset so the next install() continues. Activation calls install()
	 * on every activation. Ordinary requests do not.
	 */
	public function migrateLegacyNames(): void {
		if ( get_option( self::MIGRATED_FROM_OPTION, '' ) === self::SOURCE ) {
			return;
		}

		$collisions = array();
		$complete   = $this->renameLegacyTables( $collisions );
		$complete   = $this->renameLegacyRows( 'option', $collisions ) && $complete;
		$complete   = $this->renameLegacyRows( 'post', $collisions ) && $complete;
		$complete   = $this->renameLegacyRows( 'user', $collisions ) && $complete;

		$this->clearLegacyCronHooks();
		$this->storeCollisions( $collisions );

		if ( $complete ) {
			update_option( self::MIGRATED_FROM_OPTION, self::SOURCE, false );
		}
	}

	/**
	 * Decide which legacy rows can be renamed.
	 *
	 * A collision is per object: options use object id 0, post meta uses the
	 * post id, and user meta uses the user id. Another row on a different
	 * object may still be renamed. Two legacy rows on the same object that
	 * map to one target: the first is renamed and the second collides.
	 *
	 * @param array<int, array{id:int, object_id:int, key:string}> $legacyRows Rows in primary-key order.
	 * @param array<string, bool>                                 $occupied   Map of "objectId|targetKey" already stored.
	 * @return array{rename: array<int, array{id:int, object_id:int, from:string, to:string}>, collisions: array<int, array{id:int, object_id:int, from:string, to:string}>}
	 */
	public function planLegacyRenames( array $legacyRows, array $occupied ): array {
		$rename     = array();
		$collisions = array();
		$prefix     = self::SOURCE . '_';

		foreach ( $legacyRows as $row ) {
			$id       = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$objectId = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
			$key      = isset( $row['key'] ) ? (string) $row['key'] : '';
			if ( $id < 1 || ! str_starts_with( $key, $prefix ) ) {
				continue;
			}

			$target = 'holvek_' . substr( $key, strlen( $prefix ) );
			$slot   = $objectId . '|' . $target;
			$item   = array(
				'id'        => $id,
				'object_id' => $objectId,
				'from'      => $key,
				'to'        => $target,
			);

			if ( isset( $occupied[ $slot ] ) ) {
				$collisions[] = $item;
				continue;
			}

			$occupied[ $slot ] = true;
			$rename[]          = $item;
		}

		return array(
			'rename'     => $rename,
			'collisions' => $collisions,
		);
	}

	/**
	 * @param array<int, string> $collisions Collision labels collected for this pass.
	 */
	private function renameLegacyTables( array &$collisions ): bool {
		global $wpdb;

		$ok = true;
		foreach ( array( 'redirects', '404_logs', 'sitemap_settings', 'links' ) as $suffix ) {
			$oldTable = $wpdb->prefix . self::SOURCE . '_' . $suffix;
			$newTable = $wpdb->prefix . 'holvek_' . $suffix;
			if ( '' === $oldTable || '' === $newTable ) {
				$collisions[] = 'table:' . $oldTable;
				continue;
			}

			$oldExists = $this->tableExists( $oldTable );
			$newExists = $this->tableExists( $newTable );
			if ( null === $oldExists || null === $newExists ) {
				return false;
			}

			if ( $oldExists && $newExists ) {
				$collisions[] = 'table:' . $oldTable;
				continue;
			}

			if ( ! $oldExists ) {
				continue;
			}

			$renameSql = 'RENAME TABLE ' . $this->quotedIdentifier( $oldTable ) . ' TO ' . $this->quotedIdentifier( $newTable );
			// The suffix list is fixed and the names are backtick-quoted. A %s placeholder would quote the identifier.
			// esc_sql() does not change backticks, so the statement text stays the quoted rename.
			$renamed = $wpdb->query( esc_sql( $renameSql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $renamed ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * True when the table is present, false when it is absent, null on failure.
	 */
	private function tableExists( string $table ): ?bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);
		if ( false === $found ) {
			return null;
		}

		return $found === $table;
	}

	/**
	 * Quote a table name for RENAME TABLE. Backticks inside the name are doubled.
	 */
	private function quotedIdentifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Rename one storage kind in primary-key batches.
	 *
	 * Collisions in the batch are classified before any UPDATE, so a duplicate
	 * option_name cannot abort a multi-row statement halfway through.
	 *
	 * @param string             $kind        option, post, or user.
	 * @param array<int, string> $collisions  Collision labels for this pass.
	 */
	private function renameLegacyRows( string $kind, array &$collisions ): bool {
		$lastId = 0;
		$ok     = true;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$rows = $this->fetchLegacyBatch( $kind, $lastId );
			if ( false === $rows ) {
				return false;
			}
			if ( array() === $rows ) {
				return $ok;
			}

			$legacy   = array();
			$occupied = array();
			foreach ( $rows as $row ) {
				$rawId = $this->rawId( $kind, $row );
				if ( $rawId > $lastId ) {
					$lastId = $rawId;
				}

				$item = $this->legacyFromRow( $kind, $row );
				if ( null === $item ) {
					continue;
				}

				$exists = $this->targetExists( $kind, $item['object_id'], $item['to'] );
				if ( null === $exists ) {
					$ok = false;
					continue;
				}
				if ( $exists ) {
					$occupied[ $item['object_id'] . '|' . $item['to'] ] = true;
				}
				$legacy[] = $item;
			}

			$plan = $this->planLegacyRenames( $legacy, $occupied );
			foreach ( $plan['collisions'] as $collision ) {
				$collisions[] = $this->collisionLabel( $kind, $collision );
			}
			foreach ( $plan['rename'] as $rename ) {
				if ( ! $this->writeRename( $kind, $rename['id'], $rename['from'], $rename['to'] ) ) {
					$ok = false;
				}
			}

			if ( count( $rows ) < self::BATCH_SIZE ) {
				return $ok;
			}
		}

		return false;
	}

	/**
	 * @return array<int, object|array<string, mixed>>|false
	 */
	private function fetchLegacyBatch( string $kind, int $lastId ): array|false {
		global $wpdb;

		$like = $wpdb->esc_like( self::SOURCE . '_' ) . '%';
		if ( 'option' === $kind ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d",
					$like,
					$lastId,
					self::BATCH_SIZE
				)
			);
		} elseif ( 'post' === $kind ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$like,
					$lastId,
					self::BATCH_SIZE
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND umeta_id > %d ORDER BY umeta_id ASC LIMIT %d",
					$like,
					$lastId,
					self::BATCH_SIZE
				)
			);
		}

		if ( ! is_array( $rows ) ) {
			return false;
		}

		return $rows;
	}

	/**
	 * @param object|array<string, mixed> $row Database row.
	 */
	private function rawId( string $kind, object|array $row ): int {
		if ( 'option' === $kind ) {
			return (int) $this->field( $row, 'option_id' );
		}
		if ( 'post' === $kind ) {
			return (int) $this->field( $row, 'meta_id' );
		}
		return (int) $this->field( $row, 'umeta_id' );
	}

	/**
	 * @param object|array<string, mixed> $row Database row.
	 * @return array{id:int, object_id:int, key:string, to:string}|null
	 */
	private function legacyFromRow( string $kind, object|array $row ): ?array {
		$prefix = self::SOURCE . '_';
		if ( 'option' === $kind ) {
			$id       = (int) $this->field( $row, 'option_id' );
			$objectId = 0;
			$key      = $this->fieldString( $row, 'option_name' );
		} elseif ( 'post' === $kind ) {
			$id       = (int) $this->field( $row, 'meta_id' );
			$objectId = (int) $this->field( $row, 'post_id' );
			$key      = $this->fieldString( $row, 'meta_key' );
		} else {
			$id       = (int) $this->field( $row, 'umeta_id' );
			$objectId = (int) $this->field( $row, 'user_id' );
			$key      = $this->fieldString( $row, 'meta_key' );
		}

		if ( $id < 1 || ! str_starts_with( $key, $prefix ) ) {
			return null;
		}

		return array(
			'id'        => $id,
			'object_id' => $objectId,
			'key'       => $key,
			'to'        => 'holvek_' . substr( $key, strlen( $prefix ) ),
		);
	}

	/**
	 * True when the target name exists for this object, false when it does not, null on failure.
	 */
	private function targetExists( string $kind, int $objectId, string $target ): ?bool {
		global $wpdb;

		if ( 'option' === $kind ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$target
				)
			);
		} elseif ( 'post' === $kind ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
					$objectId,
					$target
				)
			);
		} else {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
					$objectId,
					$target
				)
			);
		}

		if ( false === $found ) {
			return null;
		}

		return null !== $found && '' !== $found;
	}

	/**
	 * @param array{id:int, object_id:int, from:string, to:string} $collision Planned collision.
	 */
	private function collisionLabel( string $kind, array $collision ): string {
		if ( 'option' === $kind ) {
			return 'option:' . $collision['from'];
		}
		if ( 'post' === $kind ) {
			return 'postmeta:' . $collision['object_id'] . ':' . $collision['from'];
		}
		return 'usermeta:' . $collision['object_id'] . ':' . $collision['from'];
	}

	private function writeRename( string $kind, int $id, string $from, string $to ): bool {
		global $wpdb;

		if ( 'option' === $kind ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_name = %s WHERE option_id = %d AND option_name = %s",
					$to,
					$id,
					$from
				)
			);
		} elseif ( 'post' === $kind ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_id = %d AND meta_key = %s",
					$to,
					$id,
					$from
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE umeta_id = %d AND meta_key = %s",
					$to,
					$id,
					$from
				)
			);
		}

		return false !== $result;
	}

	/**
	 * @param array<int, string> $collisions Collision labels collected for this pass.
	 */
	private function storeCollisions( array $collisions ): void {
		$collisions = array_values( array_unique( $collisions ) );
		if ( array() === $collisions ) {
			delete_option( self::COLLISION_OPTION );
			return;
		}

		update_option( self::COLLISION_OPTION, $collisions, false );
	}

	/**
	 * Drop scheduled events whose hook still uses the legacy prefix.
	 *
	 * Holvek hooks are left in place. The cron helpers are optional so a
	 * minimal runtime that does not expose them still finishes the rename.
	 */
	private function clearLegacyCronHooks(): void {
		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}

		$prefix = self::SOURCE . '_';
		foreach ( $crons as $cron ) {
			if ( ! is_array( $cron ) ) {
				continue;
			}
			foreach ( array_keys( $cron ) as $hook ) {
				if ( is_string( $hook ) && str_starts_with( $hook, $prefix ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}

	/**
	 * @param object|array<string, mixed> $row Database row.
	 */
	private function field( object|array $row, string $key ): mixed {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? null;
		}
		return $row->$key ?? null;
	}

	/**
	 * @param object|array<string, mixed> $row Database row.
	 */
	private function fieldString( object|array $row, string $key ): string {
		$value = $this->field( $row, $key );
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return '';
	}
}
