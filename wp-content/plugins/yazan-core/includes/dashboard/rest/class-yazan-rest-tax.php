<?php
/**
 * Yazan Dashboard — tax settings and tax rates.
 *
 * Two things live here: the store-wide tax OPTIONS (whether tax is calculated at all, whether entered
 * prices include tax, rounding, display) and the tax RATE tables themselves, per tax class.
 *
 * Rates go through the WC_Tax API (`_insert_tax_rate` / `_update_tax_rate` / `_delete_tax_rate`)
 * rather than raw SQL, so WooCommerce's own caches are cleared and the `wc_tax_rate_locations`
 * rows (postcodes / cities) stay consistent with the rate row.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /tax endpoints.
 */
class Yazan_REST_Tax {

	/** Tax configuration is a store-owner level action. */
	const CAP = 'manage_woocommerce';

	/**
	 * Store-wide tax options this controller may write. Strict allow-list, same principle as
	 * Yazan_REST_Settings — a caller can never name an arbitrary option.
	 *
	 * @return array<string,array>
	 */
	private static function option_schema() {
		return array(
			'woocommerce_calc_taxes'            => array( 'type' => 'bool_yesno', 'label' => __( 'Enable taxes', 'yazan' ) ),
			'woocommerce_prices_include_tax'    => array(
				'type'    => 'select',
				'label'   => __( 'Prices entered with tax', 'yazan' ),
				'choices' => array( 'yes' => 'Yes, I will enter prices inclusive of tax', 'no' => 'No, I will enter prices exclusive of tax' ),
			),
			'woocommerce_tax_based_on'          => array(
				'type'    => 'select',
				'label'   => __( 'Calculate tax based on', 'yazan' ),
				'choices' => array( 'shipping' => 'Customer shipping address', 'billing' => 'Customer billing address', 'base' => 'Shop base address' ),
			),
			'woocommerce_shipping_tax_class'    => array( 'type' => 'select', 'label' => __( 'Shipping tax class', 'yazan' ), 'choices' => 'tax_classes_shipping' ),
			'woocommerce_tax_round_at_subtotal' => array( 'type' => 'bool_yesno', 'label' => __( 'Round tax at subtotal level', 'yazan' ) ),
			'woocommerce_tax_display_shop'      => array(
				'type'    => 'select',
				'label'   => __( 'Display prices in the shop', 'yazan' ),
				'choices' => array( 'incl' => 'Including tax', 'excl' => 'Excluding tax' ),
			),
			'woocommerce_tax_display_cart'      => array(
				'type'    => 'select',
				'label'   => __( 'Display prices in cart & checkout', 'yazan' ),
				'choices' => array( 'incl' => 'Including tax', 'excl' => 'Excluding tax' ),
			),
			'woocommerce_price_display_suffix'  => array( 'type' => 'text', 'label' => __( 'Price display suffix', 'yazan' ) ),
		);
	}

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = Yazan_Dashboard_Auth::NS;
		$perm = Yazan_Dashboard_Auth::require_cap( self::CAP );

