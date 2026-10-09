<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RestApiAccessTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		global $mock_rest_routes;
		$mock_rest_routes = array();
	}

	public function test_all_rest_routes_have_secure_permissions() {
		global $mock_rest_routes;

		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src/Modules'));
		$files = new \RegexIterator($iterator, '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);

		foreach ($files as $file) {
			$path = $file[0];
			$content = file_get_contents($path);
			
			if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) && preg_match('/class\s+([a-zA-Z0-9_]+)/', $content, $classMatch)) {
				$className = $nsMatch[1] . '\\' . $classMatch[1];
				
				if (class_exists($className)) {
					$reflection = new \ReflectionClass($className);
					if (!$reflection->isAbstract() && $reflection->hasMethod('registerRoutes')) {
						$module = $reflection->newInstanceWithoutConstructor();
						$module->registerRoutes();
					}
				}
			}
		}

		$this->assertNotEmpty( $mock_rest_routes, 'No REST routes were captured.' );

		$missing_callbacks = array();

		// Whitelist intentional public headless / machine-readable endpoints
		$whitelisted = array(
			'holvek/v1/schemamap',
			'holvek/v1/seo/(?P<post_id>\d+)',
			'holvek/v1/seo/global'
		);

		foreach ( $mock_rest_routes as $route => $args ) {
			if (in_array($route, $whitelisted, true)) {
				continue;
			}

			if (isset($args[0]) && is_array($args[0])) {
				foreach ($args as $endpoint) {
					if (empty($endpoint['permission_callback']) || $endpoint['permission_callback'] === '__return_true') {
						$missing_callbacks[] = $route . ' [' . ($endpoint['methods'] ?? 'UNKNOWN') . ']';
					}
				}
			} else {
				if (empty($args['permission_callback']) || $args['permission_callback'] === '__return_true') {
					$missing_callbacks[] = $route . ' [' . ($args['methods'] ?? 'UNKNOWN') . ']';
				}
			}
		}

		$this->assertEmpty( $missing_callbacks, 'The following REST routes lack secure permission_callbacks: ' . print_r($missing_callbacks, true) );
	}
}
