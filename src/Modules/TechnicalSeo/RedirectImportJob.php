<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one batch of a CSV redirect import. The REST request only validates
 * and queues, so a 1000-row file does not insert inside that request.
 */
class RedirectImportJob {

	/**
	 * QueueManager constructs every job with the payload array.
	 *
	 * @param array<string, mixed> $data Unused. Rows live in the queue option.
	 */
	public function __construct( array $data ) {
		unset( $data );
	}

	public function handle(): void {
		( new RedirectManager() )->importQueuedBatch();
	}
}
