<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Holvek\WordPress\Core\Event\EventManager;
use Holvek\WordPress\Core\Queue\QueueManager;

class IndexingModule {

	private EventManager $eventManager;
	private QueueManager $queueManager;

	public function __construct( EventManager $eventManager, QueueManager $queueManager ) {
		$this->eventManager = $eventManager;
		$this->queueManager = $queueManager;
	}

	public function boot(): void {
		// Hook into post publication/update
		$this->eventManager->addAction( 'transition_post_status', array( $this, 'handlePostTransition' ), 10, 3 );
	}

	public function handlePostTransition( string $newStatus, string $oldStatus, \WP_Post $post ): void {
		if ( $newStatus !== 'publish' ) {
			return;
		}

		// IndexNow is off until an administrator explicitly enables it. It is
		// the only generic publication notification supported by this module.
		$autoIndex = get_option( 'holvek_auto_index', 'no' );
		if ( $autoIndex !== 'yes' ) {
			return; // Skip automatic indexing if disabled in settings
		}

		// Check if the post is set to noindex
		$noindex = get_post_meta( $post->ID, '_holvek_noindex', true );
		if ( $noindex === 'yes' ) {
			return;
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return;
		}

		// Dispatch a bounded background notification without blocking the save
		// request. Google is considered only for content that meets the API's
		// narrow JobPosting/BroadcastEvent eligibility rules.
				$strictMode = get_option( 'holvek_google_indexing_strict_mode', 'no' ) === 'yes';
		$submitGoogle       = $strictMode ? GoogleIndexingApi::isEligiblePost( $post ) : true;

		$this->queueManager->push(
			IndexingJob::class,
			array(
				'post_id'       => $post->ID,
				'url'           => $url,
				'action'        => 'URL_UPDATED',
				'submit_google' => $submitGoogle,
			)
		);
	}
}
