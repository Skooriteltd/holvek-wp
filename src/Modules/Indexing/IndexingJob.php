<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IndexingJob {

	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function handle(): void {
		$url    = $this->data['url'] ?? '';
		$action = $this->data['action'] ?? 'URL_UPDATED';
		$postId = (int) ( $this->data['post_id'] ?? 0 );

		if ( empty( $url ) ) {
			return;
		}

		$quotaManager = new QuotaManager();

		if (
			! empty( $this->data['submit_google'] ) &&
			get_option( 'holvek_enable_google_indexing_api', 'no' ) === 'yes' &&
			$quotaManager->canPingGoogle()
		) {
			$googleApi     = new GoogleIndexingApi();
			$googleSuccess = $googleApi->ping( $url, $action, $postId );
			if ( $googleSuccess ) {
				$quotaManager->incrementGoogle();
			}
		}

		// IndexNow is queued by BingIndexNowApi and sent by IndexNowFlushJob.
		// This job must not open that request, or a publish would notify twice.
	}
}
