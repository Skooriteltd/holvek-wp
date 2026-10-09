<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\TechnicalSeo\RobotsTxtEditor;

class RobotsTxtEditorTest extends TestCase
{
    private RobotsTxtEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = new RobotsTxtEditor();

        global $mock_wp_options;
        $mock_wp_options = [];
    }

    public function testGetMergedBotListDefault(): void
    {
        $bots = $this->editor->getMergedBotList();
        $this->assertIsArray($bots);
        $this->assertContains('GPTBot', $bots);
        $this->assertContains('ClaudeBot', $bots);
        $this->assertContains('Google-Extended', $bots);
        $this->assertContains('PerplexityBot', $bots);
    }

    public function testGetMergedBotListWithCustomBots(): void
    {
        update_option('holvek_custom_ai_bots', ['CustomBot-1', 'DeepSeek-Crawler']);

        $bots = $this->editor->getMergedBotList();
        $this->assertContains('CustomBot-1', $bots);
        $this->assertContains('DeepSeek-Crawler', $bots);
        $this->assertContains('GPTBot', $bots); // Built-in remains
    }

    public function testUpdateAiBotsSanitizationAndValidation(): void
    {
        $request = new \WP_REST_Request('POST', '/holvek/v1/ai-bots');
        $request->set_json_params([
            'custom_bots' => [
                'ValidBot-1',
                'invalid bot with spaces',
                'valid_underscore_bot.v2',
                'injection<script>',
            ]
        ]);

        $response = $this->editor->updateAiBots($request);
        $data = $response->get_data();

        $this->assertTrue($data['success']);
        $this->assertContains('ValidBot-1', $data['merged']);
        $this->assertContains('valid_underscore_bot.v2', $data['merged']);
        $this->assertNotContains('invalid bot with spaces', $data['merged']);
        $this->assertNotContains('injection<script>', $data['merged']);
    }

    public function testFilterRobotsTxtKeepsOtherPluginsAndAppendsSitemaps(): void
    {
        $incoming = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.com/wp-sitemap.xml\n# Other Plugin\nDisallow: /other-private/\n";

        $output = $this->editor->filterRobotsTxt($incoming, true);

        $this->assertStringContainsString('Sitemap: https://example.com/wp-sitemap.xml', $output);
        $this->assertStringContainsString('# Other Plugin', $output);
        $this->assertStringContainsString('Disallow: /other-private/', $output);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $output);
        $this->assertStringContainsString('Sitemap: https://example.com/news-sitemap.xml', $output);
        $this->assertStringNotContainsString('Block AI Crawlers', $output);
        $this->assertSame(1, substr_count($output, 'Sitemap: https://example.com/sitemap.xml'));
    }

    public function testExistingHolvekSitemapLineIsNotRepeated(): void
    {
        $incoming = "User-agent: *\nSitemap: https://example.com/sitemap.xml\n";

        $output = $this->editor->filterRobotsTxt($incoming, true);

        $this->assertSame(1, substr_count($output, 'Sitemap: https://example.com/sitemap.xml'));
        $this->assertStringContainsString('Sitemap: https://example.com/news-sitemap.xml', $output);
    }

    public function testPrivateSiteDoesNotAdvertiseSitemaps(): void
    {
        $incoming = "User-agent: *\nDisallow: /\n";

        $output = $this->editor->filterRobotsTxt($incoming, false);

        $this->assertStringContainsString('Disallow: /', $output);
        $this->assertStringNotContainsString('Sitemap:', $output);
    }

    public function testFilterRobotsTxtWithAiBlocking(): void
    {
        update_option('holvek_block_ai_bots', 'yes');
        update_option('holvek_custom_ai_bots', ['MyTestCrawler']);

        $output = $this->editor->filterRobotsTxt('', true);

        $this->assertStringContainsString('# Block AI Crawlers and LLM Bots', $output);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /", $output);
        $this->assertStringContainsString("User-agent: ClaudeBot\nDisallow: /", $output);
        $this->assertStringContainsString("User-agent: MyTestCrawler\nDisallow: /", $output);
    }

    public function testFilterRobotsTxtWithAiTrainingOptOut(): void
    {
        update_option('holvek_ai_training_optout', 'yes');

        $output = $this->editor->filterRobotsTxt('', true);

        $this->assertStringContainsString('# AI Training Data Opt-Out (Holvek)', $output);
        $this->assertStringContainsString('NoAI: 1', $output);
        $this->assertStringContainsString('NoImageAI: 1', $output);
    }

    public function testSavedRobotsWithoutAcknowledgementDoesNotReplace(): void
    {
        update_option('holvek_robots_txt', "User-agent: *\nDisallow: /private/\n");
        $incoming = "User-agent: *\nDisallow: /wp-admin/\n# Kept by another plugin\n";

        $output = $this->editor->filterRobotsTxt($incoming, true);

        $this->assertStringContainsString('# Kept by another plugin', $output);
        $this->assertStringNotContainsString('Disallow: /private/', $output);
    }

    public function testAcknowledgedCustomRobotsReplacesGeneratedFile(): void
    {
        update_option('holvek_robots_txt', "User-agent: *\nDisallow: /private/\nAllow: /public/\n");
        update_option('holvek_robots_txt_replaces_generated', 'yes');

        $output = $this->editor->filterRobotsTxt("User-agent: *\n# Core sitemap line\n", true);

        $this->assertStringContainsString('Disallow: /private/', $output);
        $this->assertStringContainsString('Allow: /public/', $output);
        $this->assertStringNotContainsString('# Core sitemap line', $output);
        $this->assertStringNotContainsString('Sitemap: https://example.com/sitemap.xml', $output);
    }

    public function testAcknowledgementWithoutContentDoesNotReplace(): void
    {
        update_option('holvek_robots_txt_replaces_generated', 'yes');
        $incoming = "# Still generated\n";

        $output = $this->editor->filterRobotsTxt($incoming, true);

        $this->assertStringContainsString('# Still generated', $output);
    }

    public function testUpdateRejectsReplacementUntilRulesExist(): void
    {
        update_option('holvek_robots_txt', "User-agent: *\nDisallow: /kept/\n");

        $request = new \WP_REST_Request('POST', '/holvek/v1/robots-txt');
        $request->set_json_params([
            'content' => '   ',
            'replace_generated' => true,
        ]);

        $result = $this->editor->updateRobotsTxt($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('robots_replace_empty', $result->get_error_code());
        $this->assertSame("User-agent: *\nDisallow: /kept/\n", get_option('holvek_robots_txt', ''));
        $this->assertNotSame('yes', get_option('holvek_robots_txt_replaces_generated', 'no'));
    }

    public function testUpdateAcknowledgedDocumentReplacesOnTheNextRequest(): void
    {
        $request = new \WP_REST_Request('POST', '/holvek/v1/robots-txt');
        $request->set_json_params([
            'content' => "User-agent: *\nDisallow: /private/\n",
            'replace_generated' => true,
            'block_ai_bots' => false,
        ]);

        $result = $this->editor->updateRobotsTxt($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertTrue($result->get_data()['replace_generated']);

        $output = $this->editor->filterRobotsTxt("# From WordPress\n", true);
        $this->assertStringContainsString('Disallow: /private/', $output);
        $this->assertStringNotContainsString('# From WordPress', $output);
    }

    public function testOmgiliMatchesItsOwnTokenOnly(): void
    {
        $this->assertTrue($this->editor->userAgentMatchesAiBot('Mozilla/5.0 (compatible; Omgili/1.0; +http://www.omgili.com/)'));
        $this->assertTrue($this->editor->userAgentMatchesAiBot('omgili/1.0'));
        $this->assertTrue($this->editor->userAgentMatchesAiBot('Mozilla/5.0 (compatible; Omgilibot/0.1)'));
        $this->assertFalse($this->editor->userAgentMatchesAiBot('Mozilla/5.0 (compatible; NotOmgiliBrowser/1.0)'));
        $this->assertFalse($this->editor->userAgentMatchesAiBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
    }

    public function testProactiveBlockStaysOffByDefaultAndIgnoresNormalBrowsers(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Omgili/1.0)';
        ob_start();
        $this->editor->proactiveBlockAiBots();
        $disabled = ob_get_clean();
        $this->assertSame('', $disabled);

        update_option('holvek_block_ai_bots', 'yes');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; NotOmgiliBrowser/1.0) Chrome/120.0.0.0';
        ob_start();
        $this->editor->proactiveBlockAiBots();
        $browser = ob_get_clean();
        $this->assertSame('', $browser);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testBlockedResponseIsTranslatableAndDoesNotClaimEnglishForOtherLocales(): void
    {
        $GLOBALS['mock_blogname'] = 'Acme <script>alert(1)</script>';
        $GLOBALS['mock_locale'] = 'en_US';

        $english = $this->editor->blockedResponseHtml();
        $this->assertStringContainsString('lang="en-US"', $english);
        $this->assertStringContainsString('Access Denied', $english);
        $this->assertStringContainsString('Acme &lt;script&gt;alert(1)&lt;/script&gt;', $english);
        $this->assertStringNotContainsString('<script>alert', $english);

        $GLOBALS['mock_locale'] = 'fr_FR';
        $french = $this->editor->blockedResponseHtml();
        $this->assertStringNotContainsString('lang=', $french);
        $this->assertStringContainsString('<html>', $french);

        unset($GLOBALS['mock_blogname'], $GLOBALS['mock_locale']);
    }
}
