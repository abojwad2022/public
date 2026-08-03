<?php
/**
 * Declarative hook loader.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Hooks;

use Yazan\Rewards\Core\Support\Scheduler;

use Yazan\Rewards\Core\Contracts\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the hooks declared by {@see Hookable} objects. Keeping registration in
 * one place makes hook wiring auditable and lets objects be unit-tested without
 * WordPress by inspecting hooks() directly.
 */
final class HookLoader {

	/**
	 * Register every hook a Hookable declares.
	 *
	 * @param Hookable $object Object declaring hooks.
	 * @return void
	 */
	public function register( Hookable $object ): void {
		foreach ( $object->hooks() as $hook ) {
			$type     = $hook['type'] ?? 'action';
			$name     = $hook['hook'] ?? '';
			$method   = $hook['method'] ?? '';
			$priority = isset( $hook['priority'] ) ? (int) $hook['priority'] : 10;
			$args     = isset( $hook['args'] ) ? (int) $hook['args'] : 1;

			if ( '' === $name || '' === $method || ! method_exists( $object, $method ) ) {
				continue;
			}

			$callback = array( $object, $method );

			/*
			 * ⚠️ A DECLARATION MARKED `'job' => true` IS A QUEUE HANDLER, and queue handlers run
			 * with no host — so nothing tells them which store they belong to unless the job carries
			 * it. `Scheduler::handler()` pops the store off the front of the arguments and runs the
			 * body inside `run_for()`, refusing outright when it is missing.
			 *
			 * Wrapped HERE rather than at the eight registration sites, because a site that forgot
			 * would keep working perfectly — on store 1 — and nothing would report it.
			 */
			if ( ! empty( $hook['job'] ) ) {
				$callback = Scheduler::handler( $callback );

				// +1 for the store that now leads the argument list.
				++$args;
			}

			if ( 'filter' === $type ) {
				add_filter( $name, $callback, $priority, $args );
			} else {
				add_action( $name, $callback, $priority, $args );
			}
		}
	}
}
