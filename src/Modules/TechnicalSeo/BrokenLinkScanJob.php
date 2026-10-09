<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks one bounded batch of published posts for broken outbound links.
 */
class BrokenLinkScanJob {

	/**
	 * @param array<string, mixed> $data Unused. Progress lives in the scan option.
	 */
	public function __construct( array $data ) {
		unset( $data );
	}

	public function handle(): void {
		( new BrokenLinkChecker() )->processBatch();
	}
}
