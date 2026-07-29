<?php
/**
 * Points earning subscriber.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\RulesEngineInterface;
use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where points are actually earned. Subscribes to the integration's domain events
 * and credits the ledger — purchases (via the rules engine), signups, reviews —
 * and claws points back on refund. Every credit is guarded by the ledger's
 * per-source total, so replays never double-award.
 */
final class PointsEarnSubscriber implements SubscriberInterface {

	/**
	 * @param PointsLedgerInterface $ledger    Points ledger.
	 * @param RulesEngineInterface  $rules     Rules engine.
	 * @param Settings              $settings  Settings.
	 * @param ExpiryService         $expiry    Expiry helper (stamps new credits).
	 */
	public function __construct(
		private PointsLedgerInterface $ledger,
		private RulesEngineInterface $rules,
		private Settings $settings,
		private ExpiryService $expiry
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::ORDER_REWARDED  => array( 'on_order_rewarded' ),
			Events::ORDER_REFUNDED  => array( 'on_order_refunded' ),
			Events::USER_REGISTERED => array( 'on_user_registered' ),
			Events::REVIEW_CREATED  => array( 'on_review_created' ),
		);
	}

	/**
	 * Purchase points from the rules engine.
	 *
	 * @param Event $event order_rewarded.
	 * @return void
	 */
	public function on_order_rewarded( Event $event ): void {
		if ( ! $this->settings->feature_enabled( 'points' ) ) {
			return;
		}
		$user_id  = (int) $event->get( 'customer_id' );
		$order_id = (int) $event->get( 'order_id' );
		if ( $user_id <= 0 || $order_id <= 0 ) {
			return;
		}
		// Guard against double-award for the same order.
		if ( 0 !== $this->ledger->net_for_source( $user_id, 'order', $order_id ) ) {
			return;
		}

		$context = $this->order_context( $order_id, (float) $event->get( 'order_total' ), $user_id );
		$points  = $this->rules->evaluate( 'order_completed', $context );
		if ( $points <= 0 ) {
			return;
		}

		$this->ledger->credit(
			$user_id,
			$points,
			'order',
			$order_id,
			array(
				'type'       => 'earn',
				'note'       => __( 'Purchase points', 'yazan-rewards' ),
				'expires_at' => $this->expiry->expiry_for_new_credit(),
			)
		);
	}

	/**
	 * Claw back an order's points on refund.
	 *
	 * @param Event $event order_refunded.
	 * @return void
	 */
	public function on_order_refunded( Event $event ): void {
		$user_id  = (int) $event->get( 'customer_id' );
		$order_id = (int) $event->get( 'order_id' );
		if ( $user_id <= 0 || $order_id <= 0 ) {
			return;
		}
		$net = $this->ledger->net_for_source( $user_id, 'order', $order_id );
		if ( $net <= 0 ) {
			return; // Nothing left to reverse.
		}
		$this->ledger->debit(
			$user_id,
			$net,
			'order',
			$order_id,
			array(
				'type' => 'refund',
				'note' => __( 'Points reversed on refund', 'yazan-rewards' ),
			)
		);
	}

	/**
	 * Signup bonus.
	 *
	 * @param Event $event user_registered.
	 * @return void
	 */
	public function on_user_registered( Event $event ): void {
		$user_id = (int) $event->get( 'user_id' );
		$bonus   = (int) $this->settings->get( 'points.signup_bonus', 0 );
		if ( $user_id <= 0 || $bonus <= 0 ) {
			return;
		}
		if ( 0 !== $this->ledger->net_for_source( $user_id, 'signup', $user_id ) ) {
			return;
		}
		$this->ledger->credit(
			$user_id,
			$bonus,
			'signup',
			$user_id,
			array(
				'type'       => 'earn',
				'note'       => __( 'Welcome bonus', 'yazan-rewards' ),
				'expires_at' => $this->expiry->expiry_for_new_credit(),
			)
		);
	}

	/**
	 * Review bonus (once per product per customer).
	 *
	 * @param Event $event review_created.
	 * @return void
	 */
	public function on_review_created( Event $event ): void {
		$user_id    = (int) $event->get( 'user_id' );
		$product_id = (int) $event->get( 'product_id' );
		$bonus      = (int) $this->settings->get( 'points.review_bonus', 0 );
		if ( $user_id <= 0 || $product_id <= 0 || $bonus <= 0 ) {
			return;
		}
		if ( 0 !== $this->ledger->net_for_source( $user_id, 'review', $product_id ) ) {
			return;
		}
		$this->ledger->credit(
			$user_id,
			$bonus,
			'review',
			$product_id,
			array(
				'type'       => 'earn',
				'note'       => __( 'Review reward', 'yazan-rewards' ),
				'expires_at' => $this->expiry->expiry_for_new_credit(),
			)
		);
	}

	/**
	 * Build the rule-matching context from an order.
	 *
	 * @param int   $order_id Order id.
	 * @param float $total    Order total.
	 * @param int   $user_id  Buyer user id (so per-user multipliers can resolve).
	 * @return array<string,mixed>
	 */
	private function order_context( int $order_id, float $total, int $user_id = 0 ): array {
		$order = wc_get_order( $order_id );
		$cats  = array();
		$payment = '';

		if ( $order instanceof \WC_Order ) {
			$total   = (float) $order->get_total();
			$payment = (string) $order->get_payment_method();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$cats = array_merge( $cats, (array) wc_get_product_cat_ids( $item->get_product_id() ) );
				}
			}
		}

		return array(
			'user_id'        => $user_id,
			'order_total'    => $total,
			'payment_method' => $payment,
			'product_cats'   => array_values( array_unique( array_map( 'intval', $cats ) ) ),
			'rounding'       => (string) $this->settings->get( 'points.round', 'floor' ),
		);
	}
}
