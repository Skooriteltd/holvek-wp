<?php

namespace Holvek\WordPress\Tests\Unit;

use Holvek\WordPress\Modules\Breadcrumbs\BreadcrumbRenderer;
use Holvek\WordPress\Modules\Schema\Types\BreadcrumbSchema;
use Holvek\WordPress\Modules\Schema\Types\CustomSchema;
use PHPUnit\Framework\TestCase;

class SchemaFixtureTest extends TestCase {

	private array $posts;

	private array $meta;

	protected function setUp(): void {
		parent::setUp();
		$this->posts = $GLOBALS['mock_posts'] ?? array();
		$this->meta  = $GLOBALS['mock_post_meta'] ?? array();
		$GLOBALS['mock_posts']     = $this->posts;
		$GLOBALS['mock_post_meta'] = $this->meta;
		unset( $GLOBALS['mock_has_post_thumbnail'], $GLOBALS['mock_thumbnail_url'], $GLOBALS['mock_queried_object'] );
		$GLOBALS['mock_is_singular']   = false;
		$GLOBALS['mock_is_front_page'] = false;
	}

	protected function tearDown(): void {
		$GLOBALS['mock_posts']     = $this->posts;
		$GLOBALS['mock_post_meta'] = $this->meta;
		unset(
			$GLOBALS['mock_filter_callbacks']['holvek_woocommerce_owns_product_schema'],
			$GLOBALS['mock_has_post_thumbnail'],
			$GLOBALS['mock_thumbnail_url'],
			$GLOBALS['mock_queried_object'],
			$GLOBALS['mock_wp_options']['holvek_breadcrumb_home_label']
		);
		$GLOBALS['mock_is_singular'] = false;
		parent::tearDown();
	}

