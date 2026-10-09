<?php

namespace Holvek\WordPress\Tests\Unit;

use PHPUnit\Framework\TestCase;

class FeatureCopyTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
	}

	public function testTheReadmeFeaturesDocAndAdminUseTheSameFeatureNames(): void {
		$readme   = $this->read( 'readme.txt' );
		$features = $this->read( 'docs/features.md' );
		$app      = $this->read( 'src/assets/js/App.js' );
		$phrases  = array(
			'Google Analytics 4',
			'Link Health',
			'Technical SEO',
			'XML Sitemaps',
			'Schema Markup',
			'llms.txt',
			'Bulk Meta Editor',
			'HTML Sitemap',
			'Image SEO',
			'cookie notice',
			'Roles & Capabilities',
			'Technical SEO Audit',
			'Broken Link Checker',
			'Keyword Rank Tracker',
			'Google Search Console',
			'IndexNow',
			'Redirects Manager',
			'The writing assistant uses an OpenAI, Anthropic, or Ollama key saved on this site.',
			'Holvek publishes an llms.txt file on this site. It does not submit the site to ChatGPT, Perplexity, or Claude, and it does not check whether they mention it.',
			'It does not score AI mentions, Google presence, or competitors.',
			'A Holvek account is optional.',
			'The settings screen cannot change the Holvek API host.',
		);

		foreach ( $phrases as $phrase ) {
			$this->assertStringContainsString( $phrase, $readme, $phrase );
			$this->assertStringContainsString( $phrase, $features, $phrase );
			$this->assertStringContainsString( $phrase, $app, $phrase );
		}
	}

	public function testPageSpeedStaysInTheEditorCopy(): void {
		$this->assertStringContainsString( 'PageSpeed', $this->read( 'readme.txt' ) );
		$this->assertStringContainsString( 'PageSpeed', $this->read( 'docs/features.md' ) );
		$this->assertStringContainsString( 'PageSpeed Performance', $this->read( 'src/assets/js/editor.js' ) );
	}

	public function testTheShortDescriptionMatchesThePluginHeader(): void {
		$sentence = 'Technical SEO, structured data, sitemaps, redirects, and indexing tools for WordPress.';

		$this->assertStringContainsString( '=== Holvek ===', $this->read( 'readme.txt' ) );
		$this->assertStringContainsString( $sentence, $this->read( 'readme.txt' ) );
		$this->assertStringContainsString( $sentence, $this->read( 'docs/features.md' ) );
		$this->assertStringContainsString( $sentence, $this->read( 'src/assets/js/App.js' ) );
		$this->assertStringContainsString( 'Description: ' . $sentence, $this->read( 'holvek.php' ) );
		$this->assertStringContainsString( '"description": "' . $sentence . '"', $this->read( 'composer.json' ) );
	}

	public function testTheReadmeStatesThePhpRequirementAndTheExternalServices(): void {
		$readme = $this->read( 'readme.txt' );

		$this->assertStringContainsString( 'Requires PHP: 8.2', $readme );
		$this->assertStringContainsString( 'Requires PHP: 8.2', $this->read( 'holvek.php' ) );
		$this->assertStringContainsString( '"php": ">=8.2"', $this->read( 'composer.json' ) );
		$this->assertStringContainsString( 'Tested up to: 7.1', $readme );
		$this->assertStringContainsString( 'nothing is sent until an administrator turns the feature on', $readme );
		$this->assertStringContainsString( 'https://api.holvek.com', $readme );
		$this->assertStringNotContainsString( 'guaranteed indexing', $readme );
		$this->assertStringNotContainsString( 'ranking improvement', $readme );
		$this->assertStringNotContainsString( '== Screenshots ==', $readme );
	}

	public function testUserFacingCopyDoesNotClaimADiscoveryProduct(): void {
		$files     = array(
			'readme.txt',
			'docs/features.md',
			'src/assets/js/App.js',
			'src/assets/js/editor.js',
			'holvek.php',
			'composer.json',
		);
		$forbidden = array(
			'Discovery Scan',
			'Discovery Intelligence',
			'technical discovery score',
			'AI mention check',
			'competitor comparison',
			'SEO & Discovery',
			'llms-full.txt',
			'AI-First',
			'AEO',
		);

		foreach ( $files as $file ) {
			$contents = $this->read( $file );
			foreach ( $forbidden as $phrase ) {
				$this->assertStringNotContainsString( $phrase, $contents, $file . ' contains ' . $phrase );
			}
		}
	}

	private function read( string $path ): string {
		$contents = file_get_contents( $this->root . '/' . $path );
		$this->assertIsString( $contents, $path );

		return $contents;
	}
}
