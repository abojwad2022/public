<?php
/**
 * Service-reward issuer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers a redeemed service reward by minting a voucher and opening a
 * fulfillment record (status `requested`) for staff. Announces `SERVICE_REQUESTED`
 * so the owner + customer are notified. Staff advance it through the admin queue.
 */
final class ServiceIssuer implements RewardIssuerInterface {

	/**
	 * @param ServiceVoucherRepository $vouchers Voucher store.
	 * @param EventBus                 $bus      Event bus.
	 */
	public function __construct(
		private ServiceVoucherRepository $vouchers,
		private EventBus $bus
	) {}

	/**
	 * Issue a service voucher.
	 *
	 * @param int    $user_id       User id.
	 * @param Reward $reward        Reward.
	 * @param int    $redemption_id Redemption record id (0 if not yet recorded).
	 * @return array{ok:bool,type:string,ref:string,code:string}
	 */
	public function issue( int $user_id, Reward $reward, int $redemption_id = 0 ): array {
		$code = $this->unique_code();

		$id = $this->vouchers->create(
			array(
				'user_id'       => $user_id,
				'reward_id'     => $reward->id,
				'redemption_id' => $redemption_id,
				'type'          => $reward->type,
				'code'          => $code,
				'notes'         => $reward->title,
			)
		);

		if ( ! $id ) {
			return array( 'ok' => false, 'type' => 'service', 'ref' => '', 'code' => '' );
		}

		$this->bus->dispatch(
			new GenericEvent(
				Events::SERVICE_REQUESTED,
				array(
					'user_id'    => $user_id,
					'voucher_id' => $id,
					'reward_id'  => $reward->id,
					'type'       => $reward->type,
					'code'       => $code,
					'title'      => $reward->title,
				)
			)
		);

		return array( 'ok' => true, 'type' => 'service', 'ref' => (string) $id, 'code' => $code );
	}

	/**
	 * A unique voucher code.
	 *
	 * @return string
	 */
	private function unique_code(): string {
		return 'YZ-SVC-' . strtoupper( wp_generate_password( 8, false, false ) );
	}
}
