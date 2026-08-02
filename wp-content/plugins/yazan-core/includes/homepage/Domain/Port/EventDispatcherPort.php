<?php
/**
 * Event dispatch boundary.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

use Yazan\Homepage\Domain\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes domain events to listeners.
 */
interface EventDispatcherPort {

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function dispatch( DomainEvent $event );

	/**
	 * @param DomainEvent[] $events Events.
	 * @return void
	 */
	public function dispatch_all( array $events );
}
