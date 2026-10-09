<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\ContentAssistant\ContentAssistantModule;
use PHPUnit\Framework\TestCase;

class EditorMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_registered_post_meta'] = array();
		$GLOBALS['mock_user_caps']            = array(
			'edit_posts' => true,
			'edit_post'  => array( 4 ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mock_registered_post_meta'], $GLOBALS['mock_user_caps'] );
		parent::tearDown();
	}

	public function testRestAuthUsesEditPostAndSanitizesOnTheRegistration(): void {
		$module = new ContentAssistantModule( new \Holvek\WordPress\Core\Event\EventManager() );
		$module->registerMetaFields();

		$post = $GLOBALS['mock_registered_post_meta']['post']['_holvek_meta_title'];
		$page = $GLOBALS['mock_registered_post_meta']['page']['_holvek_canonical_url'];
		$faq  = $GLOBALS['mock_registered_post_meta']['post']['_holvek_schema_faq_questions'];
		$robots = $GLOBALS['mock_registered_post_meta']['post']['_holvek_noindex'];

		$this->assertTrue( $post['auth_callback']( false, '_holvek_meta_title', 4 ) );
		$this->assertFalse( $post['auth_callback']( true, '_holvek_meta_title', 9 ) );
		$this->assertSame( 'Search title', $post['sanitize_callback']( '  <b>Search title</b>  ', '_holvek_meta_title', 'post' ) );
		$this->assertSame( 'A description', $GLOBALS['mock_registered_post_meta']['post']['_holvek_meta_description']['sanitize_callback']( '<i>A description</i>', '_holvek_meta_description', 'post' ) );

		$this->assertSame( 'https://example.com/preferred/', $page['sanitize_callback']( 'https://example.com/preferred/', '_holvek_canonical_url', 'page' ) );
		$this->assertSame( '', $page['sanitize_callback']( '', '_holvek_canonical_url', 'page' ) );
		$this->assertSame( '', $page['sanitize_callback']( 'javascript:alert(1)', '_holvek_canonical_url', 'page' ) );
		$this->assertSame( '', $page['sanitize_callback']( '//evil.example/path', '_holvek_canonical_url', 'page' ) );

		$this->assertSame( 'yes', $robots['sanitize_callback']( 'yes', '_holvek_noindex', 'post' ) );
		$this->assertSame( 'no', $robots['sanitize_callback']( 'index', '_holvek_noindex', 'post' ) );

		$stored = $faq['sanitize_callback'](
			'[{"question":"<b>What?</b>","answer":"A guide","extra":"no"},{"question":"","answer":""}]',
			'_holvek_schema_faq_questions',
			'post'
		);
		$this->assertSame( '[{"question":"What?","answer":"A guide"}]', $stored );
		$this->assertSame( '[]', $faq['sanitize_callback']( 'not-json', '_holvek_schema_faq_questions', 'post' ) );
	}
}
