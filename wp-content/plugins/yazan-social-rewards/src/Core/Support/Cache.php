<?php
/**
 * Object-cache helper.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over the WordPress object cache with a fixed group. Used for
 * derived values (balances, tier lookups) that are expensive to recompute but
 * cheap to invalidate on ledger writes.
 */
final class Cache {

	private const GROUP = 'yazan_rewards';

	/**
	 * @param string $key     Cache key.
	 * @param mixed  $default Returned on miss.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		return $found ? $value : $default;
	}

	/**
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds (0 = default).
	 * @return void
	 */
	public function set( string $key, $value, int $ttl = 0 ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * @param string $key Cache key.
	 * @return void
	 */
	public function delete( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
	}
}
