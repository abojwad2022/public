<?php
/**
 * Shared behaviour for every simulated Yazan gateway.
 *
 * @package Yazan_Test_Gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Base class for the simulated gateways.
 *
 * Subclasses supply nothing but identity — id, labels and the brand it stands in
 * for. All payment behaviour lives here so the four gateways cannot drift apart.
 *
 * The point of these is fidelity of *flow*, not of protocol: a real Apple Pay
 * payment is an express button backed by a merchant session, which cannot exist on
 * a plain-http local domain. What CAN be exercised locally is everything after the
 * gateway says yes — order status, stock, emails, the thank-you page, and the
 * Payment Bridge ledger. That is what these reproduce faithfully.
 */
abstract class Yazan_Test_Gateway extends WC_Payment_Gateway {

	/**
	 * Gateway id. Subclasses must override.
	 *
	 * @var string
	 */
	protected $gateway_id = '';

	/**
	 * Customer-facing default title. Subclasses must override.
	 *
	 * @var string
	 */
	protected $default_title = '';

	/**
	 * Prefix used when inventing a transaction id, e.g. `tst_card`.
	 *
	 * @var string
	 */
	protected $txn_prefix = 'tst';

	/**
	 * Wire up the gateway.
	 */
	public function __construct() {
		$this->id                 = $this->gateway_id;
		$this->has_fields         = false;
		$this->method_title       = sprintf(
			/* translators: %s: brand name, e.g. Apple Pay. */
			__( 'Yazan Test — %s', 'yazan-test-gateways' ),
			$this->default_title
		);
		$this->method_description = __( 'Simulated gateway for local development. Completes the order without contacting any payment processor and without moving money. Never enable on a live store.', 'yazan-test-gateways' );

		// Refunds are supported so the Payment Bridge's refund paths can be exercised.
		$this->supports = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', $this->default_title );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'yazan-test-gateways' ),
				'type'    => 'checkbox',
				/* translators: %s: brand name. */
				'label'   => sprintf( __( 'Enable simulated %s', 'yazan-test-gateways' ), $this->default_title ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'yazan-test-gateways' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'yazan-test-gateways' ),
				'default'     => $this->default_title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'yazan-test-gateways' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment option at checkout.', 'yazan-test-gateways' ),
				'default'     => __( 'Simulated payment for testing. No money will be taken.', 'yazan-test-gateways' ),
			),
			'outcome'     => array(
				'title'       => __( 'Simulated outcome', 'yazan-test-gateways' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Choose "Decline" to exercise the failed-payment path — the order is set to Failed and the customer is returned to checkout with an error.', 'yazan-test-gateways' ),
				'default'     => 'success',
				'desc_tip'    => true,
				'options'     => array(
					'success' => __( 'Approve the payment', 'yazan-test-gateways' ),
					'failure' => __( 'Decline the payment', 'yazan-test-gateways' ),
				),
			),
		);
	}

	/**
	 * An invented but realistically-shaped transaction id.
	 *
	 * Uses wp_generate_password() for entropy — it is not a security token, only a
	 * unique-looking reference, but there is no reason to reach for anything weaker.
	 *
	 * @return string
	 */
	protected function fake_transaction_id() {
		return $this->txn_prefix . '_' . strtolower( wp_generate_password( 20, false, false ) );
	}

	/**
	 * Take the (simulated) payment.
	 *
	 * On approval this calls WC_Order::payment_complete(), which is the same entry
	 * point a real gateway uses — so stock reduction, order emails, the thank-you
	 * redirect and the `woocommerce_payment_complete` hook (which the Yazan Payment
	 * Bridge listens to) all behave exactly as they would in production.
	 *
	 * @param int $order_id Order id.
	 * @return array WooCommerce checkout result.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'That order could not be found.', 'yazan-test-gateways' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'failure' === $this->get_option( 'outcome', 'success' ) ) {
			$message = sprintf(
				/* translators: %s: brand name. */
				__( 'Simulated %s payment was declined.', 'yazan-test-gateways' ),
				$this->title
			);

			$order->update_status( 'failed', $message );
			wc_add_notice( $message, 'error' );

			return array( 'result' => 'failure' );
		}

		$order->payment_complete( $this->fake_transaction_id() );

		$order->add_order_note(
			sprintf(
				/* translators: %s: brand name. */
				__( 'SIMULATED payment via %s. No money was taken — this order came from a test gateway.', 'yazan-test-gateways' ),
				$this->title
			)
		);

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Approve any refund, so full and partial refund flows (and the payment events
	 * they raise) can be tested from the order screen.
	 *
	 * @param int    $order_id Order id.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Optional reason.
	 * @return bool True — always succeeds.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: refunded amount, 2: reason. */
					__( 'SIMULATED refund of %1$s. %2$s', 'yazan-test-gateways' ),
					wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) ),
					$reason ? $reason : ''
				)
			);
		}

		return true;
	}

	/**
	 * Flag the gateway in the admin list so it can never be mistaken for the real one.
	 *
	 * @return string
	 */
	public function get_method_title() {
		return $this->method_title;
	}
}
