<?php
/**
 * Dispatches domain events onto WordPress hooks.
 *
 * Deliberately built ON TOP OF do_action rather than as a private observer list: every event this
 * engine publishes is then extensible by any other plugin with add_action, using the mechanism the
 * whole stack already understands. The bus adds naming discipline, not a parallel universe.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Events;

use Yazan\Stores\Core\Contracts\SubscriberInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event dispatcher.
 */
final class EventBus {

	/** Hook prefix for every named event. */
	public const PREFIX = 'yazan_stores/event/';

	/** Fired for EVERY event, so a listener can observe the whole stream. */
	public const ANY_HOOK = 'yazan_stores/event';

	/**
	 * Publish an event.
	 *
	 * @param Event $event Event.
	 * @return void
	 */
	public function dispatch( Event $event ): void {
		do_action( self::PREFIX . $event->name(), $event );
		do_action( self::ANY_HOOK, $event );
	}

	/**
	 * Convenience: build and dispatch in one call.
	 *
	 * @param string              $name    Event name.
	 * @param array<string,mixed> $payload Data.
	 * @return void
	 */
	public function emit( string $name, array $payload = array() ): void {
		$this->dispatch( new Event( $name, $payload ) );
	}

	/**
	 * Attach a subscriber's declared handlers.
	 *
	 * @param SubscriberInterface $subscriber Subscriber.
	 * @return void
	 */
	public function subscribe( SubscriberInterface $subscriber ): void {
		foreach ( $subscriber->subscribed_events() as $event => $handler ) {
			$method   = is_array( $handler ) ? ( $handler[0] ?? '' ) : (string) $handler;
			$priority = is_array( $handler ) ? (int) ( $handler[1] ?? 10 ) : 10;

			if ( '' === $method || ! method_exists( $subscriber, $method ) ) {
				continue;
			}

			add_action( self::PREFIX . $event, array( $subscriber, $method ), $priority, 1 );
		}
	}
}
