<?php
/**
 * Event subscriber contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An object that reacts to internal domain events on the EventBus. Keeping the
 * mapping declarative lets one engine subscribe to another engine's events
 * without ever referencing its classes — the core of the modular design.
 */
interface SubscriberInterface {

	/**
	 * Map of event name => [ method, priority ] (priority optional, default 10).
	 *
	 * @return array<string,array{0:string,1?:int}|string>
	 */
	public function subscribed_events(): array;
}
