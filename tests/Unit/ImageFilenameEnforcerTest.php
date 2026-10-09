<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Holvek\WordPress\Modules\ImageSeo\ImageFilenameEnforcer;

class ImageFilenameEnforcerTest extends TestCase
{
    private ImageFilenameEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcer = new ImageFilenameEnforcer();

        global $mock_wp_options;
        $mock_wp_options = [];
    }

    public function testCleanFilenameBasicFormatting(): void
    {
        // Lowercase and hyphen substitution
        $this->assertSame('my-great-image', $this->enforcer->cleanFilename('My Great Image'));
        $this->assertSame('another-cool-photo', $this->enforcer->cleanFilename('Another__Cool...Photo'));
        $this->assertSame('seo-ready-graphic', $this->enforcer->cleanFilename('seo---ready---graphic'));
    }

    public function testCleanFilenameStripsGenericCameraAndScreenshotPrefixes(): void
    {
        $this->assertSame('sunset-over-lake', $this->enforcer->cleanFilename('IMG_1234_sunset-over-lake'));
        $this->assertSame('nature-walk', $this->enforcer->cleanFilename('DSC_5678-nature-walk'));
        $this->assertSame('dashboard-view', $this->enforcer->cleanFilename('screenshot-2026-dashboard-view'));
        $this->assertSame('mountain-peak', $this->enforcer->cleanFilename('PHOTO_99_mountain-peak'));
        $this->assertSame('document-scan', $this->enforcer->cleanFilename('SCAN-12-document-scan'));
    }

    public function testCleanFilenameRemovesSpecialCharactersAndStripsDimensions(): void
    {
        // Special chars
        $this->assertSame('modern-art-gallery-1', $this->enforcer->cleanFilename('Modern & Art @ Gallery #1!'));

        // WordPress dimension suffix stripping
        $this->assertSame('hero-banner', $this->enforcer->cleanFilename('hero-banner-1200x800'));
        $this->assertSame('thumbnail-preview', $this->enforcer->cleanFilename('thumbnail-preview-300x250'));
    }

    public function testCleanFilenameTransliteratesAccents(): void
    {
        // Accents transliterated to ASCII equivalents
        $cleaned = $this->enforcer->cleanFilename('crème-brûlée-café');
        $this->assertStringNotContainsString('è', $cleaned);
        $this->assertStringNotContainsString('û', $cleaned);
        $this->assertStringNotContainsString('é', $cleaned);
        $this->assertSame('creme-brulee-cafe', $cleaned);
    }

    public function testEnforceFilenameUploadPrefilter(): void
    {
        // Default enforcement with no prefix
        $file = [
            'name' => 'IMG_9999_summer_holiday_beach.JPG',
            'type' => 'image/jpeg',
            'size' => 102400,
        ];

        $filtered = $this->enforcer->enforceFilename($file);
        $this->assertSame('summer-holiday-beach.jpg', $filtered['name']);
    }

    public function testEnforceFilenameWithCustomPrefix(): void
    {
        update_option('holvek_filename_prefix', 'acme-corp');

        $file = [
            'name' => 'product-box-shot.png',
            'type' => 'image/png',
            'size' => 54321,
        ];

        $filtered = $this->enforcer->enforceFilename($file);
        $this->assertSame('acme-corp-product-box-shot.png', $filtered['name']);
    }

    public function testEnforceFilenameDisabledViaOption(): void
    {
        update_option('holvek_filename_enforce', 'no');

        $file = [
            'name' => 'IMG_0001_Raw_Unchanged.PNG',
            'type' => 'image/png',
            'size' => 12345,
        ];

        $filtered = $this->enforcer->enforceFilename($file);
        $this->assertSame('IMG_0001_Raw_Unchanged.PNG', $filtered['name']);
    }
}
