<?php
/**
 * Apply store credit at checkout.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Wallet;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies chosen store credit to the cart as a negative fee and, once the order
 * is placed, deducts it from the wallet ledger. The amount the customer chose is
 * held in the WooCommerce session (set by the checkout UI in a later phase); this
 * class validates it against the live balance and cart total every recalculation
 * so a stale/hostile session value can never over-discount.
 */
final class CheckoutCredit implements Hookable {

	/** Session key holding the requested credit to apply. */
	public const SESSION_KEY = 'yzrw_apply_credit';

	/** Order meta recording credit actually spent on the order. */
	public const APPLIED_META = '_yzrw_credit_applied';

	/**
	 * @param WalletServiceInterface $wallet   Wallet.
	 * @param Settings               $settings Settings.
	 * @param Money                  $money    Money helper.
	 */
	public function __construct(
		private WalletServiceInterface $wallet,
		private Settings $settings,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_cart_calculate_fees',
				'method' => 'apply_fee',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_checkout_order_processed',
				'method' => 'spend_on_order',
				'args'   => 1,
			),
		);
	}

	/**
	 * The amount of credit that may be applied to the current cart, capped by the
	 * live balance, the requested amount, and the configured max cart percentage.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return float
	 */
	public function applicable_amount( \WC_Cart $cart ): float {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0.0;
		}

		$requested = (float) $this->session_get();
		if ( $requested <= 0 ) {
			return 0.0;
		}

		$balance      = (float) $this->wallet->balance( $user_id );
		$cart_total   = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
		$max_percent  = max( 0, min( 100, (int) $this->settings->get( 'wallet.max_cart_percent', 100 ) ) );
		$cart_ceiling = $cart_total * ( $max_percent / 100 );

		$amount = min( $requested, $balance, $cart_ceiling );
		return $amount > 0 ? (float) $this->money->format( $amount ) : 0.0;
	}

	/**
	 * Add the negative fee.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function apply_fee( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || is_admin() ) {
			return;
		}
		if ( ! $this->settings->feature_enabled( 'wallet' ) ) {
			return;
		}
		$amount = $this->applicable_amount( $cart );
		if ( $amount <= 0 ) {
			return;
		}
		// Negative fee = store-credit discount. Not taxable.
		$cart->add_fee( __( 'Yazan Credit', 'yazan-rewards' ), -$amount, false );
	}

	/**
	 * Deduct the applied credit from the wallet when the order is placed.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function spend_on_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// Idempotency: never spend twice for one order.
		if ( '' !== (string) $order->get_meta( self::APPLIED_META ) ) {
			return;
		}

		// Find the credit fee that was applied to this order.
		$applied = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( __( 'Yazan Credit', 'yazan-rewards' ) === $fee->get_name() ) {
				$applied += abs( (float) $fee->get_total() );
			}
		}
		if ( $applied <= 0 ) {
			return;
		}

		$this->wallet->debit(
			$user_id,
			$applied,
			'order',
			(int) $order->get_id(),
			array(
				'type'     => 'spend',
				'order_id' => (int) $order->get_id(),
				'note'     => __( 'Store credit applied at checkout', 'yazan-rewards' ),
			)
		);

		$order->update_meta_data( self::APPLIED_META, $this->money->format( $applied ) );
		$order->save();

		$this->session_clear();
	}

	/**
	 * Read the requested credit from the WooCommerce session.
	 *
	 * @return float
	 */
	private function session_get(): float {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0.0;
		}
		return (float) WC()->session->get( self::SESSION_KEY, 0 );
	}

	/**
	 * Clear the session value.
	 *
	 * @return void
	 */
	private function session_clear(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, 0 );
		}
	}
}
