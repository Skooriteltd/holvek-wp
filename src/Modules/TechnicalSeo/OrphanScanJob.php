<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts inbound links for one page of published posts.
 */
class OrphanScanJob {

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
		$offset = max( 0, (int) ( $this->data['offset'] ?? 0 ) );
		( new OrphanedContentFinder() )->scanBatch( $offset );
	}
}
