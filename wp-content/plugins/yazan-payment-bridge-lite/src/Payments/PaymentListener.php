<?php
/**
 * WooCommerce payment hooks → canonical YAZAN events.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Payments;

use Yazan\PaymentBridge\Contracts\Hookable;
use Yazan\PaymentBridge\Events\EventTypes;
use Yazan\PaymentBridge\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WooCommerce's server-verified payment states into the Bridge's
 * canonical events.
 *
 * H2: `woocommerce_payment_complete`, `woocommerce_order_status_processing` and
 * `woocommerce_order_status_completed` can all fire for the same order. All
 * three map to the single `payment_completed` type, so the UNIQUE
 * (order_id, event_type) constraint lets exactly one of them through — whichever
 * arrives first — and the rest are rejected by the database.
 *
 * Every handler is wrapped in a catch-all: a fault inside the Bridge must never
 * surface to a customer or break checkout.
 */
final class PaymentListener implements Hookable {

	/**
	 * @param PaymentEventService $service    Event recorder.
	 * @param RefundClassifier    $classifier Refund classifier.
	 * @param Logger              $logger     Logger.
	 */
	public function __construct(
		private PaymentEventService $service,
		private RefundClassifier $classifier,
		private Logger $logger
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_payment_complete',
				'method' => 'on_payment_complete',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_status_processing',
				'method' => 'on_status_processing',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_status_completed',
				'method' => 'on_status_completed',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_status_failed',
				'method' => 'on_status_failed',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_status_refunded',
				'method' => 'on_status_refunded',
				'args'   => 1,
			),
			array(
				'type'   => 'action',
				'hook'   => 'woocommerce_order_refunded',
				'method' => 'on_order_refunded',
				'args'   => 2,
			),
		);
	}

	/**
	 * Gateway confirmed payment.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_payment_complete( $order_id ): void {
		$this->safely( (int) $order_id, EventTypes::PAYMENT_COMPLETED );
	}

	/**
	 * Order moved to processing.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_status_processing( $order_id ): void {
		$this->safely( (int) $order_id, EventTypes::PAYMENT_COMPLETED );
	}

	/**
	 * Order moved to completed.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_status_completed( $order_id ): void {
		$this->safely( (int) $order_id, EventTypes::PAYMENT_COMPLETED );
	}

	/**
	 * Payment attempt failed.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_status_failed( $order_id ): void {
		$this->safely( (int) $order_id, EventTypes::PAYMENT_FAILED );
	}

	/**
	 * Order status set to refunded — a declaration of a full refund.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_status_refunded( $order_id ): void {
		$order_id = (int) $order_id;
		$amount   = null;

		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$refunded = $this->classifier->refunded_amount( $order );
			// Fall back to the order total: an admin can set this status without
			// any refund line existing yet.
			$amount = ( (float) $refunded > 0 ) ? $refunded : (string) wc_format_decimal( $order->get_total(), 4 );
		}

		$this->safely( $order_id, EventTypes::PAYMENT_REFUNDED, $amount );
	}

	/**
	 * A refund was created against the order — partial or full (H3).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id (unused; the cumulative total is what matters).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ): void {
		$order_id = (int) $order_id;

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$this->safely(
				$order_id,
				$this->classifier->event_type_for( $order ),
				$this->classifier->refunded_amount( $order )
			);
		} catch ( \Throwable $e ) {
			$this->swallow( $e, $order_id, 'refund_classification' );
		}
	}

	/**
	 * Record an event, absorbing every possible fault.
	 *
	 * @param int         $order_id   Order id.
	 * @param string      $event_type Canonical event type.
	 * @param string|null $amount     Optional amount override.
	 * @return void
	 */
	private function safely( int $order_id, string $event_type, ?string $amount = null ): void {
		try {
			$this->service->record( $order_id, $event_type, $amount );
		} catch ( \Throwable $e ) {
			$this->swallow( $e, $order_id, $event_type );
		}
	}

	/**
	 * Log a fault without letting it escape into checkout.
	 *
	 * @param \Throwable $e        The fault.
	 * @param int        $order_id Order id.
	 * @param string     $context  What was being attempted.
	 * @return void
	 */
	private function swallow( \Throwable $e, int $order_id, string $context ): void {
		$this->logger->error(
			'Payment listener error: ' . $e->getMessage(),
			array(
				'order_id' => $order_id,
				'code'     => $context,
			)
		);
	}
}
