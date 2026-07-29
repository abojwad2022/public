<?php
/**
 * Points balance cache (user meta).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The derived points balance, cached in user meta for O(1) reads. Always
 * reconcilable from the append-only ledger; the ledger is the source of truth,
 * this is just a fast lookup that the repository keeps in step on every write.
 */
final class BalanceCache {

	/** User-meta key for the cached cleared points balance. */
	public const META_KEY = '_yzrw_points_balance';

	/**
	 * Read the cached balance.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function get( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Write the cached balance.
	 *
	 * @param int $user_id User id.
	 * @param int $balance New balance.
	 * @return void
	 */
	public function set( int $user_id, int $balance ): void {
		update_user_meta( $user_id, self::META_KEY, $balance );
	}
}
