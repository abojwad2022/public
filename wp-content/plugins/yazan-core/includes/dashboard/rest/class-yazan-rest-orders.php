<?php
/**
 * Yazan Dashboard — Orders REST controller (yazan/v1).
 *
 * HPOS-safe by construction: every read and write goes through wc_get_orders() / wc_get_order()
 * and the WC_Order CRUD API. No get_post()/get_post_meta() on orders anywhere.
 *
 * Status changes use WC_Order::update_status() rather than set_status(), so WooCommerce fires its
 * normal status hooks — customer emails, stock handling, and the Yazan auto-print/notification
 * features in this plugin all keep working exactly as they do from wp-admin.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /orders endpoints.
 */
class Yazan_REST_Orders {

	/** Capability required for the whole order surface. */
	const CAP = 'edit_shop_orders';

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/orders',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'orders.view' )
		);

		// Bulk only ever changes status, so it maps to orders.status rather than a broad edit.
		register_rest_route(
			$ns,
			'/orders/bulk',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'bulk' ), 'orders.status' )
		);

		/*
		 * New-order polling for the dashboard. Safe alongside `/orders/(?P<id>\d+)` below —
		 * that route requires digits, so "alerts" can never be captured as an order id.
		 */
		register_rest_route(
			$ns,
			'/orders/alerts',
			Yazan_REST_Guard::args(
				WP_REST_Server::READABLE,
				array( __CLASS__, 'alerts' ),
				'orders.view',
				array(
					'args' => array(
						'since' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				)
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'show' ), 'orders.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'orders.edit' ),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/notes',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'notes' ), 'orders.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'add_note' ), 'orders.notes' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* New-order alerts                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Report orders that arrived after the id the dashboard last saw.
	 *
	 * Two modes:
	 *  - `since` omitted → SEED. Returns the newest order id with `count: 0`, so a dashboard that
	 *    has just been opened alerts only on orders arriving from now on rather than announcing
	 *    the entire existing order history. Same rule the wp-admin script uses.
	 *  - `since=<id>`    → report everything newer.
	 *
	 * The query itself lives in Yazan_Core_Notifications::orders_since(), shared with the wp-admin
	 * Heartbeat handler so the two surfaces cannot disagree.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function alerts( $request ) {
		if ( ! class_exists( 'Yazan_Core_Notifications' ) ) {
			return rest_ensure_response(
				array(
					'latest' => 0,
					'count'  => 0,
					'orders' => array(),
				)
			);
		}

		$raw = $request->get_param( 'since' );

		// Seed mode: no `since` at all. Note that `since=0` is NOT a seed — it is a genuine
		// request for "everything", which is what a store with no orders yet should report.
		if ( null === $raw ) {
			return rest_ensure_response(
				array(
					'latest' => Yazan_Core_Notifications::latest_order_id(),
					'count'  => 0,
					'orders' => array(),
				)
			);
		}

		return rest_ensure_response( Yazan_Core_Notifications::orders_since( absint( $raw ) ) );
	}

	/* --------------------------------------------------------------------- */
	/* List                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$allowed_orderby = array( 'date', 'id', 'total', 'modified' );
		$orderby         = in_array( $request->get_param( 'orderby' ), $allowed_orderby, true ) ? $request->get_param( 'orderby' ) : 'date';
		$order           = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => $orderby,
			'order'    => $order,
			'status'   => 'any',
			'type'     => 'shop_order',
		);

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $status && in_array( 'wc-' . $status, array_keys( wc_get_order_statuses() ), true ) ) {
			$args['status'] = $status;
		}

		$customer = absint( $request->get_param( 'customer_id' ) );
		if ( $customer ) {
			$args['customer_id'] = $customer;
		}

		$from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		if ( $from && $to ) {
			$args['date_created'] = $from . '...' . $to;
		} elseif ( $from ) {
			$args['date_created'] = '>=' . $from;
		} elseif ( $to ) {
			$args['date_created'] = '<=' . $to;
		}

		// Search: a bare number is treated as an order id, otherwise fall back to WC's search.
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$found = wc_get_order( (int) $search );
				$items = ( $found instanceof WC_Order ) ? array( self::summary( $found ) ) : array();
				return new WP_REST_Response(
					array(
						'items'       => $items,
						'total'       => count( $items ),
						'total_pages' => 1,
						'page'        => 1,
						'per_page'    => $per_page,
					),
					200
				);
			}
			$args['s'] = $search;
		}

		$result = wc_get_orders( $args );
		$items  = array();
		foreach ( $result->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$items[] = self::summary( $order );
			}
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $result->total,
				'total_pages' => (int) $result->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Read one                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /orders/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		return new WP_REST_Response( self::detail( $order ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Update                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * PUT /orders/{id} — change status and/or the customer-provided note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$changed = array();

		if ( null !== $request->get_param( 'status' ) ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( 'wc-' . $status, array_keys( wc_get_order_statuses() ), true ) ) {
				return new WP_Error( 'yazan_invalid', __( 'Unknown order status.', 'yazan' ), array( 'status' => 400 ) );
			}
			if ( $status !== $order->get_status() ) {
				$changed['status'] = $order->get_status() . ' → ' . $status;
				// update_status() (not set_status) so WooCommerce fires its normal side effects.
				$order->update_status( $status, __( 'Changed from the Yazan dashboard.', 'yazan' ), true );
			}
		}

		if ( null !== $request->get_param( 'customer_note' ) ) {
			$note = sanitize_textarea_field( (string) $request->get_param( 'customer_note' ) );
			$order->set_customer_note( $note );
			$order->save();
			$changed['customer_note'] = 'updated';
		}

		if ( $changed ) {
			Yazan_Dashboard_Audit::log( 'order.update', 'order', $order->get_id(), $changed );
		}

		return new WP_REST_Response( self::detail( wc_get_order( $order->get_id() ) ), 200 );
	}

	/**
	 * POST /orders/bulk — apply a status change to many orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bulk( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$ids    = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		if ( empty( $ids ) || ! in_array( 'wc-' . $status, array_keys( wc_get_order_statuses() ), true ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Invalid bulk request.', 'yazan' ), array( 'status' => 400 ) );
		}

		$done = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || $status === $order->get_status() ) {
				continue;
			}
			$order->update_status( $status, __( 'Bulk change from the Yazan dashboard.', 'yazan' ), true );
			$done[] = $id;
		}

		Yazan_Dashboard_Audit::log( 'order.bulk', 'order', 0, array( 'status' => $status, 'ids' => $done ) );

		return new WP_REST_Response( array( 'status' => $status, 'ids' => $done, 'count' => count( $done ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Notes                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /orders/{id}/notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function notes( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}
		return new WP_REST_Response( self::note_list( $order ), 200 );
	}

	/**
	 * POST /orders/{id}/notes — add a private note or a note emailed to the customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_note( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( '' === trim( $note ) ) {
			return new WP_Error( 'yazan_invalid', __( 'The note cannot be empty.', 'yazan' ), array( 'status' => 400 ) );
		}
		$to_customer = (bool) $request->get_param( 'customer_note' );

		$order->add_order_note( $note, $to_customer ? 1 : 0, true );
		Yazan_Dashboard_Audit::log( 'order.note', 'order', $order->get_id(), array( 'customer' => $to_customer ? 1 : 0 ) );

		return new WP_REST_Response( self::note_list( $order ), 201 );
	}

	/**
	 * Order notes shaped for the UI.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function note_list( WC_Order $order ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'order_by' => 'date_created', 'order' => 'DESC' ) );
		$out   = array();
		foreach ( $notes as $note ) {
			$out[] = array(
				'id'            => (int) $note->id,
				'author'        => $note->added_by,
				'date'          => $note->date_created ? $note->date_created->date( 'Y-m-d H:i' ) : '',
				'content'       => wp_kses_post( $note->content ),
				'customer_note' => (bool) $note->customer_note,
				'system'        => 'system' === $note->added_by,
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Formatting                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Compact row for the orders table.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function summary( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		return array(
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'customer'     => '' !== $name ? $name : __( 'Guest', 'yazan' ),
			'email'        => $order->get_billing_email(),
			'customer_id'  => $order->get_customer_id(),
			'items'        => $order->get_item_count(),
			'total'        => $order->get_total(),
			/*
			 * Format the total ourselves rather than using get_formatted_order_total(): for a
			 * refunded order that returns "<del>365.00</del> <ins>0.00</ins>", which collapses into
			 * a nonsense "$365.00 $0.00" once the tags are stripped. The refunded amount is sent
			 * separately below so the UI can present it properly.
			 */
			'total_html'   => html_entity_decode(
				wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
				ENT_QUOTES,
				'UTF-8'
			),
			'refunded'     => wc_format_decimal( $order->get_total_refunded(), wc_get_price_decimals() ),
			'currency'     => $order->get_currency(),
			'payment'      => $order->get_payment_method_title(),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'is_paid'      => $order->is_paid(),
			'needs_processing' => in_array( $order->get_status(), array( 'processing', 'on-hold', 'pending' ), true ),
			// Printable invoice (same document + handler the wp-admin orders list uses).
			'print_url'    => self::print_url( $order->get_id() ),
		);
	}

	/**
	 * Nonce-signed invoice URL, served by Yazan_Core_List_Print (cap: edit_shop_orders).
	 *
	 * Built with add_query_arg + esc_url_raw — never wp_nonce_url(), which HTML-encodes
	 * the ampersands and corrupts the query string once it reaches JavaScript.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function print_url( $order_id ) {
		if ( ! class_exists( 'Yazan_Core_List_Print' ) ) {
			return '';
		}
		$action = Yazan_Core_List_Print::ACTION;
		return esc_url_raw(
			add_query_arg(
				array(
					'action'   => $action,
					'order_id' => (int) $order_id,
					'_wpnonce' => wp_create_nonce( $action ),
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Public wrapper so the write controller can return the same shape after a mutation.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function public_detail( WC_Order $order ) {
		return self::detail( $order );
	}

	/**
	 * Refunds recorded against an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function refund_list( WC_Order $order ) {
		$out = array();
		foreach ( $order->get_refunds() as $refund ) {
			$author = $refund->get_refunded_by() ? get_userdata( $refund->get_refunded_by() ) : null;
			$out[]  = array(
				'id'     => $refund->get_id(),
				'amount' => wc_format_decimal( $refund->get_amount(), wc_get_price_decimals() ),
				'reason' => $refund->get_reason(),
				'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i' ) : '',
				'by'     => $author ? $author->display_name : '',
			);
		}
		return $out;
	}

	/**
	 * Full order payload for the detail view.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function detail( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product   = $item->get_product();
			$image_id  = $product instanceof WC_Product ? $product->get_image_id() : 0;
			$meta_data = array();
			foreach ( $item->get_formatted_meta_data() as $meta ) {
				$meta_data[] = array(
					'label' => wp_strip_all_tags( $meta->display_key ),
					'value' => wp_strip_all_tags( $meta->display_value ),
				);
			}

			$items[] = array(
				'id'           => (int) $item_id,
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product instanceof WC_Product ? $product->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'thumb'        => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
				'meta'         => $meta_data,
				// The Yazan serial, so staff can match a shipped ring to its certificate.
				'serial'       => $product instanceof WC_Product ? (string) $product->get_meta( Yazan_Core_Verify::SERIAL_META ) : '',
			);
		}

		$shipping_lines = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$shipping_lines[] = array( 'name' => $line->get_name(), 'total' => $line->get_total() );
		}
		$fee_lines = array();
		foreach ( $order->get_items( 'fee' ) as $line ) {
			$fee_lines[] = array( 'name' => $line->get_name(), 'total' => $line->get_total() );
		}

		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'status_label'   => wc_get_order_status_name( $order->get_status() ),
			'currency'       => $order->get_currency(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ) ),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'date_paid'      => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i' ) : '',
			'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i' ) : '',
			'customer_id'    => $order->get_customer_id(),
			'customer_note'  => $order->get_customer_note(),
			'payment_method' => $order->get_payment_method_title(),
			'transaction_id' => $order->get_transaction_id(),
			'is_paid'        => $order->is_paid(),
			'totals'         => array(
				'subtotal'       => wc_format_decimal( $order->get_subtotal(), wc_get_price_decimals() ),
				'discount_total' => wc_format_decimal( $order->get_discount_total(), wc_get_price_decimals() ),
				'shipping_total' => wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
				'total_tax'      => wc_format_decimal( $order->get_total_tax(), wc_get_price_decimals() ),
				'total'          => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'refunded'       => wc_format_decimal( $order->get_total_refunded(), wc_get_price_decimals() ),
			),
			'billing'        => $order->get_address( 'billing' ),
			'shipping'       => $order->get_address( 'shipping' ),
			'billing_formatted'  => wp_strip_all_tags( $order->get_formatted_billing_address( __( '—', 'yazan' ) ) ),
			'shipping_formatted' => wp_strip_all_tags( $order->get_formatted_shipping_address( __( '—', 'yazan' ) ) ),
			'items'          => $items,
			'shipping_lines' => $shipping_lines,
			'fee_lines'      => $fee_lines,
			'coupons'        => array_map(
				static function ( $c ) {
					return $c->get_code();
				},
				array_values( $order->get_items( 'coupon' ) )
			),
			'notes'          => self::note_list( $order ),
			'refunds'        => self::refund_list( $order ),
			'edit_url'       => $order->get_edit_order_url(),

			// Drives what the UI is allowed to offer. The server re-checks all of it on write.
			'is_editable'      => $order->is_editable(),
			'remaining_refund' => wc_format_decimal( $order->get_remaining_refund_amount(), wc_get_price_decimals() ),
			'gateway_refund_supported' => class_exists( 'Yazan_REST_Order_Write' )
				? Yazan_REST_Order_Write::gateway_supports_refund( $order )
				: false,
			'can_gateway_refund' => Yazan_Permissions::can( 'orders.refund_gateway' ),
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Order not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
