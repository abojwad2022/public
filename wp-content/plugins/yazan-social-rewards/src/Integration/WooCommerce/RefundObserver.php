<?php
/**
 * Refund → domain-event observer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Integration\WooCommerce;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires `order_refunded` when an order is refunded or moves to the refunded
 * status, so the points/ambassador engines can claw back what that order earned.
 * The clawback amount is derived from the ledger, so this observer stays simple.
 */
final class RefundObserver implements Hookable {

	/**
	 * @param EventBus $bus Event bus.
	 */
	public function __construct( private EventBus $bus ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_status_refunded',
				'method' => 'on_refunded',
				'args'   => 1,
			),
		);
	}

	/**
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_refunded( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return;
		}

		$this->bus->dispatch(
			new GenericEvent(
				Events::ORDER_REFUNDED,
				array(
					'order_id'    => (int) $order->get_id(),
					'customer_id' => $customer_id,
					'order_total' => (float) $order->get_total(),
				)
			)
		);
	}
}
