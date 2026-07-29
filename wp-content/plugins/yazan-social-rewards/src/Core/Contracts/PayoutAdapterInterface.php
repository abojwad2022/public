<?php
/**
 * Ambassador payout adapter contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How an ambassador commission is paid. v1 ships a store-credit adapter (credits
 * the wallet). A future real-money adapter (PayPal Payouts, bank transfer)
 * implements this same interface and is swapped in via the container — no other
 * code changes. This is the seam that keeps payouts replaceable.
 */
interface PayoutAdapterInterface {

	/**
	 * A machine id for the payout method (e.g. "store_credit", "paypal").
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Pay a commission to an ambassador.
	 *
	 * @param int              $user_id  Ambassador's user id.
	 * @param float|int|string $amount   Commission amount (store currency).
	 * @param int              $order_id Source order id.
	 * @param array            $context  Extra context (ambassador_id, rate…).
	 * @return bool True when the payout was recorded/dispatched.
	 */
	public function pay( int $user_id, $amount, int $order_id, array $context = array() ): bool;
}
