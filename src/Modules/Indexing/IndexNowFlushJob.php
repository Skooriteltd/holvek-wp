<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one IndexNow batch. BingIndexNowApi schedules the next batch when
 * URLs remain, so a publish never waits on the network.
 */
class IndexNowFlushJob {

	/**
	 * QueueManager constructs every job with the payload array.
	 *
	 * @param array<string, mixed> $data Unused. The URL list lives in the queue option.
	 */
	public function __construct( array $data ) {
		unset( $data );
	}

	public function handle(): void {
		( new BingIndexNowApi() )->flushQueue();
	}
}
