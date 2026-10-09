<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one fixed slice of the site-wide technical audit.
 */
class TechnicalAuditJob {

	/**
	 * @param array<string, mixed> $data Unused. Progress lives in the audit option.
	 */
	public function __construct( array $data ) {
		unset( $data );
	}

	public function handle(): void {
		( new TechnicalSeoAuditEngine() )->runBatch();
	}
}