	public function testFaqHowToRecipeEventAndLocalBusinessOmitEmptyRecommendedFields(): void {
		$this->post( 21, 'Guide' );
		update_post_meta( 21, '_holvek_primary_schema', 'faq' );
		update_post_meta(
			21,
			'_holvek_schema_faq_questions',
			wp_json_encode(
				array(
					array(
						'question' => 'What is this?',
						'answer'   => 'A guide.',
					),
					array(
						'question' => '',
						'answer'   => '',
					),
				)
			)
		);
		$faq = ( new CustomSchema() )->generate( 21 );
		$this->assertSame( 'FAQPage', $faq['@type'] );
		$this->assertCount( 1, $faq['mainEntity'] );
		$this->assertSame( 'What is this?', $faq['mainEntity'][0]['name'] );
		$this->assertSame( 'A guide.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
		$this->assertNoEmptyStrings( $faq );

		update_post_meta( 21, '_holvek_primary_schema', 'howto' );
		update_post_meta( 21, '_holvek_schema_howto_name', 'Boil water' );
		update_post_meta( 21, '_holvek_schema_howto_description', '' );
		update_post_meta( 21, '_holvek_schema_howto_supplies', ' , kettle' );
		update_post_meta(
			21,
			'_holvek_schema_howto_steps',
			wp_json_encode(
				array(
					array(
						'name' => '',
						'text' => 'Fill the kettle.',
					),
				)
			)
		);
		$howto = ( new CustomSchema() )->generate( 21 );
		$this->assertSame( 'HowTo', $howto['@type'] );
		$this->assertArrayHasKey( 'name', $howto );
		$this->assertArrayHasKey( 'step', $howto );
		$this->assertArrayNotHasKey( 'description', $howto );
		$this->assertSame( 'kettle', $howto['supply'][0]['name'] );
		$this->assertNoEmptyStrings( $howto );

		update_post_meta( 21, '_holvek_schema_howto_steps', '[]' );
		$this->assertSame( array(), ( new CustomSchema() )->generate( 21 ) );

		$GLOBALS['mock_has_post_thumbnail'] = true;
		$GLOBALS['mock_thumbnail_url']      = 'https://example.com/pie.jpg';
		update_post_meta( 21, '_holvek_primary_schema', 'recipe' );
		update_post_meta( 21, '_holvek_schema_recipe_name', 'Pie' );
		update_post_meta( 21, '_holvek_schema_recipe_description', '' );
		update_post_meta( 21, '_holvek_schema_recipe_ingredients', 'apples, , flour' );
		update_post_meta( 21, '_holvek_schema_recipe_calories', '' );
		update_post_meta( 21, '_holvek_schema_recipe_prep_time', '' );
		$recipe = ( new CustomSchema() )->generate( 21 );
		$this->assertSame( 'Recipe', $recipe['@type'] );
		$this->assertSame( 'https://example.com/pie.jpg', $recipe['image'] );
		$this->assertSame( array( 'apples', 'flour' ), $recipe['recipeIngredient'] );
		$this->assertArrayNotHasKey( 'description', $recipe );
		$this->assertArrayNotHasKey( 'nutrition', $recipe );
		$this->assertArrayNotHasKey( 'prepTime', $recipe );
		$this->assertNoEmptyStrings( $recipe );

		unset( $GLOBALS['mock_has_post_thumbnail'] );
		$this->assertSame( array(), ( new CustomSchema() )->generate( 21 ) );

		update_post_meta( 21, '_holvek_primary_schema', 'event' );
		update_post_meta( 21, '_holvek_schema_event_name', 'Launch' );
		update_post_meta( 21, '_holvek_schema_event_start_date', '2026-10-04 15:00:00' );
		update_post_meta( 21, '_holvek_schema_event_performer', '' );
		update_post_meta( 21, '_holvek_schema_event_price', '' );
		$event = ( new CustomSchema() )->generate( 21 );
		$this->assertSame( 'Event', $event['@type'] );
		$this->assertArrayHasKey( 'startDate', $event );
		$this->assertSame( 'VirtualLocation', $event['location']['@type'] );
		$this->assertArrayNotHasKey( 'performer', $event );
		$this->assertArrayNotHasKey( 'offers', $event );
		$this->assertArrayNotHasKey( 'image', $event );
		$this->assertNoEmptyStrings( $event );

		update_post_meta( 21, '_holvek_schema_event_start_date', '' );
		$this->assertSame( array(), ( new CustomSchema() )->generate( 21 ) );

		update_post_meta( 21, '_holvek_primary_schema', 'localbusiness' );
		update_post_meta( 21, '_holvek_schema_localbusiness_name', 'Holvek Shop' );
		update_post_meta( 21, '_holvek_schema_localbusiness_street', '1 High Street' );
		update_post_meta( 21, '_holvek_schema_localbusiness_city', 'Lagos' );
		update_post_meta( 21, '_holvek_schema_localbusiness_telephone', '' );
		update_post_meta( 21, '_holvek_schema_localbusiness_country', '' );
		$local = ( new CustomSchema() )->generate( 21 );
		$this->assertSame( 'LocalBusiness', $local['@type'] );
		$this->assertSame( '1 High Street', $local['address']['streetAddress'] );
		$this->assertSame( 'Lagos', $local['address']['addressLocality'] );
		$this->assertArrayNotHasKey( 'telephone', $local );
		$this->assertArrayNotHasKey( 'addressCountry', $local['address'] );
		$this->assertNoEmptyStrings( $local );
	}

	public function testProductSchemaSkipsAWooCommerceProductUrl(): void {
		$this->post( 22, 'Shoe', 'product' );
		update_post_meta( 22, '_holvek_primary_schema', 'product' );
		update_post_meta( 22, '_holvek_schema_product_name', 'Shoe' );
		update_post_meta( 22, '_holvek_schema_product_description', '' );
		update_post_meta( 22, '_holvek_schema_product_price', '19' );
		update_post_meta( 22, '_holvek_schema_product_currency', 'USD' );
		update_post_meta( 22, '_holvek_schema_product_rating', '' );

		$schema = ( new CustomSchema() )->generate( 22 );
		$this->assertSame( 'Product', $schema['@type'] );
		$this->assertSame( '19', $schema['offers']['price'] );
		$this->assertSame( 'USD', $schema['offers']['priceCurrency'] );
		$this->assertArrayNotHasKey( 'description', $schema );
		$this->assertArrayNotHasKey( 'aggregateRating', $schema );
		$this->assertNoEmptyStrings( $schema );

		add_filter(
			'holvek_woocommerce_owns_product_schema',
			static function () {
				return true;
			}
		);
		$this->assertSame( array(), ( new CustomSchema() )->generate( 22 ) );
	}

	public function testBreadcrumbSchemaUsesTheVisibleTrail(): void {
		$post = $this->post( 23, 'Guide' );
		update_option( 'holvek_breadcrumb_home_label', 'Start' );
		$GLOBALS['mock_is_singular']    = true;
		$GLOBALS['mock_queried_object'] = $post;

		$html   = ( new BreadcrumbRenderer() )->render();
		$schema = ( new BreadcrumbSchema() )->generate( 23 );
		$names  = array_column( $schema['itemListElement'], 'name' );

		$this->assertSame( array( 'Start', 'Guide' ), $names );
		$this->assertStringContainsString( 'Start', $html );
		$this->assertStringContainsString( 'Guide', $html );
		$this->assertStringContainsString( 'https://example.com/', $html );
		$this->assertSame( 'https://example.com/post-23/', $schema['itemListElement'][1]['item'] );
	}

	private function post( int $id, string $title, string $type = 'post' ): \WP_Post {
		$post                      = new \WP_Post(
			array(
				'ID'           => $id,
				'post_title'   => $title,
				'post_type'    => $type,
				'post_excerpt' => '',
				'post_status'  => 'publish',
			)
		);
		$GLOBALS['mock_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * @param array<mixed> $node
	 */
	private function assertNoEmptyStrings( array $node ): void {
		foreach ( $node as $value ) {
			$this->assertNotSame( '', $value );
			if ( is_array( $value ) ) {
				$this->assertNoEmptyStrings( $value );
			}
		}
	}
}
