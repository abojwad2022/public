<?php
/**
 * What yazan-core's modules do when the store registry changes.
 *
 * The store engine publishes its events as plain `do_action()` calls — `EventBus::dispatch()` fires
 * `yazan_stores/event/{name}` and `yazan_stores/event` — precisely so a consumer needs no class from
 * that plugin. This listens by hook name, as a literal string, so yazan-core keeps working with the
 * engine deactivated: the hooks simply never fire.
 *
 * WHAT IS NOT HERE, AND WHY
 * -------------------------
 * There is no `store/created` handler seeding default settings. There is nothing to seed: a new
 * store inherits every option from the platform through `Yazan_Store_Options`, so it starts out
 * configured and diverges only where it is deliberately changed. A seeding step would copy values
 * that are already correct and then stop tracking the platform default when it moves.
 *
 * Nor is there a `store/cloned` handler copying option rows. `StoreService::clone_store()` already
 * copies every row of the store-settings table, and the option overrides live in that table — a
 * direct dividend of storing them there rather than inventing a second home for them.
 *
 * What IS here is the work nothing else does: invalidating caches that a settings change makes
 * wrong, and stopping scheduled work for a store that is no longer running.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges store-registry events to yazan-core's modules.
 */
class Yazan_Store_Events {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'yazan_stores/event/store/setting_changed', array( __CLASS__, 'on_setting_changed' ) );
		add_action( 'yazan_stores/event/store/archived', array( __CLASS__, 'on_stopped' ) );
		add_action( 'yazan_stores/event/store/suspended', array( __CLASS__, 'on_stopped' ) );
		add_action( 'yazan_stores/event/store/activated', array( __CLASS__, 'on_started' ) );
		add_action( 'yazan_stores/event/store/updated', array( __CLASS__, 'on_updated' ) );
	}

	/**
	 * A setting changed: anything derived from it is now wrong.
	 *
	 * Only `option.*` and `module.*` keys matter here. A store renaming itself does not invalidate
	 * a module's configuration, and flushing on every key would make the cache useless during an
	 * ordinary edit session.
	 *
	 * @param mixed $event Store engine event object.
	 * @return void
	 */
	public static function on_setting_changed( $event ) {
		$key = self::event_value( $event, 'key' );

		if ( 0 !== strpos( $key, 'option.' ) && 0 !== strpos( $key, 'module.' ) ) {
			return;
		}

		Yazan_Store_Options::flush();
	}

	/**
	 * A store stopped running: suspended (temporarily) or archived (for good).
	 *
	 * Both stop side-effects; NEITHER touches data or schema. That distinction is the reason the
	 * engine emits three separate events instead of one `status_changed` — a suspended store is
	 * expected back, and a listener that deleted its rows would make the suspension permanent.
	 *
	 * @param mixed $event Store engine event object.
	 * @return void
	 */
	public static function on_stopped( $event ) {
		$store_id = self::store_id( $event );

		if ( $store_id < 1 ) {
			return;
		}

		self::unschedule( 'yazan_homepage_publish_due', $store_id );
		self::unschedule( 'yazan_homepage_schedule_boundary', $store_id );
	}

	/**
	 * A store came back. Scheduling is re-established lazily by the module's own boot path, so
	 * there is nothing to do beyond dropping caches computed while it was off.
	 *
	 * @param mixed $event Store engine event object.
	 * @return void
	 */
	public static function on_started( $event ) {
		unset( $event );

		Yazan_Store_Options::flush();
	}

	/**
	 * A store's record changed. A theme change invalidates the rendered homepage.
	 *
	 * @param mixed $event Store engine event object.
	 * @return void
	 */
	public static function on_updated( $event ) {
		unset( $event );

		Yazan_Store_Options::flush();
	}

	/**
	 * Clear a store's scheduled hook.
	 *
	 * Uses WP-Cron directly rather than Action Scheduler: these two hooks are registered with
	 * `wp_schedule_event`, and asking the wrong scheduler to unschedule them would report success
	 * and do nothing.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $store_id Store.
	 * @return void
	 */
	private static function unschedule( $hook, $store_id ) {
		/*
		 * ⚠️ TWO SIGNATURES, BECAUSE THERE ARE TWO GENERATIONS OF JOB.
		 *
		 * These hooks were originally scheduled with NO arguments. Looking them up with
		 * `array($store_id)` therefore found nothing — WP-Cron keys an event by hook AND args, so
		 * the two are different events. Archiving a store reported success and left every one of
		 * its jobs running.
		 *
		 * The argless form is cleared too, for events scheduled before jobs carried their store.
		 * It is deliberately cleared LAST: a legacy argless event belongs to whichever store
		 * scheduled it, and with one store that is this one.
		 */
		foreach ( array( array( (int) $store_id ), array() ) as $args ) {
			$timestamp = wp_next_scheduled( $hook, $args );

			while ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook, $args );
				$timestamp = wp_next_scheduled( $hook, $args );
			}
		}
	}

	/**
	 * The store an event concerns.
	 *
	 * Events carry either a `store_id` or a whole `store` entity depending on which one the emitter
	 * had to hand; both shapes are read here so a caller never has to know which.
	 *
	 * @param mixed $event Store engine event object.
	 * @return int
	 */
	private static function store_id( $event ) {
		$direct = (int) self::event_value( $event, 'store_id' );

		if ( $direct > 0 ) {
			return $direct;
		}

		$store = is_object( $event ) && method_exists( $event, 'get' ) ? $event->get( 'store' ) : null;

		return is_object( $store ) && method_exists( $store, 'id' ) ? (int) $store->id() : 0;
	}

	/**
	 * Read a payload key defensively.
	 *
	 * @param mixed  $event Store engine event object.
	 * @param string $key   Payload key.
	 * @return string
	 */
	private static function event_value( $event, $key ) {
		if ( ! is_object( $event ) || ! method_exists( $event, 'get' ) ) {
			return '';
		}

		$value = $event->get( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
