<?php
/**
 * Order → domain-event observer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Integration\WooCommerce;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches WooCommerce order status changes and fires the `order_rewarded` domain
 * event exactly once per order, when it first reaches the configured earning
 * status. Engines (points, ambassador, referral, achievements) subscribe to that
 * event — this observer knows nothing about points.
 *
 * HPOS-safe: the order object is passed by WooCommerce and accessed via CRUD.
 * Idempotency uses an order meta flag written through the CRUD API.
 */
final class OrderObserver implements Hookable {

	/** Order meta flag: the reward event has already fired for this order. */
	public const AWARDED_META = '_yzrw_points_awarded';

	/**
	 * @param EventBus $bus      Event bus.
	 * @param Settings $settings Settings.
	 */
	public function __construct(
		private EventBus $bus,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'action',
				'hook'     => 'woocommerce_order_status_changed',
				'method'   => 'on_status_changed',
				'priority' => 10,
				'args'     => 4,
			),
		);
	}

	/**
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param object $order    Order object.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		$earn_status = (string) $this->settings->get( 'points.earn_status', 'completed' );
		if ( $to !== $earn_status ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return; // Guest checkout — points require an account.
		}

		// Idempotency: fire the reward event only once per order.
		if ( 'yes' === $order->get_meta( self::AWARDED_META ) ) {
			return;
		}
		$order->update_meta_data( self::AWARDED_META, 'yes' );
		$order->save();

		$this->bus->dispatch(
			new GenericEvent(
				Events::ORDER_REWARDED,
				array(
					'order_id'    => (int) $order->get_id(),
					'customer_id' => $customer_id,
					'order_total' => (float) $order->get_total(),
				)
			)
		);
	}
}
