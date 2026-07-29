<?php
/**
 * Payment event value object.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Events;

use Yazan\PaymentBridge\Exceptions\ValidationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable snapshot of one payment state transition.
 *
 * Built exclusively from the WooCommerce CRUD API, so it is HPOS-safe: no
 * wp_posts / wp_postmeta access anywhere.
 */
final class Event {

	/** Event originated from a gateway callback or a customer-driven checkout. */
	public const SOURCE_GATEWAY = 'gateway';

	/** An administrator changed the order status by hand (H4). */
	public const SOURCE_MANUAL = 'manual';

	/** Cron, WP-CLI, or another unattended process. */
	public const SOURCE_SYSTEM = 'system';

	/**
	 * @param int         $order_id           Order id.
	 * @param string      $event_type         One of EventTypes::*.
	 * @param string      $event_uuid         RFC-4122 UUID.
	 * @param int         $customer_id        Customer user id (0 for guests).
	 * @param string      $source             One of SOURCE_*.
	 * @param string      $gateway            Payment method id.
	 * @param string|null $transaction_id     Gateway transaction id (null for COD/bank transfer).
	 * @param string      $amount             Decimal string.
	 * @param string      $currency           ISO-4217 code.
	 * @param string      $payment_status     WooCommerce order status at capture time.
	 * @param int         $id                 Stored row id, 0 when not yet persisted.
	 * @param string      $integration_status Current integration status.
	 */
	public function __construct(
		public int $order_id,
		public string $event_type,
		public string $event_uuid,
		public int $customer_id = 0,
		public string $source = self::SOURCE_GATEWAY,
		public string $gateway = '',
		public ?string $transaction_id = null,
		public string $amount = '0',
		public string $currency = '',
		public string $payment_status = '',
		public int $id = 0,
		public string $integration_status = IntegrationStatus::PENDING
	) {}

	/**
	 * Build an event from a WooCommerce order.
	 *
	 * @param \WC_Order   $order      Order.
	 * @param string      $event_type One of EventTypes::*.
	 * @param string|null $amount     Override amount (refund events pass the refunded total).
	 * @return self
	 * @throws ValidationException When the event type is unknown.
	 */
	public static function from_order( \WC_Order $order, string $event_type, ?string $amount = null ): self {
		if ( ! EventTypes::is_valid( $event_type ) ) {
			throw new ValidationException( 'Unknown event type: ' . $event_type );
		}

		$transaction_id = trim( (string) $order->get_transaction_id() );

		return new self(
			order_id: $order->get_id(),
			event_type: $event_type,
			event_uuid: wp_generate_uuid4(),
			customer_id: (int) $order->get_customer_id(),
			source: self::detect_source(),
			gateway: substr( (string) $order->get_payment_method(), 0, 64 ),
			// Nullable by design: COD and bank-transfer orders have no transaction id (H7).
			transaction_id: '' === $transaction_id ? null : substr( $transaction_id, 0, 191 ),
			amount: null !== $amount ? $amount : (string) wc_format_decimal( $order->get_total(), 4 ),
			currency: substr( (string) $order->get_currency(), 0, 3 ),
			payment_status: substr( (string) $order->get_status(), 0, 32 )
		);
	}

	/**
	 * Hydrate from a database row.
	 *
	 * @param object $row Row from the events table.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			order_id: (int) ( $row->order_id ?? 0 ),
			event_type: (string) ( $row->event_type ?? '' ),
			event_uuid: (string) ( $row->event_uuid ?? '' ),
			customer_id: (int) ( $row->customer_id ?? 0 ),
			source: (string) ( $row->source ?? self::SOURCE_GATEWAY ),
			gateway: (string) ( $row->gateway ?? '' ),
			transaction_id: isset( $row->transaction_id ) ? ( null === $row->transaction_id ? null : (string) $row->transaction_id ) : null,
			amount: (string) ( $row->amount ?? '0' ),
			currency: (string) ( $row->currency ?? '' ),
			payment_status: (string) ( $row->payment_status ?? '' ),
			id: (int) ( $row->id ?? 0 ),
			integration_status: (string) ( $row->integration_status ?? IntegrationStatus::PENDING )
		);
	}

	/**
	 * Column => value map for insertion.
	 *
	 * @param string $now MySQL UTC datetime.
	 * @return array<string,mixed>
	 */
	public function to_row( string $now ): array {
		return array(
			'event_uuid'         => $this->event_uuid,
			'order_id'           => $this->order_id,
			'customer_id'        => $this->customer_id,
			'event_type'         => $this->event_type,
			'source'             => $this->source,
			'gateway'            => $this->gateway,
			'transaction_id'     => $this->transaction_id,
			'amount'             => $this->amount,
			'currency'           => $this->currency,
			'payment_status'     => $this->payment_status,
			'integration_status' => $this->integration_status,
			'processed'          => 0,
			'error_message'      => null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
	}

	/**
	 * Identify who caused this transition (H4).
	 *
	 * A customer completing checkout is logged in but not in wp-admin, so they
	 * resolve to "gateway". A gateway webhook has no user at all. Only a
	 * privileged user acting inside wp-admin counts as "manual" — legitimate for
	 * bank transfer and COD, and labelled as such in the events list and logs.
	 *
	 * @return string One of SOURCE_*.
	 */
	public static function detect_source(): string {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return self::SOURCE_SYSTEM;
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 && is_admin() && ! wp_doing_ajax() && user_can( $user_id, 'edit_shop_orders' ) ) {
			return self::SOURCE_MANUAL;
		}

		return self::SOURCE_GATEWAY;
	}

	/**
	 * Human-readable source label.
	 *
	 * @return string
	 */
	public function source_label(): string {
		$labels = array(
			self::SOURCE_GATEWAY => __( 'Gateway', 'yazan-payment-bridge' ),
			self::SOURCE_MANUAL  => __( 'Manual', 'yazan-payment-bridge' ),
			self::SOURCE_SYSTEM  => __( 'System', 'yazan-payment-bridge' ),
		);

		return $labels[ $this->source ] ?? $this->source;
	}
}
