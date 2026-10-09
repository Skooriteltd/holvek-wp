<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\Migration\MigrationManager;

class MigrationTest extends TestCase
{
    public function testCompetitorPluginDetection(): void
    {
        $manager = new MigrationManager();
        $detected = $manager->detectPlugins();

        $this->assertIsArray($detected);
    }

    public function testUnknownPluginMigrationReturnsError(): void
    {
        $manager = new MigrationManager();
        $result = $manager->migrate('nonexistent_plugin');

        $this->assertFalse($result['success']);
        $this->assertSame('Unknown plugin.', $result['message']);
    }

    public function testPreviewCountsAndDoesNotWrite(): void
    {
        $GLOBALS['mock_post_meta'] = array();
        $GLOBALS['mock_wpdb_var'] = function (string $query) {
            if (str_contains($query, 'INNER JOIN')) {
                return 1;
            }
            return 2;
        };

        $result = (new MigrationManager())->handleMigrate($this->migrationRequest('yoast', false))->get_data();

        $this->assertFalse($result['wrote']);
        $this->assertSame(18, $result['fields']);
        $this->assertSame(9, $result['copy']);
        $this->assertSame(9, $result['skipped']);
        $this->assertStringContainsString('Deactivate Yoast SEO', $result['message']);
        $this->assertSame(array(), $GLOBALS['mock_post_meta']);
        unset($GLOBALS['mock_wpdb_var']);
    }

    public function testConfirmedImportSkipsExistingHolvekMeta(): void
    {
        $GLOBALS['mock_post_meta'] = array(
            7 => array('_holvek_meta_title' => 'Keep me'),
        );
        $GLOBALS['mock_wpdb_results'] = function (string $query) {
            if (!str_contains($query, '_yoast_wpseo_title')) {
                return array();
            }
            return array(
                (object) array('post_id' => 7, 'meta_value' => 'Replacement title'),
            );
        };

        $result = (new MigrationManager())->handleMigrate($this->migrationRequest('yoast', true))->get_data();

        $this->assertTrue($result['wrote']);
        $this->assertSame(50, $result['batch_size']);
        $this->assertSame('Keep me', get_post_meta(7, '_holvek_meta_title', true));
        $this->assertGreaterThan(0, $result['skipped']);
        $this->assertStringContainsString('Deactivate Yoast SEO', $result['message']);
        unset($GLOBALS['mock_wpdb_results']);
    }

    public function testRankMathRobotsAndFocusKeywordShapes(): void
    {
        $GLOBALS['mock_post_meta'] = array();
        $robots = serialize(array('noindex', 'nofollow'));
        $GLOBALS['mock_wpdb_results'] = function (string $query) use ($robots) {
            if (str_contains($query, 'rank_math_robots')) {
                return array(
                    (object) array('post_id' => 8, 'meta_value' => $robots),
                );
            }
            if (str_contains($query, 'rank_math_focus_keyword')) {
                return array(
                    (object) array('post_id' => 8, 'meta_value' => 'alpha, beta'),
                );
            }
            return array();
        };

        (new MigrationManager())->migrate('rankmath');

        $this->assertSame('yes', get_post_meta(8, '_holvek_noindex', true));
        $this->assertSame('yes', get_post_meta(8, '_holvek_nofollow', true));
        $this->assertSame('alpha', get_post_meta(8, '_holvek_focus_keyword', true));
        unset($GLOBALS['mock_wpdb_results']);
    }

    public function testReadmeDescribesTheImportPreview(): void
    {
        $sentence = 'The import shows how many fields it will copy and does not write until you confirm.';
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString($sentence, (string) file_get_contents($root . '/readme.txt'));
        $this->assertStringContainsString($sentence, (string) file_get_contents($root . '/docs/features.md'));
        $this->assertStringNotContainsString('The import does not show a preview.', (string) file_get_contents($root . '/readme.txt'));
    }

    private function migrationRequest(string $pluginId, bool $confirm): \WP_REST_Request
    {
        $request = new \WP_REST_Request();
        $request->set_json_params(array(
            'plugin_id' => $pluginId,
            'confirm' => $confirm,
        ));
        return $request;
    }
}
