<?php
/**
 * Plugin Name: Yazan Rate Limit
 * Description: One shared fixed-window rate limiter for every Yazan surface.
 *
 * WHY AN MU-PLUGIN AND NOT yazan-core
 * -----------------------------------
 * `yazan-social-rewards` has ZERO PHP dependency on `yazan-core`, and the roadmap lists preserving
 * that as insurance worth buying. A limiter living in yazan-core cannot be consumed by rewards
 * without destroying it. An mu-plugin is consumed by all four plugins and owned by none — the same
 * argument that put `Yazan_Store_Context` here.
 *
 * The cost is real and stated: an mu-plugin cannot be deactivated and does not ride a plugin's
 * release cycle. Mitigated by keeping this small, dependency-free, and behind `class_exists()` at
 * every call site — the pattern `yazan_store_id()` already uses.
 *
 * WHAT IT REPLACES
 * ----------------
 * Four separate fixed-window implementations existed, none of which emitted `Retry-After`:
 * `Yazan_AI_Budget::rate_get/rate_bump`, the per-IP transients in `class-yazan-rest-ai.php`, the
 * login throttle in `class-yazan-dashboard-auth.php`, and `Yazan\Rewards\Core\Security\RateLimiter`.
 * This is their shape, generalised — the storage strategy is lifted from `Yazan_AI_Budget`, which
 * was the only one that used the object cache's atomic increment.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Yazan_Rate_Limit' ) ) {
	return;
}

/**
 * Fixed-window counters over the object cache, falling back to transients.
 */
final class Yazan_Rate_Limit {

	/** Key prefix — distinct from all four predecessors so migration cannot collide. */
	const PREFIX = 'yzrl_';

	/** Object-cache group. */
	const GROUP = 'yazan_rate_limit';

	/**
	 * Count a request and refuse it if the window is full.
	 *
	 * ⚠️ THIS IS THE CALL MOST SITES WANT. The predecessor API split "are we over?" from "count
	 * this one" into two methods a caller had to remember to pair — and the way to get that wrong
	 * is to forget the second, which fails in the direction of NOT counting. One call cannot be
	 * half-made.
	 *
	 * `check()` and `hit()` remain separate below for the login-throttle shape, which counts only
	 * failures and clears on success.
	 *
	 * @param string $bucket What is being limited, e.g. 'api.read'.
	 * @param string $actor  Who, e.g. 'tok:12' or 'ip:1.2.3.4'.
	 * @param int    $max    Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @return true|WP_Error
	 */
	public static function consume( $bucket, $actor, $max, $window ) {
		$allowed = self::check( $bucket, $actor, $max, $window );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		self::hit( $bucket, $actor, $window );

		return true;
	}

	/**
	 * Is this actor already at the limit? Does NOT count the current request.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @param int    $max    Ceiling.
	 * @param int    $window Window seconds.
	 * @return true|WP_Error
	 */
	public static function check( $bucket, $actor, $max, $window ) {
		$max = (int) $max;

		// A ceiling of zero means "no limit", matching `Yazan_AI_Settings::user_rate_limit`.
		if ( $max < 1 ) {
			return true;
		}

		if ( self::count( $bucket, $actor ) < $max ) {
			return true;
		}

		$retry = max( 1, self::reset_at( $bucket, $actor, $window ) - time() );

		return new WP_Error(
			'yazan_rate_limited',
			__( 'Too many requests. Try again shortly.', 'yazan' ),
			array(
				'status'      => 429,
				'retry_after' => $retry,
			)
		);
	}

	/**
	 * Count one request.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @param int    $window Window seconds.
	 * @return int The new count.
	 */
	public static function hit( $bucket, $actor, $window ) {
		$key    = self::key( $bucket, $actor );
		$window = max( 1, (int) $window );

		if ( wp_using_ext_object_cache() ) {
			/*
			 * `wp_cache_add` only succeeds if the key is absent, so exactly one concurrent request
			 * creates the window and the rest increment it atomically. This is the only race-free
			 * path; the transient branch below is not, and says so.
			 */
			if ( wp_cache_add( $key, 1, self::GROUP, $window ) ) {
				// Written add-only: the first writer fixes the window end, and a later request
				// cannot push it forward and extend its own limit.
				wp_cache_add( $key . ':reset', time() + $window, self::GROUP, $window );

				return 1;
			}

			return (int) wp_cache_incr( $key, 1, self::GROUP );
		}

		// ⚠️ Read-modify-write. Two simultaneous requests can read the same value and both write
		// n+1, so a burst can exceed the ceiling by roughly the concurrency. Acceptable for abuse
		// control, NOT for anything that must be exact — and an external object cache removes it.
		$count = (int) get_transient( $key );

		if ( ! $count ) {
			set_transient( $key . ':reset', time() + $window, $window );
		}

		set_transient( $key, $count + 1, $window );

		return $count + 1;
	}

	/**
	 * Current count for an actor.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @return int
	 */
	public static function count( $bucket, $actor ) {
		$key = self::key( $bucket, $actor );

		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, self::GROUP );
		}

		return (int) get_transient( $key );
	}

	/**
	 * Requests left in the window.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @param int    $max    Ceiling.
	 * @return int
	 */
	public static function remaining( $bucket, $actor, $max ) {
		return max( 0, (int) $max - self::count( $bucket, $actor ) );
	}

	/**
	 * When the current window ends.
	 *
	 * The predecessors stored no window-end timestamp, which is why none of them could emit a
	 * truthful `Retry-After`. A missing companion key falls back to a full window from now — an
	 * over-estimate, which tells a client to wait too long rather than to retry too soon.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @param int    $window Window seconds.
	 * @return int Unix timestamp.
	 */
	public static function reset_at( $bucket, $actor, $window ) {
		$key = self::key( $bucket, $actor ) . ':reset';

		$reset = wp_using_ext_object_cache()
			? (int) wp_cache_get( $key, self::GROUP )
			: (int) get_transient( $key );

		return $reset > 0 ? $reset : time() + max( 1, (int) $window );
	}

	/**
	 * Forget an actor's window — used after a successful login.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @return void
	 */
	public static function clear( $bucket, $actor ) {
		$key = self::key( $bucket, $actor );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			wp_cache_delete( $key . ':reset', self::GROUP );

			return;
		}

		delete_transient( $key );
		delete_transient( $key . ':reset' );
	}

	/**
	 * The caller's address.
	 *
	 * `REMOTE_ADDR` only. `X-Forwarded-For` is attacker-controlled unless a trusted proxy is in
	 * front, and trusting it without one IS a rate-limit bypass — the client picks its own bucket.
	 * Behind a real CDN, set `YAZAN_TRUSTED_PROXY` deliberately.
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( defined( 'YAZAN_TRUSTED_PROXY' ) && YAZAN_TRUSTED_PROXY && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

			return trim( (string) $chain[0] );
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Storage key.
	 *
	 * ⚠️ THE STORE IS IN THE KEY. Without it one tenant's traffic drains another's bucket, and the
	 * victim sees 429s it did nothing to earn.
	 *
	 * @param string $bucket Bucket.
	 * @param string $actor  Actor.
	 * @return string
	 */
	private static function key( $bucket, $actor ) {
		$store = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		return self::PREFIX . md5( $store . '|' . (string) $bucket . '|' . (string) $actor );
	}
}
