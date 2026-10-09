<?php

namespace Holvek\WordPress\Core\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventManager {

	public function addAction( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): void {
		add_action( $hook, $callback, $priority, $acceptedArgs );
	}

	public function addFilter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): void {
		add_filter( $hook, $callback, $priority, $acceptedArgs );
	}

	public function doAction( string $hook, ...$args ): void {
		do_action( $hook, ...$args );
	}

	public function applyFilters( string $hook, $value, ...$args ) {
		return apply_filters( $hook, $value, ...$args );
	}
}
