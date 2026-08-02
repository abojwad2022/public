<?php
/**
 * Cache boundary.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned cache access.
 */
interface CachePort {

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null );

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 * @return void
	 */
	public function set( $key, $value, $ttl = 0 );

	/**
	 * Invalidate everything by moving the version that is part of every key.
	 *
	 * @return void
	 */
	public function flush();

	/** @return string Current cache version. */
	public function version();
}
