<?php
/**
 * Store-credit payout adapter.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

use Yazan\Rewards\Core\Contracts\PayoutAdapterInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The v1 payout method: commission is credited to the ambassador's store-credit
 * wallet. A future real-money adapter implements the same interface and is bound
 * in its place — no change to the commission logic.
 */
final class StoreCreditPayoutAdapter implements PayoutAdapterInterface {

	/**
	 * @param WalletServiceInterface $wallet Wallet.
	 */
	public function __construct( private WalletServiceInterface $wallet ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'store_credit';
	}

	/**
	 * @inheritDoc
	 */
	public function pay( int $user_id, $amount, int $order_id, array $context = array() ): bool {
		$txn = $this->wallet->credit(
			$user_id,
			$amount,
			'commission',
			$order_id,
			array(
				'type'     => 'commission',
				'order_id' => $order_id,
				'note'     => __( 'Ambassador commission', 'yazan-rewards' ),
			)
		);
		return $txn > 0;
	}
}
