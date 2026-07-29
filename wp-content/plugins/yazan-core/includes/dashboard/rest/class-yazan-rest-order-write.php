<?php
/**
 * Yazan Dashboard — order WRITE operations: create, line-item editing, refunds.
 *
 * These are the money-touching endpoints, so they are deliberately conservative:
 *
 *  - Line items may only be changed while WC_Order::is_editable() is true (pending / on-hold),
 *    exactly the rule wp-admin enforces. Editing a paid order would desynchronise the order total
 *    from the amount actually captured, so it is refused with 409.
 *  - Refunds default to MANUAL (records the refund, restocks, moves no money). Sending money back
 *    through the gateway is opt-in per request AND requires `manage_woocommerce`, because it is
 *    irreversible.
 *  - Every refund and every item change is written to the audit log with its amount.
 *
 * All order access is HPOS-safe (wc_get_order / CRUD only).
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create / edit-items / refund endpoints for orders.
 */
class Yazan_REST_Order_Write {

	/** Baseline capability for order writes. */
	const CAP = 'edit_shop_orders';

	/** Extra capability required to push a refund through the payment gateway. */
	const CAP_GATEWAY_REFUND = 'manage_woocommerce';

	/** Address fields we accept, per address type. */
	const ADDRESS_FIELDS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
	);

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * NOTE: `POST /orders` is registered here while `GET /orders` lives in Yazan_REST_Orders.
	 * WordPress merges endpoints for the same route, so both methods coexist.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = Yazan_Dashboard_Auth::NS;
		$perm = Yazan_Dashboard_Auth::require_cap( self::CAP );

		register_rest_route(
			$ns,
			'/orders',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_items' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/addresses',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_addresses' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/coupons',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_coupon' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_coupon' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/refunds',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_refunds' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_refund' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/refunds/(?P<refund_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_refund' ),
				'permission_callback' => Yazan_Dashboard_Auth::require_cap( self::CAP_GATEWAY_REFUND ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Create                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /orders — create a new order.
	 *
	 * Defaults to `pending` so nothing is emailed or captured unless explicitly asked for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$lines = (array) $request->get_param( 'line_items' );
		if ( empty( $lines ) ) {
			return new WP_Error( 'yazan_invalid', __( 'An order needs at least one product.', 'yazan' ), array( 'status' => 400 ) );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! $status || ! in_array( 'wc-' . $status, array_keys( wc_get_order_statuses() ), true ) ) {
			$status = 'pending';
		}

		$order = wc_create_order( array( 'status' => 'pending' ) );
		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'yazan_create_failed', $order->get_error_message(), array( 'status' => 500 ) );
		}

		$order->set_created_via( 'yazan-dashboard' );

		$customer_id = absint( $request->get_param( 'customer_id' ) );
		if ( $customer_id && get_userdata( $customer_id ) ) {
			$order->set_customer_id( $customer_id );
		}

		// Line items.
		$added = 0;
		foreach ( $lines as $line ) {
			$product_id = isset( $line['variation_id'] ) && $line['variation_id']
				? absint( $line['variation_id'] )
				: absint( $line['product_id'] ?? 0 );
			$qty = max( 1, absint( $line['quantity'] ?? 1 ) );

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$order->add_product( $product, $qty );
			++$added;
		}
		if ( ! $added ) {
			$order->delete( true ); // Don't leave an empty shell behind.
			return new WP_Error( 'yazan_invalid', __( 'None of the supplied products could be found.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Addresses.
		$billing = self::clean_address( (array) $request->get_param( 'billing' ), true );
		if ( $billing ) {
			$order->set_address( $billing, 'billing' );
		}
		$shipping = self::clean_address( (array) $request->get_param( 'shipping' ), false );
		if ( $shipping ) {
			$order->set_address( $shipping, 'shipping' );
		}

		// Optional shipping line.
		$shipping_lines = (array) $request->get_param( 'shipping_lines' );
		foreach ( $shipping_lines as $line ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( sanitize_text_field( (string) ( $line['method_title'] ?? __( 'Shipping', 'yazan' ) ) ) );
			$item->set_total( wc_format_decimal( $line['total'] ?? 0 ) );
			$order->add_item( $item );
		}

		// Optional fee line.
		foreach ( (array) $request->get_param( 'fee_lines' ) as $line ) {
			$item = new WC_Order_Item_Fee();
			$item->set_name( sanitize_text_field( (string) ( $line['name'] ?? __( 'Fee', 'yazan' ) ) ) );
			$item->set_total( wc_format_decimal( $line['total'] ?? 0 ) );
			$order->add_item( $item );
		}

		if ( null !== $request->get_param( 'customer_note' ) ) {
			$order->set_customer_note( sanitize_textarea_field( (string) $request->get_param( 'customer_note' ) ) );
		}

		$order->calculate_totals( true );
		$order->save();

		// Apply the requested status last, so WooCommerce's side effects fire on a complete order.
		if ( 'pending' !== $status ) {
			$order->update_status( $status, __( 'Created from the Yazan dashboard.', 'yazan' ), true );
		}

		Yazan_Dashboard_Audit::log(
			'order.create',
			'order',
			$order->get_id(),
			array( 'status' => $status, 'items' => $added, 'total' => $order->get_total() )
		);

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 201 );
	}

	/* --------------------------------------------------------------------- */
	/* Line items                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * PUT /orders/{id}/items — add, change quantity of, or remove line items.
	 *
	 * Only allowed while the order is editable (pending/on-hold), matching WooCommerce.
	 * Existing items keep their agreed UNIT price when quantity changes, so historical pricing
	 * is never silently rewritten to today's catalogue price.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_items( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		if ( ! $order->is_editable() ) {
			return new WP_Error(
				'yazan_not_editable',
				sprintf(
					/* translators: %s: order status label. */
					__( 'This order is %s, so its items can no longer be changed. Refund it instead.', 'yazan' ),
					wc_get_order_status_name( $order->get_status() )
				),
				array( 'status' => 409 )
			);
		}

		$changes = array();

		// Remove.
		foreach ( array_filter( array_map( 'absint', (array) $request->get_param( 'remove' ) ) ) as $item_id ) {
			if ( $order->get_item( $item_id ) ) {
				$order->remove_item( $item_id );
				$changes[] = 'removed:' . $item_id;
			}
		}

		// Update quantities on existing items.
		foreach ( (array) $request->get_param( 'items' ) as $row ) {
			$item_id = absint( $row['id'] ?? 0 );
			if ( ! $item_id ) {
				continue;
			}
			$item = $order->get_item( $item_id );
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty = absint( $row['quantity'] ?? 0 );
			if ( $qty < 1 ) {
				$order->remove_item( $item_id );
				$changes[] = 'removed:' . $item_id;
				continue;
			}

			$old_qty = max( 1, (int) $item->get_quantity() );
			$unit    = (float) $item->get_subtotal() / $old_qty;
			$unit_t  = (float) $item->get_total() / $old_qty;

			$item->set_quantity( $qty );
			$item->set_subtotal( wc_format_decimal( $unit * $qty ) );
			$item->set_total( wc_format_decimal( $unit_t * $qty ) );
			$item->save();
			$changes[] = 'qty:' . $item_id . '=' . $qty;
		}

		// Add new products.
		foreach ( (array) $request->get_param( 'add' ) as $row ) {
			$product_id = ! empty( $row['variation_id'] ) ? absint( $row['variation_id'] ) : absint( $row['product_id'] ?? 0 );
			$qty        = max( 1, absint( $row['quantity'] ?? 1 ) );
			$product    = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				$order->add_product( $product, $qty );
				$changes[] = 'added:' . $product_id . '×' . $qty;
			}
		}

		if ( empty( $changes ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to change.', 'yazan' ), array( 'status' => 400 ) );
		}

		$order->calculate_totals( true );
		$order->save();

		Yazan_Dashboard_Audit::log(
			'order.items',
			'order',
			$order->get_id(),
			array( 'changes' => $changes, 'total' => $order->get_total() )
		);

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Addresses                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * PUT /orders/{id}/addresses — correct the billing and/or shipping address.
	 *
	 * Unlike line items this stays available after payment: a customer giving a corrected delivery
	 * address is routine and changes no money. Totals are only recalculated when the country/state
	 * changes, since that can move the tax rate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_addresses( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$changed  = array();
		$tax_move = false;

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$raw = $request->get_param( $type );
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$clean = self::clean_address( $raw, 'billing' === $type );
			if ( ! $clean ) {
				continue;
			}

			$before_country = $order->{"get_{$type}_country"}();
			$before_state   = $order->{"get_{$type}_state"}();

			$order->set_address( $clean, $type );
			$changed[] = $type;

			if (
				( isset( $clean['country'] ) && $clean['country'] !== $before_country ) ||
				( isset( $clean['state'] ) && $clean['state'] !== $before_state )
			) {
				$tax_move = true;
			}
		}

		if ( empty( $changed ) ) {
			return new WP_Error( 'yazan_invalid', __( 'No address fields were supplied.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Only touch totals when the tax jurisdiction actually moved — and never on a paid order,
		// where the captured amount must keep matching the order total.
		if ( $tax_move && $order->is_editable() ) {
			$order->calculate_totals( true );
		}
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: comma-separated address types. */
				__( 'Address updated from the Yazan dashboard (%s).', 'yazan' ),
				implode( ', ', $changed )
			),
			0,
			true
		);

		Yazan_Dashboard_Audit::log(
			'order.address',
			'order',
			$order->get_id(),
			array( 'changed' => $changed, 'recalculated' => $tax_move ? 1 : 0 )
		);

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Coupons on an order                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /orders/{id}/coupons — apply a coupon to an existing order.
	 *
	 * Only while the order is editable: applying a discount to a paid order would drop the total
	 * below the amount already captured.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_coupon( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		if ( ! $order->is_editable() ) {
			return new WP_Error(
				'yazan_not_editable',
				__( 'Coupons can only be applied while an order is still editable. Issue a refund instead.', 'yazan' ),
				array( 'status' => 409 )
			);
		}

		$code = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Enter a coupon code.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( ! wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'yazan_no_coupon', __( 'No coupon with that code exists.', 'yazan' ), array( 'status' => 404 ) );
		}

		// WC_Order::apply_coupon() runs the full validity check (expiry, limits, restrictions)
		// and returns WP_Error when the coupon does not qualify.
		$result = $order->apply_coupon( $code );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yazan_coupon_rejected', $result->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log( 'order.coupon_add', 'order', $order->get_id(), array( 'code' => $code ) );

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/**
	 * DELETE /orders/{id}/coupons?code=XXX — remove a coupon from an order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_coupon( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		if ( ! $order->is_editable() ) {
			return new WP_Error(
				'yazan_not_editable',
				__( 'Coupons can only be removed while an order is still editable.', 'yazan' ),
				array( 'status' => 409 )
			);
		}

		$code = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Which coupon should be removed?', 'yazan' ), array( 'status' => 400 ) );
		}

		$found = false;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( wc_format_coupon_code( $item->get_code() ) === $code ) {
				$found = true;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'yazan_not_found', __( 'That coupon is not on this order.', 'yazan' ), array( 'status' => 404 ) );
		}

		$order->remove_coupon( $code );

		Yazan_Dashboard_Audit::log( 'order.coupon_remove', 'order', $order->get_id(), array( 'code' => $code ) );

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Refunds                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /orders/{id}/refunds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_refunds( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		return new WP_REST_Response( Yazan_REST_Orders::refund_list( $order ), 200 );
	}

	/**
	 * POST /orders/{id}/refunds — record a refund, optionally sending it through the gateway.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_refund( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$remaining = (float) $order->get_remaining_refund_amount();
		if ( $remaining <= 0 ) {
			return new WP_Error( 'yazan_nothing_to_refund', __( 'This order is already fully refunded.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Optional per-item refund lines.
		$line_items  = array();
		$items_total = 0.0;
		foreach ( (array) $request->get_param( 'line_items' ) as $row ) {
			$item_id = absint( $row['item_id'] ?? 0 );
			$item    = $item_id ? $order->get_item( $item_id ) : null;
			if ( ! $item ) {
				continue;
			}
			$qty   = absint( $row['qty'] ?? 0 );
			$total = isset( $row['refund_total'] ) ? (float) wc_format_decimal( $row['refund_total'] ) : 0.0;
			if ( $qty < 1 && $total <= 0 ) {
				continue;
			}
			$line_items[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => $total,
			);
			$items_total           += $total;
		}

		$amount = null !== $request->get_param( 'amount' )
			? (float) wc_format_decimal( $request->get_param( 'amount' ) )
			: $items_total;

		if ( $amount <= 0 ) {
			return new WP_Error( 'yazan_invalid', __( 'Refund amount must be greater than zero.', 'yazan' ), array( 'status' => 400 ) );
		}
		// Compare in minor units to dodge float rounding at the boundary.
		if ( round( $amount, 2 ) > round( $remaining, 2 ) ) {
			return new WP_Error(
				'yazan_amount_too_high',
				sprintf(
					/* translators: %s: formatted remaining refundable amount. */
					__( 'That is more than the %s still refundable on this order.', 'yazan' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $order->get_currency() ) ) ) )
				),
				array( 'status' => 400 )
			);
		}

		// Gateway refund: opt-in, extra capability, and the gateway must actually support it.
		$via_gateway = (bool) $request->get_param( 'refund_payment' );
		if ( $via_gateway ) {
			if ( ! current_user_can( self::CAP_GATEWAY_REFUND ) ) {
				return new WP_Error(
					'yazan_forbidden',
					__( 'You do not have permission to send refunds through the payment gateway.', 'yazan' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			if ( ! self::gateway_supports_refund( $order ) ) {
				return new WP_Error(
					'yazan_gateway_unsupported',
					__( 'The payment method used for this order cannot process automatic refunds. Record a manual refund instead.', 'yazan' ),
					array( 'status' => 400 )
				);
			}
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => sanitize_textarea_field( (string) $request->get_param( 'reason' ) ),
				'line_items'     => $line_items,
				'refund_payment' => $via_gateway,
				'restock_items'  => (bool) $request->get_param( 'restock' ),
			)
		);

		if ( is_wp_error( $refund ) ) {
			// A failed gateway call lands here — surface the gateway's own message.
			Yazan_Dashboard_Audit::log(
				'order.refund_failed',
				'order',
				$order->get_id(),
				array( 'amount' => $amount, 'gateway' => $via_gateway ? 1 : 0, 'error' => $refund->get_error_message() )
			);
			return new WP_Error( 'yazan_refund_failed', $refund->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log(
			'order.refund',
			'order',
			$order->get_id(),
			array(
				'refund_id' => $refund->get_id(),
				'amount'    => $amount,
				'gateway'   => $via_gateway ? 1 : 0,
				'restock'   => $request->get_param( 'restock' ) ? 1 : 0,
			)
		);

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 201 );
	}

	/**
	 * DELETE /orders/{id}/refunds/{refund_id} — remove a refund RECORD.
	 *
	 * This does not reverse a gateway refund; money already sent stays sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_refund( WP_REST_Request $request ) {
		$order  = wc_get_order( absint( $request['id'] ) );
		$refund = wc_get_order( absint( $request['refund_id'] ) );

		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			return self::not_found();
		}
		if ( (int) $refund->get_parent_id() !== $order->get_id() ) {
			return new WP_Error( 'yazan_invalid', __( 'That refund does not belong to this order.', 'yazan' ), array( 'status' => 400 ) );
		}

		$amount = $refund->get_amount();
		$refund->delete( true );

		Yazan_Dashboard_Audit::log( 'order.refund_delete', 'order', $order->get_id(), array( 'amount' => $amount ) );

		return new WP_REST_Response( Yazan_REST_Orders::public_detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether the gateway that took payment can process an automated refund.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function gateway_supports_refund( WC_Order $order ) {
		$method = $order->get_payment_method();
		if ( ! $method || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $method ] ) && $gateways[ $method ]->supports( 'refunds' );
	}

	/**
	 * Sanitize an address payload down to known fields.
	 *
	 * @param array $raw       Raw address.
	 * @param bool  $isBilling Billing addresses also carry email + phone.
	 * @return array
	 */
	private static function clean_address( array $raw, $isBilling ) {
		$out = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( isset( $raw[ $field ] ) ) {
				$out[ $field ] = sanitize_text_field( (string) $raw[ $field ] );
			}
		}
		if ( $isBilling ) {
			if ( isset( $raw['email'] ) && '' !== $raw['email'] ) {
				$email = sanitize_email( (string) $raw['email'] );
				if ( is_email( $email ) ) {
					$out['email'] = $email;
				}
			}
			if ( isset( $raw['phone'] ) ) {
				$out['phone'] = sanitize_text_field( (string) $raw['phone'] );
			}
		}
		return $out;
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Order not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
