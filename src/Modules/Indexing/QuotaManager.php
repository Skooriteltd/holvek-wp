<?php

namespace Holvek\WordPress\Modules\Indexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuotaManager {

	private const GOOGLE_LIMIT = 200;
	private const BING_LIMIT   = 10000;

	public function canPingGoogle(): bool {
		return $this->getDailyCount( 'google' ) < self::GOOGLE_LIMIT;
	}

	public function incrementGoogle(): void {
		$this->incrementCount( 'google' );
	}

	public function canPingBing(): bool {
		return $this->getDailyCount( 'bing' ) < self::BING_LIMIT;
	}

	public function incrementBing(): void {
		$this->incrementCount( 'bing' );
	}

	private function getDailyCount( string $engine ): int {
		$key = 'holvek_quota_' . $engine . '_' . gmdate( 'Y-m-d' );
		return (int) get_transient( $key );
	}

	private function incrementCount( string $engine ): void {
		$key   = 'holvek_quota_' . $engine . '_' . gmdate( 'Y-m-d' );
		$count = $this->getDailyCount( $engine );

		if ( $count === 0 ) {
			set_transient( $key, 1, DAY_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, DAY_IN_SECONDS );
		}
	}
}
