<?php
/**
 * Yazan Dashboard — Products REST controller (yazan/v1).
 *
 * The heart of the dashboard: list / read / create / update / delete / bulk over WooCommerce products.
 * ALL product access goes through WooCommerce CRUD (wc_get_product / WC_Product::set_* / save) so it is
 * HPOS-safe and writes exactly what core would — a price/stock/serial change here is instantly live on
 * /store/ and /verify-ring/. Yazan jewelry/authenticity fields are delegated to Yazan_Dashboard_Fields.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /products endpoints.
 */
class Yazan_REST_Products {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/products/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bulk' ),
				'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate' ),
				'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/quick-edit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'quick_edit' ),
				'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'edit_products' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'destroy' ),
					'permission_callback' => Yazan_Dashboard_Auth::require_cap( 'delete_products' ),
				),
			)
		);

	}

	/* --------------------------------------------------------------------- */
	/* List                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /products — paginated, filterable, sortable list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$allowed_orderby = array( 'date', 'title', 'menu_order', 'price', 'sku', 'modified', 'id' );
		$orderby         = in_array( $request->get_param( 'orderby' ), $allowed_orderby, true ) ? $request->get_param( 'orderby' ) : 'date';
		$order           = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';

		$args = array(
			'status'   => array( 'publish', 'draft', 'pending', 'private' ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => $orderby,
			'order'    => $order,
			'return'   => 'objects',
		);

		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $status && in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$args['status'] = $status;
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( $type && in_array( $type, array_keys( wc_get_product_types() ), true ) ) {
			$args['type'] = $type;
		}

		$stock = sanitize_key( (string) $request->get_param( 'stock_status' ) );
		if ( $stock && in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$args['stock_status'] = $stock;
		}

		$category = sanitize_title( (string) $request->get_param( 'category' ) );
		if ( '' !== $category ) {
			$args['category'] = array( $category ); // wc_get_products expects an array of category slugs.
		}

		$result = wc_get_products( $args );
		$items  = array();
		foreach ( $result->products as $product ) {
			$items[] = self::summary( $product );
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
	 * GET /products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['id'] ) );
		if ( ! $product instanceof WC_Product ) {
			return self::not_found();
		}
		return new WP_REST_Response( self::detail( $product ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Create / Update                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /products.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! $type || ! in_array( $type, array_keys( wc_get_product_types() ), true ) ) {
			$type = 'simple';
		}

		$product = wc_get_product_object( $type );
		$error   = self::apply( $product, $request );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$product->save();
		self::after_save( $product, $request );

		Yazan_Dashboard_Audit::log( 'product.create', 'product', $product->get_id(), array( 'name' => $product->get_name() ) );

		return new WP_REST_Response( self::detail( wc_get_product( $product->get_id() ) ), 201 );
	}

	/**
	 * PUT/PATCH /products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$existing = wc_get_product( $id );
		if ( ! $existing instanceof WC_Product ) {
			return self::not_found();
		}

		// Support product-type switching: reload as the new class when it changed.
		$new_type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( $new_type && $new_type !== $existing->get_type() && in_array( $new_type, array_keys( wc_get_product_types() ), true ) ) {
			$product = wc_get_product_object( $new_type, $id );
		} else {
			$product = $existing;
		}

		$error = self::apply( $product, $request );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$product->save();
		self::after_save( $product, $request );

		Yazan_Dashboard_Audit::log( 'product.update', 'product', $id, array( 'name' => $product->get_name() ) );

		return new WP_REST_Response( self::detail( wc_get_product( $id ) ), 200 );
	}

	/**
	 * Apply a sanitized payload onto a product object (pre-save). Returns WP_Error on validation fail.
	 *
	 * @param WC_Product      $product Product (new or loaded).
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function apply( WC_Product $product, WP_REST_Request $request ) {
		$has = static function ( $key ) use ( $request ) {
			return null !== $request->get_param( $key );
		};

		/* --- General --------------------------------------------------- */
		if ( $has( 'name' ) ) {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error( 'yazan_invalid', __( 'Product title is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			$product->set_name( $name );
		}
		if ( $has( 'status' ) ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			$product->set_status( in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $status : 'draft' );
		}
		if ( $has( 'featured' ) ) {
			$product->set_featured( wc_string_to_bool( $request->get_param( 'featured' ) ) );
		}
		if ( $has( 'catalog_visibility' ) ) {
			$vis = sanitize_key( (string) $request->get_param( 'catalog_visibility' ) );
			$product->set_catalog_visibility( in_array( $vis, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ? $vis : 'visible' );
		}
		if ( $has( 'description' ) ) {
			$product->set_description( wp_kses_post( (string) $request->get_param( 'description' ) ) );
		}
		if ( $has( 'short_description' ) ) {
			$product->set_short_description( wp_kses_post( (string) $request->get_param( 'short_description' ) ) );
		}
		if ( $has( 'sku' ) ) {
			$sku    = sanitize_text_field( (string) $request->get_param( 'sku' ) );
			$sku_id = wc_get_product_id_by_sku( $sku );
			if ( $sku && $sku_id && $sku_id !== $product->get_id() ) {
				return new WP_Error( 'yazan_sku', __( 'That SKU is already in use.', 'yazan' ), array( 'status' => 400 ) );
			}
			$product->set_sku( $sku );
		}

		/* --- Type flags ------------------------------------------------ */
		if ( $has( 'virtual' ) ) {
			$product->set_virtual( wc_string_to_bool( $request->get_param( 'virtual' ) ) );
		}
		if ( $has( 'downloadable' ) ) {
			$product->set_downloadable( wc_string_to_bool( $request->get_param( 'downloadable' ) ) );
		}

		/* --- Linked products ------------------------------------------- */
		if ( $has( 'upsell_ids' ) ) {
			$product->set_upsell_ids( self::id_list( $request->get_param( 'upsell_ids' ) ) );
		}
		if ( $has( 'cross_sell_ids' ) ) {
			$product->set_cross_sell_ids( self::id_list( $request->get_param( 'cross_sell_ids' ) ) );
		}

		/* --- Type-specific: grouped children / external link ------------ */
		// Gate on the setter, not the type string: the type may have just been switched
		// in this same request, and only the matching WC_Product subclass has these.
		if ( $has( 'children' ) && method_exists( $product, 'set_children' ) ) {
			$product->set_children( self::id_list( $request->get_param( 'children' ) ) );
		}
		if ( $has( 'product_url' ) && method_exists( $product, 'set_product_url' ) ) {
			$product->set_product_url( esc_url_raw( (string) $request->get_param( 'product_url' ) ) );
		}
		if ( $has( 'button_text' ) && method_exists( $product, 'set_button_text' ) ) {
			$product->set_button_text( sanitize_text_field( (string) $request->get_param( 'button_text' ) ) );
		}

		/* --- Scheduled publishing --------------------------------------- */
		// WooCommerce/WordPress model a scheduled post as status `future` + a future date.
		if ( $has( 'publish_date' ) ) {
			$raw = trim( (string) $request->get_param( 'publish_date' ) );
			if ( '' === $raw ) {
				// Un-schedule. The date must be reset too: WordPress re-flags any post whose
				// date is still in the future back to `future`, whatever status we set.
				$created = $product->get_date_created();
				if ( $created && $created->getTimestamp() > time() ) {
					$product->set_date_created( gmdate( 'Y-m-d H:i:s' ) );
				}
				if ( 'future' === $product->get_status() ) {
					$product->set_status( 'publish' );
				}
			} else {
				$timestamp = strtotime( $raw );
				if ( $timestamp ) {
					$product->set_date_created( gmdate( 'Y-m-d H:i:s', $timestamp ) );
					if ( $timestamp > time() ) {
						$product->set_status( 'future' );
					}
				}
			}
		}

		/* --- Pricing --------------------------------------------------- */
		if ( $has( 'regular_price' ) ) {
			$product->set_regular_price( wc_format_decimal( $request->get_param( 'regular_price' ) ) );
		}
		if ( $has( 'sale_price' ) ) {
			$product->set_sale_price( '' === $request->get_param( 'sale_price' ) ? '' : wc_format_decimal( $request->get_param( 'sale_price' ) ) );
		}
		if ( $has( 'sale_from' ) ) {
			$product->set_date_on_sale_from( self::date_or_null( $request->get_param( 'sale_from' ) ) );
		}
		if ( $has( 'sale_to' ) ) {
			$product->set_date_on_sale_to( self::date_or_null( $request->get_param( 'sale_to' ) ) );
		}
		if ( $has( 'tax_status' ) ) {
			$ts = sanitize_key( (string) $request->get_param( 'tax_status' ) );
			$product->set_tax_status( in_array( $ts, array( 'taxable', 'shipping', 'none' ), true ) ? $ts : 'taxable' );
		}
		if ( $has( 'tax_class' ) ) {
			$product->set_tax_class( sanitize_title( (string) $request->get_param( 'tax_class' ) ) );
		}

		/* --- Inventory ------------------------------------------------- */
		if ( $has( 'manage_stock' ) ) {
			$product->set_manage_stock( wc_string_to_bool( $request->get_param( 'manage_stock' ) ) );
		}
		if ( $has( 'stock_quantity' ) ) {
			$qty = $request->get_param( 'stock_quantity' );
			$product->set_stock_quantity( '' === $qty || null === $qty ? null : wc_stock_amount( $qty ) );
		}
		if ( $has( 'stock_status' ) ) {
			$ss = sanitize_key( (string) $request->get_param( 'stock_status' ) );
			$product->set_stock_status( in_array( $ss, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $ss : 'instock' );
		}
		if ( $has( 'backorders' ) ) {
			$bo = sanitize_key( (string) $request->get_param( 'backorders' ) );
			$product->set_backorders( in_array( $bo, array( 'no', 'notify', 'yes' ), true ) ? $bo : 'no' );
		}
		if ( $has( 'low_stock_amount' ) ) {
			$low = $request->get_param( 'low_stock_amount' );
			$product->set_low_stock_amount( '' === $low || null === $low ? '' : wc_stock_amount( $low ) );
		}
		if ( $has( 'sold_individually' ) ) {
			$product->set_sold_individually( wc_string_to_bool( $request->get_param( 'sold_individually' ) ) );
		}

		/* --- Shipping -------------------------------------------------- */
		if ( $has( 'weight' ) ) {
			$product->set_weight( '' === $request->get_param( 'weight' ) ? '' : wc_format_decimal( $request->get_param( 'weight' ) ) );
		}
		foreach ( array( 'length', 'width', 'height' ) as $dim ) {
			if ( $has( $dim ) ) {
				$val = $request->get_param( $dim );
				$product->{"set_$dim"}( '' === $val ? '' : wc_format_decimal( $val ) );
			}
		}
		if ( $has( 'shipping_class_id' ) ) {
			$product->set_shipping_class_id( absint( $request->get_param( 'shipping_class_id' ) ) );
		}

		/* --- Taxonomies (categories / tags) ---------------------------- */
		if ( $has( 'categories' ) ) {
			$product->set_category_ids( array_filter( array_map( 'absint', (array) $request->get_param( 'categories' ) ) ) );
		}
		if ( $has( 'tags' ) ) {
			$product->set_tag_ids( array_filter( array_map( 'absint', (array) $request->get_param( 'tags' ) ) ) );
		}

		/* --- Images ---------------------------------------------------- */
		$images = $request->get_param( 'images' );
		if ( is_array( $images ) ) {
			if ( array_key_exists( 'featured', $images ) ) {
				$product->set_image_id( absint( $images['featured'] ) );
			}
			if ( array_key_exists( 'gallery', $images ) ) {
				$product->set_gallery_image_ids( array_filter( array_map( 'absint', (array) $images['gallery'] ) ) );
			}
		}

		/* --- Attributes (generic) + Jewelry taxonomies ----------------- */
		$attributes = $product->get_attributes();
		if ( $has( 'attributes' ) ) {
			$attributes = self::build_attributes( (array) $request->get_param( 'attributes' ) );
		}
		$jewelry = (array) $request->get_param( 'jewelry' );
		if ( isset( $jewelry['attributes'] ) && is_array( $jewelry['attributes'] ) ) {
			$attributes = Yazan_Dashboard_Fields::merge_attributes( $attributes, $jewelry['attributes'] );
		}
		$product->set_attributes( $attributes );

		// Jewelry / authenticity meta (serial, silver weight, craftsmanship, status, qr, certificate).
		if ( ! empty( $jewelry ) ) {
			Yazan_Dashboard_Fields::stage_meta( $product, $jewelry );
		}

		/* --- Downloads ------------------------------------------------- */
		if ( $has( 'downloads' ) && $product->is_downloadable() ) {
			$product->set_downloads( self::build_downloads( (array) $request->get_param( 'downloads' ) ) );
		}
		if ( $has( 'download_limit' ) ) {
			$product->set_download_limit( (int) $request->get_param( 'download_limit' ) );
		}
		if ( $has( 'download_expiry' ) ) {
			$product->set_download_expiry( (int) $request->get_param( 'download_expiry' ) );
		}

		/* --- Advanced -------------------------------------------------- */
		if ( $has( 'purchase_note' ) ) {
			$product->set_purchase_note( sanitize_textarea_field( (string) $request->get_param( 'purchase_note' ) ) );
		}
		if ( $has( 'menu_order' ) ) {
			$product->set_menu_order( (int) $request->get_param( 'menu_order' ) );
		}
		if ( $has( 'reviews_allowed' ) ) {
			$product->set_reviews_allowed( wc_string_to_bool( $request->get_param( 'reviews_allowed' ) ) );
		}

		return true;
	}

	/**
	 * Post-save work needing a persisted product id: collection term + variations.
	 *
	 * @param WC_Product      $product Saved product.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	private static function after_save( WC_Product $product, WP_REST_Request $request ) {
		$jewelry = (array) $request->get_param( 'jewelry' );
		if ( array_key_exists( 'collection', $jewelry ) ) {
			Yazan_Dashboard_Fields::assign_collection( $product->get_id(), $jewelry['collection'] );
		}

		if ( $product->is_type( 'variable' ) && null !== $request->get_param( 'variations' ) ) {
			self::sync_variations( $product, (array) $request->get_param( 'variations' ), (array) $request->get_param( 'delete_variations' ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Duplicate + quick edit                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /products/{id}/duplicate — copy a product as a draft.
	 *
	 * Uses WooCommerce's own duplicator when available so variations, attributes and meta are
	 * copied exactly as wp-admin's "Duplicate" does. The copy is always a DRAFT with the SKU
	 * cleared and the serial removed — a duplicated ring is a different physical ring, and copying
	 * `_yz_serial` would create two products claiming the same certificate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['id'] ) );
		if ( ! $product instanceof WC_Product ) {
			return self::not_found();
		}

		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			$path = WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			return new WP_Error( 'yazan_unavailable', __( 'The WooCommerce duplicator is unavailable.', 'yazan' ), array( 'status' => 500 ) );
		}

		$duplicator = new WC_Admin_Duplicate_Product();
		$copy       = $duplicator->product_duplicate( $product );
		if ( ! $copy instanceof WC_Product ) {
			return new WP_Error( 'yazan_duplicate_failed', __( 'Could not duplicate that product.', 'yazan' ), array( 'status' => 500 ) );
		}

		// A copy must never inherit the original's identity.
		$copy->set_status( 'draft' );
		$copy->set_sku( '' );
		$copy->delete_meta_data( Yazan_Core_Verify::SERIAL_META );
		$copy->delete_meta_data( Yazan_Dashboard_Fields::META_QR );
		$copy->delete_meta_data( Yazan_Dashboard_Fields::META_CERTIFICATE );
		$copy->update_meta_data( Yazan_Dashboard_Fields::META_STATUS, 'draft' );
		$copy->save();

		Yazan_Dashboard_Audit::log(
			'product.duplicate',
			'product',
			$copy->get_id(),
			array( 'source' => $product->get_id(), 'name' => $copy->get_name() )
		);

		return new WP_REST_Response( self::detail( wc_get_product( $copy->get_id() ) ), 201 );
	}

	/**
	 * POST /products/quick-edit — patch a few fields on several products at once.
	 *
	 * Body: { items: [ { id, name?, sku?, regular_price?, sale_price?, status?, stock_status?,
	 *                    stock_quantity?, manage_stock?, menu_order? } ] }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quick_edit( WP_REST_Request $request ) {
		$rows = (array) $request->get_param( 'items' );
		if ( empty( $rows ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( count( $rows ) > 100 ) {
			return new WP_Error( 'yazan_too_many', __( 'Please save at most 100 products at a time.', 'yazan' ), array( 'status' => 400 ) );
		}

		$updated = array();
		$skipped = array();

		foreach ( $rows as $row ) {
			$id      = absint( $row['id'] ?? 0 );
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				$skipped[] = array( 'id' => $id, 'reason' => 'not_found' );
				continue;
			}

			if ( array_key_exists( 'name', $row ) ) {
				$name = sanitize_text_field( (string) $row['name'] );
				if ( '' === trim( $name ) ) {
					$skipped[] = array( 'id' => $id, 'reason' => 'empty_name' );
					continue;
				}
				$product->set_name( $name );
			}
			if ( array_key_exists( 'sku', $row ) ) {
				$sku    = sanitize_text_field( (string) $row['sku'] );
				$sku_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
				if ( $sku && $sku_id && (int) $sku_id !== $id ) {
					$skipped[] = array( 'id' => $id, 'reason' => 'duplicate_sku' );
					continue;
				}
				$product->set_sku( $sku );
			}
			if ( array_key_exists( 'regular_price', $row ) ) {
				$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
			}
			if ( array_key_exists( 'sale_price', $row ) ) {
				$product->set_sale_price( '' === $row['sale_price'] ? '' : wc_format_decimal( $row['sale_price'] ) );
			}
			if ( array_key_exists( 'status', $row ) ) {
				$status = sanitize_key( (string) $row['status'] );
				if ( in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
					$product->set_status( $status );
				}
			}
			if ( array_key_exists( 'menu_order', $row ) ) {
				$product->set_menu_order( (int) $row['menu_order'] );
			}
			if ( array_key_exists( 'manage_stock', $row ) ) {
				$product->set_manage_stock( wc_string_to_bool( $row['manage_stock'] ) );
			}
			if ( $product->get_manage_stock() && array_key_exists( 'stock_quantity', $row ) ) {
				$qty = $row['stock_quantity'];
				$product->set_stock_quantity( ( '' === $qty || null === $qty ) ? null : wc_stock_amount( $qty ) );
			}
			if ( array_key_exists( 'stock_status', $row ) ) {
				$ss = sanitize_key( (string) $row['stock_status'] );
				if ( in_array( $ss, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					// Managed stock derives status from quantity — move it too, or WC discards this.
					if ( $product->get_manage_stock() && ! array_key_exists( 'stock_quantity', $row ) ) {
						if ( 'outofstock' === $ss ) {
							$product->set_stock_quantity( 0 );
						} elseif ( 'instock' === $ss && (int) $product->get_stock_quantity() <= 0 ) {
							$product->set_stock_quantity( 1 );
						}
					}
					$product->set_stock_status( $ss );
				}
			}

			$product->save();
			$updated[] = self::summary( wc_get_product( $id ) );
		}

		Yazan_Dashboard_Audit::log(
			'product.quick_edit',
			'product',
			0,
			array( 'updated' => count( $updated ), 'skipped' => count( $skipped ) )
		);

		return new WP_REST_Response(
			array( 'updated' => $updated, 'skipped' => $skipped, 'count' => count( $updated ) ),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Delete + bulk                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * DELETE /products/{id}?force=1.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return self::not_found();
		}
		$force = wc_string_to_bool( $request->get_param( 'force' ) );
		$product->delete( $force );

		Yazan_Dashboard_Audit::log( 'product.delete', 'product', $id, array( 'force' => $force ? 1 : 0 ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id, 'force' => $force ), 200 );
	}

	/**
	 * POST /products/bulk — apply one action to many ids.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bulk( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		$allowed = array( 'trash', 'delete', 'publish', 'draft', 'set_instock', 'set_outofstock' );
		if ( ! in_array( $action, $allowed, true ) || empty( $ids ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Invalid bulk request.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( in_array( $action, array( 'trash', 'delete' ), true ) && ! current_user_can( 'delete_products' ) ) {
			return new WP_Error( 'yazan_forbidden', __( 'Insufficient permissions.', 'yazan' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$done = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			switch ( $action ) {
				case 'delete':
					$product->delete( true );
					break;
				case 'trash':
					$product->delete( false );
					break;
				case 'publish':
				case 'draft':
					$product->set_status( $action );
					$product->save();
					break;
				// For stock-managed products WooCommerce derives the status from the quantity on
				// save, so setting the status alone would be silently discarded — move the
				// quantity too, then let WC settle the final status.
				case 'set_instock':
					if ( $product->get_manage_stock() && (int) $product->get_stock_quantity() <= 0 ) {
						$product->set_stock_quantity( 1 );
					}
					$product->set_stock_status( 'instock' );
					$product->save();
					break;
				case 'set_outofstock':
					if ( $product->get_manage_stock() ) {
						$product->set_stock_quantity( 0 );
					}
					$product->set_stock_status( 'outofstock' );
					$product->save();
					break;
			}
			$done[] = $id;
		}

		Yazan_Dashboard_Audit::log( 'product.bulk', 'product', 0, array( 'action' => $action, 'ids' => $done ) );

		return new WP_REST_Response( array( 'action' => $action, 'ids' => $done, 'count' => count( $done ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Builders                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Turn the incoming attributes payload into WC_Product_Attribute objects, keyed by taxonomy/name.
	 *
	 * @param array $raw Attribute rows.
	 * @return array<string,WC_Product_Attribute>
	 */
	private static function build_attributes( array $raw ) {
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$taxonomy = isset( $row['taxonomy'] ) ? sanitize_title( (string) $row['taxonomy'] ) : '';
			$name     = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			$options  = isset( $row['options'] ) ? (array) $row['options'] : array();

			$attribute = new WC_Product_Attribute();
			if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( array_filter( array_map( 'absint', $options ) ) );
				$key = $taxonomy;
			} else {
				if ( '' === $name ) {
					continue;
				}
				$attribute->set_id( 0 );
				$attribute->set_name( $name );
				$attribute->set_options( array_map( 'sanitize_text_field', array_map( 'strval', $options ) ) );
				$key = $name;
			}
			$attribute->set_visible( ! isset( $row['visible'] ) || wc_string_to_bool( $row['visible'] ) );
			$attribute->set_variation( isset( $row['variation'] ) && wc_string_to_bool( $row['variation'] ) );
			$out[ $key ] = $attribute;
		}
		return $out;
	}

	/**
	 * Build the downloads array for a downloadable product.
	 *
	 * @param array $raw Rows of { name, file }.
	 * @return array<int,array{name:string,file:string}>
	 */
	private static function build_downloads( array $raw ) {
		$downloads = array();
		foreach ( $raw as $row ) {
			if ( empty( $row['file'] ) ) {
				continue;
			}
			$downloads[] = array(
				'name' => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				'file' => esc_url_raw( (string) $row['file'] ),
			);
		}
		return $downloads;
	}

	/**
	 * Create/update/delete variations of a variable product from the payload.
	 *
	 * @param WC_Product $product Variable product.
	 * @param array      $rows    Variation rows.
	 * @param array      $delete  Variation ids to remove.
	 * @return void
	 */
	private static function sync_variations( WC_Product $product, array $rows, array $delete ) {
		foreach ( array_filter( array_map( 'absint', $delete ) ) as $del_id ) {
			$v = wc_get_product( $del_id );
			if ( $v instanceof WC_Product_Variation && $v->get_parent_id() === $product->get_id() ) {
				$v->delete( true );
			}
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$vid       = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$variation = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
			}
			$variation->set_parent_id( $product->get_id() );

			// Variation attributes: map of taxonomy => term slug.
			if ( isset( $row['attributes'] ) && is_array( $row['attributes'] ) ) {
				$attrs = array();
				foreach ( $row['attributes'] as $tax => $slug ) {
					$attrs[ sanitize_title( (string) $tax ) ] = sanitize_title( (string) $slug );
				}
				$variation->set_attributes( $attrs );
			}
			if ( array_key_exists( 'regular_price', $row ) ) {
				$variation->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
			}
			if ( array_key_exists( 'sale_price', $row ) ) {
				$variation->set_sale_price( '' === $row['sale_price'] ? '' : wc_format_decimal( $row['sale_price'] ) );
			}
			if ( array_key_exists( 'sku', $row ) ) {
				$variation->set_sku( sanitize_text_field( (string) $row['sku'] ) );
			}
			if ( array_key_exists( 'manage_stock', $row ) ) {
				$variation->set_manage_stock( wc_string_to_bool( $row['manage_stock'] ) );
			}
			if ( array_key_exists( 'stock_quantity', $row ) ) {
				$variation->set_stock_quantity( '' === $row['stock_quantity'] ? null : wc_stock_amount( $row['stock_quantity'] ) );
			}
			if ( array_key_exists( 'stock_status', $row ) ) {
				$ss = sanitize_key( (string) $row['stock_status'] );
				$variation->set_stock_status( in_array( $ss, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $ss : 'instock' );
			}
			if ( array_key_exists( 'description', $row ) ) {
				$variation->set_description( wp_kses_post( (string) $row['description'] ) );
			}
			if ( array_key_exists( 'virtual', $row ) ) {
				$variation->set_virtual( wc_string_to_bool( $row['virtual'] ) );
			}
			$variation->save();
		}

		WC_Product_Variable::sync( $product->get_id() );
	}

	/* --------------------------------------------------------------------- */
	/* Formatting                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Compact row for the product table.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function summary( WC_Product $p ) {
		$img_id = $p->get_image_id();
		return array(
			'id'           => $p->get_id(),
			'name'         => $p->get_name(),
			'sku'          => $p->get_sku(),
			'type'         => $p->get_type(),
			'status'       => $p->get_status(),
			'featured'     => $p->get_featured(),
			'price'        => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'   => $p->get_sale_price(),
			'price_html'   => wp_strip_all_tags( $p->get_price_html() ),
			'on_sale'      => $p->is_on_sale(),
			'manage_stock' => $p->get_manage_stock(),
			'stock_status' => $p->get_stock_status(),
			'stock_qty'    => $p->get_stock_quantity(),
			'categories'   => wp_strip_all_tags( wc_get_product_category_list( $p->get_id(), ', ' ) ),
			'thumb'        => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
			'date'         => $p->get_date_created() ? $p->get_date_created()->date( 'Y-m-d' ) : '',
			'permalink'    => get_permalink( $p->get_id() ),
			'edit_serial'  => (string) $p->get_meta( Yazan_Core_Verify::SERIAL_META ),
		);
	}

	/**
	 * Full product payload for the editor.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function detail( WC_Product $p ) {
		$gallery = array();
		foreach ( $p->get_gallery_image_ids() as $gid ) {
			$gallery[] = array( 'id' => (int) $gid, 'url' => wp_get_attachment_image_url( $gid, 'medium' ) );
		}
		$featured_id = $p->get_image_id();

		$attributes = array();
		foreach ( $p->get_attributes() as $key => $attribute ) {
			$attributes[] = array(
				'key'       => $key,
				'name'      => $attribute->get_name(),
				'taxonomy'  => $attribute->is_taxonomy() ? $attribute->get_name() : '',
				'label'     => wc_attribute_label( $attribute->get_name() ),
				'options'   => $attribute->get_options(), // term ids (taxonomy) or strings (custom).
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}

		$variations = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v instanceof WC_Product_Variation ) {
					continue;
				}
				$variations[] = array(
					'id'             => $v->get_id(),
					'attributes'     => $v->get_attributes(),
					'sku'            => $v->get_sku(),
					'regular_price'  => $v->get_regular_price(),
					'sale_price'     => $v->get_sale_price(),
					'manage_stock'   => $v->get_manage_stock(),
					'stock_quantity' => $v->get_stock_quantity(),
					'stock_status'   => $v->get_stock_status(),
					'virtual'        => $v->get_virtual(),
					'description'    => $v->get_description(),
				);
			}
		}

		return array(
			'id'                 => $p->get_id(),
			'name'               => $p->get_name(),
			'type'               => $p->get_type(),
			'status'             => $p->get_status(),
			'featured'           => $p->get_featured(),
			'catalog_visibility' => $p->get_catalog_visibility(),
			'description'        => $p->get_description(),
			'short_description'  => $p->get_short_description(),
			'sku'                => $p->get_sku(),
			'virtual'            => $p->get_virtual(),
			'downloadable'       => $p->get_downloadable(),
			'regular_price'      => $p->get_regular_price(),
			'sale_price'         => $p->get_sale_price(),
			'sale_from'          => $p->get_date_on_sale_from() ? $p->get_date_on_sale_from()->date( 'Y-m-d' ) : '',
			'sale_to'            => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->date( 'Y-m-d' ) : '',
			'tax_status'         => $p->get_tax_status(),
			'tax_class'          => $p->get_tax_class(),
			'manage_stock'       => $p->get_manage_stock(),
			'stock_quantity'     => $p->get_stock_quantity(),
			'stock_status'       => $p->get_stock_status(),
			'backorders'         => $p->get_backorders(),
			'low_stock_amount'   => $p->get_low_stock_amount(),
			'sold_individually'  => $p->get_sold_individually(),
			'weight'             => $p->get_weight(),
			'length'             => $p->get_length(),
			'width'              => $p->get_width(),
			'height'             => $p->get_height(),
			'shipping_class_id'  => $p->get_shipping_class_id(),
			'categories'         => array_map( 'intval', $p->get_category_ids() ),
			'tags'               => array_map( 'intval', $p->get_tag_ids() ),
			'downloads'          => array_map(
				static function ( $d ) {
					return array( 'name' => $d->get_name(), 'file' => $d->get_file() );
				},
				array_values( $p->get_downloads() )
			),
			'download_limit'     => $p->get_download_limit(),
			'download_expiry'    => $p->get_download_expiry(),
			'purchase_note'      => $p->get_purchase_note(),
			'menu_order'         => $p->get_menu_order(),
			'reviews_allowed'    => $p->get_reviews_allowed(),
			'permalink'          => get_permalink( $p->get_id() ),
			'images'             => array(
				'featured'     => (int) $featured_id,
				'featured_url' => $featured_id ? wp_get_attachment_image_url( $featured_id, 'medium' ) : '',
				'gallery'      => $gallery,
			),
			'attributes'         => $attributes,
			'variations'         => $variations,

			// Linked products / type-specific fields. Returned as {id,name,sku} so the editor
			// can render names without a second round-trip per id.
			'upsells'            => self::link_refs( $p->get_upsell_ids() ),
			'cross_sells'        => self::link_refs( $p->get_cross_sell_ids() ),
			'children'           => $p->is_type( 'grouped' ) ? self::link_refs( $p->get_children() ) : array(),
			'product_url'        => $p->is_type( 'external' ) ? $p->get_product_url() : '',
			'button_text'        => $p->is_type( 'external' ) ? $p->get_button_text() : '',

			// Scheduling: WooCommerce stores a future publish date as status `future`.
			'date_created'       => $p->get_date_created() ? $p->get_date_created()->date( 'Y-m-d H:i' ) : '',

			'jewelry'            => Yazan_Dashboard_Fields::read( $p ),
		);
	}

	/**
	 * Normalise a posted list of product ids: ints only, no zeros, no duplicates.
	 * Accepts either a plain array or a comma-separated string.
	 *
	 * @param mixed $raw Raw value.
	 * @return int[]
	 */
	private static function id_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : explode( ',', $raw );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
	}

	/**
	 * Resolve product ids into lightweight references for the linked-product pickers.
	 *
	 * @param array $ids Product ids.
	 * @return array
	 */
	private static function link_refs( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product ) {
				continue; // Skip ids whose product was deleted.
			}
			$out[] = array(
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'sku'  => $product->get_sku(),
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Small helpers                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Parse a Y-m-d (or timestamp) into something WC setters accept, or null to clear.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function date_or_null( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? null : sanitize_text_field( $value );
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'yazan_not_found', __( 'Product not found.', 'yazan' ), array( 'status' => 404 ) );
	}
}
