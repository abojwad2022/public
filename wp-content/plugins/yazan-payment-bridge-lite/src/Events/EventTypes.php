<?php
/**
 * Canonical event vocabulary.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Bridge's internal event types.
 *
 * H2: `woocommerce_payment_complete`, `woocommerce_order_status_processing` and
 * `woocommerce_order_status_completed` all map to PAYMENT_COMPLETED. Because the
 * events table carries UNIQUE (order_id, event_type), whichever hook fires first
 * creates the canonical event and every later qualifying hook is rejected by the
 * database — not by a PHP check that a concurrent request could race past.
 */
final class EventTypes {

	/** Canonical "this order was really paid for" event — once per order. */
	public const PAYMENT_COMPLETED = 'payment_completed';

	/** Payment attempt failed. */
	public const PAYMENT_FAILED = 'payment_failed';

	/** Order fully refunded. */
	public const PAYMENT_REFUNDED = 'payment_refunded';

	/** Order partially refunded — recorded, never auto-revoked (H3). */
	public const PAYMENT_PARTIALLY_REFUNDED = 'payment_partially_refunded';

	/**
	 * Every known type.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PAYMENT_COMPLETED,
			self::PAYMENT_FAILED,
			self::PAYMENT_REFUNDED,
			self::PAYMENT_PARTIALLY_REFUNDED,
		);
	}

	/**
	 * Whether a string is a known event type.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	public static function label( string $type ): string {
		$labels = array(
			self::PAYMENT_COMPLETED          => __( 'Payment completed', 'yazan-payment-bridge' ),
			self::PAYMENT_FAILED             => __( 'Payment failed', 'yazan-payment-bridge' ),
			self::PAYMENT_REFUNDED           => __( 'Refunded (full)', 'yazan-payment-bridge' ),
			self::PAYMENT_PARTIALLY_REFUNDED => __( 'Refunded (partial)', 'yazan-payment-bridge' ),
		);

		return $labels[ $type ] ?? $type;
	}
}
