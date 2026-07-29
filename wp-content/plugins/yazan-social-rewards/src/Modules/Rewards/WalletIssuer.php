<?php
/**
 * Wallet-credit reward issuer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers a redeemed reward as store-credit wallet balance.
 */
final class WalletIssuer implements RewardIssuerInterface {

	/**
	 * @param WalletServiceInterface $wallet Wallet.
	 * @param Money                  $money  Money helper.
	 */
	public function __construct(
		private WalletServiceInterface $wallet,
		private Money $money
	) {}

	/**
	 * Credit the wallet for a reward.
	 *
	 * @param int    $user_id User id.
	 * @param Reward $reward  Reward (value.amount = currency to credit).
	 * @return array{ok:bool,type:string,ref:string,amount:string}
	 */
	public function issue( int $user_id, Reward $reward ): array {
		$amount = $this->money->format( $reward->value['amount'] ?? 0 );
		if ( (float) $amount <= 0 ) {
			return array( 'ok' => false, 'type' => 'wallet', 'ref' => '', 'amount' => $amount );
		}

		$txn = $this->wallet->credit(
			$user_id,
			$amount,
			'redeem',
			$reward->id,
			array(
				'type' => 'redeem',
				'note' => sprintf(
					/* translators: %s: reward title. */
					__( 'Redeemed: %s', 'yazan-rewards' ),
					$reward->title
				),
			)
		);

		return array(
			'ok'     => $txn > 0,
			'type'   => 'wallet',
			'ref'    => (string) $txn,
			'amount' => $amount,
		);
	}
}
