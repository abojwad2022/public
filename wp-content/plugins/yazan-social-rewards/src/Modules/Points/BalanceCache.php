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

	/** Legacy user-meta key — one balance for the whole platform. Read for migration only. */
	public const META_KEY = '_yzrw_points_balance';

	/**
	 * User-meta key for this store's balance.
	 *
	 * ⚠️ THIS WAS NOT A CACHING BUG, IT WAS A WRONG NUMBER.
	 *
	 * A customer holds a SEPARATE points balance per store — that is a decision on record in the
	 * target architecture, not an implementation detail. Keeping it under one meta key meant a
	 * purchase in store A moved the customer's balance in store B, and the ledger (which IS
	 * per-store) and this lookup disagreed permanently.
	 *
	 * Store 1 keeps the legacy key so no existing balance has to be migrated; every other store
	 * gets its own. Same inherit-then-override shape as `Yazan_Store_Options`.
	 *
	 * @param int|null $store_id Store, or null for the current one.
	 * @return string
	 */
	private function key( ?int $store_id = null ): string {
		$store = null === $store_id
			? ( \class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1 )
			: $store_id;

		return 1 === $store ? self::META_KEY : self::META_KEY . '_s' . $store;
	}

	/**
	 * Read the cached balance for the active store.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function get( int $user_id ): int {
		return (int) get_user_meta( $user_id, $this->key(), true );
	}

	/**
	 * Write the cached balance for the active store.
	 *
	 * @param int $user_id User id.
	 * @param int $balance New balance.
	 * @return void
	 */
	public function set( int $user_id, int $balance ): void {
		update_user_meta( $user_id, $this->key(), $balance );
	}
}
