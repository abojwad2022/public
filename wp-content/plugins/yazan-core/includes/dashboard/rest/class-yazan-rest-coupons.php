<?php
/**
 * Yazan Dashboard — coupons.
 *
 * Full CRUD over WooCommerce coupons through the WC_Coupon CRUD API — general settings, usage
 * restrictions and usage limits, i.e. the three tabs of the wp-admin coupon screen.
 *
 * Capabilities follow WooCommerce's own model for the `shop_coupon` post type
 * (`edit_shop_coupons` / `delete_shop_coupons`), which both Administrator and Shop Manager hold.
 *
 * Coupon codes are normalised with wc_format_coupon_code() and checked for uniqueness before
 * saving — two coupons sharing a code would make which discount applies non-deterministic.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /coupons endpoints.
 */
class Yazan_REST_Coupons {

	/** Capability to read/write coupons. */
	const CAP = 'edit_shop_coupons';

	/** Capability to delete coupons. */
	const CAP_DELETE = 'delete_shop_coupons';

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/coupons',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'coupons.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'coupons.create' ),
			)
		);

		register_rest_route(
			$ns,
			'/coupons/(?P<id>\d+)',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'show' ), 'coupons.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'coupons.edit' ),
				Yazan_REST_Guard::args( WP_REST_Server::DELETABLE, array( __CLASS__, 'destroy' ), 'coupons.delete' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* List                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /coupons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$coupon = new WC_Coupon( $post->ID );
			$items[] = self::summary( $coupon );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
				'types'       => wc_get_coupon_types(),
			),
			200
		);
	}

	/**
	 * GET /coupons/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$coupon = self::load( $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		return new WP_REST_Response( self::detail( $coupon ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Create / update                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /coupons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$code = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A coupon code is required.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'yazan_exists', __( 'A coupon with that code already exists.', 'yazan' ), array( 'status' => 400 ) );
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );

		$error = self::apply( $coupon, $request );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$coupon->save();

		Yazan_Dashboard_Audit::log( 'coupon.create', 'coupon', $coupon->get_id(), array( 'code' => $code ) );

		return new WP_REST_Response( self::detail( new WC_Coupon( $coupon->get_id() ) ), 201 );
	}

	/**
	 * PUT /coupons/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$coupon = self::load( $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		if ( null !== $request->get_param( 'code' ) ) {
			$code = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
			if ( '' === trim( $code ) ) {
				return new WP_Error( 'yazan_invalid', __( 'A coupon code is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			$existing = wc_get_coupon_id_by_code( $code );
			if ( $existing && (int) $existing !== $coupon->get_id() ) {
				return new WP_Error( 'yazan_exists', __( 'Another coupon already uses that code.', 'yazan' ), array( 'status' => 400 ) );
			}
			$coupon->set_code( $code );
		}

		$error = self::apply( $coupon, $request );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$coupon->save();

		Yazan_Dashboard_Audit::log( 'coupon.update', 'coupon', $coupon->get_id(), array( 'code' => $coupon->get_code() ) );

		return new WP_REST_Response( self::detail( new WC_Coupon( $coupon->get_id() ) ), 200 );
	}

	/**
	 * DELETE /coupons/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$coupon = self::load( $request['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		$id   = $coupon->get_id();
		$code = $coupon->get_code();
		$coupon->delete( wc_string_to_bool( $request->get_param( 'force' ) ) );

		Yazan_Dashboard_Audit::log( 'coupon.delete', 'coupon', $id, array( 'code' => $code ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/**
	 * Apply the payload onto a coupon (pre-save).
	 *
	 * @param WC_Coupon       $coupon  Coupon.
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function apply( WC_Coupon $coupon, WP_REST_Request $request ) {
		$has = static function ( $key ) use ( $request ) {
			return null !== $request->get_param( $key );
		};

		/* --- General ---------------------------------------------------- */
		if ( $has( 'description' ) ) {
			$coupon->set_description( sanitize_textarea_field( (string) $request->get_param( 'description' ) ) );
		}
		if ( $has( 'discount_type' ) ) {
			$type = sanitize_key( (string) $request->get_param( 'discount_type' ) );
			if ( ! array_key_exists( $type, wc_get_coupon_types() ) ) {
				return new WP_Error( 'yazan_invalid', __( 'Unknown discount type.', 'yazan' ), array( 'status' => 400 ) );
			}
			$coupon->set_discount_type( $type );
		}
		if ( $has( 'amount' ) ) {
			$amount = (float) wc_format_decimal( $request->get_param( 'amount' ) );
			if ( $amount < 0 ) {
				return new WP_Error( 'yazan_invalid', __( 'The discount amount cannot be negative.', 'yazan' ), array( 'status' => 400 ) );
			}
			// A percentage over 100 would hand out more than the order is worth.
			if ( 'percent' === $coupon->get_discount_type() && $amount > 100 ) {
				return new WP_Error( 'yazan_invalid', __( 'A percentage discount cannot exceed 100%.', 'yazan' ), array( 'status' => 400 ) );
			}
			$coupon->set_amount( wc_format_decimal( $amount ) );
		}
		if ( $has( 'date_expires' ) ) {
			$expires = trim( (string) $request->get_param( 'date_expires' ) );
			$coupon->set_date_expires( '' === $expires ? null : sanitize_text_field( $expires ) );
		}
		if ( $has( 'free_shipping' ) ) {
			$coupon->set_free_shipping( wc_string_to_bool( $request->get_param( 'free_shipping' ) ) );
		}

		/* --- Usage restrictions ----------------------------------------- */
		if ( $has( 'minimum_amount' ) ) {
			$min = $request->get_param( 'minimum_amount' );
			$coupon->set_minimum_amount( '' === $min ? '' : wc_format_decimal( $min ) );
		}
		if ( $has( 'maximum_amount' ) ) {
			$max = $request->get_param( 'maximum_amount' );
			$coupon->set_maximum_amount( '' === $max ? '' : wc_format_decimal( $max ) );
		}
		if ( $has( 'individual_use' ) ) {
			$coupon->set_individual_use( wc_string_to_bool( $request->get_param( 'individual_use' ) ) );
		}
		if ( $has( 'exclude_sale_items' ) ) {
			$coupon->set_exclude_sale_items( wc_string_to_bool( $request->get_param( 'exclude_sale_items' ) ) );
		}
		foreach ( array(
			'product_ids'                 => 'set_product_ids',
			'excluded_product_ids'        => 'set_excluded_product_ids',
			'product_categories'          => 'set_product_categories',
			'excluded_product_categories' => 'set_excluded_product_categories',
		) as $param => $setter ) {
			if ( $has( $param ) ) {
				$coupon->{$setter}( array_values( array_filter( array_map( 'absint', (array) $request->get_param( $param ) ) ) ) );
			}
		}
		if ( $has( 'email_restrictions' ) ) {
			$emails = array();
			foreach ( (array) $request->get_param( 'email_restrictions' ) as $raw ) {
				$email = sanitize_email( (string) $raw );
				// Allow wildcards like *@example.com, which WooCommerce supports.
				if ( is_email( $email ) ) {
					$emails[] = $email;
				} elseif ( preg_match( '/^\*@[\w.\-]+\.\w+$/', trim( (string) $raw ) ) ) {
					$emails[] = strtolower( trim( (string) $raw ) );
				}
			}
			$coupon->set_email_restrictions( $emails );
		}

		/* --- Usage limits ------------------------------------------------ */
		foreach ( array(
			'usage_limit'             => 'set_usage_limit',
			'usage_limit_per_user'    => 'set_usage_limit_per_user',
			'limit_usage_to_x_items'  => 'set_limit_usage_to_x_items',
		) as $param => $setter ) {
			if ( $has( $param ) ) {
				$value = $request->get_param( $param );
				$coupon->{$setter}( ( '' === $value || null === $value ) ? null : absint( $value ) );
			}
		}

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Formatting                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Compact row for the coupon table.
	 *
	 * @param WC_Coupon $c Coupon.
	 * @return array
	 */
	private static function summary( WC_Coupon $c ) {
		$expires = $c->get_date_expires();
		return array(
			'id'            => $c->get_id(),
			'code'          => $c->get_code(),
			'description'   => $c->get_description(),
			'discount_type' => $c->get_discount_type(),
			'amount'        => $c->get_amount(),
			'amount_html'   => self::amount_html( $c ),
			'usage_count'   => (int) $c->get_usage_count(),
			'usage_limit'   => $c->get_usage_limit(),
			'expires'       => $expires ? $expires->date( 'Y-m-d' ) : '',
			'expired'       => $expires ? $expires->getTimestamp() < time() : false,
			'free_shipping' => $c->get_free_shipping(),
		);
	}

	/**
	 * Full coupon payload for the editor.
	 *
	 * @param WC_Coupon $c Coupon.
	 * @return array
	 */
	private static function detail( WC_Coupon $c ) {
		$expires = $c->get_date_expires();

		return array(
			'id'                          => $c->get_id(),
			'code'                        => $c->get_code(),
			'description'                 => $c->get_description(),
			'discount_type'               => $c->get_discount_type(),
			'amount'                      => $c->get_amount(),
			'amount_html'                 => self::amount_html( $c ),
			'date_expires'                => $expires ? $expires->date( 'Y-m-d' ) : '',
			'free_shipping'               => $c->get_free_shipping(),
			'minimum_amount'              => $c->get_minimum_amount(),
			'maximum_amount'              => $c->get_maximum_amount(),
			'individual_use'              => $c->get_individual_use(),
			'exclude_sale_items'          => $c->get_exclude_sale_items(),
			'product_ids'                 => array_map( 'intval', $c->get_product_ids() ),
			'excluded_product_ids'        => array_map( 'intval', $c->get_excluded_product_ids() ),
			'product_categories'          => array_map( 'intval', $c->get_product_categories() ),
			'excluded_product_categories' => array_map( 'intval', $c->get_excluded_product_categories() ),
			'email_restrictions'          => array_values( (array) $c->get_email_restrictions() ),
			'usage_limit'                 => $c->get_usage_limit(),
			'usage_limit_per_user'        => $c->get_usage_limit_per_user(),
			'limit_usage_to_x_items'      => $c->get_limit_usage_to_x_items(),
			'usage_count'                 => (int) $c->get_usage_count(),
			// Names for the product pickers, so the UI doesn't need a second round-trip.
			'product_names'               => self::names( $c->get_product_ids() ),
			'excluded_product_names'      => self::names( $c->get_excluded_product_ids() ),
		);
	}

	/**
	 * Human-readable discount, e.g. "20%" or "$15.00".
	 *
	 * @param WC_Coupon $c Coupon.
	 * @return string
	 */
	private static function amount_html( WC_Coupon $c ) {
		if ( 'percent' === $c->get_discount_type() ) {
			return wc_format_decimal( $c->get_amount() ) . '%';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( $c->get_amount() ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * id => name map for a list of product ids.
	 *
	 * @param array $ids Product ids.
	 * @return array<int,string>
	 */
	private static function names( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product ) {
				$out[ (int) $id ] = $product->get_name();
			}
		}
		return $out;
	}

	/**
	 * Load a coupon, verifying it really is one.
	 *
	 * @param mixed $id Raw id.
	 * @return WC_Coupon|WP_Error
	 */
	private static function load( $id ) {
		$id = absint( $id );
		if ( ! $id || 'shop_coupon' !== get_post_type( $id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'Coupon not found.', 'yazan' ), array( 'status' => 404 ) );
		}
		return new WC_Coupon( $id );
	}
}