		register_rest_route(
			$ns,
			'/tax',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_settings' ), 'permission_callback' => $perm ),
			)
		);

		register_rest_route(
			$ns,
			'/tax/classes',
			array(
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_class' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'delete_class' ), 'permission_callback' => $perm ),
			)
		);

		register_rest_route(
			$ns,
			'/tax/rates',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rates' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_rate' ), 'permission_callback' => $perm ),
			)
		);

		register_rest_route(
			$ns,
			'/tax/rates/(?P<id>\d+)',
			array(
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_rate' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'delete_rate' ), 'permission_callback' => $perm ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /tax — options schema + values, and the list of tax classes.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$fields = array();
		foreach ( self::option_schema() as $option => $spec ) {
			$raw = get_option( $option, '' );
			$fields[] = array(
				'option'  => $option,
				'type'    => $spec['type'],
				'label'   => $spec['label'],
				'choices' => self::choices( $spec ),
				'value'   => 'bool_yesno' === $spec['type'] ? ( 'yes' === $raw ) : (string) $raw,
			);
		}

		return new WP_REST_Response(
			array(
				'fields'  => $fields,
				'classes' => self::class_list(),
				'enabled' => wc_tax_enabled(),
			),
			200
		);
	}

	/**
	 * PUT /tax — write whitelisted tax options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$incoming = (array) $request->get_param( 'settings' );
		if ( empty( $incoming ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}
		$schema = self::option_schema();
		$saved  = array();

		foreach ( $incoming as $option => $value ) {
			if ( ! isset( $schema[ $option ] ) ) {
				continue; // Not in the allow-list — ignore.
			}
			$spec = $schema[ $option ];

			if ( 'bool_yesno' === $spec['type'] ) {
				$clean = ( true === $value || 'yes' === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';
			} elseif ( 'select' === $spec['type'] ) {
				$choices = self::choices( $spec );
				$clean   = sanitize_text_field( (string) $value );
				if ( ! array_key_exists( $clean, $choices ) ) {
					return new WP_Error(
						'yazan_invalid',
						sprintf( /* translators: %s: field label */ __( 'Invalid value for %s.', 'yazan' ), $spec['label'] ),
						array( 'status' => 400 )
					);
				}
			} else {
				$clean = sanitize_text_field( (string) $value );
			}

			update_option( $option, $clean );
			$saved[] = $option;
		}

		if ( empty( $saved ) ) {
			return new WP_Error( 'yazan_invalid', __( 'None of those tax settings can be changed here.', 'yazan' ), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log( 'tax.settings', 'settings', 0, array( 'changed' => $saved ) );

		return self::index();
	}

	/* --------------------------------------------------------------------- */
	/* Tax classes                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /tax/classes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_class( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A tax class name is required.', 'yazan' ), array( 'status' => 400 ) );
		}
		$result = WC_Tax::create_tax_class( $name );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yazan_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Dashboard_Audit::log( 'tax.class_create', 'settings', 0, array( 'name' => $name ) );

		return new WP_REST_Response( array( 'classes' => self::class_list() ), 201 );
	}

	/**
	 * DELETE /tax/classes?slug=xxx — the Standard class cannot be removed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_class( WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'yazan_invalid', __( 'Standard rates cannot be deleted.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ) {
			return new WP_Error( 'yazan_not_found', __( 'Tax class not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$rate_count = count( WC_Tax::get_rates_for_tax_class( $slug ) );
		WC_Tax::delete_tax_class_by( 'slug', $slug );

		Yazan_Dashboard_Audit::log( 'tax.class_delete', 'settings', 0, array( 'slug' => $slug, 'rates_removed' => $rate_count ) );

		return new WP_REST_Response( array( 'classes' => self::class_list(), 'rates_removed' => $rate_count ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Tax rates                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /tax/rates?class=slug.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rates( WP_REST_Request $request ) {
		$class = self::clean_class( $request->get_param( 'class' ) );
		$rows  = array();

		foreach ( WC_Tax::get_rates_for_tax_class( $class ) as $rate ) {
			$rows[] = self::rate_payload( $rate );
		}

		return new WP_REST_Response( array( 'class' => $class, 'items' => $rows ), 200 );
	}

	/**
	 * POST /tax/rates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_rate( WP_REST_Request $request ) {
		$data = self::rate_from_request( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = WC_Tax::_insert_tax_rate( $data['rate'] );
		WC_Tax::_update_tax_rate_postcodes( $id, $data['postcode'] );
		WC_Tax::_update_tax_rate_cities( $id, $data['city'] );

		Yazan_Dashboard_Audit::log( 'tax.rate_create', 'settings', (int) $id, array( 'name' => $data['rate']['tax_rate_name'], 'rate' => $data['rate']['tax_rate'] ) );

		return new WP_REST_Response( array( 'id' => (int) $id ), 201 );
	}

	/**
	 * PUT /tax/rates/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_rate( WP_REST_Request $request ) {
		$id   = absint( $request['id'] );
		$data = self::rate_from_request( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		WC_Tax::_update_tax_rate( $id, $data['rate'] );
		WC_Tax::_update_tax_rate_postcodes( $id, $data['postcode'] );
		WC_Tax::_update_tax_rate_cities( $id, $data['city'] );

		Yazan_Dashboard_Audit::log( 'tax.rate_update', 'settings', $id, array( 'rate' => $data['rate']['tax_rate'] ) );

		return new WP_REST_Response( array( 'id' => $id ), 200 );
	}

	/**
	 * DELETE /tax/rates/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_rate( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		WC_Tax::_delete_tax_rate( $id );

		Yazan_Dashboard_Audit::log( 'tax.rate_delete', 'settings', $id );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Build + validate a rate row from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private static function rate_from_request( WP_REST_Request $request ) {
		$rate_value = $request->get_param( 'rate' );
		if ( ! is_numeric( $rate_value ) ) {
			return new WP_Error( 'yazan_invalid', __( 'The tax rate must be a number.', 'yazan' ), array( 'status' => 400 ) );
		}
		$rate_value = (float) $rate_value;
		if ( $rate_value < 0 || $rate_value > 100 ) {
			return new WP_Error( 'yazan_invalid', __( 'The tax rate must be between 0 and 100.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Country/state are stored uppercase; '*' means "any".
		$country = strtoupper( sanitize_text_field( (string) $request->get_param( 'country' ) ) );
		$state   = strtoupper( sanitize_text_field( (string) $request->get_param( 'state' ) ) );

		return array(
			'rate'     => array(
				'tax_rate_country'  => '*' === $country ? '' : $country,
				'tax_rate_state'    => '*' === $state ? '' : $state,
				'tax_rate'          => wc_format_decimal( $rate_value, 4 ),
				'tax_rate_name'     => sanitize_text_field( (string) ( $request->get_param( 'name' ) ?: __( 'Tax', 'yazan' ) ) ),
				'tax_rate_priority' => max( 1, absint( $request->get_param( 'priority' ) ?: 1 ) ),
				'tax_rate_compound' => wc_string_to_bool( $request->get_param( 'compound' ) ) ? 1 : 0,
				'tax_rate_shipping' => null === $request->get_param( 'shipping' ) || wc_string_to_bool( $request->get_param( 'shipping' ) ) ? 1 : 0,
				'tax_rate_order'    => absint( $request->get_param( 'order' ) ),
				'tax_rate_class'    => self::clean_class( $request->get_param( 'class' ) ),
			),
			'postcode' => self::split_list( $request->get_param( 'postcode' ) ),
			'city'     => self::split_list( $request->get_param( 'city' ) ),
		);
	}

	/**
	 * A rate row shaped for the UI, including its postcode/city locations.
	 *
	 * @param object $rate Rate row.
	 * @return array
	 */
	private static function rate_payload( $rate ) {
		global $wpdb;
		$id = (int) $rate->tax_rate_id;

		// Locations live in a side table; WC_Tax has no public getter for them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locations = $wpdb->get_results(
			$wpdb->prepare( "SELECT location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d", $id )
		);
		$postcode = array();
		$city     = array();
		foreach ( (array) $locations as $loc ) {
			if ( 'postcode' === $loc->location_type ) {
				$postcode[] = $loc->location_code;
			} elseif ( 'city' === $loc->location_type ) {
				$city[] = $loc->location_code;
			}
		}

		return array(
			'id'       => $id,
			'country'  => $rate->tax_rate_country ?: '*',
			'state'    => $rate->tax_rate_state ?: '*',
			'postcode' => implode( '; ', $postcode ),
			'city'     => implode( '; ', $city ),
			'rate'     => $rate->tax_rate,
			'name'     => $rate->tax_rate_name,
			'priority' => (int) $rate->tax_rate_priority,
			'compound' => (bool) $rate->tax_rate_compound,
			'shipping' => (bool) $rate->tax_rate_shipping,
			'class'    => $rate->tax_rate_class,
		);
	}

	/**
	 * Normalise a tax class slug ('' = Standard).
	 *
	 * @param mixed $raw Raw class.
	 * @return string
	 */
	private static function clean_class( $raw ) {
		$slug = sanitize_title( (string) $raw );
		return in_array( $slug, WC_Tax::get_tax_class_slugs(), true ) ? $slug : '';
	}

	/**
	 * Split a "A; B; C" or array input into a clean list.
	 *
	 * @param mixed $raw Raw value.
	 * @return string[]
	 */
	private static function split_list( $raw ) {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[;,\n]+/', (string) $raw );
		}
		$out = array();
		foreach ( (array) $parts as $part ) {
			$clean = strtoupper( trim( sanitize_text_field( (string) $part ) ) );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Standard + custom tax classes as {slug,name}.
	 *
	 * @return array
	 */
	private static function class_list() {
		$out = array( array( 'slug' => '', 'name' => __( 'Standard', 'yazan' ), 'rates' => count( WC_Tax::get_rates_for_tax_class( '' ) ) ) );
		foreach ( WC_Tax::get_tax_classes() as $name ) {
			$slug  = sanitize_title( $name );
			$out[] = array( 'slug' => $slug, 'name' => $name, 'rates' => count( WC_Tax::get_rates_for_tax_class( $slug ) ) );
		}
		return $out;
	}

	/**
	 * Resolve a field's choices, expanding the shipping tax-class token.
	 *
	 * @param array $spec Field spec.
	 * @return array
	 */
	private static function choices( array $spec ) {
		if ( ! isset( $spec['choices'] ) ) {
			return array();
		}
		if ( 'tax_classes_shipping' === $spec['choices'] ) {
			$out = array( 'inherit' => __( 'Shipping tax class based on cart items', 'yazan' ), '' => __( 'Standard', 'yazan' ) );
			foreach ( WC_Tax::get_tax_classes() as $name ) {
				$out[ sanitize_title( $name ) ] = $name;
			}
			return $out;
		}
		return (array) $spec['choices'];
	}
}
