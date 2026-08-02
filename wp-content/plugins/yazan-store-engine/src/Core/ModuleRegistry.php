<?php
/**
 * Holds modules and orders them by declared dependency.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core;

use Yazan\Stores\Core\Contracts\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency-ordered module collection.
 */
final class ModuleRegistry {

	/** @var array<string,ModuleInterface> */
	private array $modules = array();

	/** @var string[]|null Memoised topological order. */
	private ?array $order = null;

	/**
	 * Add (or replace) a module.
	 *
	 * @param ModuleInterface $module Module.
	 * @return void
	 */
	public function add( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
		$this->order                    = null;
	}

	public function has( string $id ): bool {
		return isset( $this->modules[ $id ] );
	}

	/**
	 * Modules in dependency order.
	 *
	 * @return ModuleInterface[]
	 */
	public function all(): array {
		$out = array();

		foreach ( $this->resolve_order() as $id ) {
			if ( isset( $this->modules[ $id ] ) ) {
				$out[] = $this->modules[ $id ];
			}
		}

		return $out;
	}

	/**
	 * Depth-first topological sort.
	 *
	 * A module whose dependency is absent is DROPPED, not fataled — a missing optional integration
	 * should degrade, not take the site down. It is logged under WP_DEBUG so the degradation is
	 * discoverable.
	 *
	 * @return string[]
	 */
	private function resolve_order(): array {
		if ( null !== $this->order ) {
			return $this->order;
		}

		$ordered = array();
		$seen    = array();

		$visit = function ( string $id, array $trail ) use ( &$visit, &$ordered, &$seen ) {
			if ( isset( $seen[ $id ] ) || in_array( $id, $trail, true ) ) {
				return;
			}

			$module = $this->modules[ $id ] ?? null;

			if ( null === $module ) {
				return;
			}

			foreach ( $module->dependencies() as $dep ) {
				if ( ! isset( $this->modules[ $dep ] ) ) {
					$seen[ $id ] = 'skipped';

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( sprintf( '[yazan-stores] module "%s" skipped: missing dependency "%s"', $id, $dep ) );
					}

					return;
				}

				$visit( $dep, array_merge( $trail, array( $id ) ) );
			}

			if ( isset( $seen[ $id ] ) ) {
				return;
			}

			$seen[ $id ] = true;
			$ordered[]   = $id;
		};

		foreach ( array_keys( $this->modules ) as $id ) {
			$visit( $id, array() );
		}

		$this->order = $ordered;

		return $ordered;
	}
}
