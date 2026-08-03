<?php
/**
 * Yazan AI — rate limiting & budget guard.
 *
 * Two independent brakes before any provider call:
 *
 *  1. Per-user hourly rate limit — reuses the transient-counter idiom from {@see Yazan_Dashboard_Auth}
 *     so a runaway loop or a compromised session cannot burn the whole budget in a minute.
 *  2. Monthly spend cap — sums {@see Yazan_AI_Log} cost for the current calendar month and refuses
 *     new calls once the configured USD ceiling is reached.
 *
 * Both are read from {@see Yazan_AI_Settings}; either set to 0 disables that brake.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-flight checks that keep AI spend bounded.
 */
class Yazan_AI_Budget {

	/** Transient key prefix for the per-user counter. */
	const RATE_PREFIX = 'yz_ai_rate_';

	/**
	 * The store this request belongs to.
	 *
	 * The AI budget is per-store (the generation log is), so its rate window must be too — a user
	 * who shops at two stores was otherwise sharing one hourly allowance between them.
	 *
	 * @return int
	 */
	private static function store() {
		return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
	}

	/** Object-cache group used for the atomic counter path. */
	const RATE_GROUP = 'yazan_ai_rate';

	/** Rate-limit window in seconds. */
	const WINDOW = 3600; // 1 hour.

	/**
	 * Gate a generation. Returns true to proceed, or a WP_Error describing which brake tripped.
	 *
	 * @return true|WP_Error
	 */
	public static function check() {
		$config = Yazan_AI_Settings::all();

		if ( empty( $config['enabled'] ) ) {
			return new WP_Error( 'yazan_ai_disabled', __( 'The AI assistant is turned off.', 'yazan' ), array( 'status' => 503 ) );
		}

		// The per-user hourly counter only makes sense for an IDENTIFIED user (a logged-in dashboard
		// operator). Anonymous storefront visitors all share user id 0, so applying it there would
		// throttle the whole public concierge to one shared bucket — those requests are guarded instead
		// by the per-IP throttle in the REST controller plus the monthly budget cap below.
		$uid   = get_current_user_id();
		$limit = (int) $config['user_rate_limit'];
		if ( $uid > 0 && $limit > 0 ) {
			$count = self::rate_get( $uid );
			if ( $count >= $limit ) {
				return new WP_Error(
					'yazan_ai_rate_limited',
					__( 'You have reached the AI generation limit for this hour. Please try again shortly.', 'yazan' ),
					array( 'status' => 429 )
				);
			}
		}

		$cap = (float) $config['monthly_budget'];
		if ( $cap > 0 ) {
			$spent = self::month_spend();
			if ( $spent >= $cap ) {
				return new WP_Error(
					'yazan_ai_over_budget',
					__( 'The monthly AI budget has been reached. Raise the cap in Provider Settings to continue.', 'yazan' ),
					array( 'status' => 402 )
				);
			}
		}

		return true;
	}

	/**
	 * Increment the per-user counter after a real (non-cached) provider call.
	 *
	 * @return void
	 */
	public static function tick() {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return; // Anonymous visitors are throttled per-IP, not by this shared counter.
		}
		$limit = (int) Yazan_AI_Settings::get( 'user_rate_limit', 0 );
		if ( $limit <= 0 ) {
			return;
		}
		self::rate_bump( $uid );
	}

	/**
	 * Read the current per-user counter. Uses the persistent object cache when one is present, else
	 * a transient — both keyed identically so check() and tick() always read the same store.
	 *
	 * @param int $uid User id.
	 * @return int
	 */
	private static function rate_get( $uid ) {
		$key = self::RATE_PREFIX . self::store() . '_' . $uid;
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $key, self::RATE_GROUP );
			return false === $value ? 0 : (int) $value;
		}
		return (int) get_transient( $key );
	}

	/**
	 * Increment the per-user counter. With a persistent object cache this uses the backend's ATOMIC
	 * increment (wp_cache_incr), closing the read-modify-write race that lets a concurrent burst
	 * overshoot the cap. Without one it falls back to the original transient behaviour (no regression
	 * on installs — like the local dev box — that have no external object cache).
	 *
	 * @param int $uid User id.
	 * @return void
	 */
	private static function rate_bump( $uid ) {
		$key = self::RATE_PREFIX . self::store() . '_' . $uid;
		if ( wp_using_ext_object_cache() ) {
			if ( false === wp_cache_get( $key, self::RATE_GROUP ) ) {
				wp_cache_add( $key, 0, self::RATE_GROUP, self::WINDOW );
			}
			wp_cache_incr( $key, 1, self::RATE_GROUP );
			return;
		}
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::WINDOW );
	}

	/**
	 * USD spent so far this calendar month (successful calls only).
	 *
	 * @return float
	 */
	public static function month_spend() {
		$since = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$totals = Yazan_AI_Log::totals_since( $since );
		return (float) $totals['cost'];
	}
}
