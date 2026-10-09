<?php

namespace Holvek\WordPress\Modules\Schema\Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Modules\Schema\JsonLd;

/**
 * Generates custom point-and-click schemas (Product, FAQ, HowTo, LocalBusiness, Recipe, Event)
 * based on post-level metadata.
 */
class CustomSchema {

	/**
	 * Determines if a custom schema is configured for the current post.
	 */
	public function isApplicable( ?int $postId = null ): bool {
		$id = $postId !== null ? $postId : get_the_ID();
		if ( ! $id ) {
			return false;
		}

		$schemaType = \Holvek\WordPress\Modules\Schema\SchemaGenerator::getEffectivePrimarySchema( $id );
		return ! empty( $schemaType ) && $schemaType !== 'none';
	}

	/**
	 * Generates the schema payload.
	 */
	public function generate( ?int $postId = null ): array {
		$id = $postId !== null ? $postId : get_the_ID();
		if ( ! $id ) {
			return array();
		}

		$schemaType = \Holvek\WordPress\Modules\Schema\SchemaGenerator::getEffectivePrimarySchema( $id );
		$schema     = array();

		switch ( $schemaType ) {
			case 'product':
				$schema = $this->generateProductSchema( $id );
				break;
			case 'faq':
				$schema = $this->generateFaqSchema( $id );
				break;
			case 'howto':
				$schema = $this->generateHowToSchema( $id );
				break;
			case 'localbusiness':
				$schema = $this->generateLocalBusinessSchema( $id );
				break;
			case 'recipe':
				$schema = $this->generateRecipeSchema( $id );
				break;
			case 'event':
				$schema = $this->generateEventSchema( $id );
				break;
			case 'custom':
				$parsed = $this->parsedCustomJson( $id );
				if ( is_array( $parsed ) && 'nodes' === ( $parsed['kind'] ?? '' ) ) {
					return $parsed['nodes'];
				}
				$schema = array(
					'@type' => $this->customType( $id ),
					'@id'   => get_permalink( $id ) . '#custom',
				);
				break;
			default:
				$schema = array();
				break;
		}

		if ( ! empty( $schema ) ) {
			$schema = $this->mergeCustomProperties( $id, $schema );
		}

		return $schema;
	}

	/**
	 * Product Schema.
	 */
	private function generateProductSchema( int $postId ): array {
		if ( $this->wooCommerceOwnsProduct( $postId ) ) {
			return array();
		}

		$name     = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_name', true ), get_the_title( $postId ) );
		$desc     = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_description', true ), wp_strip_all_tags( get_the_excerpt( $postId ) ) );
		$price    = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_price', true ) );
		$currency = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_currency', true ), 'USD' );
		$rating   = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_rating', true ) );
		$stock    = $this->textValue( get_post_meta( $postId, '_holvek_schema_product_availability', true ), 'InStock' );

		if ( $name === '' || ( $price === '' && $rating === '' ) ) {
			return array();
		}

		$schema = array(
			'@type' => 'Product',
			'@id'   => get_permalink( $postId ) . '#product',
			'name'  => $name,
			'url'   => get_permalink( $postId ),
		);
		$schema = $this->putText( $schema, 'description', $desc );
		$image  = $this->thumbnailUrl( $postId );
		if ( $image !== '' ) {
			$schema['image'] = $image;
		}

		if ( $price !== '' && $currency !== '' ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
				'availability'  => 'https://schema.org/' . $stock,
				'url'           => get_permalink( $postId ),
			);
		}

