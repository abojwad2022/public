<?php
/**
 * Yazan AI — response cache.
 *
 * Idempotent generations (the same task + model + inputs) are served from a transient instead of
 * spending another provider call. This is a real cost saver on the dashboard, where an owner often
 * regenerates the same product a few times while tweaking. Keyed by a hash of everything that would
 * change the output.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed cache for gateway results.
 */
class Yazan_AI_Cache {

	/** Transient key prefix. */
	const PREFIX = 'yz_ai_';

	/** Default TTL for a cached generation (seconds). */
	const TTL = 3600; // 1 hour.

	/**
	 * Build a stable cache key from the task and its normalized request.
	 *
	 * @param string $task    Task name.
	 * @param string $model   Concrete model id.
	 * @param array  $request Normalized request (system/messages/images/flags).
	 * @return string
	 */
	public static function key( $task, $model, array $request ) {
		$material = wp_json_encode(
			array(
				't' => $task,
				'm' => $model,
				'r' => $request,
			)
		);
		return self::PREFIX . md5( (string) $material );
	}

	/**
	 * Read a cached envelope, or null on miss.
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$value = get_transient( $key );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store an envelope.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Envelope to cache.
	 * @param int    $ttl   Optional TTL override.
	 * @return void
	 */
	public static function set( $key, array $value, $ttl = self::TTL ) {
		set_transient( $key, $value, max( 60, (int) $ttl ) );
	}
}
