<?php
/**
 * Yazan AI — analytics pipeline.
 *
 * Gathers a compact store snapshot (sales, orders, AOV, best sellers, low stock) over a window using
 * WooCommerce CRUD, then asks the model to turn the raw numbers into clear, specific, actionable
 * observations for the owner. The numbers are computed here — the model only interprets what it is
 * given, so it cannot invent figures. Returns both the metrics (for charts) and the narrative.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store metrics → narrative insights.
 */
class Yazan_AI_Analytics {

	/**
	 * Produce insights for a window.
	 *
	 * @param array $input days (7|30|90…), language.
	 * @return array{ok:bool,metrics:array,suggestion:array,meta:array,error:?array}
	 */
	public static function insights( array $input ) {
		$days    = max( 1, min( 365, absint( $input['days'] ?? 30 ) ) );
		$metrics = self::metrics( $days );

		$lang = Yazan_AI_Product::language( $input['language'] ?? null );
		$dual = ( 'both' === $lang );
		$str  = $dual ? '{ "en": "…", "ar": "…" }' : '"…"';

		$brief = self::render_metrics( $metrics, $days );

		$user = "Here is the YAZAN store performance over the last {$days} days. Analyze it for the owner: sales "
			. "trend, best sellers, weak/underperforming pieces, category performance, customer behavior, and "
			. "inventory health. Base EVERY conclusion on the numbers below and cite them; never invent a metric "
			. "(there is no traffic/conversion data — do not estimate conversion). Keep OBSERVATIONS (insights, "
			. "risks) strictly separate from RECOMMENDATIONS (opportunities, actions), and prioritize the most "
			. "actionable items first.\n\n"
			. $brief . "\n\n"
			. "Return ONLY JSON with these five sections:\n"
			. '{ "summary": ' . $str . ', '
			. '"insights": [ { "title": ' . $str . ', "detail": ' . $str . ' } ], '     // observations: what the data shows
			. '"risks": [ { "title": ' . $str . ', "detail": ' . $str . ' } ], '         // observations: what to watch
			. '"opportunities": [ { "title": ' . $str . ', "detail": ' . $str . ' } ], ' // where the upside is
			. '"actions": [ ' . $str . ' ] }';                                            // prioritized recommendations

		$envelope = Yazan_AI_Gateway::run(
			array(
				'system'   => Yazan_AI_Prompts::system( 'analytics.insights', $lang ),
				'messages' => array( array( 'role' => 'user', 'content' => $user ) ),
				'json'     => true,
				// Headroom for "thinking" models + the larger five-section output.
				'max_tokens' => 3072,
			),
			array(
				'task'       => 'analytics.insights',
				'module'     => 'analytics',
				'capability' => 'text',
			)
		);

		if ( empty( $envelope['ok'] ) ) {
			$err = $envelope['error'] ?? array();
			return array(
				'ok'         => false,
				'metrics'    => $metrics,
				'suggestion' => array(),
				'meta'       => Yazan_AI_Product::meta( $envelope ),
				'error'      => array( 'code' => $err['code'] ?? 'error', 'message' => $err['message'] ?? __( 'AI request failed.', 'yazan' ) ),
			);
		}

		return array(
			'ok'         => true,
			'metrics'    => $metrics,
			'suggestion' => is_array( $envelope['json'] ) ? $envelope['json'] : array(),
			'meta'       => Yazan_AI_Product::meta( $envelope ),
			'error'      => null,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Metrics                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Compute a snapshot over the last N days (paid orders): sales + trend vs the prior window, best
	 * sellers, weak/no-sale products, category performance, customer behavior, and inventory health.
	 * Everything here is REAL data — the model only interprets it, so it can never invent a figure.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function metrics( $days ) {
		$now         = time();
		$after       = gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
		$prev_start  = gmdate( 'Y-m-d H:i:s', $now - ( 2 * $days * DAY_IN_SECONDS ) );
		$statuses    = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );

		$orders = wc_get_orders(
			array( 'limit' => 500, 'status' => $statuses, 'date_created' => '>=' . $after, 'return' => 'objects' )
		);

		$revenue     = 0.0;
		$order_count = 0;
		$by_product  = array();
		$by_category = array();
		$customers   = array();
		$guest       = 0;
		$account     = 0;

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			++$order_count;
			$revenue += (float) $order->get_total();

			// Customer key: user id, else billing email, else the order itself (unique guest).
			$key = $order->get_customer_id()
				? 'u' . $order->get_customer_id()
				: ( $order->get_billing_email() ? 'e' . strtolower( $order->get_billing_email() ) : 'o' . $order->get_id() );
			$customers[ $key ] = ( $customers[ $key ] ?? 0 ) + 1;
			if ( $order->get_customer_id() ) {
				++$account;
			} else {
				++$guest;
			}

			foreach ( $order->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! $pid ) {
					continue;
				}
				$qty = (int) $item->get_quantity();
				$rev = (float) $item->get_total();

				if ( ! isset( $by_product[ $pid ] ) ) {
					$by_product[ $pid ] = array( 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0 );
				}
				$by_product[ $pid ]['qty']     += $qty;
				$by_product[ $pid ]['revenue'] += $rev;

				// Attribute to the product's PRIMARY (first) category so totals never exceed revenue.
				$terms = get_the_terms( $pid, 'product_cat' );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					$t = $terms[0];
					if ( ! isset( $by_category[ $t->term_id ] ) ) {
						$by_category[ $t->term_id ] = array( 'name' => $t->name, 'qty' => 0, 'revenue' => 0.0 );
					}
					$by_category[ $t->term_id ]['qty']     += $qty;
					$by_category[ $t->term_id ]['revenue'] += $rev;
				}
			}
		}

		// Trend vs the immediately preceding window of equal length.
		$prev_revenue = 0.0;
		$prev_count   = 0;
		$prev_orders  = wc_get_orders(
			array( 'limit' => 500, 'status' => $statuses, 'date_created' => $prev_start . '...' . $after, 'return' => 'objects' )
		);
		foreach ( (array) $prev_orders as $o ) {
			if ( $o instanceof WC_Order ) {
				$prev_revenue += (float) $o->get_total();
				++$prev_count;
			}
		}
		$revenue_trend_pct = $prev_revenue > 0 ? round( ( ( $revenue - $prev_revenue ) / $prev_revenue ) * 100, 1 ) : null;

		// Best sellers (by units).
		uasort( $by_product, static function ( $a, $b ) { return $b['qty'] <=> $a['qty']; } );
		$top = array_slice( array_values( $by_product ), 0, 5 );

		// Category performance (by revenue).
		uasort( $by_category, static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		$categories = array();
		foreach ( array_slice( array_values( $by_category ), 0, 6 ) as $c ) {
			$categories[] = array( 'name' => $c['name'], 'revenue' => round( $c['revenue'], 2 ), 'qty' => (int) $c['qty'] );
		}

		// Weak products: published, in-stock, but ZERO units sold this window.
		$sold_ids  = array_map( 'intval', array_keys( $by_product ) );
		$published = wc_get_products( array( 'status' => 'publish', 'stock_status' => 'instock', 'limit' => -1, 'return' => 'ids' ) );
		$weak      = array();
		foreach ( (array) $published as $pid ) {
			if ( in_array( (int) $pid, $sold_ids, true ) ) {
				continue;
			}
			$p = wc_get_product( $pid );
			if ( $p instanceof WC_Product ) {
				$weak[] = $p->get_name();
			}
			if ( count( $weak ) >= 8 ) {
				break;
			}
		}

		// Customer behavior (within the window — labelled as such).
		$customers_total  = count( $customers );
		$repeat_customers = count( array_filter( $customers, static function ( $n ) { return $n > 1; } ) );
		$repeat_rate      = $customers_total ? round( ( $repeat_customers / $customers_total ) * 100, 1 ) : 0.0;

		// Inventory health.
		$low_stock = array();
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$low       = wc_get_products( array( 'limit' => 20, 'stock_status' => 'instock', 'manage_stock' => true, 'return' => 'objects' ) );
		foreach ( (array) $low as $p ) {
			$qty = $p->get_stock_quantity();
			if ( null !== $qty && (int) $qty <= $threshold ) {
				$low_stock[] = array( 'name' => $p->get_name(), 'qty' => (int) $qty );
			}
		}
		$out_of_stock = (int) count( (array) wc_get_products( array( 'status' => 'publish', 'stock_status' => 'outofstock', 'limit' => -1, 'return' => 'ids' ) ) );

		return array(
			'days'              => (int) $days,
			'currency'          => get_woocommerce_currency(),
			'revenue'           => round( $revenue, 2 ),
			'orders'            => $order_count,
			'aov'               => $order_count ? round( $revenue / $order_count, 2 ) : 0.0,
			'prev_revenue'      => round( $prev_revenue, 2 ),
			'prev_orders'       => $prev_count,
			'revenue_trend_pct' => $revenue_trend_pct,
			'top_products'      => $top,
			'weak_products'     => $weak,
			'categories'        => $categories,
			'customers_total'   => $customers_total,
			'repeat_customers'  => $repeat_customers,
			'repeat_rate'       => $repeat_rate,
			'orders_guest'      => $guest,
			'orders_account'    => $account,
			'low_stock'         => $low_stock,
			'out_of_stock'      => $out_of_stock,
		);
	}

	/**
	 * Render the metrics as a compact text brief for the prompt.
	 *
	 * @param array $m    Metrics.
	 * @param int   $days Window.
	 * @return string
	 */
	private static function render_metrics( array $m, $days ) {
		$cur     = $m['currency'];
		$lines   = array();

		// Sales + trend.
		$lines[] = sprintf( 'Revenue (last %d days): %s %s', $days, $cur, number_format( (float) $m['revenue'], 2 ) );
		$lines[] = sprintf( 'Previous %d days: %s %s', $days, $cur, number_format( (float) $m['prev_revenue'], 2 ) );
		$lines[] = null === $m['revenue_trend_pct']
			? 'Revenue trend: no comparable prior-period sales.'
			: sprintf( 'Revenue trend vs previous period: %+.1f%%', (float) $m['revenue_trend_pct'] );
		$lines[] = sprintf( 'Orders: %d (previous: %d)', (int) $m['orders'], (int) $m['prev_orders'] );
		$lines[] = sprintf( 'Average order value: %s %s', $cur, number_format( (float) $m['aov'], 2 ) );

		// Customer behavior (within window).
		$lines[] = sprintf(
			'Customers this window: %d (repeat buyers: %d = %.1f%%; orders from accounts: %d, guest: %d)',
			(int) $m['customers_total'], (int) $m['repeat_customers'], (float) $m['repeat_rate'], (int) $m['orders_account'], (int) $m['orders_guest']
		);

		// Best sellers.
		if ( ! empty( $m['top_products'] ) ) {
			$lines[] = 'Best sellers (by units):';
			foreach ( $m['top_products'] as $p ) {
				$lines[] = sprintf( '  - %s — %d sold, %s %s', wp_strip_all_tags( $p['name'] ), (int) $p['qty'], $cur, number_format( (float) $p['revenue'], 2 ) );
			}
		} else {
			$lines[] = 'Best sellers: no paid orders in this window.';
		}

		// Category performance.
		if ( ! empty( $m['categories'] ) ) {
			$lines[] = 'Category performance (by revenue, primary category):';
			foreach ( $m['categories'] as $c ) {
				$lines[] = sprintf( '  - %s — %s %s, %d units', wp_strip_all_tags( $c['name'] ), $cur, number_format( (float) $c['revenue'], 2 ), (int) $c['qty'] );
			}
		}

		// Weak products.
		if ( ! empty( $m['weak_products'] ) ) {
			$lines[] = 'In-stock products with ZERO sales this window: ' . implode( ', ', array_map( 'wp_strip_all_tags', $m['weak_products'] ) );
		}

		// Inventory health.
		$lines[] = sprintf( 'Inventory: %d product(s) out of stock.', (int) $m['out_of_stock'] );
		if ( ! empty( $m['low_stock'] ) ) {
			$lines[] = 'Low stock:';
			foreach ( $m['low_stock'] as $p ) {
				$lines[] = sprintf( '  - %s — %d left', wp_strip_all_tags( $p['name'] ), (int) $p['qty'] );
			}
		}

		// Honest limitation so the model does not fabricate a conversion rate.
		$lines[] = 'NOTE: Traffic/visit and conversion-rate data are NOT available (no web-analytics integration). Do not estimate a conversion rate; comment only on order/revenue/AOV trends.';

		return implode( "\n", $lines );
	}
}
