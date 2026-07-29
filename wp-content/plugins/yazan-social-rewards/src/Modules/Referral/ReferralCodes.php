<?php
/**
 * Per-user referral codes.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and resolves each customer's personal referral code. The code is stored
 * in user meta and reused by the Ambassador engine (an ambassador's code IS their
 * referral code), so resolving a code always maps back to one user.
 */
final class ReferralCodes {

	/** User-meta key holding the personal referral code. */
	public const META_KEY = '_yzrw_ref_code';

	/**
	 * Get (or lazily create) a user's referral code.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function for_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$code = (string) get_user_meta( $user_id, self::META_KEY, true );
		if ( '' !== $code ) {
			return $code;
		}
		$code = $this->generate_unique();
		update_user_meta( $user_id, self::META_KEY, $code );
		return $code;
	}

	/**
	 * Force a user's code to a specific value (used when an ambassador is approved
	 * with a chosen code). Returns the code actually stored.
	 *
	 * @param int    $user_id User id.
	 * @param string $desired Desired code (falls back to a generated one).
	 * @return string
	 */
	public function set_for_user( int $user_id, string $desired ): string {
		$desired = $this->clean( $desired );
		if ( '' === $desired || ( $this->resolve( $desired ) && $this->resolve( $desired ) !== $user_id ) ) {
			$desired = $this->generate_unique();
		}
		update_user_meta( $user_id, self::META_KEY, $desired );
		return $desired;
	}

	/**
	 * Resolve a code to the owning user id, or 0.
	 *
	 * @param string $code Code.
	 * @return int
	 */
	public function resolve( string $code ): int {
		$code = $this->clean( $code );
		if ( '' === $code ) {
			return 0;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $code,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return ! empty( $users ) ? (int) $users[0] : 0;
	}

	/**
	 * Normalise a code: uppercase alnum + hyphen, capped.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public function clean( string $code ): string {
		$code = strtoupper( trim( $code ) );
		$code = (string) preg_replace( '/[^A-Z0-9\-]/', '', $code );
		return substr( $code, 0, 32 );
	}

	/**
	 * Generate a unique code not already assigned to any user.
	 *
	 * @return string
	 */
	private function generate_unique(): string {
		do {
			$code = 'YZ' . strtoupper( wp_generate_password( 6, false, false ) );
		} while ( $this->resolve( $code ) > 0 );
		return $code;
	}
}
