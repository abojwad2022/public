<?php
/**
 * Event dispatcher → WordPress actions.
 *
 * Each event fires twice: once under its own name so a listener can be specific, and once under a
 * generic name so cross-cutting listeners (audit, cache) subscribe in one line.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Event\DomainEvent;
use Yazan\Homepage\Domain\Port\EventDispatcherPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes domain events as WordPress actions.
 */
final class WpEventDispatcher implements EventDispatcherPort {

	/** The action every homepage event fires. */
	const ANY_HOOK = 'yazan_homepage_event';

	/**
	 * @param DomainEvent $event Event.
	 * @return void
	 */
	public function dispatch( DomainEvent $event ) {
		do_action( 'yazan_' . str_replace( '.', '_', $event->name() ), $event );
		do_action( self::ANY_HOOK, $event );
	}

	/**
	 * @param DomainEvent[] $events Events.
	 * @return void
	 */
	public function dispatch_all( array $events ) {
		foreach ( $events as $event ) {
			if ( $event instanceof DomainEvent ) {
				$this->dispatch( $event );
			}
		}
	}
}
