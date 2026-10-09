<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Schema\JsonLd;
use Holvek\WordPress\Modules\Schema\SchemaGenerator;
use PHPUnit\Framework\TestCase;

class JsonLdSchemaTest extends TestCase {

	private SchemaGenerator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = new SchemaGenerator();

		global $mock_post_meta, $mock_posts, $mock_current_post_id, $mock_is_singular, $mock_is_admin;
		$mock_post_meta       = array();
		$mock_posts           = array();
		$mock_current_post_id = 0;
		$mock_is_singular     = false;
		$mock_is_admin        = false;
	}

	public function testScriptEncodingKeepsMarkupInsideTheJsonString(): void {
		$title = 'Café & Jerry\'s </script><script>alert(1)</script>';
		$html  = $this->renderPost( 80, $title, 'post' );
		$body  = $this->scriptBody( $html );

		$this->assertStringNotContainsString( '<', $body );
		$this->assertStringNotContainsString( '&', $body );
		$this->assertStringNotContainsString( '\'', $body );
		$this->assertStringContainsString( '\u003C/script\u003E', $body );
		$this->assertStringContainsString( 'Café', $body );

		$decoded = json_decode( $body, true );
		$article = $this->nodeOfType( $decoded['@graph'], 'Article' );
		$this->assertSame( $title, $article['headline'] );
	}

	public function testCustomPropertyBreakoutIsEncodedAndBadJsonIsLeftOut(): void {
		$postId = 81;
		$this->storePost( $postId, 'Safe title', 'page' );
		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_primary_schema']           = 'custom';
		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_custom_schema_properties'] = wp_json_encode(
			array(
				array(
					'key'   => 'brand',
					'value' => '</script><script>alert(1)</script>',
				),
			)
		);

		$html = $this->render( $postId );
		$body = $this->scriptBody( $html );
		$this->assertStringNotContainsString( '<', $body );
		$decoded = json_decode( $body, true );
		$custom  = $this->nodeOfType( $decoded['@graph'], 'Thing' );
		$this->assertSame( '</script><script>alert(1)</script>', $custom['brand'] );

		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_custom_schema_properties'] = '"just a string"';
		$rejected = json_decode( $this->scriptBody( $this->render( $postId ) ), true );
		$thing    = $this->nodeOfType( $rejected['@graph'], 'Thing' );
		$this->assertArrayNotHasKey( 'brand', $thing );
		$this->assertStringNotContainsString( 'just a string', $this->scriptBody( $this->render( $postId ) ) );

		$this->assertSame( 'invalid_custom_schema', JsonLd::sanitizeCustomMeta( '"just a string"' )->get_error_code() );
		$this->assertSame( 'invalid_custom_schema', JsonLd::sanitizeCustomMeta( '{"name":"Secret"}' )->get_error_code() );
		$this->assertSame( 'invalid_custom_schema', JsonLd::sanitizeCustomMeta( '[{"key":"</script>","value":"x"}]' )->get_error_code() );
		$this->assertSame( 'invalid_custom_schema', JsonLd::sanitizeCustomMeta( array( 'nope' ) )->get_error_code() );
		$this->assertIsString( JsonLd::sanitizeCustomMeta( '[]' ) );
	}

	public function testCustomJsonAcceptsANodeAnArrayAndAGraph(): void {
		$postId = 82;
		$this->storePost( $postId, 'Course page', 'page' );
		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_primary_schema']     = 'custom';
		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_schema_custom_type'] = '</script>';
		$GLOBALS['mock_post_meta'][ $postId ]['_holvek_custom_schema_properties'] = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type' => 'Course',
						'name'  => 'Safety',
					),
					array(
						'@type' => array( 'Person', 'Thing' ),
						'name'  => 'Ada',
					),
				),
			)
		);

		$decoded = json_decode( $this->scriptBody( $this->render( $postId ) ), true );
		$this->assertNotNull( $this->nodeOfType( $decoded['@graph'], 'Course' ) );
		$this->assertNotNull( $this->nodeOfType( $decoded['@graph'], array( 'Person', 'Thing' ) ) );
		$this->assertNull( $this->nodeOfType( $decoded['@graph'], 'Thing' ) );

		$single = JsonLd::parseCustomJson( '{"@type":"Course","name":"One"}' );
		$this->assertSame( 'nodes', $single['kind'] );
		$this->assertSame( 'Course', $single['nodes'][0]['@type'] );

		$badContext = JsonLd::parseCustomJson( '{"@type":"Course","@context":"javascript:alert(1)"}' );
		$this->assertSame( 'invalid_custom_schema', $badContext->get_error_code() );
	}

	public function testPrintScriptUsesTheSameEncoding(): void {
		ob_start();
		JsonLd::printScript(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Product',
				'name'     => '</script>',
			)
		);
		$html = (string) ob_get_clean();
		$body = $this->scriptBody( $html );
		$this->assertStringContainsString( '\u003C/script\u003E', $body );
		$this->assertSame( '</script>', json_decode( $body, true )['name'] );
	}

	private function renderPost( int $postId, string $title, string $type ): string {
		$this->storePost( $postId, $title, $type );
		return $this->render( $postId );
	}

	private function storePost( int $postId, string $title, string $type ): void {
		$GLOBALS['mock_posts'][ $postId ] = new \WP_Post(
			array(
				'ID'         => $postId,
				'post_title' => $title,
				'post_type'  => $type,
			)
		);
	}

	private function render( int $postId ): string {
		$GLOBALS['mock_is_singular']     = true;
		$GLOBALS['mock_is_admin']        = false;
		$GLOBALS['mock_current_post_id'] = $postId;
		ob_start();
		$this->generator->outputSchema();
		return (string) ob_get_clean();
	}

	private function scriptBody( string $html ): string {
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">\s*(.*?)\s*</script>#s', $html, $matches ) );
		return $matches[1];
	}

	/**
	 * @param array<int, array<string, mixed>> $graph
	 * @param string|array<int, string>        $type
	 * @return array<string, mixed>|null
	 */
	private function nodeOfType( array $graph, $type ): ?array {
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && ( $node['@type'] ?? null ) === $type ) {
				return $node;
			}
		}
		return null;
	}
}
