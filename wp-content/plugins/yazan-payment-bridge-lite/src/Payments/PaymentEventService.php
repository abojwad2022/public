<?php
/**
 * Payment event orchestration.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Payments;

use Yazan\PaymentBridge\Events\Event;
use Yazan\PaymentBridge\Events\EventRepository;
use Yazan\PaymentBridge\Events\EventTypes;
use Yazan\PaymentBridge\Events\IntegrationStatus;
use Yazan\PaymentBridge\Exceptions\ValidationException;
use Yazan\PaymentBridge\Integrations\IntegrationDispatcher;
use Yazan\PaymentBridge\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates an order, records the canonical event, and — only if the record was
 * newly created — dispatches the integrations.
 *
 * Duplicate suppression happens at the database, so this class does not need to
 * (and must not) reason about which of the three qualifying WooCommerce hooks
 * arrived first.
 */
final class PaymentEventService {

	/**
	 * @param EventRepository       $repository  Event store.
	 * @param IntegrationDispatcher $dispatcher  Integration runner.
	 * @param RefundClassifier      $refunds     Refund classifier.
	 * @param Logger                $logger      Logger.
	 */
	public function __construct(
		private EventRepository $repository,
		private IntegrationDispatcher $dispatcher,
		private RefundClassifier $refunds,
		private Logger $logger
	) {}

	/**
	 * Record a payment event for an order and run its integrations.
	 *
	 * @param int         $order_id   Order id.
	 * @param string      $event_type One of EventTypes::*.
	 * @param string|null $amount     Override amount (refunds pass the refunded total).
	 * @return Event|null The stored event, or null when it was a duplicate.
	 * @throws ValidationException When the order cannot be resolved.
	 */
	public function record( int $order_id, string $event_type, ?string $amount = null ): ?Event {
		$order = $this->resolve_order( $order_id );
		$event = Event::from_order( $order, $event_type, $amount );

		if ( EventTypes::PAYMENT_PARTIALLY_REFUNDED === $event_type ) {
			$event->integration_status = IntegrationStatus::PENDING;
		}

		$id = $this->repository->insert_unique( $event );

		if ( 0 === $id ) {
			$this->on_duplicate( $order, $event );
			return null;
		}

		$this->logger->info(
			'Payment event recorded.',
			array(
				'event_id'   => $id,
				'event_uuid' => $event->event_uuid,
				'order_id'   => $event->order_id,
				'event_type' => $event->event_type,
				'source'     => $event->source,
				'gateway'    => $event->gateway,
			)
		);

		$this->dispatcher->dispatch( $event );

		return $event;
	}

	/**
	 * Re-run integrations for an already-stored event (admin retry).
	 *
	 * Goes through the same claim-gated dispatcher, so a retry can never produce
	 * a second downstream run.
	 *
	 * @param int $event_id Event row id.
	 * @return string Resulting status, or IntegrationDispatcher::RESULT_LOCKED.
	 */
	public function retry( int $event_id ): string {
		$row = $this->repository->find( $event_id );

		if ( ! $row ) {
			return IntegrationDispatcher::RESULT_LOCKED;
		}

		return $this->dispatcher->dispatch( Event::from_row( $row ) );
	}

	/**
	 * Handle an event the unique constraint rejected.
	 *
	 * For a repeat partial refund this is not really a duplicate: a second,
	 * distinct refund happened against the same order. Rather than lose it, the
	 * stored row is refreshed to the cumulative refunded total and kept flagged
	 * for review (H3). Every other type is a genuine duplicate and is dropped.
	 *
	 * @param \WC_Order $order Order.
	 * @param Event     $event The event that was rejected.
	 * @return void
	 */
	private function on_duplicate( \WC_Order $order, Event $event ): void {
		if ( EventTypes::PAYMENT_PARTIALLY_REFUNDED !== $event->event_type ) {
			return;
		}

		$existing = $this->repository->find_by_order_and_type( $order->get_id(), $event->event_type );

		if ( ! $existing ) {
			return;
		}

		$this->repository->update_amount(
			(int) $existing->id,
			$this->refunds->refunded_amount( $order ),
			IntegrationStatus::REVIEW
		);

		$this->logger->info(
			'Additional partial refund recorded against the existing event.',
			array(
				'event_id'   => (int) $existing->id,
				'order_id'   => $order->get_id(),
				'event_type' => $event->event_type,
			)
		);
	}

	/**
	 * Resolve and validate an order.
	 *
	 * HPOS-safe: wc_get_order() reads from whichever data store is active, so no
	 * wp_posts / wp_postmeta access is involved.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order
	 * @throws ValidationException When the id is not a real order.
	 */
	private function resolve_order( int $order_id ): \WC_Order {
		if ( $order_id <= 0 ) {
			throw new ValidationException( 'Invalid order id.' );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			throw new ValidationException( 'Order not found or not a WC_Order: ' . $order_id );
		}

		return $order;
	}
}
