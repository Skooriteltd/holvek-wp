<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;

/**
 * Boots the Schema module and registers REST API endpoints.
 */
class SchemaModule {

	private const SCRAPE_LIMIT  = 10;
	private const SCRAPE_WINDOW = 600;

	private EventManager $eventManager;
	private SchemaGenerator $schemaGenerator;

	public function __construct( EventManager $eventManager, SchemaGenerator $schemaGenerator ) {
		$this->eventManager    = $eventManager;
		$this->schemaGenerator = $schemaGenerator;
	}

	public function boot(): void {
		$this->eventManager->addAction( 'wp_footer', array( $this->schemaGenerator, 'outputSchema' ), 10 );
		$this->eventManager->addAction( 'rest_api_init', array( $this, 'registerRoutes' ) );
		$this->eventManager->addAction( 'save_post', array( $this, 'bumpSchemaMapCache' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function registerRoutes(): void {
		// Public machine-readable endpoint for LLM system ingestion
		register_rest_route(
			'holvek/v1',
			'/schemamap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handleSchemaMap' ),
				'permission_callback' => '__return_true', // Publicly accessible
				'args'                => array(
					'post_id'  => array(
						'sanitize_callback' => 'absint',
					),
					'url'      => array(
						'sanitize_callback' => 'esc_url_raw',
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Competitor URL scraper proxy (Authenticated)
		register_rest_route(
			'holvek/v1',
			'/schema/scrape',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handleScrapeCompetitor' ),
				'permission_callback' => array( $this, 'checkScrapePermission' ),
			)
		);

		// Read/Write global conditional schema display rules (Admin only)
		register_rest_route(
			'holvek/v1',
			'/schema/global-rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getGlobalRules' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateGlobalRules' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
			)
		);
	}

	public function checkScrapePermission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function checkAdminPermission(): bool {
		return current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Handles /wp-json/holvek/v1/schemamap
	 */
	public function handleSchemaMap( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$postIdParam = $request->get_param( 'post_id' );
		$urlParam    = $request->get_param( 'url' );

		$postId = 0;
		if ( ! empty( $postIdParam ) ) {
			$postId = intval( $postIdParam );
		} elseif ( ! empty( $urlParam ) ) {
			$postId = url_to_postid( esc_url_raw( $urlParam ) );
		}

		if ( $postId > 0 ) {
			$post = get_post( $postId );
			// inherit would expose attachment records that are not public pages.
			if ( ! $post || $post->post_status !== 'publish' ) {
				return new \WP_Error( 'post_not_found', __( 'Specified post was not found or is not published.', 'holvek' ), array( 'status' => 404 ) );
			}

			$schema = $this->schemaGenerator->getSchemaForPost( $postId );
			return rest_ensure_response( $schema );
		}

		$page    = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$perPage = absint( $request->get_param( 'per_page' ) ?: 20 );
		if ( $perPage < 1 ) {
			$perPage = 20;
		}
		$perPage = min( 20, $perPage );

		$version  = (int) get_option( 'holvek_schemamap_version', 1 );
		$cacheKey = 'holvek_schemamap_' . $version . '_' . $page . '_' . $perPage;
		$cached   = get_transient( $cacheKey );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		// Return a site-wide mapping list for LLMs. Published posts only.
		$postTypes = get_post_types( array( 'public' => true ) );
		$query     = new \WP_Query(
			array(
				'post_type'              => array_values( $postTypes ),
				'post_status'            => 'publish',
				'posts_per_page'         => $perPage,
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$posts = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id              = get_the_ID();
				$effectiveSchema = SchemaGenerator::getEffectivePrimarySchema( $id );
				$schemas         = array( 'BreadcrumbList' ); // Default
				$isNews          = get_post_meta( $id, '_holvek_is_news', true );
				$schemas[]       = ( $isNews === 'yes' ) ? 'NewsArticle' : 'Article';

				if ( $effectiveSchema !== 'none' ) {
					// map slug to actual schema name
					$schemas[] = ucfirst( $effectiveSchema );
				}

				// Check video Objects
				$videoObjects = VideoExtractor::extractVideoObjects( $id );
				if ( ! empty( $videoObjects ) ) {
					$schemas[] = 'VideoObject';
				}

				$posts[] = array(
					'id'            => $id,
					'title'         => get_the_title(),
					'url'           => get_permalink(),
					'schemas'       => array_unique( $schemas ),
					'schemamap_url' => rest_url( "holvek/v1/schemamap?post_id={$id}" ),
				);
			}
			wp_reset_postdata();
		}

		$payload = array(
			'posts'    => $posts,
			'page'     => $page,
			'per_page' => $perPage,
			'total'    => isset( $query->found_posts ) ? (int) $query->found_posts : count( $posts ),
		);
		set_transient( $cacheKey, $payload, 15 * MINUTE_IN_SECONDS );

		return rest_ensure_response( $payload );
	}

	/**
	 * Drop cached schema maps after content changes.
	 */
	public function bumpSchemaMapCache( int $postId ): void {
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $postId ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $postId ) ) {
			return;
		}

		$version = (int) get_option( 'holvek_schemamap_version', 1 );
		update_option( 'holvek_schemamap_version', $version + 1, false );
	}

	/**
	 * Handles /wp-json/holvek/v1/schema/scrape
	 */
	public function handleScrapeCompetitor( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->checkScrapePermission() ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to import schema from another site.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		$params = $request->get_json_params();
		$url    = isset( $params['url'] ) && is_string( $params['url'] ) ? sanitize_text_field( $params['url'] ) : '';

		if ( '' === $url ) {
			return new \WP_Error( 'missing_url', __( 'URL parameter is required.', 'holvek' ), array( 'status' => 400 ) );
		}

		if ( ! $this->allowScrapeAttempt() ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Too many schema imports. Wait a few minutes and try again.', 'holvek' ),
				array( 'status' => 429 )
			);
		}

		$result = CompetitorScraper::scrapeUrl( $url );
		if ( ! $result['success'] ) {
			return new \WP_Error( 'scrape_failed', $result['message'], array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}

	private function allowScrapeAttempt(): bool {
		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			return false;
		}

		$key   = 'holvek_schema_scrape_' . $userId;
		$count = (int) get_transient( $key );
		if ( $count >= self::SCRAPE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::SCRAPE_WINDOW );
		return true;
	}

	/**
	 * Handles GET /wp-json/holvek/v1/schema/global-rules
	 */
	public function getGlobalRules( \WP_REST_Request $request ): \WP_REST_Response {
		$rulesJson = get_option( 'holvek_global_schema_rules', '[]' );
		$rules     = json_decode( $rulesJson, true );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		// Get post types and categories for the UI selectors
		$allPublicTypes = get_post_types( array( 'public' => true ), 'objects' );
		$typesList      = array();
		foreach ( $allPublicTypes as $type ) {
			$typesList[] = array(
				'name'  => $type->name,
				'label' => $type->label ?: $type->name,
			);
		}

		$categories = get_categories( array( 'hide_empty' => false ) );
		$catsList   = array();
		foreach ( $categories as $cat ) {
			$catsList[] = array(
				'id'   => $cat->term_id,
				'name' => $cat->name,
			);
		}

		return rest_ensure_response(
			array(
				'rules'      => $rules,
				'post_types' => $typesList,
				'categories' => $catsList,
			)
		);
	}

	/**
	 * Handles POST /wp-json/holvek/v1/schema/global-rules
	 */
	public function updateGlobalRules( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		$rules  = isset( $params['rules'] ) ? $params['rules'] : array();

		if ( ! is_array( $rules ) ) {
			return new \WP_Error( 'invalid_format', __( 'Rules must be a valid array.', 'holvek' ), array( 'status' => 400 ) );
		}

		// Sanitize
		$sanitized = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['post_type'] ) || empty( $rule['schema_type'] ) ) {
				continue;
			}
			$sanitized[] = array(
				'post_type'   => sanitize_text_field( $rule['post_type'] ),
				'category'    => sanitize_text_field( $rule['category'] ), // e.g. "all" or term ID
				'schema_type' => sanitize_text_field( $rule['schema_type'] ),
			);
		}

		update_option( 'holvek_global_schema_rules', wp_json_encode( $sanitized ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'rules'   => $sanitized,
			)
		);
	}
}
