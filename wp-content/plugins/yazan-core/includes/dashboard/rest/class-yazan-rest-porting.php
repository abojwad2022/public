<?php
/**
 * Yazan Dashboard — product CSV export and import.
 *
 * Export streams the catalogue (including the Yazan jewelry/authenticity columns) as CSV.
 *
 * Import is the single most destructive operation in the dashboard: a bad file can rewrite the whole
 * catalogue. It is therefore built as TWO steps that cannot be collapsed:
 *
 *   1. `dry_run: true`  — parses, validates and reports exactly what WOULD change. Writes nothing.
 *   2. `dry_run: false` — performs the same work for real.
 *
 * Rows are matched by `id` first, then `sku`. A row that matches nothing is created only when
 * `create_missing` is explicitly set, so a typo'd SKU column can never silently spawn a catalogue of
 * duplicates. Serial numbers are honoured but never allowed to collide with an existing ring.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /porting endpoints.
 */
class Yazan_REST_Porting {

	/** Import/export both touch the whole catalogue. */
	const CAP = 'manage_woocommerce';

	/** Hard ceiling on rows per import request. */
	const MAX_ROWS = 500;

	/**
	 * Columns understood on import and produced on export.
	 * key => WC_Product setter-ish handler resolved in apply_row().
	 */
	const COLUMNS = array(
		'id', 'sku', 'name', 'status', 'type', 'description', 'short_description',
		'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'manage_stock',
		'weight', 'length', 'width', 'height', 'categories', 'tags', 'menu_order',
		// Yazan-specific
		'serial', 'verification_status', 'silver_weight', 'craftsmanship',
	);

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/porting/export',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'export' ), 'porting.export' )
		);

		register_rest_route(
			$ns,
			'/porting/import',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'import' ), 'porting.import' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Export                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /porting/export — the catalogue as CSV text.
	 *
	 * Returned as a JSON payload containing the CSV rather than a file download, so the SPA can
	 * trigger the download itself while still sending its auth nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( 'orders' === $type ) {
			return self::export_orders( $request );
		}
		if ( 'customers' === $type ) {
			return self::export_customers();
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$args   = array(
			'limit'  => -1,
			'return' => 'objects',
			'status' => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true )
				? $status
				: array( 'publish', 'draft', 'pending', 'private' ),
		);

		$rows = array();
		foreach ( wc_get_products( $args ) as $product ) {
			$rows[] = self::export_row( $product );
		}

		// Build the CSV in memory — php://temp spills to disk only if it grows large.
		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, self::COLUMNS );
		foreach ( $rows as $row ) {
			$ordered = array();
			foreach ( self::COLUMNS as $col ) {
				$ordered[] = $row[ $col ] ?? '';
			}
			fputcsv( $handle, $ordered );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		Yazan_Dashboard_Audit::log( 'porting.export', 'product', 0, array( 'rows' => count( $rows ) ) );

		return new WP_REST_Response(
			array(
				'filename' => 'yazan-products-' . gmdate( 'Y-m-d' ) . '.csv',
				'rows'     => count( $rows ),
				'csv'      => $csv,
			),
			200
		);
	}

	/**
	 * One product as an export row.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function export_row( WC_Product $p ) {
		$cats = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$tags = wc_get_product_terms( $p->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		return array(
			'id'                  => $p->get_id(),
			'sku'                 => $p->get_sku(),
			'name'                => $p->get_name(),
			'status'              => $p->get_status(),
			'type'                => $p->get_type(),
			'description'         => $p->get_description(),
			'short_description'   => $p->get_short_description(),
			'regular_price'       => $p->get_regular_price(),
			'sale_price'          => $p->get_sale_price(),
			'stock_status'        => $p->get_stock_status(),
			'stock_quantity'      => $p->get_stock_quantity(),
			'manage_stock'        => $p->get_manage_stock() ? 'yes' : 'no',
			'weight'              => $p->get_weight(),
			'length'              => $p->get_length(),
			'width'               => $p->get_width(),
			'height'              => $p->get_height(),
			'categories'          => is_wp_error( $cats ) ? '' : implode( '|', $cats ),
			'tags'                => is_wp_error( $tags ) ? '' : implode( '|', $tags ),
			'menu_order'          => $p->get_menu_order(),
			'serial'              => (string) $p->get_meta( Yazan_Core_Verify::SERIAL_META ),
			'verification_status' => (string) $p->get_meta( Yazan_Dashboard_Fields::META_STATUS ),
			'silver_weight'       => (string) $p->get_meta( Yazan_Dashboard_Fields::META_SILVER_WEIGHT ),
			'craftsmanship'       => (string) $p->get_meta( Yazan_Dashboard_Fields::META_CRAFTSMANSHIP ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Import                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /porting/import — validate (dry run) or apply a CSV.
	 *
	 * Body: { csv: "...", dry_run: true|false, create_missing: bool }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import( WP_REST_Request $request ) {
		$csv = (string) $request->get_param( 'csv' );
		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'yazan_invalid', __( 'No CSV content was supplied.', 'yazan' ), array( 'status' => 400 ) );
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( 'customers' === $type ) {
			return self::import_customers( $request, $csv );
		}
		if ( 'orders' === $type ) {
			/*
			 * Importing orders would fabricate financial records: payments, refunds and gateway
			 * transaction ids cannot be meaningfully re-created from a spreadsheet, and a bad file
			 * would corrupt the store's revenue history. Export is supported; import is not.
			 */
			return new WP_Error(
				'yazan_unsupported',
				__( 'Orders can be exported but not imported — importing would fabricate financial records. Create orders from the Orders screen instead.', 'yazan' ),
				array( 'status' => 400 )
			);
		}

		$parsed = self::parse( $csv );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		list( $header, $rows ) = $parsed;

		if ( count( $rows ) > self::MAX_ROWS ) {
			return new WP_Error(
				'yazan_too_many',
				sprintf(
					/* translators: 1: row count, 2: maximum. */
					__( 'That file has %1$d rows; please import at most %2$d at a time.', 'yazan' ),
					count( $rows ),
					self::MAX_ROWS
				),
				array( 'status' => 400 )
			);
		}

		$dry_run        = null === $request->get_param( 'dry_run' ) ? true : wc_string_to_bool( $request->get_param( 'dry_run' ) );
		$create_missing = wc_string_to_bool( $request->get_param( 'create_missing' ) );

		$plan = array( 'update' => array(), 'create' => array(), 'skip' => array() );

		foreach ( $rows as $index => $row ) {
			$line    = $index + 2; // +1 for zero-index, +1 for the header line.
			$product = self::match( $row );

			if ( $product ) {
				$plan['update'][] = array( 'line' => $line, 'id' => $product->get_id(), 'name' => $product->get_name(), 'changes' => self::diff( $product, $row ) );
				continue;
			}

			if ( ! $create_missing ) {
				$plan['skip'][] = array( 'line' => $line, 'reason' => 'no_match', 'sku' => $row['sku'] ?? '', 'name' => $row['name'] ?? '' );
				continue;
			}
			if ( empty( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
				$plan['skip'][] = array( 'line' => $line, 'reason' => 'missing_name' );
				continue;
			}
			if ( ! empty( $row['sku'] ) && wc_get_product_id_by_sku( $row['sku'] ) ) {
				$plan['skip'][] = array( 'line' => $line, 'reason' => 'duplicate_sku', 'sku' => $row['sku'] );
				continue;
			}
			$plan['create'][] = array( 'line' => $line, 'name' => $row['name'], 'sku' => $row['sku'] ?? '' );
		}

		// Serial collisions would break /verify-ring/, so they are refused in both modes.
		$serial_errors = self::serial_conflicts( $rows );
		if ( $serial_errors ) {
			return new WP_Error(
				'yazan_serial_conflict',
				sprintf(
					/* translators: %s: comma-separated serials. */
					__( 'These serial numbers already belong to a different product: %s', 'yazan' ),
					implode( ', ', $serial_errors )
				),
				array( 'status' => 400 )
			);
		}

		if ( $dry_run ) {
			return new WP_REST_Response(
				array(
					'dry_run'  => true,
					'header'   => $header,
					'rows'     => count( $rows ),
					'will_update' => count( $plan['update'] ),
					'will_create' => count( $plan['create'] ),
					'will_skip'   => count( $plan['skip'] ),
					'plan'     => $plan,
				),
				200
			);
		}

		/* --- apply ------------------------------------------------------ */
		$updated = 0;
		$created = 0;

		foreach ( $rows as $index => $row ) {
			$product = self::match( $row );
			if ( ! $product ) {
				if ( ! $create_missing || empty( trim( (string) ( $row['name'] ?? '' ) ) ) ) {
					continue;
				}
				if ( ! empty( $row['sku'] ) && wc_get_product_id_by_sku( $row['sku'] ) ) {
					continue;
				}
				$type    = ! empty( $row['type'] ) && in_array( $row['type'], array_keys( wc_get_product_types() ), true ) ? $row['type'] : 'simple';
				$product = wc_get_product_object( $type );
				++$created;
			} else {
				++$updated;
			}

			self::apply_row( $product, $row );
			$product->save();
		}

		Yazan_Dashboard_Audit::log( 'porting.import', 'product', 0, array( 'updated' => $updated, 'created' => $created, 'rows' => count( $rows ) ) );

		return new WP_REST_Response(
			array( 'dry_run' => false, 'updated' => $updated, 'created' => $created, 'skipped' => count( $plan['skip'] ) ),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Import helpers                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Parse CSV text into a header + associative rows.
	 *
	 * @param string $csv Raw CSV.
	 * @return array|WP_Error
	 */
	private static function parse( $csv ) {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'yazan_invalid', __( 'That file has no header row.', 'yazan' ), array( 'status' => 400 ) );
		}
		// Strip a UTF-8 BOM from the first column name, which Excel adds.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header );

		$known = array_intersect( $header, self::COLUMNS );
		if ( empty( $known ) ) {
			fclose( $handle );
			return new WP_Error(
				'yazan_invalid',
				sprintf(
					/* translators: %s: comma-separated column names. */
					__( 'No recognised columns. Expected some of: %s', 'yazan' ),
					implode( ', ', self::COLUMNS )
				),
				array( 'status' => 400 )
			);
		}

		$rows = array();
		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			if ( null === $line || ( 1 === count( $line ) && null === $line[0] ) ) {
				continue; // blank line
			}
			$row = array();
			foreach ( $header as $i => $col ) {
				if ( in_array( $col, self::COLUMNS, true ) ) {
					$row[ $col ] = isset( $line[ $i ] ) ? trim( (string) $line[ $i ] ) : '';
				}
			}
			if ( array_filter( $row, static fn( $v ) => '' !== $v ) ) {
				$rows[] = $row;
			}
		}
		fclose( $handle );

		return array( $header, $rows );
	}

	/**
	 * Match a row to an existing product by id, then SKU.
	 *
	 * @param array $row Row.
	 * @return WC_Product|null
	 */
	private static function match( array $row ) {
		if ( ! empty( $row['id'] ) ) {
			$product = wc_get_product( absint( $row['id'] ) );
			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}
		if ( ! empty( $row['sku'] ) ) {
			$id = wc_get_product_id_by_sku( sanitize_text_field( $row['sku'] ) );
			if ( $id ) {
				return wc_get_product( $id );
			}
		}
		return null;
	}

	/**
	 * Serials in the file that already belong to a DIFFERENT product.
	 *
	 * @param array $rows Rows.
	 * @return string[]
	 */
	private static function serial_conflicts( array $rows ) {
		$bad = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['serial'] ) ) {
				continue;
			}
			$serial = Yazan_Core_Verify::clean_serial( $row['serial'] );
			if ( '' === $serial ) {
				continue;
			}
			$existing = yazan_verify_lookup( $serial );
			if ( ! $existing ) {
				continue;
			}
			$target = self::match( $row );
			if ( ! $target || (int) $existing['product_id'] !== $target->get_id() ) {
				$bad[] = $serial;
			}
		}
		return array_unique( $bad );
	}

	/**
	 * Human-readable list of what a row would change on a product.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row.
	 * @return string[]
	 */
	private static function diff( WC_Product $product, array $row ) {
		$changes = array();
		$compare = array(
			'name'          => $product->get_name(),
			'sku'           => $product->get_sku(),
			'status'        => $product->get_status(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'stock_status'  => $product->get_stock_status(),
			'serial'        => (string) $product->get_meta( Yazan_Core_Verify::SERIAL_META ),
		);
		foreach ( $compare as $key => $current ) {
			if ( array_key_exists( $key, $row ) && (string) $row[ $key ] !== (string) $current ) {
				$changes[] = sprintf( '%s: %s → %s', $key, $current === '' ? '—' : $current, $row[ $key ] === '' ? '—' : $row[ $key ] );
			}
		}
		return $changes;
	}

	/**
	 * Write a row's values onto a product (pre-save). Absent columns are left untouched.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row.
	 * @return void
	 */
	private static function apply_row( WC_Product $product, array $row ) {
		$has = static fn( $k ) => array_key_exists( $k, $row ) && '' !== $row[ $k ];

		if ( $has( 'name' ) ) {
			$product->set_name( sanitize_text_field( $row['name'] ) );
		}
		if ( array_key_exists( 'sku', $row ) ) {
			$sku    = sanitize_text_field( $row['sku'] );
			$sku_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
			if ( ! $sku || ! $sku_id || (int) $sku_id === $product->get_id() ) {
				$product->set_sku( $sku );
			}
		}
		if ( $has( 'status' ) && in_array( $row['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$product->set_status( $row['status'] );
		}
		if ( array_key_exists( 'description', $row ) ) {
			$product->set_description( wp_kses_post( $row['description'] ) );
		}
		if ( array_key_exists( 'short_description', $row ) ) {
			$product->set_short_description( wp_kses_post( $row['short_description'] ) );
		}
		if ( $has( 'regular_price' ) ) {
			$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
		}
		if ( array_key_exists( 'sale_price', $row ) ) {
			$product->set_sale_price( '' === $row['sale_price'] ? '' : wc_format_decimal( $row['sale_price'] ) );
		}
		if ( $has( 'manage_stock' ) ) {
			$product->set_manage_stock( wc_string_to_bool( $row['manage_stock'] ) );
		}
		if ( $product->get_manage_stock() && $has( 'stock_quantity' ) ) {
			$product->set_stock_quantity( wc_stock_amount( $row['stock_quantity'] ) );
		}
		if ( $has( 'stock_status' ) && in_array( $row['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			if ( $product->get_manage_stock() && ! $has( 'stock_quantity' ) ) {
				if ( 'outofstock' === $row['stock_status'] ) {
					$product->set_stock_quantity( 0 );
				} elseif ( 'instock' === $row['stock_status'] && (int) $product->get_stock_quantity() <= 0 ) {
					$product->set_stock_quantity( 1 );
				}
			}
			$product->set_stock_status( $row['stock_status'] );
		}
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $dim ) {
			if ( array_key_exists( $dim, $row ) ) {
				$product->{"set_$dim"}( '' === $row[ $dim ] ? '' : wc_format_decimal( $row[ $dim ] ) );
			}
		}
		if ( $has( 'menu_order' ) ) {
			$product->set_menu_order( (int) $row['menu_order'] );
		}
		if ( $has( 'categories' ) ) {
			$product->set_category_ids( self::term_ids( $row['categories'], 'product_cat' ) );
		}
		if ( $has( 'tags' ) ) {
			$product->set_tag_ids( self::term_ids( $row['tags'], 'product_tag' ) );
		}

		// Yazan fields.
		if ( array_key_exists( 'serial', $row ) ) {
			$serial = Yazan_Core_Verify::clean_serial( $row['serial'] );
			if ( '' === $serial ) {
				$product->delete_meta_data( Yazan_Core_Verify::SERIAL_META );
			} else {
				$product->update_meta_data( Yazan_Core_Verify::SERIAL_META, $serial );
			}
		}
		if ( $has( 'verification_status' ) && in_array( $row['verification_status'], Yazan_Dashboard_Fields::STATUSES, true ) ) {
			$product->update_meta_data( Yazan_Dashboard_Fields::META_STATUS, $row['verification_status'] );
		}
		if ( array_key_exists( 'silver_weight', $row ) ) {
			$product->update_meta_data( Yazan_Dashboard_Fields::META_SILVER_WEIGHT, sanitize_text_field( $row['silver_weight'] ) );
		}
		if ( array_key_exists( 'craftsmanship', $row ) ) {
			$product->update_meta_data( Yazan_Dashboard_Fields::META_CRAFTSMANSHIP, sanitize_textarea_field( $row['craftsmanship'] ) );
		}
	}

	/**
	 * Resolve "A|B|C" term names to ids, creating any that are missing.
	 *
	 * @param string $value    Pipe-separated names.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function term_ids( $value, $taxonomy ) {
		$ids = array();
		foreach ( explode( '|', (string) $value ) as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $created ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}
		return $ids;
	}

	/* --------------------------------------------------------------------- */
	/* Orders + customers                                                     */
	/* --------------------------------------------------------------------- */

	/** Columns produced by the order export. */
	const ORDER_COLUMNS = array(
		'order_id', 'order_number', 'status', 'date_created', 'date_paid', 'currency',
		'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'total', 'refunded',
		'payment_method', 'customer_id', 'billing_name', 'billing_email', 'billing_phone',
		'billing_address', 'billing_city', 'billing_country',
		'shipping_address', 'shipping_city', 'shipping_country',
		'items', 'serials', 'coupons', 'customer_note',
	);

	/** Columns for the customer export/import. */
	const CUSTOMER_COLUMNS = array(
		'user_id', 'email', 'first_name', 'last_name', 'username', 'role', 'registered',
		'billing_phone', 'billing_address', 'billing_city', 'billing_postcode', 'billing_country',
		'order_count', 'total_spent',
	);

	/**
	 * GET /porting/export?type=orders
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private static function export_orders( WP_REST_Request $request ) {
		$args = array( 'limit' => -1, 'type' => 'shop_order', 'status' => 'any', 'orderby' => 'date', 'order' => 'DESC' );

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $status && in_array( 'wc-' . $status, array_keys( wc_get_order_statuses() ), true ) ) {
			$args['status'] = $status;
		}
		$from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		if ( $from && $to ) {
			$args['date_created'] = $from . '...' . $to;
		}

		$rows = array();
		foreach ( wc_get_orders( $args ) as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$items   = array();
			$serials = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = sprintf( '%s x%d', $item->get_name(), $item->get_quantity() );
				$product = $item->get_product();
				if ( $product instanceof WC_Product ) {
					$serial = (string) $product->get_meta( Yazan_Core_Verify::SERIAL_META );
					if ( $serial ) {
						$serials[] = $serial;
					}
				}
			}
			$coupons = array();
			foreach ( $order->get_items( 'coupon' ) as $coupon ) {
				$coupons[] = $coupon->get_code();
			}

			$rows[] = array(
				'order_id'         => $order->get_id(),
				'order_number'     => $order->get_order_number(),
				'status'           => $order->get_status(),
				'date_created'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
				'date_paid'        => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i' ) : '',
				'currency'         => $order->get_currency(),
				'subtotal'         => wc_format_decimal( $order->get_subtotal(), 2 ),
				'discount_total'   => wc_format_decimal( $order->get_discount_total(), 2 ),
				'shipping_total'   => wc_format_decimal( $order->get_shipping_total(), 2 ),
				'tax_total'        => wc_format_decimal( $order->get_total_tax(), 2 ),
				'total'            => wc_format_decimal( $order->get_total(), 2 ),
				'refunded'         => wc_format_decimal( $order->get_total_refunded(), 2 ),
				'payment_method'   => $order->get_payment_method_title(),
				'customer_id'      => $order->get_customer_id(),
				'billing_name'     => $order->get_formatted_billing_full_name(),
				'billing_email'    => $order->get_billing_email(),
				'billing_phone'    => $order->get_billing_phone(),
				'billing_address'  => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				'billing_city'     => $order->get_billing_city(),
				'billing_country'  => $order->get_billing_country(),
				'shipping_address' => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ),
				'shipping_city'    => $order->get_shipping_city(),
				'shipping_country' => $order->get_shipping_country(),
				'items'            => implode( ' | ', $items ),
				'serials'          => implode( ' | ', $serials ),
				'coupons'          => implode( ' | ', $coupons ),
				'customer_note'    => $order->get_customer_note(),
			);
		}

		Yazan_Dashboard_Audit::log( 'porting.export_orders', 'order', 0, array( 'rows' => count( $rows ) ) );

		return self::csv_response( 'yazan-orders', self::ORDER_COLUMNS, $rows );
	}

	/**
	 * GET /porting/export?type=customers
	 *
	 * @return WP_REST_Response
	 */
	private static function export_customers() {
		$rows = array();

		foreach ( get_users( array( 'number' => -1, 'orderby' => 'registered', 'order' => 'DESC' ) ) as $user ) {
			$customer = new WC_Customer( $user->ID );
			$rows[]   = array(
				'user_id'          => $user->ID,
				'email'            => $user->user_email,
				'first_name'       => $customer->get_billing_first_name() ? $customer->get_billing_first_name() : $user->first_name,
				'last_name'        => $customer->get_billing_last_name() ? $customer->get_billing_last_name() : $user->last_name,
				'username'         => $user->user_login,
				'role'             => implode( '|', (array) $user->roles ),
				'registered'       => $user->user_registered ? gmdate( 'Y-m-d', strtotime( $user->user_registered ) ) : '',
				'billing_phone'    => $customer->get_billing_phone(),
				'billing_address'  => trim( $customer->get_billing_address_1() . ' ' . $customer->get_billing_address_2() ),
				'billing_city'     => $customer->get_billing_city(),
				'billing_postcode' => $customer->get_billing_postcode(),
				'billing_country'  => $customer->get_billing_country(),
				'order_count'      => wc_get_customer_order_count( $user->ID ),
				'total_spent'      => wc_format_decimal( wc_get_customer_total_spent( $user->ID ), 2 ),
			);
		}

		Yazan_Dashboard_Audit::log( 'porting.export_customers', 'settings', 0, array( 'rows' => count( $rows ) ) );

		return self::csv_response( 'yazan-customers', self::CUSTOMER_COLUMNS, $rows );
	}

	/**
	 * POST /porting/import?type=customers — create/update customer accounts.
	 *
	 * Two safety rules that are not negotiable:
	 *  - Passwords are never taken from a CSV. New accounts get a random one and the person uses
	 *    "forgot password", which is the only safe way to hand out an account by file.
	 *  - The role is pinned to `customer`, so an import can never escalate anyone to administrator
	 *    even if the file contains a `role` column saying so.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $csv     Raw CSV.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function import_customers( WP_REST_Request $request, $csv ) {
		$rows = self::parse_with( $csv, self::CUSTOMER_COLUMNS );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			return new WP_Error( 'yazan_too_many', __( 'Please import at most 500 customers at a time.', 'yazan' ), array( 'status' => 400 ) );
		}

		$dry_run = null === $request->get_param( 'dry_run' ) ? true : wc_string_to_bool( $request->get_param( 'dry_run' ) );
		$plan    = array( 'update' => array(), 'create' => array(), 'skip' => array() );

		foreach ( $rows as $index => $row ) {
			$line  = $index + 2;
			$email = sanitize_email( (string) ( $row['email'] ?? '' ) );

			if ( ! is_email( $email ) ) {
				$plan['skip'][] = array( 'line' => $line, 'reason' => 'invalid_email', 'name' => (string) ( $row['email'] ?? '' ) );
				continue;
			}
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$plan['update'][] = array( 'line' => $line, 'id' => $existing->ID, 'name' => $existing->display_name, 'changes' => array( 'billing details' ) );
			} else {
				$name             = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
				$plan['create'][] = array( 'line' => $line, 'name' => '' !== $name ? $name : $email, 'sku' => $email );
			}
		}

		if ( $dry_run ) {
			return new WP_REST_Response(
				array(
					'dry_run'     => true,
					'rows'        => count( $rows ),
					'will_update' => count( $plan['update'] ),
					'will_create' => count( $plan['create'] ),
					'will_skip'   => count( $plan['skip'] ),
					'plan'        => $plan,
				),
				200
			);
		}

		$updated = 0;
		$created = 0;

		foreach ( $rows as $row ) {
			$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				continue;
			}

			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				$login = sanitize_user( (string) ( $row['username'] ?? '' ), true );
				if ( '' === $login || username_exists( $login ) ) {
					$login = self::unique_login( $email );
				}
				$user_id = wp_insert_user(
					array(
						'user_login' => $login,
						'user_email' => $email,
						'user_pass'  => wp_generate_password( 24, true ),
						'first_name' => sanitize_text_field( (string) ( $row['first_name'] ?? '' ) ),
						'last_name'  => sanitize_text_field( (string) ( $row['last_name'] ?? '' ) ),
						'role'       => 'customer',
					)
				);
				if ( is_wp_error( $user_id ) ) {
					continue;
				}
				$user = get_user_by( 'id', $user_id );
				++$created;
			} else {
				++$updated;
			}

			$customer = new WC_Customer( $user->ID );
			$map      = array(
				'billing_phone'    => 'set_billing_phone',
				'billing_address'  => 'set_billing_address_1',
				'billing_city'     => 'set_billing_city',
				'billing_postcode' => 'set_billing_postcode',
				'billing_country'  => 'set_billing_country',
				'first_name'       => 'set_billing_first_name',
				'last_name'        => 'set_billing_last_name',
			);
			foreach ( $map as $col => $setter ) {
				if ( array_key_exists( $col, $row ) && '' !== $row[ $col ] ) {
					$customer->{$setter}( sanitize_text_field( (string) $row[ $col ] ) );
				}
			}
			$customer->set_billing_email( $email );
			$customer->save();
		}

		Yazan_Dashboard_Audit::log( 'porting.import_customers', 'settings', 0, array( 'updated' => $updated, 'created' => $created ) );

		return new WP_REST_Response(
			array( 'dry_run' => false, 'updated' => $updated, 'created' => $created, 'skipped' => count( $plan['skip'] ) ),
			200
		);
	}

	/**
	 * A login derived from an email that is guaranteed not to exist yet.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function unique_login( $email ) {
		$parts = explode( '@', $email );
		$base  = sanitize_user( $parts[0], true );
		if ( '' === $base ) {
			$base = 'customer';
		}
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . $i;
		}
		return $login;
	}

	/**
	 * Parse CSV against an arbitrary column set (used by the customer importer).
	 *
	 * @param string $csv     Raw CSV.
	 * @param array  $columns Recognised columns.
	 * @return array|WP_Error
	 */
	private static function parse_with( $csv, array $columns ) {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'yazan_invalid', __( 'That file has no header row.', 'yazan' ), array( 'status' => 400 ) );
		}
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map(
			static function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			$header
		);

		if ( ! in_array( 'email', $header, true ) ) {
			fclose( $handle );
			return new WP_Error( 'yazan_invalid', __( 'Customer imports need an “email” column.', 'yazan' ), array( 'status' => 400 ) );
		}

		$rows = array();
		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			if ( null === $line || ( 1 === count( $line ) && null === $line[0] ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $i => $col ) {
				if ( in_array( $col, $columns, true ) ) {
					$row[ $col ] = isset( $line[ $i ] ) ? trim( (string) $line[ $i ] ) : '';
				}
			}
			if ( array_filter( $row, static function ( $v ) {
				return '' !== $v;
			} ) ) {
				$rows[] = $row;
			}
		}
		fclose( $handle );

		return $rows;
	}

	/**
	 * Build the standard CSV response envelope.
	 *
	 * @param string $prefix  Filename prefix.
	 * @param array  $columns Column order.
	 * @param array  $rows    Rows.
	 * @return WP_REST_Response
	 */
	private static function csv_response( $prefix, array $columns, array $rows ) {
		$handle = fopen( 'php://temp', 'r+' );
		fputcsv( $handle, $columns );
		foreach ( $rows as $row ) {
			$ordered = array();
			foreach ( $columns as $col ) {
				$ordered[] = $row[ $col ] ?? '';
			}
			fputcsv( $handle, $ordered );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return new WP_REST_Response(
			array(
				'filename' => $prefix . '-' . gmdate( 'Y-m-d' ) . '.csv',
				'rows'     => count( $rows ),
				'csv'      => $csv,
			),
			200
		);
	}
}
