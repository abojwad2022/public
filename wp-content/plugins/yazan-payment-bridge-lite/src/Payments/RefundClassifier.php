<?php
/**
 * Full vs partial refund classification.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Payments;

use Yazan\PaymentBridge\Events\EventTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether an order has been refunded in full or in part (H3).
 *
 * Comparison happens in integer minor units rather than on floats: currency
 * totals held as floats make `==` unreliable, and bcmath is not guaranteed to be
 * loaded on every host.
 */
final class RefundClassifier {

	/**
	 * Whether the order's refunded total now covers its whole total.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_full_refund( \WC_Order $order ): bool {
		$total    = $this->to_minor_units( (string) $order->get_total() );
		$refunded = $this->to_minor_units( (string) $order->get_total_refunded() );

		// A zero-total order that has been refunded at all counts as fully refunded.
		if ( $total <= 0 ) {
			return true;
		}

		return $refunded >= $total;
	}

	/**
	 * Map an order's refund state to a canonical event type.
	 *
	 * @param \WC_Order $order Order.
	 * @return string One of EventTypes::PAYMENT_REFUNDED | PAYMENT_PARTIALLY_REFUNDED.
	 */
	public function event_type_for( \WC_Order $order ): string {
		return $this->is_full_refund( $order )
			? EventTypes::PAYMENT_REFUNDED
			: EventTypes::PAYMENT_PARTIALLY_REFUNDED;
	}

	/**
	 * The cumulative refunded amount, as a decimal string for the DECIMAL column.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function refunded_amount( \WC_Order $order ): string {
		return (string) wc_format_decimal( $order->get_total_refunded(), 4 );
	}

	/**
	 * Convert a money string to integer minor units at the store's precision.
	 *
	 * @param string $value Money value.
	 * @return int
	 */
	private function to_minor_units( string $value ): int {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$decimals = max( 0, min( 6, $decimals ) );
		$formatted = (float) wc_format_decimal( $value, $decimals );

		return (int) round( $formatted * ( 10 ** $decimals ) );
	}
}
