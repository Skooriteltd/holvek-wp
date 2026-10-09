<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\Schema\SchemaGenerator;

class SchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SchemaGenerator();

        global $mock_post_meta, $mock_posts, $mock_current_post_id;
        global $mock_is_singular, $mock_is_admin, $mock_wp_options;

        $mock_post_meta = [];
        $mock_posts = [];
        $mock_current_post_id = 0;
        $mock_wp_options = [];
        $mock_is_singular = false;
        $mock_is_admin = false;
    }

    public function testGetEffectivePrimarySchemaLocalOverride(): void
    {
        global $mock_post_meta;
        $mock_post_meta[10]['_holvek_primary_schema'] = 'faq';

        $resolved = SchemaGenerator::getEffectivePrimarySchema(10);
        $this->assertSame('faq', $resolved);
    }

    public function testGetEffectivePrimarySchemaGlobalRules(): void
    {
        global $mock_posts, $mock_post_meta;

        $mock_posts[20] = new \WP_Post([
            'ID' => 20,
            'post_type' => 'post',
        ]);
        $mock_post_meta[20]['_holvek_primary_schema'] = ''; // No local override

        $rules = [
            [
                'post_type' => 'post',
                'category' => 'all',
                'schema_type' => 'article',
            ],
            [
                'post_type' => 'page',
                'category' => 'all',
                'schema_type' => 'localbusiness',
            ]
        ];
        update_option('holvek_global_schema_rules', json_encode($rules));

        $resolved = SchemaGenerator::getEffectivePrimarySchema(20);
        $this->assertSame('article', $resolved);
    }

    public function testGetSchemaForPostArticleAndBreadcrumbs(): void
    {
        global $mock_posts;

        $postId = 100;
        $mock_posts[$postId] = new \WP_Post([
            'ID' => $postId,
            'post_title' => 'Complete SEO Guide',
            'post_type' => 'post',
            'post_content' => 'Comprehensive guide to AI search optimization.',
            'post_excerpt' => 'Complete SEO Guide Excerpt',
            'post_author' => 1,
        ]);

        $schema = $this->generator->getSchemaForPost($postId);

        $this->assertIsArray($schema);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertNotEmpty($schema['@graph']);

        $types = array_column($schema['@graph'], '@type');
        $this->assertContains('Article', $types);
        $this->assertContains('BreadcrumbList', $types);

        // Verify Article fields
        $article = null;
        foreach ($schema['@graph'] as $node) {
            if ($node['@type'] === 'Article') {
                $article = $node;
                break;
            }
        }
        $this->assertNotNull($article);
        $this->assertSame('Complete SEO Guide', $article['headline']);
        $this->assertSame('https://example.com/post-100/#article', $article['@id']);
        $this->assertSame('Person', $article['author']['@type']);
        $this->assertSame('John Doe', $article['author']['name']);
    }

    public function testGetSchemaForPostWithFaqCustomSchema(): void
    {
        global $mock_posts, $mock_post_meta;

        $postId = 101;
        $mock_posts[$postId] = new \WP_Post([
            'ID' => $postId,
            'post_title' => 'FAQ Page',
            'post_type' => 'page',
            'post_content' => 'Questions and answers.',
        ]);

        // Enable FAQ schema on this post
        $mock_post_meta[$postId]['_holvek_primary_schema'] = 'faq';
        $faqs = [
            ['question' => 'What is Holvek?', 'answer' => 'An AI SEO WordPress plugin.'],
            ['question' => 'Does it support Schema.org?', 'answer' => 'Yes, comprehensive JSON-LD graphs.']
        ];
        $mock_post_meta[$postId]['_holvek_schema_faq_questions'] = json_encode($faqs);

        $schema = $this->generator->getSchemaForPost($postId);
        $this->assertNotEmpty($schema['@graph']);

        $faqNode = null;
        foreach ($schema['@graph'] as $node) {
            if ($node['@type'] === 'FAQPage') {
                $faqNode = $node;
                break;
            }
        }

        $this->assertNotNull($faqNode);
        $this->assertSame('https://example.com/post-101/#faq', $faqNode['@id']);
        $this->assertCount(2, $faqNode['mainEntity']);
        $this->assertSame('What is Holvek?', $faqNode['mainEntity'][0]['name']);
        $this->assertSame('An AI SEO WordPress plugin.', $faqNode['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testOutputSchemaRendersScriptTag(): void
    {
        global $mock_is_singular, $mock_current_post_id, $mock_posts;

        $mock_is_singular = true;
        $mock_current_post_id = 102;
        $mock_posts[102] = new \WP_Post([
            'ID' => 102,
            'post_title' => 'Singular Post For Output Test',
            'post_type' => 'post',
        ]);

        ob_start();
        $this->generator->outputSchema();
        $rendered = ob_get_clean();

        $this->assertStringContainsString('<script type="application/ld+json">', $rendered);
        $this->assertStringContainsString('https://schema.org', $rendered);
        $this->assertStringContainsString('BreadcrumbList', $rendered);
        $this->assertStringContainsString('<!-- /Holvek Schema -->', $rendered);
    }
}
