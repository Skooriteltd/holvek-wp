<?php

namespace Holvek\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Container\Container;
use Holvek\WordPress\Core\Api\BackendApiClient;
use Holvek\WordPress\Core\Security\KeyVault;
use Holvek\WordPress\Modules\Seo\SeoModule;
use Holvek\WordPress\Modules\Seo\MetaTagsGenerator;
use Holvek\WordPress\Modules\Seo\OpenGraphGenerator;
use Holvek\WordPress\Modules\Admin\AdminModule;
use Holvek\WordPress\Modules\Admin\AdminMenu;
use Holvek\WordPress\Modules\Sitemap\SitemapModule;
use Holvek\WordPress\Modules\Sitemap\SitemapRouteManager;
use Holvek\WordPress\Modules\Sitemap\SitemapGenerator;
use Holvek\WordPress\Modules\Sitemap\NewsSitemapGenerator;
use Holvek\WordPress\Modules\Sitemap\VideoSitemapGenerator;
use Holvek\WordPress\Modules\Indexing\IndexingModule;
use Holvek\WordPress\Modules\TechnicalSeo\TechnicalSeoModule;
use Holvek\WordPress\Modules\TechnicalSeo\RedirectManager;
use Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor;
use Holvek\WordPress\Modules\Schema\SchemaModule;
use Holvek\WordPress\Modules\Schema\SchemaGenerator;
use Holvek\WordPress\Modules\ContentAssistant\ContentAssistantModule;
use Holvek\WordPress\Modules\Breadcrumbs\BreadcrumbRenderer;
use Holvek\WordPress\Modules\ImageSeo\ImageSeoModule;
use Holvek\WordPress\Modules\TechnicalSeo\RobotsTxtEditor;
use Holvek\WordPress\Modules\Migration\MigrationManager;
use Holvek\WordPress\Modules\Onboarding\SetupWizard;
use Holvek\WordPress\Modules\Social\SocialModule;
use Holvek\WordPress\Modules\Ai\LlmsTxtGenerator;
use Holvek\WordPress\Modules\Admin\BulkMetaEditor;
use Holvek\WordPress\Modules\Compliance\CookieBannerModule;

class Plugin {

	private static ?Plugin $instance = null;
	private Container $container;
	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function getInstance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		// plugins_loaded calls holvek_init(), and a second call in the same
		// process must not register the hooks again.
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Load translation textdomain
		add_action(
			'init',
			function () {
				load_plugin_textdomain(
					'holvek',
					false,
					dirname( plugin_basename( HOLVEK_PLUGIN_FILE ) ) . '/languages'
				);
			}
		);

		$dbVersion = get_option( 'holvek_db_version', '0' );
		if ( version_compare( $dbVersion, HOLVEK_VERSION, '<' ) ) {
			$this->container->get( \Holvek\WordPress\Core\Database\Installer::class )->install();
		}

		$this->registerServices();

		// One autoloaded flag check. The editor is constructed only while a
		// public backup directory still needs to be removed.
		add_action( 'init', array( $this, 'retireHtaccessBackupsOnce' ) );

		// REST_REQUEST is defined on parse_request, after this boot runs.
		// Cacheable HTML must not build admin, queue, or analytics services.
		if ( $this->isFrontendHtmlRequest() ) {
			add_action( 'rest_api_init', array( $this, 'registerRestControllers' ) );
			$this->bootFrontendHtml();
			return;
		}

		if ( is_admin() ) {
			KeyVault::registerAdminNotice();
		}

		/** @var \Holvek\WordPress\Core\Queue\QueueManager $queueManager */
		$queueManager = $this->container->get( 'queue_manager' );
		$queueManager->boot();

		$this->bootModules();

