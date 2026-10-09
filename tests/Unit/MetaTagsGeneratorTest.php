<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\Seo\MetaTagsGenerator;

class MetaTagsGeneratorTest extends TestCase
{
    private MetaTagsGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new MetaTagsGenerator();

        global $mock_post_meta, $mock_posts, $mock_current_post_id;
        global $mock_is_singular, $mock_is_admin, $mock_is_front_page, $mock_is_home, $mock_is_404, $mock_is_search, $mock_is_paged, $mock_is_date;
        global $mock_wp_options;

        $mock_post_meta = [];
        $mock_posts = [];
        $mock_current_post_id = 0;
        $mock_wp_options = [];

        $mock_is_singular = false;
        $mock_is_admin = false;
        $mock_is_front_page = false;
        $mock_is_home = false;
        $mock_is_404 = false;
        $mock_is_search = false;
        $mock_is_paged = false;
        $mock_is_date = false;
    }

    public function testGetDocumentTitleNonSingular(): void
    {
        $title = $this->generator->getDocumentTitle();
        $this->assertSame('Holvek Test Site - AI Powered SEO Plugin', $title);
    }

    public function testGetDocumentTitleSingularDefault(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_posts;
        $mock_is_singular = true;
        $mock_current_post_id = 42;
        $mock_posts[42] = new \WP_Post([
            'ID' => 42,
            'post_title' => 'Sample Post Title',
        ]);

        $title = $this->generator->getDocumentTitle();
        $this->assertSame('Sample Post Title - Holvek Test Site', $title);
    }

    public function testGetDocumentTitleSingularCustomMeta(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_posts, $mock_post_meta;
        $mock_is_singular = true;
        $mock_current_post_id = 42;
        $mock_posts[42] = new \WP_Post([
            'ID' => 42,
            'post_title' => 'Original Post Title',
        ]);
        $mock_post_meta[42]['_holvek_meta_title'] = 'Custom AI SEO Optimized Title';

        $title = $this->generator->getDocumentTitle();
        $this->assertSame('Custom AI SEO Optimized Title', $title);
    }

    public function testRobotsMetaDirectives(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_post_meta;
        global $mock_is_404, $mock_is_search, $mock_is_paged, $mock_is_date;

        $ref = new \ReflectionClass(MetaTagsGenerator::class);
        $method = $ref->getMethod('getRobotsMeta');
        $method->setAccessible(true);

        // Default non-singular (archive/home)
        $this->assertSame('index, follow', $method->invoke($this->generator));

        // 404 page
        $mock_is_404 = true;
        $this->assertSame('noindex, follow', $method->invoke($this->generator));
        $mock_is_404 = false;

        // Search page
        $mock_is_search = true;
        $this->assertSame('noindex, follow', $method->invoke($this->generator));
        $mock_is_search = false;

        // Paged archive (page 2+)
        $mock_is_paged = true;
        $this->assertSame('noindex, follow', $method->invoke($this->generator));
        $mock_is_paged = false;

        // Date archive
        $mock_is_date = true;
        $this->assertSame('noindex, follow', $method->invoke($this->generator));
        $mock_is_date = false;

        // Singular default (index, follow)
        $mock_is_singular = true;
        $mock_current_post_id = 10;
        $this->assertSame('index, follow', $method->invoke($this->generator));

        // Singular noindex
        $mock_post_meta[10]['_holvek_noindex'] = 'yes';
        $this->assertSame('noindex, follow', $method->invoke($this->generator));

        // Singular noindex and nofollow
        $mock_post_meta[10]['_holvek_nofollow'] = 'yes';
        $this->assertSame('noindex, nofollow', $method->invoke($this->generator));
    }

    public function testCanonicalUrlResolution(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_post_meta, $mock_posts;
        global $mock_is_front_page, $mock_is_404, $mock_is_search;

        $ref = new \ReflectionClass(MetaTagsGenerator::class);
        $method = $ref->getMethod('getCanonicalUrl');
        $method->setAccessible(true);

        // Front page canonical
        $mock_is_front_page = true;
        $this->assertSame('https://example.com/', $method->invoke($this->generator));
        $mock_is_front_page = false;

        // 404 page suppresses canonical
        $mock_is_404 = true;
        $this->assertSame('', $method->invoke($this->generator));
        $mock_is_404 = false;

        // Search page suppresses canonical
        $mock_is_search = true;
        $this->assertSame('', $method->invoke($this->generator));
        $mock_is_search = false;

        // Singular standard permalink
        $mock_is_singular = true;
        $mock_current_post_id = 55;
        $mock_posts[55] = new \WP_Post(['ID' => 55, 'post_title' => 'Test Post']);
        $this->assertSame('https://example.com/post-55/', $method->invoke($this->generator));

        // Singular custom canonical override
        $mock_post_meta[55]['_holvek_canonical_url'] = 'https://example.com/preferred-url/';
        $this->assertSame('https://example.com/preferred-url/', $method->invoke($this->generator));

        // Singular noindex suppresses canonical
        $mock_post_meta[55]['_holvek_noindex'] = 'yes';
        $this->assertSame('', $method->invoke($this->generator));
    }

    public function testOutputStandardMetaTagsIncludesVerificationAndDescriptions(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_posts, $mock_post_meta;

        $mock_is_singular = true;
        $mock_current_post_id = 99;
        $mock_posts[99] = new \WP_Post([
            'ID' => 99,
            'post_title' => 'SEO Title',
            'post_excerpt' => 'This is a sample excerpt for description.',
        ]);

        update_option('holvek_google_verify', 'google-test-token-123');
        update_option('holvek_bing_verify', 'bing-test-token-456');

        ob_start();
        $this->generator->outputStandardMetaTags();
        $output = ob_get_clean();

        $this->assertStringContainsString('name="description" content="This is a sample excerpt for description."', $output);
        $this->assertStringContainsString('rel="canonical" href="https://example.com/post-99/"', $output);
        $this->assertStringContainsString('name="robots" content="index, follow"', $output);
        $this->assertStringContainsString('name="google-site-verification" content="google-test-token-123"', $output);
        $this->assertStringContainsString('name="msvalidate.01" content="bing-test-token-456"', $output);
    }
}
