<?php
/**
 * Internal domain event bus.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Events;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The inter-module backbone. Engines announce state changes as {@see Event}s and
 * other engines subscribe — no engine ever references another's classes.
 *
 * Under the hood dispatch is a namespaced WordPress action, so events are ALSO
 * public extension points: third parties can `add_action( 'yazan_rewards/event/points_credited', … )`.
 */
final class EventBus {

	/** Prefix for every event action name. */
	private const PREFIX = 'yazan_rewards/event/';

	/**
	 * @param Container $container Service container (subscribers are resolved lazily).
	 */
	public function __construct( private Container $container ) {}

	/**
	 * Dispatch an event to all listeners.
	 *
	 * @param Event $event Event object.
	 * @return void
	 */
	public function dispatch( Event $event ): void {
		/**
		 * Fires when a Yazan Rewards domain event is dispatched.
		 *
		 * The dynamic portion of the hook, `$event->name()`, is the event name
		 * (e.g. `points_credited`, `order_rewarded`).
		 *
		 * @param Event $event The dispatched event.
		 */
		do_action( self::PREFIX . $event->name(), $event );

		// A catch-all channel for cross-cutting listeners (analytics, logging).
		do_action( self::PREFIX . '*', $event );
	}

	/**
	 * Subscribe a single listener to an event name.
	 *
	 * @param string   $event_name Event name (without prefix).
	 * @param callable $listener   fn(Event $event): void.
	 * @param int      $priority   Hook priority.
	 * @return void
	 */
	public function listen( string $event_name, callable $listener, int $priority = 10 ): void {
		add_action( self::PREFIX . $event_name, $listener, $priority, 1 );
	}

	/**
	 * Register every mapping a subscriber declares. The subscriber is invoked by
	 * method name so instances stay lightweight until an event actually fires.
	 *
	 * @param SubscriberInterface $subscriber Subscriber.
	 * @return void
	 */
	public function subscribe( SubscriberInterface $subscriber ): void {
		foreach ( $subscriber->subscribed_events() as $event_name => $handler ) {
			$method   = is_array( $handler ) ? ( $handler[0] ?? '' ) : (string) $handler;
			$priority = is_array( $handler ) ? (int) ( $handler[1] ?? 10 ) : 10;

			if ( '' === $method || ! method_exists( $subscriber, $method ) ) {
				continue;
			}

			$this->listen(
				(string) $event_name,
				static function ( Event $event ) use ( $subscriber, $method ) {
					$subscriber->{$method}( $event );
				},
				$priority
			);
		}
	}
}