		// wp-admin already attaches these inside bootModules(). A REST request
		// is not is_admin(), and those routes still have to exist there.
		if ( ! is_admin() ) {
			add_action( 'rest_api_init', array( $this, 'registerAdminScopedRestControllers' ) );
		}
	}

	private function registerServices(): void {
		// Core services (Events, Queues, Backend API Client, etc.)
		$this->container->singleton( 'event_manager', \Holvek\WordPress\Core\Event\EventManager::class );
		$this->container->singleton( 'backend_api_client', BackendApiClient::class );

		// Register SEO Module Dependencies
		$this->container->singleton( 'meta_tags_generator', MetaTagsGenerator::class );
		$this->container->singleton( 'open_graph_generator', OpenGraphGenerator::class );

		$this->container->singleton(
			'seo_module',
			function () {
				return new SeoModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'meta_tags_generator' ),
					$this->container->get( 'open_graph_generator' )
				);
			}
		);

		// Register Admin Module Dependencies
		$this->container->singleton( 'admin_menu', AdminMenu::class );
		$this->container->singleton(
			'admin_module',
			function () {
				return new AdminModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'admin_menu' )
				);
			}
		);

		// Register Sitemap Module Dependencies
		$this->container->singleton( 'sitemap_generator', SitemapGenerator::class );
		$this->container->singleton( 'news_sitemap_generator', NewsSitemapGenerator::class );
		$this->container->singleton( 'video_sitemap_generator', VideoSitemapGenerator::class );

		$this->container->singleton(
			'sitemap_route_manager',
			function () {
				return new SitemapRouteManager(
					$this->container->get( 'sitemap_generator' ),
					$this->container->get( 'news_sitemap_generator' ),
					$this->container->get( 'video_sitemap_generator' )
				);
			}
		);

		$this->container->singleton(
			'sitemap_module',
			function () {
				return new SitemapModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'sitemap_route_manager' )
				);
			}
		);

		// Register Indexing Module Dependencies
		$this->container->singleton( 'queue_manager', \Holvek\WordPress\Core\Queue\QueueManager::class );
		$this->container->singleton(
			'indexing_module',
			function () {
				return new IndexingModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'queue_manager' )
				);
			}
		);

		// Register Technical SEO Module Dependencies
		$this->container->singleton( 'redirect_manager', RedirectManager::class );
		$this->container->singleton( 'error_monitor', ErrorMonitor::class );

		$this->container->singleton(
			'technical_seo_module',
			function () {
				return new TechnicalSeoModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'redirect_manager' ),
					$this->container->get( 'error_monitor' )
				);
			}
		);

		// Register Schema Module Dependencies
		$this->container->singleton( 'schema_generator', SchemaGenerator::class );
		$this->container->singleton(
			'schema_module',
			function () {
				return new SchemaModule(
					$this->container->get( 'event_manager' ),
					$this->container->get( 'schema_generator' )
				);
			}
		);

		// Register Content Assistant Module
		$this->container->singleton(
			'content_assistant_module',
			function () {
				return new ContentAssistantModule(
					$this->container->get( 'event_manager' )
				);
			}
		);

		// Register Breadcrumb Renderer
		$this->container->singleton(
			'breadcrumb_renderer',
			function () {
				return new BreadcrumbRenderer();
			}
		);

		// Register Image SEO Module
		$this->container->singleton(
			'image_seo_module',
			function () {
				return new ImageSeoModule(
					$this->container->get( 'event_manager' )
				);
			}
		);

		// Register Robots.txt Editor
		$this->container->singleton(
			'robots_txt_editor',
			function () {
				return new RobotsTxtEditor();
			}
		);

		// Register Migration Manager
		$this->container->singleton(
			'migration_manager',
			function () {
				return new MigrationManager();
			}
		);

		// Register Setup Wizard
		$this->container->singleton(
			'setup_wizard',
			function () {
				return new SetupWizard();
			}
		);

		// Register Social Module
		$this->container->singleton(
			'social_module',
			function () {
				return new SocialModule(
					$this->container->get( 'event_manager' )
				);
			}
		);

		// Register llms.txt Generator
		$this->container->singleton(
			'llms_txt_generator',
			function () {
				return new LlmsTxtGenerator();
			}
		);

		// Register Bulk Meta Editor
		$this->container->singleton(
			'bulk_meta_editor',
			function () {
				return new BulkMetaEditor();
			}
		);

		// Register Cookie Banner Module
		$this->container->singleton(
			'cookie_banner_module',
			function () {
				return new CookieBannerModule();
			}
		);

		// ── Backlog Modules ───────────────────────────────────────────────────
		$this->container->singleton( 'orphaned_content_finder', \Holvek\WordPress\Modules\TechnicalSeo\OrphanedContentFinder::class );
		$this->container->singleton( 'image_object_schema', \Holvek\WordPress\Modules\ImageSeo\ImageObjectSchema::class );
		$this->container->singleton( 'image_filename_enforcer', \Holvek\WordPress\Modules\ImageSeo\ImageFilenameEnforcer::class );
		$this->container->singleton( 'technical_seo_audit_engine', \Holvek\WordPress\Modules\Admin\TechnicalSeoAuditEngine::class );
		$this->container->singleton( 'ccpa_privacy_tools', \Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools::class );
		$this->container->singleton( 'internal_link_suggester', \Holvek\WordPress\Modules\ContentAssistant\InternalLinkSuggester::class );
		$this->container->singleton( 'usage_metering_manager', \Holvek\WordPress\Modules\Ai\UsageMeteringManager::class );
		$this->container->singleton( 'upgrade_prompt_manager', \Holvek\WordPress\Modules\Admin\UpgradePromptManager::class );
		$this->container->singleton( 'htaccess_editor', \Holvek\WordPress\Modules\TechnicalSeo\HtaccessEditor::class );
		$this->container->singleton( 'ai_disclosure_manager', \Holvek\WordPress\Modules\Compliance\AiDisclosureManager::class );
		$this->container->singleton( 'index_status_checker', \Holvek\WordPress\Modules\Indexing\IndexStatusChecker::class );
		$this->container->singleton( 'schema_output_validator', \Holvek\WordPress\Modules\Schema\SchemaOutputValidator::class );
		$this->container->singleton( 'stale_cornerstone_detector', \Holvek\WordPress\Modules\ContentAssistant\StaleCornerStoneDetector::class );
		$this->container->singleton( 'audit_scheduler', \Holvek\WordPress\Modules\ContentAssistant\AuditScheduler::class );
		$this->container->singleton( 'search_intent_classifier', \Holvek\WordPress\Modules\ContentAssistant\SearchIntentClassifier::class );
		$this->container->singleton( 'inclusive_language_checker', \Holvek\WordPress\Modules\ContentAssistant\InclusiveLanguageChecker::class );
		$this->container->singleton( 'word_complexity_scorer', \Holvek\WordPress\Modules\ContentAssistant\WordComplexityScorer::class );
		$this->container->singleton( 'bing_indexnow_api', \Holvek\WordPress\Modules\Indexing\BingIndexNowApi::class );

		// ── Phase 2 backlog services ───────────────────────────────────────────
		$this->container->singleton( 'morphological_keyword_matcher', \Holvek\WordPress\Modules\ContentAssistant\MorphologicalKeywordMatcher::class );
		$this->container->singleton( 'woocommerce_product_schema', \Holvek\WordPress\Modules\Schema\WooCommerceProductSchema::class );
		$this->container->singleton( 'headless_seo_endpoints', \Holvek\WordPress\Modules\Api\HeadlessSeoEndpoints::class );
		$this->container->singleton( 'content_gap_analyser', \Holvek\WordPress\Modules\ContentAssistant\ContentGapAnalyser::class );
		$this->container->singleton( 'frontend_seo_inspector', \Holvek\WordPress\Modules\Admin\FrontendSeoInspector::class );
		$this->container->singleton( 'sitemap_priority_manager', \Holvek\WordPress\Modules\Sitemap\SitemapPriorityManager::class );
		$this->container->singleton( 'llm_writing_assistant', \Holvek\WordPress\Modules\ContentAssistant\LlmWritingAssistant::class );
		$this->container->singleton( 'keyword_cannibalization_detector', \Holvek\WordPress\Modules\Analytics\KeywordCannibalizationDetector::class );
		$this->container->singleton( 'seo_audit_history_log', \Holvek\WordPress\Modules\Admin\SeoAuditHistoryLog::class );
		$this->container->singleton( 'custom_seo_user_roles', \Holvek\WordPress\Modules\Admin\CustomSeoUserRoles::class );
		$this->container->singleton( 'pagespeed_dashboard', \Holvek\WordPress\Modules\Analytics\PageSpeedDashboard::class );
		$this->container->singleton( 'competitor_seo_importer', \Holvek\WordPress\Modules\Import\CompetitorSeoImporter::class );
		$this->container->singleton( 'multisite_network_seo', \Holvek\WordPress\Modules\Analytics\MultisiteNetworkSeo::class );
		$this->container->singleton( 'google_search_console', \Holvek\WordPress\Modules\Analytics\GoogleSearchConsoleIntegration::class );
		$this->container->singleton( 'bing_webmaster', \Holvek\WordPress\Modules\Analytics\BingWebmasterIntegration::class );
		$this->container->singleton( 'keyword_rank_tracker', \Holvek\WordPress\Modules\Analytics\KeywordRankTracker::class );
	}

	/**
	 * Public response behavior for a cacheable HTML request.
	 *
	 * Sitemap, llms.txt, and IndexNow objects are constructed inside the
	 * request callback, and only when the path matches.
	 */
	private function bootFrontendHtml(): void {
		$this->container->get( 'seo_module' )->boot();
		$this->container->get( 'schema_module' )->boot();
		$this->container->get( 'robots_txt_editor' )->register();
		$this->container->get( 'breadcrumb_renderer' )->register();

		add_action( 'template_redirect', array( $this->container->get( 'redirect_manager' ), 'handleRedirects' ), 1 );
		add_action( 'template_redirect', array( $this->container->get( 'error_monitor' ), 'log404Errors' ), 99 );

		$custom404 = new \Holvek\WordPress\Modules\TechnicalSeo\Custom404Handler();
		add_filter( 'template_include', array( $custom404, 'maybeServeCustom404' ), 99 );

		$htmlSitemap = new \Holvek\WordPress\Modules\Sitemap\HtmlSitemapShortcode();
		$htmlSitemap->register();

		add_action( 'init', array( $this->container->get( 'ccpa_privacy_tools' ), 'handleGpcSignal' ) );

		$cookieConfig = get_option( 'holvek_cookie_banner', array() );
		if ( is_array( $cookieConfig ) && ! empty( $cookieConfig['enabled'] ) ) {
			$this->container->get( 'cookie_banner_module' )->boot();
		}

		add_filter( 'the_content', array( $this->container->get( 'ai_disclosure_manager' ), 'injectDisclosure' ) );
		add_action( 'template_redirect', array( $this->container->get( 'image_seo_module' ), 'redirectAttachmentPages' ) );

		$this->container->get( 'woocommerce_product_schema' )->register();
		$this->container->get( 'image_object_schema' )->register();

		if ( is_user_logged_in() ) {
			add_action( 'wp_footer', array( $this->container->get( 'frontend_seo_inspector' ), 'injectOverlay' ), 99 );
		}

		if ( get_option( 'holvek_filename_enforce', 'yes' ) === 'yes' ) {
			add_filter(
				'wp_handle_upload_prefilter',
				array( $this->container->get( 'image_filename_enforcer' ), 'enforceFilename' )
			);
		}

		add_filter( 'wp_sitemaps_enabled', array( $this, 'filterCoreSitemapsEnabled' ) );

		if ( $this->isSitemapRequest() ) {
			add_action( 'template_redirect', array( $this, 'serveFrontendSitemap' ), 0 );
		}

		if ( $this->isLlmsTxtRequest() ) {
			add_action( 'template_redirect', array( $this, 'serveFrontendLlmsTxt' ), 0 );
		}

		if ( $this->isIndexNowKeyRequest() ) {
			add_action( 'init', array( $this, 'serveFrontendIndexNowKey' ) );
		}
	}

	public function retireHtaccessBackupsOnce(): void {
		if ( get_option( 'holvek_htaccess_web_backups_retired', 'no' ) === 'yes' ) {
			return;
		}

		$this->container->get( 'htaccess_editor' )->retirePublicBackupsOnce();
	}

	public function filterCoreSitemapsEnabled( bool $enabled ): bool {
		$pluginSitemap = \Holvek\WordPress\Modules\Sitemap\SitemapSettings::get( 'enable_index_sitemap', 'no' );
		if ( $pluginSitemap === 'yes' ) {
			return false;
		}

		return $enabled;
	}

	public function serveFrontendSitemap(): void {
		$this->container->get( 'sitemap_route_manager' )->handleSitemapRequests();
	}

	public function serveFrontendLlmsTxt(): void {
		// This path does not register the rewrite query var, so set it before serving.
		set_query_var( 'holvek_llms_txt', '1' );
		$this->container->get( 'llms_txt_generator' )->serveFile();
	}

	public function serveFrontendIndexNowKey(): void {
		$this->container->get( 'bing_indexnow_api' )->serveKeyFile();
	}

	/**
	 * Register every REST controller without constructing its HTTP client.
	 *
	 * Hooked from the HTML boot so a request that was not yet marked as REST
	 * still exposes routes when rest_api_init fires.
	 */
	public function registerRestControllers(): void {
		$this->container->get( 'queue_manager' )->registerRoutes();
		$this->container->get( 'admin_module' )->registerRestRoutes();
		$this->container->get( 'sitemap_module' )->registerRoutes();
		$this->container->get( 'redirect_manager' )->registerRoutes();
		$this->container->get( 'error_monitor' )->registerRoutes();
		$this->container->get( 'schema_module' )->registerRoutes();
		$this->container->get( 'content_assistant_module' )->registerRestRoutes();
		$this->container->get( 'robots_txt_editor' )->registerRoutes();
		$this->container->get( 'migration_manager' )->registerRoutes();
		$this->container->get( 'setup_wizard' )->registerRoutes();
		$this->container->get( 'social_module' )->registerRestRoutes();
		$this->container->get( 'llms_txt_generator' )->registerRestRoutes();
		$this->container->get( 'bulk_meta_editor' )->registerRestRoutes();
		$this->container->get( 'cookie_banner_module' )->registerRoutes();
		$this->container->get( 'orphaned_content_finder' )->registerRoutes();
		$this->container->get( 'image_object_schema' )->registerRoutes();
		$this->container->get( 'image_filename_enforcer' )->registerRoutes();
		$this->container->get( 'technical_seo_audit_engine' )->registerRoutes();
		$this->container->get( 'ccpa_privacy_tools' )->registerRoutes();
		$this->container->get( 'internal_link_suggester' )->registerRoutes();
		$this->container->get( 'usage_metering_manager' )->registerRoutes();
		$this->container->get( 'upgrade_prompt_manager' )->registerRoutes();
		( new \Holvek\WordPress\Core\Api\CloudCredential() )->registerRoutes();
		$this->container->get( 'htaccess_editor' )->registerRoutes();
		$this->container->get( 'ai_disclosure_manager' )->registerRoutes();
		$this->container->get( 'index_status_checker' )->registerRoutes();
		$this->container->get( 'schema_output_validator' )->registerRoutes();
		$this->container->get( 'stale_cornerstone_detector' )->registerRoutes();
		$this->container->get( 'audit_scheduler' )->registerRoutes();
		$this->container->get( 'search_intent_classifier' )->registerRoutes();
		$this->container->get( 'inclusive_language_checker' )->registerRoutes();
		$this->container->get( 'word_complexity_scorer' )->registerRoutes();
		$this->container->get( 'bing_indexnow_api' )->registerRoutes();
		$this->container->get( 'morphological_keyword_matcher' )->registerRoutes();

		if ( class_exists( '\WC_Product' ) || function_exists( 'wc_get_product' ) ) {
			$this->container->get( 'woocommerce_product_schema' )->registerRoutes();
		}

		$this->container->get( 'headless_seo_endpoints' )->registerRoutes();
		$this->container->get( 'content_gap_analyser' )->registerRoutes();
		$this->container->get( 'frontend_seo_inspector' )->registerRoutes();
		$this->container->get( 'sitemap_priority_manager' )->registerRoutes();
		$this->container->get( 'llm_writing_assistant' )->registerRoutes();
		$this->container->get( 'keyword_cannibalization_detector' )->registerRoutes();
		$this->container->get( 'seo_audit_history_log' )->registerRoutes();
		$this->container->get( 'custom_seo_user_roles' )->registerRoutes();
		$this->container->get( 'pagespeed_dashboard' )->registerRoutes();
		$this->container->get( 'competitor_seo_importer' )->registerRoutes();
		$this->container->get( 'multisite_network_seo' )->registerRoutes();
		$this->container->get( 'google_search_console' )->registerRoutes();
		$this->container->get( 'bing_webmaster' )->registerRoutes();
		$this->container->get( 'keyword_rank_tracker' )->registerRoutes();

		( new \Holvek\WordPress\Modules\ImageSeo\ImageAltAudit() )->registerRoutes();
		( new \Holvek\WordPress\Modules\ImageSeo\ImageCompressor() )->registerRoutes();
		( new \Holvek\WordPress\Modules\TechnicalSeo\Custom404Handler() )->registerRoutes();
		( new \Holvek\WordPress\Modules\TechnicalSeo\BrokenLinkChecker() )->registerRoutes();
	}

	/**
	 * Routes that bootModules() attaches only inside is_admin().
	 */
	public function registerAdminScopedRestControllers(): void {
		$this->container->get( 'admin_module' )->registerRestRoutes();
		$this->container->get( 'migration_manager' )->registerRoutes();
		$this->container->get( 'setup_wizard' )->registerRoutes();
		$this->container->get( 'bulk_meta_editor' )->registerRestRoutes();
		$this->container->get( 'technical_seo_audit_engine' )->registerRoutes();
		$this->container->get( 'upgrade_prompt_manager' )->registerRoutes();
		( new \Holvek\WordPress\Core\Api\CloudCredential() )->registerRoutes();
		$this->container->get( 'seo_audit_history_log' )->registerRoutes();
		$this->container->get( 'custom_seo_user_roles' )->registerRoutes();
	}

	private function isFrontendHtmlRequest(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( $this->isRestRequest() ) {
			return false;
		}

		return true;
	}

	private function isRestRequest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( $this->hasRestRouteQuery() ) {
			return true;
		}

		$path   = $this->requestPath();
		$prefix = '/' . $this->restPrefix();
		if ( str_contains( $path, $prefix . '/' ) ) {
			return true;
		}

		return str_ends_with( $path, $prefix );
	}

	private function hasRestRouteQuery(): bool {
		// Routing detection only. The value is not stored or authorized here.
		if ( ! isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$route = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $route ) ) {
			return false;
		}

		return '' !== sanitize_text_field( $route );
	}

	private function restPrefix(): string {
		$prefix = 'wp-json';
		if ( function_exists( 'rest_get_url_prefix' ) ) {
			$prefix = (string) rest_get_url_prefix();
		}

		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			return 'wp-json';
		}

		return $prefix;
	}

	private function requestPath(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( ! is_string( $uri ) || '' === $uri ) {
			return '/';
		}

		$path = wp_parse_url( sanitize_text_field( $uri ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return $path;
	}

	private function isSitemapRequest(): bool {
		return 1 === preg_match(
			'#/(sitemap\.xml|sitemap-posts-\d{4}-\d{2}(?:-(?:[2-9]|[1-9]\d+))?\.xml|sitemap-news\.xml|news-sitemap\.xml|video-sitemap\.xml)$#',
			$this->requestPath()
		);
	}

	private function isLlmsTxtRequest(): bool {
		return str_ends_with( $this->requestPath(), '/llms.txt' );
	}

	private function isIndexNowKeyRequest(): bool {
		$path = $this->requestPath();
		if ( ! str_ends_with( $path, '.txt' ) ) {
			return false;
		}
		if ( str_ends_with( $path, '/robots.txt' ) || str_ends_with( $path, '/llms.txt' ) ) {
			return false;
		}

		return true;
	}

	private function bootModules(): void {
		/** @var SeoModule $seoModule */
		$seoModule = $this->container->get( 'seo_module' );
		$seoModule->boot();

		if ( is_admin() ) {
			/** @var AdminModule $adminModule */
			$adminModule = $this->container->get( 'admin_module' );
			$adminModule->boot();
		}

		/** @var SitemapModule $sitemapModule */
		$sitemapModule = $this->container->get( 'sitemap_module' );
		$sitemapModule->boot();

		/** @var IndexingModule $indexingModule */
		$indexingModule = $this->container->get( 'indexing_module' );
		$indexingModule->boot();

		/** @var TechnicalSeoModule $technicalSeoModule */
		$technicalSeoModule = $this->container->get( 'technical_seo_module' );
		$technicalSeoModule->boot();

		/** @var SchemaModule $schemaModule */
		$schemaModule = $this->container->get( 'schema_module' );
		$schemaModule->boot();

		/** @var ContentAssistantModule $contentAssistantModule */
		$contentAssistantModule = $this->container->get( 'content_assistant_module' );
		$contentAssistantModule->boot();

		/** @var BreadcrumbRenderer $breadcrumbRenderer */
		$breadcrumbRenderer = $this->container->get( 'breadcrumb_renderer' );
		$breadcrumbRenderer->register();

		/** @var ImageSeoModule $imageSeoModule */
		$imageSeoModule = $this->container->get( 'image_seo_module' );
		$imageSeoModule->boot();

		/** @var RobotsTxtEditor $robotsTxtEditor */
		$robotsTxtEditor = $this->container->get( 'robots_txt_editor' );
		$robotsTxtEditor->register();

		// Register Robots.txt REST routes
		add_action( 'rest_api_init', array( $robotsTxtEditor, 'registerRoutes' ) );

		if ( is_admin() ) {
			/** @var MigrationManager $migrationManager */
			$migrationManager = $this->container->get( 'migration_manager' );
			add_action( 'rest_api_init', array( $migrationManager, 'registerRoutes' ) );

			/** @var SetupWizard $setupWizard */
			$setupWizard = $this->container->get( 'setup_wizard' );
			$setupWizard->register();
		}

		/** @var SocialModule $socialModule */
		$socialModule = $this->container->get( 'social_module' );
		$socialModule->boot();

		/** @var LlmsTxtGenerator $llmsTxtGenerator */
		$llmsTxtGenerator = $this->container->get( 'llms_txt_generator' );
		$llmsTxtGenerator->boot();
		add_action( 'rest_api_init', array( $llmsTxtGenerator, 'registerRestRoutes' ) );

		if ( is_admin() ) {
			/** @var BulkMetaEditor $bulkMetaEditor */
			$bulkMetaEditor = $this->container->get( 'bulk_meta_editor' );
			add_action( 'rest_api_init', array( $bulkMetaEditor, 'registerRestRoutes' ) );
		}

		if ( ! is_admin() ) {
			/** @var CookieBannerModule $cookieBannerModule */
			$cookieBannerModule = $this->container->get( 'cookie_banner_module' );
			$cookieBannerModule->boot();
		}

		// HTML Sitemap shortcode: [holvek_sitemap]
		$htmlSitemapShortcode = new \Holvek\WordPress\Modules\Sitemap\HtmlSitemapShortcode();
		$htmlSitemapShortcode->register();

		/** @var \Holvek\WordPress\Modules\TechnicalSeo\OrphanedContentFinder $orphanedFinder */
		$orphanedFinder = $this->container->get( 'orphaned_content_finder' );
		$orphanedFinder->register();

		/** @var \Holvek\WordPress\Modules\ImageSeo\ImageObjectSchema $imageObjectSchema */
		$imageObjectSchema = $this->container->get( 'image_object_schema' );
		$imageObjectSchema->register();

		/** @var \Holvek\WordPress\Modules\ImageSeo\ImageFilenameEnforcer $imageFilenameEnforcer */
		$imageFilenameEnforcer = $this->container->get( 'image_filename_enforcer' );
		$imageFilenameEnforcer->register();

		if ( is_admin() ) {
			/** @var \Holvek\WordPress\Modules\Admin\TechnicalSeoAuditEngine $auditEngine */
			$auditEngine = $this->container->get( 'technical_seo_audit_engine' );
			$auditEngine->register();
		}

		if ( ! is_admin() ) {
			/** @var \Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools $ccpaTools */
			$ccpaTools = $this->container->get( 'ccpa_privacy_tools' );
			$ccpaTools->boot();
		}

		/** @var \Holvek\WordPress\Modules\ContentAssistant\InternalLinkSuggester $linkSuggester */
		$linkSuggester = $this->container->get( 'internal_link_suggester' );
		$linkSuggester->register();

		/** @var \Holvek\WordPress\Modules\Ai\UsageMeteringManager $usageMetering */
		$usageMetering = $this->container->get( 'usage_metering_manager' );
		$usageMetering->boot();

		if ( is_admin() ) {
			/** @var \Holvek\WordPress\Modules\Admin\UpgradePromptManager $upgradePrompt */
			$upgradePrompt = $this->container->get( 'upgrade_prompt_manager' );
			$upgradePrompt->boot();
		}

		/** @var \Holvek\WordPress\Modules\TechnicalSeo\HtaccessEditor $htaccessEditor */
		$htaccessEditor = $this->container->get( 'htaccess_editor' );
		$htaccessEditor->register();

		/** @var \Holvek\WordPress\Modules\Compliance\AiDisclosureManager $aiDisclosure */
		$aiDisclosure = $this->container->get( 'ai_disclosure_manager' );
		$aiDisclosure->register();

		/** @var \Holvek\WordPress\Modules\Indexing\IndexStatusChecker $indexStatusChecker */
		$indexStatusChecker = $this->container->get( 'index_status_checker' );
		$indexStatusChecker->register();

		/** @var \Holvek\WordPress\Modules\Schema\SchemaOutputValidator $schemaValidator */
		$schemaValidator = $this->container->get( 'schema_output_validator' );
		$schemaValidator->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\StaleCornerStoneDetector $staleDetector */
		$staleDetector = $this->container->get( 'stale_cornerstone_detector' );
		$staleDetector->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\AuditScheduler $auditScheduler */
		$auditScheduler = $this->container->get( 'audit_scheduler' );
		$auditScheduler->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\SearchIntentClassifier $intentClassifier */
		$intentClassifier = $this->container->get( 'search_intent_classifier' );
		$intentClassifier->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\InclusiveLanguageChecker $inclusiveChecker */
		$inclusiveChecker = $this->container->get( 'inclusive_language_checker' );
		$inclusiveChecker->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\WordComplexityScorer $complexityScorer */
		$complexityScorer = $this->container->get( 'word_complexity_scorer' );
		$complexityScorer->register();

		/** @var \Holvek\WordPress\Modules\Indexing\BingIndexNowApi $bingIndexNow */
		$bingIndexNow = $this->container->get( 'bing_indexnow_api' );
		$bingIndexNow->register();

		// ── Boot Phase 2 Backlog Modules ─────────────────────────────────────
		/** @var \Holvek\WordPress\Modules\ContentAssistant\MorphologicalKeywordMatcher $morphoMatcher */
		$morphoMatcher = $this->container->get( 'morphological_keyword_matcher' );
		$morphoMatcher->register();

		/** @var \Holvek\WordPress\Modules\Schema\WooCommerceProductSchema $wooSchema */
		$wooSchema = $this->container->get( 'woocommerce_product_schema' );
		$wooSchema->register();

		/** @var \Holvek\WordPress\Modules\Api\HeadlessSeoEndpoints $headlessEndpoints */
		$headlessEndpoints = $this->container->get( 'headless_seo_endpoints' );
		$headlessEndpoints->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\ContentGapAnalyser $contentGap */
		$contentGap = $this->container->get( 'content_gap_analyser' );
		$contentGap->register();

		if ( ! is_admin() ) {
			/** @var \Holvek\WordPress\Modules\Admin\FrontendSeoInspector $frontendInspector */
			$frontendInspector = $this->container->get( 'frontend_seo_inspector' );
			$frontendInspector->register();
		}

		/** @var \Holvek\WordPress\Modules\Sitemap\SitemapPriorityManager $sitemapPriority */
		$sitemapPriority = $this->container->get( 'sitemap_priority_manager' );
		$sitemapPriority->register();

		/** @var \Holvek\WordPress\Modules\ContentAssistant\LlmWritingAssistant $llmAssistant */
		$llmAssistant = $this->container->get( 'llm_writing_assistant' );
		$llmAssistant->register();

		/** @var \Holvek\WordPress\Modules\Analytics\KeywordCannibalizationDetector $cannibalizationDetector */
		$cannibalizationDetector = $this->container->get( 'keyword_cannibalization_detector' );
		$cannibalizationDetector->register();

		if ( is_admin() ) {
			/** @var \Holvek\WordPress\Modules\Admin\SeoAuditHistoryLog $auditHistoryLog */
			$auditHistoryLog = $this->container->get( 'seo_audit_history_log' );
			$auditHistoryLog->register();

			/** @var \Holvek\WordPress\Modules\Admin\CustomSeoUserRoles $userRoles */
			$userRoles = $this->container->get( 'custom_seo_user_roles' );
			$userRoles->register();
		}

		/** @var \Holvek\WordPress\Modules\Analytics\PageSpeedDashboard $pageSpeed */
		$pageSpeed = $this->container->get( 'pagespeed_dashboard' );
		$pageSpeed->register();

		/** @var \Holvek\WordPress\Modules\Import\CompetitorSeoImporter $competitorImporter */
		$competitorImporter = $this->container->get( 'competitor_seo_importer' );
		$competitorImporter->register();

		/** @var \Holvek\WordPress\Modules\Analytics\MultisiteNetworkSeo $multisiteNetworkSeo */
		$multisiteNetworkSeo = $this->container->get( 'multisite_network_seo' );
		$multisiteNetworkSeo->register();

		/** @var \Holvek\WordPress\Modules\Analytics\GoogleSearchConsoleIntegration $gsc */
		$gsc = $this->container->get( 'google_search_console' );
		$gsc->register();

		$bing = $this->container->get( 'bing_webmaster' );
		$bing->register();

		/** @var \Holvek\WordPress\Modules\Analytics\KeywordRankTracker $rankTracker */
		$rankTracker = $this->container->get( 'keyword_rank_tracker' );
		$rankTracker->register();

		// Broken Link Checker (BL-018)
		$brokenLinkChecker = new \Holvek\WordPress\Modules\TechnicalSeo\BrokenLinkChecker();
		$brokenLinkChecker->register();
	}

	public function getContainer(): Container {
		return $this->container;
	}

	public static function activate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			$siteIds = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $siteIds as $siteId ) {
				switch_to_blog( (int) $siteId );
				self::activateSite();
				restore_current_blog();
			}
			return;
		}

		self::activateSite();
	}

	private static function activateSite(): void {
		// 1. Run schema installation
		$installer = new \Holvek\WordPress\Core\Database\Installer();
		$installer->install();

		// 2. Generate persistent encryption salt for KeyVault
		\Holvek\WordPress\Core\Security\KeyVault::ensureSaltExists();

		// 3. Cache the 404 table existence flag
		\Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor::markTableExists();

		// 4. Create AI usage metering table
		\Holvek\WordPress\Modules\Ai\UsageMeteringManager::createTable();

		// 5. Create SEO Audit History log table
		\Holvek\WordPress\Modules\Admin\SeoAuditHistoryLog::createTable();

		// 6. Create keyword rank tracker table
		\Holvek\WordPress\Modules\Analytics\KeywordRankTracker::createTable();

		// 7. Create broken link checker results table
		\Holvek\WordPress\Modules\TechnicalSeo\BrokenLinkChecker::createTable();

		// 8. Register custom SEO capabilities
		\Holvek\WordPress\Modules\Admin\CustomSeoUserRoles::addCapabilities();

		// 9. Register and persist sitemap routes during activation. The normal
		// boot listeners are not registered while an activation hook runs.
		self::sitemapRoutes()->flushRules();

		// 10. Recurring work is scheduled here. Boot must not call wp_next_scheduled().
		self::scheduleBackgroundEvents();

		// 11. Secrets, logs, and dashboard caches stay out of the alloptions set.
		self::enforceOptionAutoload();
	}

	/**
	 * Schedule recurring events once. Each worker schedules again if its event is missing.
	 */
	private static function scheduleBackgroundEvents(): void {
		\Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor::schedule();
		\Holvek\WordPress\Modules\Analytics\KeywordRankTracker::schedule();
		\Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools::schedule();
		\Holvek\WordPress\Modules\Ai\UsageMeteringManager::schedule();
		\Holvek\WordPress\Modules\ContentAssistant\StaleCornerStoneDetector::schedule();
		\Holvek\WordPress\Modules\Admin\SeoAuditHistoryLog::schedule();
		\Holvek\WordPress\Modules\ContentAssistant\AuditScheduler::schedule();
	}

	/**
	 * Front-end flags stay autoloaded. Secrets, logs, and dashboard caches do not.
	 */
	private static function enforceOptionAutoload(): void {
		global $wpdb;

		$frontEnd = array(
			'holvek_redirect_counts',
			'holvek_robots_txt_replaces_generated',
			'holvek_block_ai_bots',
			'holvek_custom_ai_bots',
			'holvek_ai_training_optout',
			'holvek_cookie_banner',
			'holvek_filename_enforce',
			'holvek_honour_gpc',
			'holvek_htaccess_web_backups_retired',
			'holvek_auto_index',
		);
		$quiet    = array(
			'holvek_encryption_salt',
			'holvek_license_key',
			'holvek_api_key',
			'holvek_cloud_revoked',
			'holvek_cloud_verification',
			'holvek_user_tier',
			'holvek_google_indexing_key',
			'holvek_openai_key',
			'holvek_anthropic_key',
			'holvek_ga4_credentials',
			'holvek_indexnow_key',
			'holvek_indexnow_log',
			'holvek_indexnow_queue',
			'holvek_indexnow_flush_pending',
			'holvek_gsc_token',
			'holvek_gsc_config',
			'holvek_bing_api_key',
			'holvek_social_apps',
			'holvek_social_accounts',
			'holvek_serpapi_key',
			'holvek_robots_txt',
			'holvek_last_audit_results',
			'holvek_audit_history',
			'holvek_audit_results',
			'holvek_audit_progress',
			'holvek_404_logs',
			'holvek_stale_cornerstone_ids',
			'holvek_htaccess_backups',
		);

		foreach ( $quiet as $name ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = %s AND autoload NOT IN ('no', 'off', 'auto-off')",
					$name
				)
			);
		}

		foreach ( $frontEnd as $name ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name = %s AND autoload NOT IN ('yes', 'on', 'auto-on', 'auto')",
					$name
				)
			);
		}
	}

	public static function deactivate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			$siteIds = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $siteIds as $siteId ) {
				switch_to_blog( (int) $siteId );
				self::deactivateSite();
				restore_current_blog();
			}
			return;
		}

		self::deactivateSite();
	}

	private static function deactivateSite(): void {
		// Stop all background work before the plugin is disabled.
		\Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor::deactivate();
		\Holvek\WordPress\Core\Queue\QueueManager::deactivate();
		self::clearScheduledWork();

		// Remove plugin-owned rewrite rules before flushing so disabled plugins
		// do not leave virtual endpoints active in the persisted ruleset.
		self::sitemapRoutes()->removeRulesAndFlush();
	}

	private static function sitemapRoutes(): SitemapRouteManager {
		return new SitemapRouteManager(
			new SitemapGenerator(),
			new NewsSitemapGenerator(),
			new VideoSitemapGenerator()
		);
	}

	private static function clearScheduledWork(): void {
		$hooks = array(
			'holvek_process_job',
			'holvek_flush_404_buffer',
			'holvek_scan_orphaned',
			'holvek_stale_cornerstone_check',
			'holvek_cornerstone_staleness_check',
			'holvek_weekly_audit',
			'holvek_monthly_audit',
			'holvek_scheduled_audit',
			'holvek_rank_check',
			'holvek_prune_audit_log',
			'holvek_404_cleanup',
			'holvek_apply_retention',
			'holvek_run_technical_audit',
			'holvek_blc_scan_batch',
			'holvek_weekly_usage_email',
		);

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
				as_unschedule_all_actions( $hook, array(), 'holvek' );
			}
		}
	}
}
