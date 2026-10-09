<?php

namespace Holvek\WordPress\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;

class SeoModule {

	private EventManager $eventManager;
	private MetaTagsGenerator $metaGenerator;
	private OpenGraphGenerator $openGraphGenerator;

	public function __construct(
		EventManager $eventManager,
		MetaTagsGenerator $metaGenerator,
		OpenGraphGenerator $openGraphGenerator
	) {
		$this->eventManager       = $eventManager;
		$this->metaGenerator      = $metaGenerator;
		$this->openGraphGenerator = $openGraphGenerator;
	}

	public function boot(): void {
		// Hook into WordPress standard header output
		$this->eventManager->addAction( 'wp_head', array( $this->metaGenerator, 'outputStandardMetaTags' ), 1 );
		$this->eventManager->addAction( 'wp_head', array( $this->openGraphGenerator, 'outputSocialMetaTags' ), 2 );

		// Disable default WordPress title tag generation if theme supports title-tag
		$this->eventManager->addFilter( 'pre_get_document_title', array( $this->metaGenerator, 'getDocumentTitle' ), 10, 0 );

		// Boot RSS Optimizations module
		$rssOptimizations = new RssOptimizations();
		$rssOptimizations->register();
	}
}
