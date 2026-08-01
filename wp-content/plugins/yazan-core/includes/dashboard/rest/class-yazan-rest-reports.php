<?php
/**
 * Yazan Dashboard — reports.
 *
 * Practical store reporting: revenue over a period, best sellers, and stock that needs attention.
 * Deliberately built on wc_get_orders() + order line items rather than the WooCommerce Analytics
 * lookup tables, because those tables can lag or be un-synced on a store that has had orders
 * imported or edited outside the normal flow — this always reflects the actual orders.
 *
 * Refunds are subtracted so revenue is NET, which is the number that matters.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /reports endpoints.
 */
class Yazan_REST_Reports {

	/** Statuses that represent real, countable revenue. */
	const REVENUE_STATUSES = array( 'processing', 'completed', 'on-hold' );

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Yazan_Dashboard_Auth::NS,
			'/reports/sales',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'sales' ), 'reports.view' )
		);

		register_rest_route(
			Yazan_Dashboard_Auth::NS,
			'/reports/stock',
			// Stock reporting is an inventory read, not a revenue read — a warehouse role should
			// reach it without also being handed sales figures.
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'stock' ), 'inventory.view' )
		);
	}

	/**
	 * GET /reports/sales — totals, daily series and top sellers for a date range.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function sales( WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'days' ) );
		$days = $days > 0 ? min( 365, $days ) : 30;

		$from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );

		if ( ! $from || ! $to ) {
			$to   = gmdate( 'Y-m-d' );
			$from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		}

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'type'         => 'shop_order',
				'status'       => self::REVENUE_STATUSES,
				'date_created' => $from . '...' . $to,
			)
		);

		$gross    = 0.0;
		$refunded = 0.0;
		$items    = 0;
		$series   = array();
		$sellers  = array();

		// Seed every day in the range so the chart has no gaps.
		for ( $d = strtotime( $from ); $d <= strtotime( $to ); $d += DAY_IN_SECONDS ) {
			$series[ gmdate( 'Y-m-d', $d ) ] = array( 'date' => gmdate( 'Y-m-d', $d ), 'total' => 0.0, 'orders' => 0 );
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$total     = (float) $order->get_total();
			$refund    = (float) $order->get_total_refunded();
			$gross    += $total;
			$refunded += $refund;
			$items    += $order->get_item_count();

			$day = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
			if ( isset( $series[ $day ] ) ) {
				$series[ $day ]['total'] += ( $total - $refund );
				++$series[ $day ]['orders'];
			}

			foreach ( $order->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! $pid ) {
					continue;
				}
				if ( ! isset( $sellers[ $pid ] ) ) {
					$product          = $item->get_product();
					$image_id         = $product instanceof WC_Product ? $product->get_image_id() : 0;
					$sellers[ $pid ] = array(
						'product_id' => $pid,
						'name'       => $item->get_name(),
						'thumb'      => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
						'qty'        => 0,
						'revenue'    => 0.0,
					);
				}
				$sellers[ $pid ]['qty']     += (int) $item->get_quantity();
				$sellers[ $pid ]['revenue'] += (float) $item->get_total();
			}
		}

		usort(
			$sellers,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		$order_count = count( $orders );
		$net         = $gross - $refunded;
		$decimals    = wc_get_price_decimals();

		return new WP_REST_Response(
			array(
				'range'    => array( 'from' => $from, 'to' => $to ),
				'totals'   => array(
					'net_revenue'   => wc_format_decimal( $net, $decimals ),
					'gross_revenue' => wc_format_decimal( $gross, $decimals ),
					'refunded'      => wc_format_decimal( $refunded, $decimals ),
					'orders'        => $order_count,
					'items_sold'    => $items,
					'average_order' => wc_format_decimal( $order_count ? $net / $order_count : 0, $decimals ),
				),
				'series'   => array_values( $series ),
				'top'      => array_slice( array_map(
					static function ( $s ) use ( $decimals ) {
						$s['revenue'] = wc_format_decimal( $s['revenue'], $decimals );
						return $s;
					},
					$sellers
				), 0, 10 ),
				'currency' => array(
					'symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
					'decimals' => $decimals,
				),
				'statuses_counted' => self::REVENUE_STATUSES,
			),
			200
		);
	}

	/**
	 * GET /reports/stock — out of stock, low stock, and most-stocked.
	 *
	 * @return WP_REST_Response
	 */
	public static function stock() {
		$low_threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		$out = wc_get_products(
			array( 'limit' => 50, 'status' => 'publish', 'stock_status' => 'outofstock', 'return' => 'objects' )
		);

		// Low stock: managed products at or below the store threshold but not yet zero.
		$managed = wc_get_products(
			array( 'limit' => -1, 'status' => 'publish', 'stock_status' => 'instock', 'return' => 'objects' )
		);
		$low = array();
		foreach ( $managed as $product ) {
			if ( ! $product->get_manage_stock() ) {
				continue;
			}
			$qty = (int) $product->get_stock_quantity();
			if ( $qty > 0 && $qty <= $low_threshold ) {
				$low[] = self::stock_row( $product );
			}
		}

		return new WP_REST_Response(
			array(
				'low_threshold' => $low_threshold,
				'out_of_stock'  => array_map( array( __CLASS__, 'stock_row' ), $out ),
				'low_stock'     => $low,
			),
			200
		);
	}

	/**
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function stock_row( WC_Product $product ) {
		return array(
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'sku'      => $product->get_sku(),
			'qty'      => $product->get_stock_quantity(),
			'managed'  => $product->get_manage_stock(),
			'status'   => $product->get_stock_status(),
		);
	}
}
