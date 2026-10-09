<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Analytics\MultisiteNetworkSeo;
use PHPUnit\Framework\TestCase;

class MultisiteLifecycleTest extends TestCase {

	private array $saved = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved = array(
			'options'  => $GLOBALS['mock_wp_options'] ?? array(),
			'autoload' => $GLOBALS['mock_option_autoload'] ?? array(),
			'cron'     => $GLOBALS['mock_recurring_events'] ?? array(),
			'events'   => $GLOBALS['mock_cron_events'] ?? array(),
			'cleared'  => $GLOBALS['mock_cleared_hooks'] ?? array(),
			'hooks'    => $GLOBALS['mock_hooks'] ?? array(),
			'caps'     => $GLOBALS['mock_user_caps'] ?? null,
			'prefix'   => $GLOBALS['wpdb']->prefix ?? 'wp_',
			'delta'    => $GLOBALS['mock_db_delta'] ?? array(),
			'flush'    => $GLOBALS['mock_flush_count'] ?? 0,
			'rewrite'  => $GLOBALS['mock_rewrite_rules'] ?? array(),
		);
		$GLOBALS['mock_is_multisite']     = false;
		$GLOBALS['mock_current_blog_id']  = 1;
		$GLOBALS['mock_switched_blogs']   = array();
		$GLOBALS['mock_blog_stack']       = array();
		$GLOBALS['mock_sites']            = array();
		$GLOBALS['mock_sites_calls']      = 0;
		$GLOBALS['mock_site_options']     = array();
		$GLOBALS['mock_role_events']      = array();
		$GLOBALS['mock_roles']            = array();
		$GLOBALS['mock_db_delta']         = array();
		$GLOBALS['wpdb']->prefix          = 'wp_';
	}

	protected function tearDown(): void {
		$GLOBALS['mock_wp_options']         = $this->saved['options'];
		$GLOBALS['mock_option_autoload']    = $this->saved['autoload'];
		$GLOBALS['mock_recurring_events']   = $this->saved['cron'];
		$GLOBALS['mock_cron_events']        = $this->saved['events'];
		$GLOBALS['mock_cleared_hooks']      = $this->saved['cleared'];
		$GLOBALS['mock_hooks']              = $this->saved['hooks'];
		$GLOBALS['mock_db_delta']           = $this->saved['delta'];
		$GLOBALS['mock_flush_count']        = $this->saved['flush'];
		$GLOBALS['mock_rewrite_rules']      = $this->saved['rewrite'];
		$GLOBALS['wpdb']->prefix            = $this->saved['prefix'];
		if ( null === $this->saved['caps'] ) {
			unset( $GLOBALS['mock_user_caps'] );
		} else {
			$GLOBALS['mock_user_caps'] = $this->saved['caps'];
		}
		unset(
			$GLOBALS['mock_is_multisite'],
			$GLOBALS['mock_current_blog_id'],
			$GLOBALS['mock_switched_blogs'],
			$GLOBALS['mock_blog_stack'],
			$GLOBALS['mock_sites'],
			$GLOBALS['mock_sites_calls'],
			$GLOBALS['mock_site_options'],
			$GLOBALS['mock_role_events'],
			$GLOBALS['mock_roles']
		);
		$this->removeBackupTree();
		$this->removeUpgradeStub();
		parent::tearDown();
	}

	public function testNewNetworkSiteGetsTablesAndCapabilities(): void {
		$this->markNetworkActive();
		$this->stubWordPressUpgrade();
		$GLOBALS['mock_is_multisite'] = true;

		( new MultisiteNetworkSeo() )->initializeNewSite( (object) array( 'blog_id' => 4 ) );

		$created = implode( "\n", array_map( 'strval', $GLOBALS['mock_db_delta'] ) );
		$this->assertStringContainsString( 'wp_4_holvek_redirects', $created );
		$this->assertTrue( $this->roleEventOnBlog( 'add', 'manage_seo', 4 ) );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( 'wp_', $GLOBALS['wpdb']->prefix );
	}

	public function testNewSiteIsLeftAloneWhenThePluginIsNotNetworkActive(): void {
		$GLOBALS['mock_is_multisite'] = true;

		( new MultisiteNetworkSeo() )->initializeNewSite( (object) array( 'blog_id' => 4 ) );

		$this->assertSame( array(), $GLOBALS['mock_db_delta'] );
		$this->assertSame( array(), $GLOBALS['mock_switched_blogs'] );
		$this->assertFalse( $this->roleEventOnBlog( 'add', 'manage_seo', 4 ) );
	}

	public function testInitializeHookIsRegisteredOnlyOnMultisite(): void {
		$GLOBALS['mock_hooks']        = array();
		$GLOBALS['mock_is_multisite'] = false;
		( new MultisiteNetworkSeo() )->register();
		$this->assertNotContains( 'wp_initialize_site', $this->hookNames() );

		$GLOBALS['mock_hooks']        = array();
		$GLOBALS['mock_is_multisite'] = true;
		( new MultisiteNetworkSeo() )->register();
		$this->assertContains( 'wp_initialize_site', $this->hookNames() );
	}

	public function testNetworkSiteListRequiresManageNetworkOptions(): void {
		$GLOBALS['mock_is_multisite'] = true;
		$GLOBALS['mock_user_caps']['manage_network_options'] = false;
		$GLOBALS['mock_sites'] = array(
			(object) array(
				'blog_id' => 3,
				'domain'  => 'a.test',
				'path'    => '/',
			),
		);
		$network = new MultisiteNetworkSeo();

		$denied = $network->getNetworkSites( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'rest_forbidden', $denied->get_error_code() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );
		$this->assertSame( 0, $GLOBALS['mock_sites_calls'] );
		$this->assertSame( array(), $GLOBALS['mock_switched_blogs'] );

		$saved = array( 'untouched' => true );
		$GLOBALS['mock_site_options']['holvek_network_seo_settings'] = $saved;
		$write = $network->saveNetworkSettings( new \WP_REST_Request() );
		$this->assertSame( 'rest_forbidden', $write->get_error_code() );
		$this->assertSame( $saved, $GLOBALS['mock_site_options']['holvek_network_seo_settings'] );

		$GLOBALS['mock_user_caps']['manage_network_options'] = true;
		$allowed = $network->getNetworkSites( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $allowed );
		$this->assertSame( 3, $allowed->get_data()['sites'][0]['blog_id'] );
		$this->assertSame( array( 3 ), $GLOBALS['mock_switched_blogs'] );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function testUninstallCleansEverySiteAndKeepsDataWhenAsked(): void {
		$keep = $this->runUninstall( 'no' );
		$this->assertFalse( $this->queriesContain( $keep['queries'], 'DROP TABLE' ) );
		$this->assertFalse( $this->queriesContain( $keep['queries'], 'wp_usermeta' ) );
		$this->assertFalse( $this->queriesContain( $keep['queries'], 'wp_sitemeta' ) );
		$this->assertTrue( $keep['backup_exists'] );
		$this->assertContains( 1, $keep['blogs'] );
		$this->assertContains( 2, $keep['blogs'] );
		$this->assertSame( 32, $keep['removals'] );
		$this->assertContains( 'holvek_404_cleanup', $keep['cleared'] );
		$this->assertContains( 'holvek_indexnow_flush', $keep['cleared'] );

		$drop = $this->runUninstall( 'yes' );
		$this->assertTrue( $this->queriesContain( $drop['queries'], 'DROP TABLE IF EXISTS `wp_holvek_redirects`' ) );
		$this->assertTrue( $this->queriesContain( $drop['queries'], 'DROP TABLE IF EXISTS `wp_2_holvek_redirects`' ) );
		$this->assertTrue( $this->queriesContain( $drop['queries'], 'wp_usermeta' ) );
		$this->assertTrue( $this->queriesContain( $drop['queries'], 'wp_sitemeta' ) );
		$this->assertFalse( $drop['backup_exists'] );
		$this->assertContains( 1, $drop['blogs'] );
		$this->assertContains( 2, $drop['blogs'] );
		$this->assertSame( 32, $drop['removals'] );
	}

	private bool $createdUpgrade = false;

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

	private function markNetworkActive(): void {
		if ( ! defined( 'HOLVEK_PLUGIN_FILE' ) ) {
			define( 'HOLVEK_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/holvek.php' );
		}
		$GLOBALS['mock_site_options']['active_sitewide_plugins'] = array(
			plugin_basename( HOLVEK_PLUGIN_FILE ) => 1,
		);
	}

	private function roleEventOnBlog( string $action, string $cap, int $blogId ): bool {
		foreach ( $GLOBALS['mock_role_events'] as $event ) {
			if ( ( $event['action'] ?? '' ) === $action && ( $event['cap'] ?? '' ) === $cap && (int) ( $event['blog'] ?? 0 ) === $blogId ) {
				return true;
			}
		}
		return false;
	}

	private function hookNames(): array {
		$names = array();
		foreach ( $GLOBALS['mock_hooks'] as $hook ) {
			$names[] = $hook[1];
		}
		return $names;
	}

	private function queriesContain( array $queries, string $needle ): bool {
		foreach ( $queries as $query ) {
			if ( is_string( $query ) && str_contains( $query, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function runUninstall( string $mode ): array {
		if ( ! in_array( $mode, array( 'yes', 'no' ), true ) ) {
			$this->fail( 'Unexpected uninstall mode.' );
		}
		$this->removeBackupTree();
		$root   = dirname( __DIR__, 2 );
		$script = tempnam( sys_get_temp_dir(), 'holvek-uninstall-' );
		if ( ! is_string( $script ) ) {
			$this->fail( 'Could not create the uninstall script.' );
		}
		$phpScript = $script . '.php';
		rename( $script, $phpScript );
		file_put_contents(
			$phpScript,
			"<?php\nrequire " . var_export( $root . '/tests/bootstrap.php', true ) . ";\n"
			. "define('WP_UNINSTALL_PLUGIN', true);\n"
			. '$mode = $argv[1] ?? \'no\';' . "\n"
			. "\$GLOBALS['mock_is_multisite'] = true;\n"
			. "\$GLOBALS['mock_sites'] = array(1, 2);\n"
			. "\$GLOBALS['mock_current_blog_id'] = 1;\n"
			. "\$GLOBALS['mock_wp_options']['holvek_delete_data_on_uninstall'] = \$mode;\n"
			. "\$GLOBALS['mock_role_events'] = array();\n"
			. "\$GLOBALS['mock_cleared_hooks'] = array();\n"
			. "\$content = WP_CONTENT_DIR;\n"
			. "if (!is_dir(\$content) && !mkdir(\$content, 0777, true) && !is_dir(\$content)) { fwrite(STDERR, \"content mkdir failed\\n\"); exit(1); }\n"
			. "\$backup = \$content . '/holvek-backups/htaccess';\n"
			. "if (!is_dir(\$backup) && !mkdir(\$backup, 0777, true) && !is_dir(\$backup)) { fwrite(STDERR, \"backup mkdir failed\\n\"); exit(1); }\n"
			. "file_put_contents(\$backup . '/old.txt', 'secret');\n"
			. 'require ' . var_export( $root . '/uninstall.php', true ) . ";\n"
			. "\$blogs = array();\n"
			. "\$removals = 0;\n"
			. "foreach ((\$GLOBALS['mock_role_events'] ?? array()) as \$event) {\n"
			. "    if ((\$event['action'] ?? '') !== 'remove') { continue; }\n"
			. "    \$removals++;\n"
			. "    \$blogs[] = (int) \$event['blog'];\n"
			. "}\n"
			. "echo json_encode(array(\n"
			. "    'queries' => \$GLOBALS['wpdb']->queries,\n"
			. "    'blogs' => array_values(array_unique(\$blogs)),\n"
			. "    'removals' => \$removals,\n"
			. "    'cleared' => \$GLOBALS['mock_cleared_hooks'] ?? array(),\n"
			. "    'backup_exists' => is_dir(\$content . '/holvek-backups'),\n"
			. "));\n"
		);

		$command = array( PHP_BINARY, '-d', 'display_errors=stderr', $phpScript, $mode );
		$pipes   = array();
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) {
			unlink( $phpScript );
			$this->fail( 'Could not start the uninstall process.' );
		}
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		unlink( $phpScript );
		if ( 0 !== $code ) {
			$this->fail( 'Uninstall process failed: ' . $stderr . $stdout );
		}
		$decoded = json_decode( (string) $stdout, true );
		if ( ! is_array( $decoded ) ) {
			$this->fail( 'Uninstall process returned invalid JSON: ' . $stdout . $stderr );
		}
		return $decoded;
	}

	private function removeBackupTree(): void {
		$root = dirname( __DIR__, 2 ) . '/wp-content/holvek-backups';
		if ( ! is_dir( $root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
				continue;
			}
			unlink( $item->getPathname() );
		}
		rmdir( $root );
		$content = dirname( $root );
		$left    = scandir( $content );
		if ( is_array( $left ) && array( '.', '..' ) === $left ) {
			rmdir( $content );
		}
	}
}
