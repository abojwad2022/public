<?php
/**
 * Store-credit wallet contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The monetary store-credit API. Kept distinct from points: points are the
 * loyalty currency, wallet credit is real money value spendable at checkout.
 * Amounts are decimal strings to avoid float drift.
 */
interface WalletServiceInterface {

	/**
	 * Current credit balance (decimal string).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function balance( int $user_id ): string;

	/**
	 * Add credit (commission, points redemption, refund, manual adjust).
	 *
	 * @param int              $user_id   User id.
	 * @param float|int|string $amount    Positive amount.
	 * @param string           $source    Source key.
	 * @param int              $source_id Related object id.
	 * @param array            $meta      Optional: note, order_id, type.
	 * @return int Ledger row id.
	 */
	public function credit( int $user_id, $amount, string $source, int $source_id = 0, array $meta = array() ): int;

	/**
	 * Spend/deduct credit.
	 *
	 * @param int              $user_id   User id.
	 * @param float|int|string $amount    Positive amount to remove.
	 * @param string           $source    Source key.
	 * @param int              $source_id Related object id.
	 * @param array            $meta      Optional: note, order_id, type.
	 * @return int Ledger row id.
	 */
	public function debit( int $user_id, $amount, string $source, int $source_id = 0, array $meta = array() ): int;
}
