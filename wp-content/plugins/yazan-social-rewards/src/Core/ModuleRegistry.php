<?php
/**
 * Module registry.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core;

use Yazan\Rewards\Core\Contracts\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the active modules and orders them by declared dependencies so that a
 * module's register()/boot() always runs after everything it depends on.
 *
 * The set of modules is filterable (`yazan_rewards/modules`) — a third party or
 * a future Yazan add-on can add or remove an engine without editing this plugin.
 */
final class ModuleRegistry {

	/**
	 * Registered modules keyed by id, in insertion order.
	 *
	 * @var array<string,ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Dependency-sorted module ids (lazy, memoised).
	 *
	 * @var string[]|null
	 */
	private ?array $order = null;

	/**
	 * Add a module. Later registration of the same id replaces the earlier one.
	 *
	 * @param ModuleInterface $module Module.
	 * @return void
	 */
	public function add( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
		$this->order                    = null; // Invalidate the sort.
	}

	/**
	 * Whether a module id is present.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->modules[ $id ] );
	}

	/**
	 * All modules in dependency order (dependencies first). Modules whose
	 * dependencies are missing are skipped (with a debug log) rather than fatally
	 * failing, keeping the platform resilient.
	 *
	 * @return ModuleInterface[]
	 */
	public function all(): array {
		if ( null === $this->order ) {
			$this->order = $this->resolve_order();
		}

		$ordered = array();
		foreach ( $this->order as $id ) {
			$ordered[] = $this->modules[ $id ];
		}
		return $ordered;
	}

	/**
	 * Topologically sort module ids by dependency. Skips modules that depend on
	 * an absent module. Cycles are broken defensively (a module is emitted once
	 * its resolvable dependencies are placed).
	 *
	 * @return string[]
	 */
	private function resolve_order(): array {
		$available = array_keys( $this->modules );
		$resolved  = array();
		$seen      = array();

		$visit = function ( string $id, array $trail ) use ( &$visit, &$resolved, &$seen, $available ) {
			if ( isset( $seen[ $id ] ) || in_array( $id, $trail, true ) ) {
				return; // Already placed or cycle — stop.
			}

			foreach ( $this->modules[ $id ]->dependencies() as $dep ) {
				if ( ! in_array( $dep, $available, true ) ) {
					// A hard dependency is missing — skip this module entirely.
					$seen[ $id ] = 'skipped';
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'Yazan Rewards: module "%s" skipped — missing dependency "%s".', $id, $dep ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					return;
				}
				$visit( $dep, array_merge( $trail, array( $id ) ) );
			}

			if ( 'skipped' === ( $seen[ $id ] ?? '' ) ) {
				return;
			}
			$seen[ $id ]  = true;
			$resolved[]   = $id;
		};

		foreach ( $available as $id ) {
			$visit( $id, array() );
		}

		return $resolved;
	}
}
