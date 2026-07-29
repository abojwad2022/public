<?php
/**
 * Reward issuer (fulfillment provider) contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fulfills a redeemed reward of a particular type (store credit, coupon, service
 * voucher, or a third-party type). Issuers are keyed by reward type in the
 * {@see RewardProviderRegistry}; the {@see RedemptionService} resolves the issuer
 * for a reward's type and calls issue() AFTER points have been debited. Returning
 * `ok => false` triggers a full compensation (points refunded, stock released), so
 * an issuer must report failure rather than partially deliver.
 *
 * Third parties register a custom provider via `yazan_register_reward_provider()`.
 */
interface RewardIssuerInterface {

	/**
	 * Deliver the reward's value to the user.
	 *
	 * @param int    $user_id User id.
	 * @param Reward $reward  The reward being redeemed.
	 * @return array{ok:bool,type:string,ref:string,code?:string,amount?:string} Delivery result.
	 */
	public function issue( int $user_id, Reward $reward ): array;
}
