<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rechecks one stored broken-link row away from the admin request.
 */
class BrokenLinkRecheckJob {

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
		( new BrokenLinkChecker() )->recheckQueued( $this->data );
	}
}
