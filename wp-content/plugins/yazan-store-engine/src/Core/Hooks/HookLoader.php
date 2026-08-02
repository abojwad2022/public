<?php
/**
 * Turns a Hookable's declared array into real add_action/add_filter calls.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Hooks;

use Yazan\Stores\Core\Contracts\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place hooks are attached from declarations.
 */
final class HookLoader {

	/**
	 * Wire every hook an object declares.
	 *
	 * A malformed entry is skipped rather than fatal — but under WP_DEBUG it is logged, because a
	 * silently dropped hook is exactly the kind of failure that is discovered weeks later by its
	 * absence. The sibling implementation in yazan-social-rewards skips silently; that is the one
	 * place this deliberately diverges.
	 *
	 * @param Hookable $object Object declaring hooks.
	 * @return void
	 */
	public function register( Hookable $object ): void {
		foreach ( $object->hooks() as $hook ) {
			$type     = $hook['type'] ?? 'action';
			$name     = (string) ( $hook['hook'] ?? '' );
			$method   = (string) ( $hook['method'] ?? '' );
			$priority = isset( $hook['priority'] ) ? (int) $hook['priority'] : 10;
			$args     = isset( $hook['args'] ) ? (int) $hook['args'] : 1;

			if ( '' === $name || '' === $method || ! method_exists( $object, $method ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[yazan-stores] dropped hook declaration on %s: hook="%s" method="%s"',
							get_class( $object ),
							$name,
							$method
						)
					);
				}
				continue;
			}

			$callback = array( $object, $method );

			if ( 'filter' === $type ) {
				add_filter( $name, $callback, $priority, $args );
			} else {
				add_action( $name, $callback, $priority, $args );
			}
		}
	}
}
