<?php
/**
 * An object that reacts to domain events.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps event names to handler methods.
 */
interface SubscriberInterface {

	/**
	 * @return array<string,array{0:string,1?:int}|string> event name => method, or [method, priority].
	 */
	public function subscribed_events(): array;
}