		if ( $rating !== '' ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $rating,
				'bestRating'  => 5,
				'ratingCount' => 1,
			);
		}

		return $schema;
	}

	/**
	 * WooCommerce prints its own Product node on a product URL. A second Product node is not added.
	 */
	private function wooCommerceOwnsProduct( int $postId ): bool {
		$post = get_post( $postId );
		if ( ! $post || ( $post->post_type ?? '' ) !== 'product' ) {
			return false;
		}

		$active = class_exists( '\WC_Product' ) || function_exists( 'wc_get_product' );
		return (bool) apply_filters( 'holvek_woocommerce_owns_product_schema', $active, $postId );
	}

	/**
	 * FAQ Schema.
	 */
	private function generateFaqSchema( int $postId ): array {
		$faqsJson = get_post_meta( $postId, '_holvek_schema_faq_questions', true );
		$faqs     = ! empty( $faqsJson ) ? json_decode( $faqsJson, true ) : array();

		if ( empty( $faqs ) || ! is_array( $faqs ) ) {
			return array();
		}

		$mainEntities = array();
		foreach ( $faqs as $item ) {
			if ( empty( $item['question'] ) || empty( $item['answer'] ) ) {
				continue;
			}
			$mainEntities[] = array(
				'@type'          => 'Question',
				'name'           => $item['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['answer'],
				),
			);
		}

		if ( empty( $mainEntities ) ) {
			return array();
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $postId ) . '#faq',
			'mainEntity' => $mainEntities,
		);
	}

	/**
	 * HowTo Schema.
	 */
	private function generateHowToSchema( int $postId ): array {
		$name = $this->textValue( get_post_meta( $postId, '_holvek_schema_howto_name', true ), get_the_title( $postId ) );
		$desc = $this->textValue( get_post_meta( $postId, '_holvek_schema_howto_description', true ), wp_strip_all_tags( get_the_excerpt( $postId ) ) );
		if ( $name === '' ) {
			return array();
		}

		$stepsJson = get_post_meta( $postId, '_holvek_schema_howto_steps', true );
		$steps     = ! empty( $stepsJson ) ? json_decode( $stepsJson, true ) : array();

		$suppliesText = get_post_meta( $postId, '_holvek_schema_howto_supplies', true );
		$toolsText    = get_post_meta( $postId, '_holvek_schema_howto_tools', true );

		$schema = array(
			'@type' => 'HowTo',
			'@id'   => get_permalink( $postId ) . '#howto',
			'name'  => $name,
		);
		$schema = $this->putText( $schema, 'description', $desc );

		if ( ! empty( $steps ) && is_array( $steps ) ) {
			$stepElements = array();
			foreach ( $steps as $idx => $step ) {
				if ( empty( $step['text'] ) ) {
					continue;
				}
				$stepElements[] = array(
					'@type' => 'HowToStep',
					'name'  => isset( $step['name'] ) && $step['name'] !== '' ? $step['name'] : ( 'Step ' . ( $idx + 1 ) ),
					'text'  => $step['text'],
					'url'   => get_permalink( $postId ) . '#step-' . ( $idx + 1 ),
				);
			}
			if ( ! empty( $stepElements ) ) {
				$schema['step'] = $stepElements;
			}
		}

		if ( empty( $schema['step'] ) ) {
			return array();
		}

		$supplies = $this->namedThings( (string) $suppliesText, 'HowToSupply' );
		if ( array() !== $supplies ) {
			$schema['supply'] = $supplies;
		}
		$tools = $this->namedThings( (string) $toolsText, 'HowToTool' );
		if ( array() !== $tools ) {
			$schema['tool'] = $tools;
		}

		return $schema;
	}

	/**
	 * LocalBusiness Schema.
	 */
	private function generateLocalBusinessSchema( int $postId ): array {
		$name      = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_name', true ), get_bloginfo( 'name' ) );
		$telephone = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_telephone', true ) );
		$street    = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_street', true ) );
		$city      = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_city', true ) );
		$postal    = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_postal', true ) );
		$country   = $this->textValue( get_post_meta( $postId, '_holvek_schema_localbusiness_country', true ) );

		if ( $name === '' || ( $street === '' && $city === '' ) ) {
			return array();
		}

		$schema = array(
			'@type' => 'LocalBusiness',
			'@id'   => get_permalink( $postId ) . '#localbusiness',
			'name'  => $name,
			'url'   => get_permalink( $postId ),
		);
		$schema = $this->putText( $schema, 'telephone', $telephone );
		$image  = $this->thumbnailUrl( $postId );
		if ( $image !== '' ) {
			$schema['image'] = $image;
		}

		$address           = array( '@type' => 'PostalAddress' );
		$address           = $this->putText( $address, 'streetAddress', $street );
		$address           = $this->putText( $address, 'addressLocality', $city );
		$address           = $this->putText( $address, 'postalCode', $postal );
		$address           = $this->putText( $address, 'addressCountry', $country );
		$schema['address'] = $address;

		return $schema;
	}

	/**
	 * Recipe Schema.
	 */
	private function generateRecipeSchema( int $postId ): array {
		$name  = $this->textValue( get_post_meta( $postId, '_holvek_schema_recipe_name', true ), get_the_title( $postId ) );
		$desc  = $this->textValue( get_post_meta( $postId, '_holvek_schema_recipe_description', true ), wp_strip_all_tags( get_the_excerpt( $postId ) ) );
		$image = $this->thumbnailUrl( $postId );
		if ( $name === '' || $image === '' ) {
			return array();
		}

		$ingredientsText = get_post_meta( $postId, '_holvek_schema_recipe_ingredients', true );
		$ingredients     = array();
		if ( ! empty( $ingredientsText ) ) {
			// Can be JSON or comma-separated
			$decoded = json_decode( $ingredientsText, true );
			if ( is_array( $decoded ) ) {
				$ingredients = $decoded;
			} else {
				$ingredients = array_map( 'trim', explode( ',', $ingredientsText ) );
			}
		}
		$ingredients = array_values(
			array_filter(
				$ingredients,
				static function ( $item ): bool {
					return is_string( $item ) && trim( $item ) !== '';
				}
			)
		);

		$instructionsJson = get_post_meta( $postId, '_holvek_schema_recipe_instructions', true );
		$instructions     = ! empty( $instructionsJson ) ? json_decode( $instructionsJson, true ) : array();

		$prepTimeRaw = get_post_meta( $postId, '_holvek_schema_recipe_prep_time', true );
		$cookTimeRaw = get_post_meta( $postId, '_holvek_schema_recipe_cook_time', true );
		$calories    = get_post_meta( $postId, '_holvek_schema_recipe_calories', true );

		$schema = array(
			'@type' => 'Recipe',
			'@id'   => get_permalink( $postId ) . '#recipe',
			'name'  => $name,
			'image' => $image,
			'url'   => get_permalink( $postId ),
		);
		$schema = $this->putText( $schema, 'description', $desc );

		if ( ! empty( $ingredients ) ) {
			$schema['recipeIngredient'] = $ingredients;
		}

		if ( ! empty( $instructions ) && is_array( $instructions ) ) {
			$instructionElements = array();
			foreach ( $instructions as $idx => $step ) {
				if ( empty( $step['text'] ) ) {
					continue;
				}
				$instructionElements[] = array(
					'@type' => 'HowToStep',
					'name'  => isset( $step['name'] ) && $step['name'] !== '' ? $step['name'] : ( 'Step ' . ( $idx + 1 ) ),
					'text'  => $step['text'],
				);
			}
			if ( ! empty( $instructionElements ) ) {
				$schema['recipeInstructions'] = $instructionElements;
			}
		}

		$prepIso = $this->convertToIsoDuration( $prepTimeRaw );
		if ( $prepIso ) {
			$schema['prepTime'] = $prepIso;
		}

		$cookIso = $this->convertToIsoDuration( $cookTimeRaw );
		if ( $cookIso ) {
			$schema['cookTime'] = $cookIso;
		}

		if ( ! empty( $calories ) ) {
			$schema['nutrition'] = array(
				'@type'    => 'NutritionInformation',
				'calories' => $calories . ' calories',
			);
		}

		return $schema;
	}

	/**
	 * Event Schema.
	 */
	private function generateEventSchema( int $postId ): array {
		$name          = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_name', true ), get_the_title( $postId ) );
		$startDate     = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_start_date', true ) );
		$endDate       = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_end_date', true ) );
		$venue         = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_venue', true ), 'Online' );
		$address       = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_address', true ) );
		$performerName = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_performer', true ) );
		$price         = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_price', true ) );
		$currency      = $this->textValue( get_post_meta( $postId, '_holvek_schema_event_currency', true ), 'USD' );

		if ( $name === '' || $startDate === '' ) {
			return array();
		}

		$schema = array(
			'@type'               => 'Event',
			'@id'                 => get_permalink( $postId ) . '#event',
			'name'                => $name,
			'url'                 => get_permalink( $postId ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => $address === '' ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
		);

		$image = $this->thumbnailUrl( $postId );
		if ( $image !== '' ) {
			$schema['image'] = $image;
		}

		if ( ! empty( $startDate ) ) {
			// Convert to ISO 8601
			$schema['startDate'] = gmdate( 'c', strtotime( $startDate ) );
		}

		if ( ! empty( $endDate ) ) {
			$schema['endDate'] = gmdate( 'c', strtotime( $endDate ) );
		}

		if ( ! empty( $address ) ) {
			$schema['location'] = array(
				'@type'   => 'Place',
				'name'    => $venue,
				'address' => array(
					'@type'         => 'PostalAddress',
					'streetAddress' => $address,
				),
			);
		} else {
			$schema['location'] = array(
				'@type' => 'VirtualLocation',
				'url'   => get_permalink( $postId ),
			);
		}

		if ( ! empty( $performerName ) ) {
			$schema['performer'] = array(
				'@type' => 'Person',
				'name'  => $performerName,
			);
		}

		if ( ! empty( $price ) ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => floatval( $price ),
				'priceCurrency' => $currency,
				'availability'  => 'https://schema.org/InStock',
				'url'           => get_permalink( $postId ),
			);
		}

		return $schema;
	}

	/**
	 * First non-empty trimmed string.
	 */
	private function textValue( $primary, string $fallback = '' ): string {
		foreach ( array( $primary, $fallback ) as $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$text = trim( (string) $value );
				if ( $text !== '' ) {
					return $text;
				}
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	private function putText( array $schema, string $key, string $value ): array {
		if ( $value !== '' ) {
			$schema[ $key ] = $value;
		}
		return $schema;
	}

	private function thumbnailUrl( int $postId ): string {
		if ( ! has_post_thumbnail( $postId ) ) {
			return '';
		}
		$url = get_the_post_thumbnail_url( $postId, 'full' );
		return is_string( $url ) ? trim( $url ) : '';
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function namedThings( string $csv, string $type ): array {
		$items = array();
		foreach ( array_map( 'trim', explode( ',', $csv ) ) as $name ) {
			if ( $name === '' ) {
				continue;
			}
			$items[] = array(
				'@type' => $type,
				'name'  => $name,
			);
		}
		return $items;
	}

	/**
	 * Converts a duration representation into ISO 8601 duration format.
	 */
	private function convertToIsoDuration( string $time ): string {
		$time = trim( $time );
		if ( empty( $time ) ) {
			return '';
		}
		if ( strpos( $time, 'P' ) === 0 ) {
			return $time;
		}

		$hours   = 0;
		$minutes = 0;

		if ( preg_match( '/(\d+)\s*(?:hour|hr|h)/i', $time, $m ) ) {
			$hours = intval( $m[1] );
		}
		if ( preg_match( '/(\d+)\s*(?:minute|min|m)/i', $time, $m ) ) {
			$minutes = intval( $m[1] );
		}

		if ( $hours === 0 && $minutes === 0 && is_numeric( $time ) ) {
			$minutes = intval( $time );
		}

		$duration = 'PT';
		if ( $hours > 0 ) {
			$duration .= $hours . 'H';
		}
		if ( $minutes > 0 || $hours === 0 ) {
			$duration .= $minutes . 'M';
		}
		return $duration;
	}

	/**
	 * Merge user-defined visual tree properties dynamically.
	 */
	private function mergeCustomProperties( int $postId, array $schema ): array {
		$parsed = $this->parsedCustomJson( $postId );
		if ( ! is_array( $parsed ) || 'properties' !== ( $parsed['kind'] ?? '' ) ) {
			return $schema;
		}

		foreach ( $parsed['properties'] as $key => $value ) {
			$schema[ $key ] = $value;
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function parsedCustomJson( int $postId ): ?array {
		$raw = get_post_meta( $postId, '_holvek_custom_schema_properties', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$parsed = JsonLd::parseCustomJson( $raw );
		if ( is_wp_error( $parsed ) || 'empty' === ( $parsed['kind'] ?? '' ) ) {
			return null;
		}

		return $parsed;
	}

	private function customType( int $postId ): string {
		$type = get_post_meta( $postId, '_holvek_schema_custom_type', true );
		if ( ! JsonLd::isSchemaType( $type ) ) {
			return 'Thing';
		}

		return $type;
	}
}
