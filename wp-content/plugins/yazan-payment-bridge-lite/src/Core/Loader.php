<?php
/**
 * Hook loader.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Core;

use Yazan\PaymentBridge\Contracts\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a {@see Hookable}'s declarative hooks() array into real
 * add_action()/add_filter() registrations.
 */
final class Loader {

	/**
	 * Register every hook a Hookable declares.
	 *
	 * @param Hookable $target Object declaring hooks.
	 * @return void
	 */
	public static function register( Hookable $target ): void {
		foreach ( $target->hooks() as $hook ) {
			$type     = $hook['type'] ?? 'action';
			$name     = (string) ( $hook['hook'] ?? '' );
			$method   = (string) ( $hook['method'] ?? '' );
			$priority = (int) ( $hook['priority'] ?? 10 );
			$args     = (int) ( $hook['args'] ?? 1 );

			if ( '' === $name || '' === $method || ! method_exists( $target, $method ) ) {
				continue;
			}

			if ( 'filter' === $type ) {
				add_filter( $name, array( $target, $method ), $priority, $args );
			} else {
				add_action( $name, array( $target, $method ), $priority, $args );
			}
		}
	}
}
