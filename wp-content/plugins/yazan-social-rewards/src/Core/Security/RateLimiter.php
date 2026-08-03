<?php
/**
 * Transient-backed rate limiter.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple fixed-window rate limiter for sensitive actions (redeem, apply credit,
 * UGC submit, ambassador apply). Backed by transients keyed on action + user/IP.
 * Mirrors the yazan-core dashboard-auth throttle.
 */
final class RateLimiter {

	/**
	 * Whether the actor is over the limit for an action (does not increment).
	 *
	 * @param string $action Action key.
	 * @param string $actor  Stable actor id (user id or IP).
	 * @param int    $max    Max attempts in the window.
	 * @return bool
	 */
	public function too_many( string $action, string $actor, int $max ): bool {
		return $this->count( $action, $actor ) >= $max;
	}

	/**
	 * Record one attempt in the window.
	 *
	 * @param string $action Action key.
	 * @param string $actor  Actor id.
	 * @param int    $window Window length in seconds.
	 * @return int New count.
	 */
	public function hit( string $action, string $actor, int $window ): int {
		$key   = $this->key( $action, $actor );
		$count = $this->count( $action, $actor ) + 1;
		set_transient( $key, $count, $window );
		return $count;
	}

	/**
	 * Clear the counter (e.g. on success).
	 *
	 * @param string $action Action key.
	 * @param string $actor  Actor id.
	 * @return void
	 */
	public function clear( string $action, string $actor ): void {
		delete_transient( $this->key( $action, $actor ) );
	}

	/**
	 * Current count.
	 *
	 * @param string $action Action key.
	 * @param string $actor  Actor id.
	 * @return int
	 */
	public function count( string $action, string $actor ): int {
		return (int) get_transient( $this->key( $action, $actor ) );
	}

	/**
	 * Best-effort client IP (direct connection only; proxy headers are spoofable
	 * and not trusted).
	 *
	 * @return string
	 */
	public function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Transient key.
	 *
	 * @param string $action Action key.
	 * @param string $actor  Actor id.
	 * @return string
	 */
	private function key( string $action, string $actor ): string {
		// ⚠️ The store is in the key. Without it one tenant's traffic drained another tenant's
		// bucket, and the victim saw refusals it did nothing to earn.
		$store = \class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;

		return 'yzrw_rl_' . md5( $store . '|' . $action . '|' . $actor );
	}
}
