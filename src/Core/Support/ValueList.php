<?php

namespace Holvek\WordPress\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a zero-based list without relying on a WordPress 6.5 polyfill.
 */
class ValueList {

	/**
	 * Whether the array keys are 0, 1, 2, and so on.
	 *
	 * An empty array is a list. A map, or a list with a gap, is not.
	 */
	public static function isList( array $value ): bool {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}

		return true;
	}
}
