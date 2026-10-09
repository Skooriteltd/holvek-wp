<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;

/**
 * Boots technical SEO routines including redirects, 404 monitoring, and their admin REST routes.
 */
class TechnicalSeoModule {

	private EventManager $eventManager;
	private RedirectManager $redirectManager;
	private ErrorMonitor $errorMonitor;

	public function __construct(
		EventManager $eventManager,
		RedirectManager $redirectManager,
		ErrorMonitor $errorMonitor
	) {
		$this->eventManager    = $eventManager;
		$this->redirectManager = $redirectManager;
		$this->errorMonitor    = $errorMonitor;
	}

	public function boot(): void {
		// Boot the 404 monitor — registers its hourly background flush cron
		$this->errorMonitor->boot();

		// Hook into template_redirect to catch redirects and 404s before the template loads
		$this->eventManager->addAction( 'template_redirect', array( $this->redirectManager, 'handleRedirects' ), 1 );
		$this->eventManager->addAction( 'template_redirect', array( $this->errorMonitor, 'log404Errors' ), 99 );

		// Register custom REST routes for redirect management and 404 monitors
		$this->eventManager->addAction( 'rest_api_init', array( $this->redirectManager, 'registerRoutes' ) );
		$this->eventManager->addAction( 'rest_api_init', array( $this->errorMonitor, 'registerRoutes' ) );

		// Clean up cron schedule on deactivation
		$this->eventManager->addAction( 'holvek_deactivation', array( 'Holvek\WordPress\Modules\TechnicalSeo\ErrorMonitor', 'deactivate' ) );

		// Custom 404 page handler
		$custom404 = new Custom404Handler();
		$custom404->register();

		// Favicon audit — admin notice when no site icon is configured
		$faviconAudit = new FaviconAudit();
		$faviconAudit->register();
	}
}
