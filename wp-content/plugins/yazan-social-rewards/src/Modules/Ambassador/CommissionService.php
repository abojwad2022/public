<?php
/**
 * Ambassador commission service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Ambassador;

use Yazan\Rewards\Core\Contracts\PayoutAdapterInterface;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Referral\ReferralRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pays an ambassador commission when a customer they referred places an order,
 * and reverses it on refund. Attribution comes from the Referral engine; payout
 * goes through the PayoutAdapter (store credit in v1). Idempotent per order via
 * order meta, so replays never double-pay.
 */
final class CommissionService implements SubscriberInterface {

	/** Order meta: commission amount paid + the ambassador user id. */
	private const PAID_META  = '_yzrw_commission_paid';
	private const AMOUNT_META = '_yzrw_commission_amount';
	private const USER_META   = '_yzrw_commission_user';

	/**
	 * @param AmbassadorRepository   $ambassadors Ambassador registry.
	 * @param ReferralRepository     $referrals   Referral attribution.
	 * @param PayoutAdapterInterface $payout      Payout adapter.
	 * @param WalletServiceInterface $wallet      Wallet (for reversal).
	 * @param EventBus               $bus         Event bus.
	 * @param Money                  $money       Money helper.
	 * @param Settings               $settings    Settings.
	 */
	public function __construct(
		private AmbassadorRepository $ambassadors,
		private ReferralRepository $referrals,
		private PayoutAdapterInterface $payout,
		private WalletServiceInterface $wallet,
		private EventBus $bus,
		private Money $money,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::ORDER_REWARDED => array( 'on_order_rewarded', 30 ), // After points(10) + referral(20).
			Events::ORDER_REFUNDED => array( 'on_order_refunded', 30 ),
		);
	}

	/**
	 * Pay commission if the buyer was referred by an active ambassador.
	 *
	 * @param Event $event order_rewarded.
	 * @return void
	 */
	public function on_order_rewarded( Event $event ): void {
		if ( ! $this->settings->feature_enabled( 'ambassador' ) ) {
			return;
		}
		$buyer    = (int) $event->get( 'customer_id' );
		$order_id = (int) $event->get( 'order_id' );
		$total    = (float) $event->get( 'order_total' );
		if ( $buyer <= 0 || $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// Idempotency.
		if ( '' !== (string) $order->get_meta( self::PAID_META ) ) {
			return;
		}

		$referral = $this->referrals->find_by_referred( $buyer );
		if ( ! $referral ) {
			return; // Not referred.
		}
		$ambassador = $this->ambassadors->get_by_user( (int) $referral->referrer_user_id );
		if ( ! $ambassador || ! $ambassador->is_active() ) {
			return; // Referrer isn't an active ambassador.
		}
		// An ambassador never earns on their own purchase.
		if ( $ambassador->user_id === $buyer ) {
			return;
		}

		$rate       = (float) $ambassador->commission_rate;
		$commission = (float) $this->money->format( $total * ( $rate / 100 ) );
		if ( $commission <= 0 ) {
			return;
		}

		if ( ! $this->payout->pay( $ambassador->user_id, $commission, $order_id, array( 'ambassador_id' => $ambassador->id, 'rate' => $rate ) ) ) {
			return;
		}

		// Stamp the order for idempotency + reversal.
		$order->update_meta_data( self::PAID_META, $this->payout->id() );
		$order->update_meta_data( self::AMOUNT_META, $this->money->format( $commission ) );
		$order->update_meta_data( self::USER_META, (string) $ambassador->user_id );
		$order->save();

		// Update cached ambassador stats.
		$stats                       = $ambassador->stats;
		$stats['orders']             = (int) ( $stats['orders'] ?? 0 ) + 1;
		$stats['commission_total']   = $this->money->format( (float) ( $stats['commission_total'] ?? 0 ) + $commission );
		$this->ambassadors->set_stats( $ambassador->id, $stats );

		$this->bus->dispatch(
			new GenericEvent(
				Events::COMMISSION_EARNED,
				array(
					'ambassador_id' => $ambassador->id,
					'user_id'       => $ambassador->user_id,
					'order_id'      => $order_id,
					'amount'        => $this->money->format( $commission ),
				)
			)
		);
	}

	/**
	 * Reverse a paid commission when its order is refunded.
	 *
	 * @param Event $event order_refunded.
	 * @return void
	 */
	public function on_order_refunded( Event $event ): void {
		$order_id = (int) $event->get( 'order_id' );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' === (string) $order->get_meta( self::PAID_META ) ) {
			return; // No commission was paid.
		}

		$amount = (float) $order->get_meta( self::AMOUNT_META );
		$user   = (int) $order->get_meta( self::USER_META );
		if ( $amount <= 0 || $user <= 0 ) {
			return;
		}

		$this->wallet->debit(
			$user,
			$amount,
			'commission',
			$order_id,
			array( 'type' => 'refund', 'order_id' => $order_id, 'note' => __( 'Commission reversed on refund', 'yazan-rewards' ) )
		);

		// Prevent a second reversal.
		$order->update_meta_data( self::PAID_META, '' );
		$order->save();
	}
}
