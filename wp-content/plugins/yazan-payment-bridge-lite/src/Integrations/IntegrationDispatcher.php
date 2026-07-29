<?php
/**
 * Runs the connectors for a stored event, under the claim lock.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Integrations;

use Yazan\PaymentBridge\Events\Event;
use Yazan\PaymentBridge\Events\EventRepository;
use Yazan\PaymentBridge\Events\EventTypes;
use Yazan\PaymentBridge\Events\IntegrationStatus;
use Yazan\PaymentBridge\Integrations\Ownership\OwnershipConnector;
use Yazan\PaymentBridge\Integrations\Warranty\WarrantyConnector;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Payments\ProductEligibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single place downstream integrations are invoked.
 *
 * Every run is gated by {@see EventRepository::claim()}, so the same event
 * cannot drive downstream work twice — whether the second attempt comes from a
 * concurrent webhook, a cron pass, or an admin clicking Retry.
 */
final class IntegrationDispatcher {

	/** Returned when the event was already claimed by someone else. */
	public const RESULT_LOCKED = 'locked';

	/**
	 * @param EventRepository           $repository  Event store.
	 * @param OwnershipConnector        $ownership   Ownership seam.
	 * @param WarrantyConnector         $warranty    Warranty seam.
	 * @param ProductEligibilityService $eligibility Eligibility resolver.
	 * @param Logger                    $logger      Logger.
	 */
	public function __construct(
		private EventRepository $repository,
		private OwnershipConnector $ownership,
		private WarrantyConnector $warranty,
		private ProductEligibilityService $eligibility,
		private Logger $logger
	) {}

	/**
	 * Claim the event and run its integrations.
	 *
	 * @param Event $event Stored event (must carry a row id).
	 * @return string Final integration status, or RESULT_LOCKED when not claimed.
	 */
	public function dispatch( Event $event ): string {
		if ( $event->id <= 0 ) {
			return self::RESULT_LOCKED;
		}

		if ( ! $this->repository->claim( $event->id ) ) {
			$this->logger->debug(
				'Event already claimed; integrations not re-run.',
				array(
					'event_id'   => $event->id,
					'event_uuid' => $event->event_uuid,
					'order_id'   => $event->order_id,
				)
			);
			return self::RESULT_LOCKED;
		}

		try {
			$status = $this->run( $event );
			$this->repository->finish( $event->id, $status );

			$this->logger->info(
				'Integration run finished.',
				array(
					'event_id'           => $event->id,
					'event_uuid'         => $event->event_uuid,
					'order_id'           => $event->order_id,
					'event_type'         => $event->event_type,
					'integration_status' => $status,
					'source'             => $event->source,
				)
			);

			return $status;
		} catch ( \Throwable $e ) {
			$this->repository->finish( $event->id, IntegrationStatus::FAILED, $e->getMessage() );

			$this->logger->error(
				'Integration run failed.',
				array(
					'event_id'   => $event->id,
					'event_uuid' => $event->event_uuid,
					'order_id'   => $event->order_id,
					'event_type' => $event->event_type,
					'code'       => (string) $e->getCode(),
				)
			);

			return IntegrationStatus::FAILED;
		}
	}

	/**
	 * Decide and perform the work for one event type.
	 *
	 * @param Event $event Stored event.
	 * @return string Resulting integration status.
	 */
	private function run( Event $event ): string {
		/**
		 * Generic extension point, fired once per recorded event — exactly once,
		 * because the caller holds the claim. Any system (rewards, CRM, analytics)
		 * can subscribe without the Bridge depending on it.
		 *
		 * @param Event $event Payment event.
		 */
		do_action( 'yazan_payment_bridge/event/' . $event->event_type, $event );

		switch ( $event->event_type ) {
			case EventTypes::PAYMENT_COMPLETED:
				return $this->on_payment_completed( $event );

			case EventTypes::PAYMENT_REFUNDED:
				return $this->on_full_refund( $event );

			case EventTypes::PAYMENT_PARTIALLY_REFUNDED:
				// Recorded and flagged for a human. Never auto-revoked (H3).
				return IntegrationStatus::REVIEW;

			case EventTypes::PAYMENT_FAILED:
			default:
				return IntegrationStatus::SKIPPED;
		}
	}

	/**
	 * Canonical payment: create ownership + warranty for eligible items.
	 *
	 * @param Event $event Event.
	 * @return string Status.
	 */
	private function on_payment_completed( Event $event ): string {
		$order = wc_get_order( $event->order_id );

		if ( ! $order instanceof \WC_Order ) {
			return IntegrationStatus::SKIPPED;
		}

		$result = $this->eligibility->evaluate( $order );

		if ( empty( $result['eligible'] ) ) {
			$this->logger->debug(
				'No YAZAN-eligible items on the order; integrations skipped.',
				array(
					'order_id'   => $event->order_id,
					'event_uuid' => $event->event_uuid,
				)
			);
			return IntegrationStatus::SKIPPED;
		}

		$items   = (array) $result['items'];
		$handled = false;

		if ( $this->ownership->is_enabled() ) {
			$handled = $this->ownership->createOwnership( $event, $items ) || $handled;
		}

		if ( $this->warranty->is_enabled() ) {
			$handled = $this->warranty->createWarranty( $event, $items ) || $handled;
		}

		return $handled ? IntegrationStatus::COMPLETED : IntegrationStatus::SKIPPED;
	}

	/**
	 * Full refund: notify both seams so downstream systems can revoke (H3).
	 *
	 * The Bridge only notifies; the revocation policy itself belongs to the
	 * downstream YAZAN systems.
	 *
	 * @param Event $event Event.
	 * @return string Status.
	 */
	private function on_full_refund( Event $event ): string {
		$reason  = 'order_fully_refunded';
		$handled = false;

		if ( $this->ownership->is_enabled() ) {
			$handled = $this->ownership->revokeOwnership( $event, $reason ) || $handled;
		}

		if ( $this->warranty->is_enabled() ) {
			$handled = $this->warranty->suspendWarranty( $event, $reason ) || $handled;
		}

		return $handled ? IntegrationStatus::COMPLETED : IntegrationStatus::SKIPPED;
	}
}
