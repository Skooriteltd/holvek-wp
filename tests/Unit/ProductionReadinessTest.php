<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Api\HeadlessSeoEndpoints;
use Holvek\WordPress\Modules\ImageSeo\ImageCompressor;
use Holvek\WordPress\Modules\Indexing\GoogleIndexingApi;
use Holvek\WordPress\Modules\Indexing\IndexStatusChecker;
use PHPUnit\Framework\TestCase;

class ProductionReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $mock_wp_options, $mock_posts, $mock_post_meta;
        $mock_wp_options = [];
        $mock_posts = [];
        $mock_post_meta = [];
    }

    public function testPublicGlobalSeoPayloadNeverExposesAdminOrIndexnowSecrets(): void
    {
        update_option('admin_email', 'admin@example.com');
        update_option('holvek_indexnow_key', 'private-indexnow-key');

        $response = (new HeadlessSeoEndpoints())->getGlobalSeo(new \WP_REST_Request());
        $data = $response->get_data();

        $this->assertArrayNotHasKey('admin_email', $data);
        $this->assertArrayNotHasKey('indexnow_key', $data);
        $this->assertSame('Holvek Test Site', $data['site_name']);
    }

    public function testGoogleIndexingEligibilityRejectsOrdinaryContent(): void
    {
        $post = new \WP_Post([
            'ID' => 700,
            'post_title' => 'Ordinary article',
            'post_content' => '<p>Normal content.</p>',
            'post_status' => 'publish',
        ]);

        $this->assertFalse(GoogleIndexingApi::isEligiblePost($post));
    }

    public function testGoogleIndexingEligibilityAcceptsJobPostingSchema(): void
    {
        $post = new \WP_Post([
            'ID' => 701,
            'post_title' => 'Engineering role',
            'post_content' => '<script type="application/ld+json">{"@context":"https://schema.org","@type":"JobPosting","title":"Engineer"}</script>',
            'post_status' => 'publish',
        ]);

        $this->assertTrue(GoogleIndexingApi::isEligiblePost($post));
    }

    public function testImageCompressionIsDisabledUntilAcknowledged(): void
    {
        $compressor = new ImageCompressor();
        $this->assertFalse($compressor->getSettings()->get_data()['enabled']);

        $request = new \WP_REST_Request();
        $request->set_json_params(['enabled' => true, 'quality' => 80]);
        $this->assertInstanceOf(\WP_Error::class, $compressor->saveSettings($request));

        $request->set_json_params([
            'enabled' => true,
            'quality' => 80,
            'acknowledge_reversible_processing' => true,
        ]);
        $response = $compressor->saveSettings($request);
        $this->assertTrue($response->get_data()['config']['enabled']);
        $this->assertTrue($response->get_data()['config']['preserve']);
        $this->assertFalse($response->get_data()['config']['webp']);
    }

    public function testIndexStatusCheckRequiresAnAuthorizedPostObject(): void
    {
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        $request = new \WP_REST_Request();
        $request->set_json_params([]);

        $response = (new IndexStatusChecker())->checkStatus($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('missing_post_id', $response->get_error_code());
    }
}
