<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\TechnicalSeo\RedirectManager;

class RedirectManagerTest extends TestCase
{
    private RedirectManager $manager;

    private \MockWpdb $db;

    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new \MockWpdb();
        $GLOBALS['wpdb'] = $this->db;
        $this->manager = new RedirectManager();
        unset(
            $GLOBALS['mock_wp_options']['holvek_redirect_counts'],
            $GLOBALS['mock_wp_options']['holvek_redirects'],
            $GLOBALS['mock_wpdb_var'],
            $GLOBALS['mock_wpdb_row'],
            $GLOBALS['mock_wpdb_insert'],
            $GLOBALS['mock_wpdb_delete'],
            $GLOBALS['mock_wpdb_results'],
            $GLOBALS['mock_redirect_throw']
        );
        $GLOBALS['mock_is_admin'] = false;
        $GLOBALS['mock_object_cache'] = array();
        $GLOBALS['mock_transients'] = array();
        $GLOBALS['mock_redirects'] = array();
        $_SERVER['REQUEST_URI'] = '/hello';
    }

    protected function tearDown(): void
    {
        $GLOBALS['mock_is_admin'] = false;
        unset(
            $GLOBALS['mock_wpdb_var'],
            $GLOBALS['mock_wpdb_row'],
            $GLOBALS['mock_wpdb_insert'],
            $GLOBALS['mock_wpdb_delete'],
            $GLOBALS['mock_wpdb_results'],
            $GLOBALS['mock_redirect_throw']
        );
        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        }
        parent::tearDown();
    }

    public function testNormalizePathWithVariousFormats(): void
    {
        $reflection = new \ReflectionClass(RedirectManager::class);
        $method = $reflection->getMethod('normalizePath');
        $method->setAccessible(true);

        // Relative paths
        $this->assertSame('/old-page', $method->invoke($this->manager, 'old-page'));
        $this->assertSame('/old-page', $method->invoke($this->manager, '/old-page/'));
        $this->assertSame('/category/sub', $method->invoke($this->manager, 'category/sub'));

        // Full URLs
        $this->assertSame('/about-us', $method->invoke($this->manager, 'https://example.com/about-us'));
        $this->assertSame('/about-us', $method->invoke($this->manager, 'http://example.com/about-us/'));
    }

    public function testRegexPatternValidation(): void
    {
        $validPattern = '^/blog/(\d{4})/(.*)$';
        $compiled = '/' . str_replace('/', '\\/', ltrim($validPattern, '/')) . '/i';
        
        $this->assertNotFalse(@preg_match($compiled, ''));
        $this->assertSame(1, preg_match($compiled, '/blog/2026/my-seo-post'));

        // Test replacement
        $target = '/articles/$2';
        $replaced = preg_replace($compiled, $target, '/blog/2026/my-seo-post');
        $this->assertSame('/articles/my-seo-post', $replaced);
    }

    public function testZeroCountsReturnBeforeAnyRedirectQuery(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 0, 'regex' => 0));
        unset($_SERVER['REQUEST_URI']);
        $before = ini_get('pcre.backtrack_limit');

        $this->manager->handleRedirects();

        $this->assertSame(array(), $this->db->queries);
        $this->assertSame(array(), $GLOBALS['mock_redirects']);
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    public function testMissingCountsAreCountedOnceFromTheTable(): void
    {
        $GLOBALS['mock_wpdb_var'] = static function ($query) {
            if (!is_string($query)) {
                return null;
            }
            if (str_contains($query, 'is_regex = 1')) {
                return '2';
            }
            if (str_contains($query, 'is_regex = 0')) {
                return '4';
            }
            return null;
        };

        $this->manager->handleRedirects();

        $this->assertSame(
            array('exact' => 4, 'regex' => 2),
            get_option('holvek_redirect_counts')
        );
        $this->assertNotSame(array(), array_filter(
            $this->db->queries,
            static function ($query) {
                return is_string($query) && str_contains($query, 'LIMIT 1');
            }
        ));

        $this->db->queries = array();
        $this->manager->handleRedirects();
        $countQueries = array_filter(
            $this->db->queries,
            static function ($query) {
                return is_string($query) && str_contains($query, 'COUNT(*)');
            }
        );
        $this->assertSame(array(), $countQueries);
    }

    public function testEmptyTableBecomesIdleOnTheNextRequest(): void
    {
        $this->manager->handleRedirects();
        $this->assertSame(
            array('exact' => 0, 'regex' => 0),
            get_option('holvek_redirect_counts')
        );

        $this->db->queries = array();
        $this->manager->handleRedirects();
        $this->assertSame(array(), $this->db->queries);
    }

    public function testExactMatchRedirectsAndDoesNotTouchTheBacktrackLimit(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 1, 'regex' => 0));
        $_SERVER['REQUEST_URI'] = '/old-page?utm=1';
        $GLOBALS['mock_wpdb_row'] = static function () {
            return (object) array(
                'target' => '/new-page',
                'code' => 301,
            );
        };
        $GLOBALS['mock_redirect_throw'] = true;
        $before = ini_get('pcre.backtrack_limit');

        try {
            $this->manager->handleRedirects();
            $this->fail('A matching exact redirect must leave the request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('redirect:https://example.com/new-page', $exception->getMessage());
        }

        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        $regexQueries = array_filter(
            $this->db->queries,
            static function ($query) {
                return is_string($query) && str_contains($query, 'is_regex = 1');
            }
        );
        $this->assertSame(array(), $regexQueries);
    }

    public function testRegexRulesAreCachedInTheTransientAndObjectCache(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 0, 'regex' => 1));
        $_SERVER['REQUEST_URI'] = '/hello';
        $GLOBALS['mock_wpdb_results'] = static function ($query) {
            if (is_string($query) && str_contains($query, 'is_regex = 1')) {
                return array(
                    (object) array(
                        'source' => '^/nope$',
                        'target' => '/elsewhere',
                        'code' => 302,
                    ),
                );
            }
            return array();
        };
        $before = ini_get('pcre.backtrack_limit');

        $this->manager->handleRedirects();

        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        $this->assertSame(array(), $GLOBALS['mock_redirects']);
        $this->assertCount(1, get_transient('holvek_regex_redirects'));
        $this->assertArrayHasKey('holvek_regex_redirects', $GLOBALS['mock_object_cache']['holvek']);

        $this->db->queries = array();
        unset($GLOBALS['mock_object_cache']['holvek']['holvek_regex_redirects']);
        $this->manager->handleRedirects();
        $this->assertSame(array(), $this->db->queries);
    }

    public function testCreateImportAndDeleteMaintainCounts(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 0, 'regex' => 0));
        set_transient('holvek_regex_redirects', array('stale'), HOUR_IN_SECONDS);

        $created = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
            'source' => '/old',
            'target' => '/new',
            'code' => 301,
        )));
        $this->assertInstanceOf(\WP_REST_Response::class, $created);
        $this->assertSame(array('exact' => 1, 'regex' => 0), get_option('holvek_redirect_counts'));
        $this->assertFalse(get_transient('holvek_regex_redirects'));

        $rejected = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
            'source' => '(',
            'target' => '/new',
            'is_regex' => true,
        )));
        $this->assertInstanceOf(\WP_Error::class, $rejected);
        $this->assertSame('invalid_regex', $rejected->get_error_code());
        $this->assertSame(array('exact' => 1, 'regex' => 0), get_option('holvek_redirect_counts'));

        $import = new \WP_REST_Request('POST', '/redirects/import-csv');
        $import->set_body("source,target,code,is_regex\n/from,/to,301,0\n^/news/(.*)$,/stories/$1,301,1\n(, /broken,301,1\n");
        $queriesBeforeImport = count($this->db->queries);
        $imported = $this->manager->importCsvEndpoint($import);
        $importQueries = array_slice($this->db->queries, $queriesBeforeImport);
        $this->assertInstanceOf(\WP_REST_Response::class, $imported);
        $this->assertSame(2, $imported->data['queued']);
        $this->assertSame(0, $imported->data['imported']);
        $this->assertSame(1, $imported->data['skipped']);
        $this->assertFalse($GLOBALS['mock_option_autoload']['holvek_redirect_import_queue']);
        $this->assertSame(array('exact' => 1, 'regex' => 0), get_option('holvek_redirect_counts'));
        $this->assertSame(array(), array_values(array_filter($importQueries, static function ($query) {
            return is_string($query) && str_starts_with($query, 'INSERT');
        })));

        (new \Holvek\WordPress\Modules\TechnicalSeo\RedirectImportJob(array()))->handle();
        $this->assertSame(array('exact' => 2, 'regex' => 1), get_option('holvek_redirect_counts'));
        $this->assertFalse(get_option('holvek_redirect_import_queue', false));

        $GLOBALS['mock_wpdb_row'] = static function () {
            return (object) array('is_regex' => 1);
        };
        $GLOBALS['mock_wpdb_delete'] = static function () {
            return 1;
        };
        $deleted = $this->manager->deleteRedirectEndpoint($this->jsonRequest(array('id' => 'rule-1')));
        $this->assertInstanceOf(\WP_REST_Response::class, $deleted);
        $this->assertSame(array('exact' => 2, 'regex' => 0), get_option('holvek_redirect_counts'));
    }

    public function testRegexPatternsAreCappedBeforeTheyAreSaved(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 0, 'regex' => 0));
        $before = ini_get('pcre.backtrack_limit');

        $long = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
            'source' => str_repeat('a', 181),
            'target' => '/new',
            'is_regex' => true,
        )));
        $this->assertSame('invalid_regex', $long->get_error_code());
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        $this->assertSame(array('exact' => 0, 'regex' => 0), get_option('holvek_redirect_counts'));

        $accepted = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
            'source' => '^/news/(.*)$',
            'target' => '/stories/$1',
            'is_regex' => true,
        )));
        $this->assertInstanceOf(\WP_REST_Response::class, $accepted);
        $this->assertSame(array('exact' => 0, 'regex' => 1), get_option('holvek_redirect_counts'));
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    public function testCsvImportWritesFiftyRowsAndQueuesTheRest(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 0, 'regex' => 0));
        $rows = array();
        for ($i = 0; $i < 51; ++$i) {
            $rows[] = array(
                'source' => '/from-' . $i,
                'target' => '/to-' . $i,
                'code' => 301,
                'is_regex' => 0,
            );
        }
        update_option('holvek_redirect_import_queue', $rows, false);
        $GLOBALS['mock_cron_events'] = array();

        $this->manager->importQueuedBatch();

        $this->assertSame(array('exact' => 50, 'regex' => 0), get_option('holvek_redirect_counts'));
        $this->assertCount(1, get_option('holvek_redirect_import_queue'));
        $this->assertFalse($GLOBALS['mock_option_autoload']['holvek_redirect_import_queue']);
        $this->assertNotEmpty($GLOBALS['mock_cron_events']);
    }

    public function testDeletingAMissingRedirectDoesNotChangeCounts(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 2, 'regex' => 1));
        $GLOBALS['mock_wpdb_delete'] = static function () {
            return 0;
        };

        $deleted = $this->manager->deleteRedirectEndpoint($this->jsonRequest(array('id' => 'missing')));

        $this->assertInstanceOf(\WP_REST_Response::class, $deleted);
        $this->assertSame(array('exact' => 2, 'regex' => 1), get_option('holvek_redirect_counts'));
    }

    public function testAdminRequestDoesNotReadRedirects(): void
    {
        update_option('holvek_redirect_counts', array('exact' => 3, 'regex' => 3));
        $GLOBALS['mock_is_admin'] = true;

        $this->manager->handleRedirects();

        $this->assertSame(array(), $this->db->queries);
    }

    public function testProtocolRelativeAndOffSiteTargetsAreRejected(): void
    {
        $rejected = array(
            array('//evil.example/phish', false),
            array('//evil.example/$1', true),
            array('https://evil.example/phish', false),
            array('javascript:alert(1)', false),
        );

        foreach ($rejected as $case) {
            $this->db->queries = array();
            $result = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
                'source' => '/old-page',
                'target' => $case[0],
                'code' => 301,
                'is_regex' => $case[1],
            )));

            $this->assertInstanceOf(\WP_Error::class, $result, $case[0]);
            $this->assertSame('unsafe_target', $result->get_error_code(), $case[0]);
            $this->assertSame(400, $result->get_error_data()['status'], $case[0]);
            $this->assertNotContains('INSERT wp_holvek_redirects', $this->db->queries, $case[0]);
        }

        foreach (array('/local-page', 'https://example.com/about') as $target) {
            $saved = $this->manager->addRedirectEndpoint($this->jsonRequest(array(
                'source' => '/from-' . md5($target),
                'target' => $target,
                'code' => 301,
            )));
            $this->assertInstanceOf(\WP_REST_Response::class, $saved, $target);
            $this->assertTrue($saved->get_data()['success'], $target);
        }
    }

    private function jsonRequest(array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/redirects');
        $request->set_json_params($params);
        return $request;
    }
}
