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
			$stored        = (string) get_option( self::VERSION_OPTION, '1' );
			$this->version = $stored . '.' . ( defined( 'YAZAN_CORE_VERSION' ) ? YAZAN_CORE_VERSION : '0' );
		}

		return $this->version;
	}

	/**
	 * @param string $key Key.
	 * @return string
	 */
	private function full_key( $key ) {
		return 'yzhp:' . $this->version() . ':' . $key;
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
		$next = (int) get_option( self::VERSION_OPTION, '1' ) + 1;

		update_option( self::VERSION_OPTION, (string) $next, false );

		$this->version = null;
	}
}
