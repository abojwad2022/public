<?php
/**
 * Cache adapter — object cache with a persistent version.
 *
 * The version is part of the KEY, not the value: a stale entry becomes unreachable instead of
 * merely wrong, so there is no delete anyone can forget. Same idea as Yazan_Permissions, and
 * YAZAN_CORE_VERSION is folded in so a deploy cannot serve a payload from the previous build.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Port\CachePort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned wp_cache access with a transient fallback.
 */
final class WpObjectCacheAdapter implements CachePort {

	/** Cache group. */
	const GROUP = 'yazan_homepage';

	/** Option holding the cache version. */
	const VERSION_OPTION = 'yazan_homepage_cache_version';

	/** @var string|null */
	private $version = null;

	/** @return string */
	public function version() {
		if ( null === $this->version ) {
			$stored        = (string) \Yazan_Store_Options::get( self::VERSION_OPTION, '1' );
			$this->version = $stored . '.' . ( defined( 'YAZAN_CORE_VERSION' ) ? YAZAN_CORE_VERSION : '0' );
		}

		return $this->version;
	}

	/**
	 * Namespace a key with the version AND the store.
	 *
	 * ⚠️ THE STORE IN THIS KEY IS NOT AN OPTIMISATION. Without it `live:default` means one thing for
	 * the whole installation, so the first store to warm the cache serves its rendered homepage to
	 * every other store. The page returns 200 and renders perfectly — it is simply the wrong brand,
	 * which is the hardest kind of bug to notice and the worst kind to explain.
	 *
	 * The version segment is now per-store too, so invalidating one store's homepage no longer
	 * empties everybody's cache.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function full_key( $key ) {
		$store = class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;

		return 'yzhp:' . $store . ':' . $this->version() . ':' . $key;
	}

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$found = false;
		$value = wp_cache_get( $this->full_key( $key ), self::GROUP, false, $found );

		if ( $found ) {
			return $value;
		}

		// No persistent object cache on this host: a transient survives the request, wp_cache does not.
		$value = get_transient( 'yzhp_' . md5( $this->full_key( $key ) ) );

		return false === $value ? $default : $value;
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 * @return void
	 */
	public function set( $key, $value, $ttl = 0 ) {
		wp_cache_set( $this->full_key( $key ), $value, self::GROUP, $ttl );
		set_transient( 'yzhp_' . md5( $this->full_key( $key ) ), $value, $ttl ? $ttl : HOUR_IN_SECONDS * 12 );
	}

	/**
	 * Move the version — every existing key becomes unreachable at once.
	 *
	 * @return void
	 */
	public function flush() {
		// Per-store, so one store's publish does not cost every other store a cold cache.
		$next = (int) \Yazan_Store_Options::get( self::VERSION_OPTION, '1' ) + 1;

		\Yazan_Store_Options::set( self::VERSION_OPTION, (string) $next );

		$this->version = null;
	}
}
