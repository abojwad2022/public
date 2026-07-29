<?php
/**
 * Yazan Core — New-order notifications.
 *
 * Centralises "a new order arrived" alerts across channels:
 *   1. Owner desktop/browser notification inside wp-admin (works locally, no cron —
 *      driven by the WP Heartbeat API so it fires while the dashboard is open).
 *   2. Email hardening — force the real owner recipient on the WooCommerce "New order"
 *      email and brand the From name (customer emails stay WooCommerce-native).
 *   3. SMS scaffold — a provider-agnostic sender, disabled by default, ready for a
 *      Twilio / regional gateway adapter to hook `yazan_sms_gateway` later.
 *
 * Phone push (owner) is delivered by the official WooCommerce mobile app via Jetpack
 * once the site is public — that needs no code here.
 *
 * HPOS-safe: all order access goes through wc_get_orders() / wc_get_order().
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class Yazan_Core_Notifications {

	/**
	 * Register every hook. Called once on `init`.
	 *
	 * @return void
	 */
	public static function register() {
		// 1. Owner browser alert (admin only).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_alert' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 10, 2 );
		add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_settings' ) );

		// 2. Email hardening (owner recipient + brand From name).
		add_filter( 'woocommerce_email_recipient_new_order', array( __CLASS__, 'new_order_recipient' ), 10, 2 );
		add_filter( 'woocommerce_email_from_name', array( __CLASS__, 'email_from_name' ), 10, 2 );

		// 3. SMS scaffold (no-op until a gateway + keys are configured).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'sms_owner_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sms_customer_status' ), 20, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	/**
	 * The real inbox that should receive owner alerts. Falls back to the site
	 * admin email, and is filterable for portability.
	 *
	 * @return string
	 */
	public static function owner_email() {
		$email = (string) get_option( 'yazan_owner_alert_email', '' );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return (string) apply_filters( 'yazan_owner_alert_email', $email );
	}

	/**
	 * ID of the most recent order (any status). Used to seed the client so it only
	 * alerts on orders that arrive AFTER the dashboard was opened.
	 *
	 * @return int
	 */
	public static function latest_order_id() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$ids = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
				'type'    => 'shop_order',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/* ---------------------------------------------------------------------
	 * 1. Owner browser alert (Heartbeat)
	 * ------------------------------------------------------------------- */

	/**
	 * Load the alert script on every admin screen for shop managers/admins.
	 *
	 * @return void
	 */
	public static function enqueue_admin_alert() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		/*
		 * The alert now draws from the shared token file rather than its own
		 * palette — it previously hardcoded a third gold (#b7935b) that matched
		 * neither theme's --yz-gold, so the same brand read differently depending
		 * on which Yazan surface you were looking at.
		 */
		$tokens_path = YAZAN_CORE_DIR . 'assets/css/yz-tokens.css';
		$deps        = array();
		if ( file_exists( $tokens_path ) ) {
			wp_enqueue_style(
				'yz-tokens',
				YAZAN_CORE_URL . 'assets/css/yz-tokens.css',
				array(),
				(string) filemtime( $tokens_path )
			);
			$deps[] = 'yz-tokens';
		}

		wp_enqueue_style(
			'yazan-order-alert',
			YAZAN_CORE_URL . 'assets/css/admin-order-alert.css',
			$deps,
			YAZAN_CORE_VERSION
		);
		wp_enqueue_script( 'heartbeat' );
		wp_enqueue_script(
			'yazan-order-alert',
			YAZAN_CORE_URL . 'assets/js/admin-order-alert.js',
			array( 'jquery', 'heartbeat' ),
			YAZAN_CORE_VERSION,
			true
		);
		wp_localize_script(
			'yazan-order-alert',
			'yazanOrderAlert',
			array(
				'latest'   => self::latest_order_id(),
				'adminUrl' => admin_url( 'admin.php?page=wc-orders' ),
				'i18n'     => array(
					'title'  => __( 'New order', 'yazan' ),
					'one'    => __( 'A new order has arrived.', 'yazan' ),
					/* translators: %d: number of new orders. */
					'many'   => __( '%d new orders have arrived.', 'yazan' ),
					'view'   => __( 'View orders', 'yazan' ),
				),
			)
		);
	}

	/**
	 * Nudge the admin Heartbeat interval so alerts feel prompt (seconds).
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public static function heartbeat_settings( $settings ) {
		$settings['interval'] = 30;
		return $settings;
	}

	/**
	 * Orders newer than a given id.
	 *
	 * The single source of truth for "what has arrived since you last looked". Both the wp-admin
	 * Heartbeat handler below and the dashboard's `yazan/v1/orders/alerts` route call it, so the
	 * two surfaces cannot drift apart — one query, two consumers.
	 *
	 * Callers are responsible for the capability check; this only reads.
	 *
	 * @param int $last_seen Highest order id the client already knows about.
	 * @return array{count:int,latest:int,orders:array<int,array<string,mixed>>}
	 */
	public static function orders_since( $last_seen ) {
		$empty = array(
			'count'  => 0,
			'latest' => 0,
			'orders' => array(),
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $empty;
		}

		$last_seen = (int) $last_seen;

		$ids = wc_get_orders(
			array(
				'limit'   => 20,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
				'type'    => 'shop_order',
			)
		);

		if ( ! $ids ) {
			return $empty;
		}

		$new = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $last_seen ) {
					return (int) $id > $last_seen;
				}
			)
		);

		$orders = array();

		// A short summary of the newest few — enough for the dashboard to name what arrived
		// without a second round trip. Capped so a long-idle tab can't request 20 full orders.
		foreach ( array_slice( $new, 0, 5 ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			/*
			 * wc_price() returns markup whose currency symbol is an HTML entity, so stripping the
			 * tags alone leaves "&#36;770.00". This is consumed as JSON by a React client that
			 * renders text, not HTML — decode so it reads "$770.00" rather than the raw entity.
			 */
			$total = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );

			$orders[] = array(
				'id'       => (int) $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'total'    => html_entity_decode( $total, ENT_QUOTES, 'UTF-8' ),
				'status'   => (string) $order->get_status(),
				'customer' => trim( $order->get_formatted_billing_full_name() ),
			);
		}

		return array(
			'count'  => count( $new ),
			'latest' => (int) max( $ids ),
			'orders' => $orders,
		);
	}

	/**
	 * Server side: report any orders newer than the client's last-seen id.
	 *
	 * @param array $response Outgoing heartbeat response.
	 * @param array $data     Incoming heartbeat data.
	 * @return array
	 */
	public static function heartbeat_received( $response, $data ) {
		if ( ! isset( $data['yazan_last_seen'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $response;
		}

		$result = self::orders_since( (int) $data['yazan_last_seen'] );

		if ( $result['count'] > 0 ) {
			$response['yazan_new_orders'] = array(
				'count'  => $result['count'],
				'latest' => $result['latest'],
			);
		}

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * 2. Email hardening
	 * ------------------------------------------------------------------- */

	/**
	 * Ensure the owner's real inbox is on the New Order email recipient list
	 * (without dropping any recipients already configured in WooCommerce).
	 *
	 * @param string        $recipient Comma-separated recipients.
	 * @param WC_Order|null $order     The order (unused, kept for signature).
	 * @return string
	 */
	public static function new_order_recipient( $recipient, $order = null ) {
		$owner = self::owner_email();
		$list  = array_filter( array_map( 'trim', explode( ',', (string) $recipient ) ) );
		if ( $owner && is_email( $owner ) && ! in_array( $owner, $list, true ) ) {
			$list[] = $owner;
		}
		return implode( ',', $list );
	}

	/**
	 * Brand the From name on outgoing WooCommerce emails.
	 *
	 * @param string        $name  Current From name.
	 * @param WC_Email|null $email The email object (unused).
	 * @return string
	 */
	public static function email_from_name( $name, $email = null ) {
		$brand = get_bloginfo( 'name' );
		return (string) apply_filters( 'yazan_email_from_name', $brand ? $brand : 'Yazan' );
	}

	/* ---------------------------------------------------------------------
	 * 3. SMS scaffold (disabled until a gateway is configured)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether SMS sending is switched on.
	 *
	 * @return bool
	 */
	public static function sms_enabled() {
		return 'yes' === get_option( 'yazan_sms_enabled', 'no' );
	}

	/**
	 * Provider-agnostic SMS send. A gateway adapter returns a boolean from the
	 * `yazan_sms_gateway` filter; with no adapter it is a harmless no-op.
	 *
	 * @param string $to      Destination number (E.164 recommended).
	 * @param string $message Message body.
	 * @return bool True on success.
	 */
	public static function send_sms( $to, $message ) {
		$to      = preg_replace( '/[^0-9+]/', '', (string) $to );
		$message = trim( (string) $message );
		if ( '' === $to || '' === $message ) {
			return false;
		}

		/**
		 * Filter: an SMS gateway adapter should hook this, send the message, and
		 * return true/false. Default null means "no gateway configured".
		 *
		 * @param null|bool $result  Send result (null = no gateway).
		 * @param string    $to      Destination number.
		 * @param string    $message Message body.
		 */
		$result = apply_filters( 'yazan_sms_gateway', null, $to, $message );

		if ( null === $result ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Yazan SMS] no gateway configured; skipped message to %s', $to ) ); // phpcs:ignore
			}
			return false;
		}
		return (bool) $result;
	}

	/**
	 * Owner SMS on a new order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function sms_owner_new_order( $order_id ) {
		if ( ! self::sms_enabled() ) {
			return;
		}
		$phone = (string) get_option( 'yazan_owner_alert_phone', '' );
		if ( '' === $phone ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$total = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
		$msg   = sprintf(
			/* translators: 1: order number, 2: order total. */
			__( 'Yazan: new order %1$s — %2$s', 'yazan' ),
			$order->get_order_number(),
			$total
		);
		self::send_sms( $phone, $msg );
	}

	/**
	 * Customer SMS on a meaningful status change.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public static function sms_customer_status( $order_id, $from, $to, $order ) {
		if ( ! self::sms_enabled() ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		/**
		 * Filter: which new statuses trigger a customer SMS.
		 *
		 * @param string[] $statuses Status slugs (without the wc- prefix).
		 * @param WC_Order $order    The order.
		 */
		$notify = apply_filters( 'yazan_sms_customer_statuses', array( 'processing', 'completed' ), $order );
		if ( ! in_array( $to, (array) $notify, true ) ) {
			return;
		}

		$phone = $order->get_billing_phone();
		if ( ! $phone ) {
			return;
		}
		$msg = sprintf(
			/* translators: 1: order number, 2: human status name. */
			__( 'Your Yazan order %1$s is now %2$s.', 'yazan' ),
			$order->get_order_number(),
			wc_get_order_status_name( $to )
		);
		self::send_sms( $phone, $msg );
	}
}
