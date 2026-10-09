<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Proves the ameverywhere rename left a single autoloadable namespace.
 *
 * Composer maps Holvek\WordPress\ to src/. Every class whose file name matches
 * its class name must load. holvek.php must reference that same class.
 */
class NamespaceBootTest extends TestCase {

	public function testEveryMatchingClassAutoloadsUnderHolvekWordPress(): void {
		$src = dirname( __DIR__, 2 ) . '/src';
		$loaded = array();
		$failures = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() );
			if ( ! preg_match( '/^namespace\s+([^;]+);/m', $contents, $namespaceMatch ) ) {
				$failures[] = $file->getPathname() . ' has no namespace';
				continue;
			}

			$namespace = trim( $namespaceMatch[1] );
			if ( ! str_starts_with( $namespace, 'Holvek\\WordPress' ) ) {
				$failures[] = $file->getPathname() . " uses {$namespace}";
				continue;
			}

			if ( ! preg_match( '/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $contents, $classMatch ) ) {
				continue;
			}

			$class = $classMatch[1];
			$fqcn  = $namespace . '\\' . $class;

			// PSR-4 requires the file name to match the class. The gateway file
			// still uses the old name and is covered by its own assertion.
			if ( $file->getBasename( '.php' ) !== $class ) {
				continue;
			}

			if ( ! class_exists( $fqcn ) ) {
				$failures[] = $fqcn . ' did not autoload from ' . $file->getFilename();
				continue;
			}

			$loaded[] = $fqcn;
		}

		$this->assertGreaterThan( 60, count( $loaded ), 'Expected the plugin class map to load.' );
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	public function testBootstrapReferencesTheAutoloadablePluginClass(): void {
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/holvek.php' );

		$this->assertStringContainsString( 'Holvek\\WordPress\\Plugin::getInstance()', $bootstrap );
		$this->assertStringContainsString( 'Holvek\\WordPress\\Plugin::class', $bootstrap );
		$this->assertDoesNotMatchRegularExpression( '/\\\\Holvek\\\\Plugin(?!\\\\)/', $bootstrap );
		$this->assertDoesNotMatchRegularExpression( '/\\\\Holvek\\\\(Core|Modules)\\\\/', $bootstrap );

		require_once dirname( __DIR__, 2 ) . '/holvek.php';

		$this->assertTrue( function_exists( 'holvek_init' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertInstanceOf( Plugin::class, Plugin::getInstance() );
		$this->assertSame( Plugin::getInstance(), Plugin::getInstance() );
	}

	public function testHolvekApiGatewayAutoloadsFromItsClassFilename(): void {
		$root = dirname( __DIR__, 2 );
		$this->assertFileDoesNotExist( $root . '/src/Core/Api/AmEveryWhereApiGateway.php' );
		$this->assertFileExists( $root . '/src/Core/Api/HolvekApiGateway.php' );
		$this->assertTrue( class_exists( \Holvek\WordPress\Core\Api\HolvekApiGateway::class ) );
	}

	public function testSourceAndTestsHaveNoLegacyNamespacePrefix(): void {
		$roots = array(
			dirname( __DIR__, 2 ) . '/src',
			dirname( __DIR__, 2 ) . '/tests',
			dirname( __DIR__, 2 ) . '/holvek.php',
		);
		$stale = array();

		foreach ( $roots as $root ) {
			$files = is_file( $root ) ? array( new \SplFileInfo( $root ) ) : $this->phpFiles( $root );
			foreach ( $files as $file ) {
				$contents = file_get_contents( $file->getPathname() );
				if ( preg_match( '/Holvek\\\\(?!WordPress\\\\)(Core|Modules|Plugin)\\\\/', $contents ) ) {
					$stale[] = $file->getPathname();
				}
			}
		}

		$this->assertSame( array(), $stale, "Legacy Holvek\\ prefix remains in:\n" . implode( "\n", $stale ) );
	}

	/**
	 * @return list<\SplFileInfo>
	 */
	private function phpFiles( string $directory ): array {
		$files = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file;
			}
		}
		return $files;
	}
}
