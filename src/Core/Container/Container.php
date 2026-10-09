<?php

namespace Holvek\WordPress\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Container {

	private array $instances = array();
	private array $bindings  = array();

	public function singleton( string $service, $concrete = null ): void {
		if ( $concrete === null ) {
			$concrete = $service;
		}

		if ( $concrete instanceof \Closure ) {
			$this->bindings[ $service ] = $concrete;
		} else {
			$this->bindings[ $service ] = function () use ( $concrete ) {
				return new $concrete();
			};
		}
	}

	public function get( string $service ) {
		if ( isset( $this->instances[ $service ] ) ) {
			return $this->instances[ $service ];
		}

		if ( isset( $this->bindings[ $service ] ) ) {
			$this->instances[ $service ] = $this->bindings[ $service ]();
			return $this->instances[ $service ];
		}

		throw new ContainerException( sprintf( 'No binding found for %s', esc_html( $service ) ) );
	}
}
