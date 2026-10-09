<?php

namespace Holvek\WordPress\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks the rank provider about one fixed slice of tracked keywords.
 */
class RankCheckJob {

	/**
	 * @param array<string, mixed> $data
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function handle(): void {
		( new KeywordRankTracker() )->processQueuedCheck( $this->data );
	}
}
