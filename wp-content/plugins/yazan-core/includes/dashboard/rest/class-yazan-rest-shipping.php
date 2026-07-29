<?php
/**
 * Yazan Dashboard — shipping zones, regions and methods.
 *
 * Uses the WC_Shipping_Zones / WC_Shipping_Zone API so zone ordering, the "Rest of the world"
 * fallback zone (id 0) and method instance settings all behave exactly as in wp-admin.
 *
 * Method settings are written through the instance settings option
 * (`woocommerce_{method_id}_{instance_id}_settings`) after being filtered against the method's OWN
 * declared form fields — so a caller can only set keys the method actually defines.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /shipping endpoints.
 */
class Yazan_REST_Shipping {

	/** Shipping configuration is a store-owner level action. */
	const CAP = 'manage_woocommerce';

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
			'/shipping/zones',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_zone' ), 'permission_callback' => $perm ),
			)
		);

		register_rest_route(
			$ns,
			'/shipping/zones/(?P<id>\d+)',
			array(
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_zone' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'delete_zone' ), 'permission_callback' => $perm ),
			)
		);

		register_rest_route(
			$ns,
			'/shipping/zones/(?P<id>\d+)/methods',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_method' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/shipping/zones/(?P<id>\d+)/methods/(?P<instance>\d+)',
			array(
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_method' ), 'permission_callback' => $perm ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'delete_method' ), 'permission_callback' => $perm ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Zones                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /shipping/zones — every zone plus the "Rest of the world" fallback.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $raw ) {
			$zones[] = self::zone_payload( WC_Shipping_Zones::get_zone( $raw['id'] ) );
		}
		// Zone 0 always exists and cannot be deleted — it catches everything unmatched.
		$zones[] = self::zone_payload( WC_Shipping_Zones::get_zone( 0 ) );

		$available = array();
		foreach ( WC()->shipping()->get_shipping_method_class_names() as $id => $class ) {
			$method      = new $class();
			$available[] = array(
				'id'          => $id,
				'title'       => $method->get_method_title(),
				'description' => $method->get_method_description(),
			);
		}

		return new WP_REST_Response(
			array(
				'zones'             => $zones,
				'available_methods' => $available,
				'countries'         => WC()->countries->get_countries(),
				'continents'        => wp_list_pluck( WC()->countries->get_continents(), 'name' ),
			),
			200
		);
	}

	/**
	 * POST /shipping/zones.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_zone( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A zone name is required.', 'yazan' ), array( 'status' => 400 ) );
		}

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		self::apply_regions( $zone, $request->get_param( 'regions' ) );
		$zone->save();

		Yazan_Dashboard_Audit::log( 'shipping.zone_create', 'settings', $zone->get_id(), array( 'name' => $name ) );

		return new WP_REST_Response( self::zone_payload( $zone ), 201 );
	}

	/**
	 * PUT /shipping/zones/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_zone( WP_REST_Request $request ) {
		$zone = self::load_zone( $request['id'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		if ( null !== $request->get_param( 'name' ) ) {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === trim( $name ) ) {
				return new WP_Error( 'yazan_invalid', __( 'A zone name is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			// Zone 0's name is a WooCommerce label, not editable.
			if ( 0 !== $zone->get_id() ) {
				$zone->set_zone_name( $name );
			}
		}
		if ( null !== $request->get_param( 'regions' ) && 0 !== $zone->get_id() ) {
			self::apply_regions( $zone, $request->get_param( 'regions' ) );
		}
		$zone->save();

		Yazan_Dashboard_Audit::log( 'shipping.zone_update', 'settings', $zone->get_id() );

		return new WP_REST_Response( self::zone_payload( WC_Shipping_Zones::get_zone( $zone->get_id() ) ), 200 );
	}

	/**
	 * DELETE /shipping/zones/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_zone( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( 0 === $id ) {
			return new WP_Error(
				'yazan_protected',
				__( '“Rest of the world” is WooCommerce\'s fallback zone and cannot be deleted.', 'yazan' ),
				array( 'status' => 409 )
			);
		}
		$zone = self::load_zone( $id );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$name = $zone->get_zone_name();
		$zone->delete( true );

		Yazan_Dashboard_Audit::log( 'shipping.zone_delete', 'settings', $id, array( 'name' => $name ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Methods                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /shipping/zones/{id}/methods — attach a shipping method to a zone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_method( WP_REST_Request $request ) {
		$zone = self::load_zone( $request['id'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$method_id = sanitize_key( (string) $request->get_param( 'method_id' ) );
		if ( ! array_key_exists( $method_id, WC()->shipping()->get_shipping_method_class_names() ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Unknown shipping method.', 'yazan' ), array( 'status' => 400 ) );
		}

		$instance_id = $zone->add_shipping_method( $method_id );
		if ( ! $instance_id ) {
			return new WP_Error( 'yazan_failed', __( 'Could not add that shipping method.', 'yazan' ), array( 'status' => 500 ) );
		}
		$zone->save();

		Yazan_Dashboard_Audit::log( 'shipping.method_add', 'settings', $zone->get_id(), array( 'method' => $method_id, 'instance' => $instance_id ) );

		return new WP_REST_Response( self::zone_payload( WC_Shipping_Zones::get_zone( $zone->get_id() ) ), 201 );
	}

	/**
	 * PUT /shipping/zones/{id}/methods/{instance} — enable/disable or configure a method.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_method( WP_REST_Request $request ) {
		$zone = self::load_zone( $request['id'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$instance_id = absint( $request['instance'] );
		$method      = self::find_method( $zone, $instance_id );
		if ( ! $method ) {
			return new WP_Error( 'yazan_not_found', __( 'That shipping method is not on this zone.', 'yazan' ), array( 'status' => 404 ) );
		}

		if ( null !== $request->get_param( 'enabled' ) ) {
			$enabled = wc_string_to_bool( $request->get_param( 'enabled' ) );
			// WooCommerce stores this on the zone_methods row, not in the settings option.
			WC_Shipping_Zones::get_zone( $zone->get_id() ); // ensure loaded
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'woocommerce_shipping_zone_methods',
				array( 'is_enabled' => $enabled ? 1 : 0 ),
				array( 'instance_id' => $instance_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			// Only keys the method itself declares are accepted.
			$allowed = array_keys( $method->get_instance_form_fields() );
			$current = $method->instance_settings;
			foreach ( $settings as $key => $value ) {
				$key = sanitize_key( $key );
				if ( ! in_array( $key, $allowed, true ) ) {
					continue;
				}
				$current[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
			update_option( $method->get_instance_option_key(), $current );
		}

		// Bust WooCommerce's shipping caches so the storefront sees the change immediately.
		WC_Cache_Helper::get_transient_version( 'shipping', true );

		Yazan_Dashboard_Audit::log( 'shipping.method_update', 'settings', $zone->get_id(), array( 'instance' => $instance_id ) );

		return new WP_REST_Response( self::zone_payload( WC_Shipping_Zones::get_zone( $zone->get_id() ) ), 200 );
	}

	/**
	 * DELETE /shipping/zones/{id}/methods/{instance}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_method( WP_REST_Request $request ) {
		$zone = self::load_zone( $request['id'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$instance_id = absint( $request['instance'] );
		if ( ! self::find_method( $zone, $instance_id ) ) {
			return new WP_Error( 'yazan_not_found', __( 'That shipping method is not on this zone.', 'yazan' ), array( 'status' => 404 ) );
		}

		$zone->delete_shipping_method( $instance_id );
		WC_Cache_Helper::get_transient_version( 'shipping', true );

		Yazan_Dashboard_Audit::log( 'shipping.method_delete', 'settings', $zone->get_id(), array( 'instance' => $instance_id ) );

		return new WP_REST_Response( self::zone_payload( WC_Shipping_Zones::get_zone( $zone->get_id() ) ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Zone + its methods, shaped for the UI.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return array
	 */
	private static function zone_payload( WC_Shipping_Zone $zone ) {
		$methods = array();
		foreach ( $zone->get_shipping_methods( false ) as $instance_id => $method ) {
			$fields = array();
			foreach ( $method->get_instance_form_fields() as $key => $field ) {
				// Skip pure presentation fields; they carry no value.
				if ( in_array( $field['type'] ?? 'text', array( 'title' ), true ) ) {
					continue;
				}
				$fields[] = array(
					'key'         => $key,
					'label'       => $field['title'] ?? $key,
					'type'        => $field['type'] ?? 'text',
					'description' => wp_strip_all_tags( (string) ( $field['description'] ?? '' ) ),
					'options'     => $field['options'] ?? null,
					'value'       => $method->get_option( $key ),
				);
			}

			$methods[] = array(
				'instance_id' => (int) $instance_id,
				'method_id'   => $method->id,
				'title'       => $method->get_title(),
				'method_title' => $method->get_method_title(),
				'enabled'     => 'yes' === $method->enabled,
				'fields'      => $fields,
			);
		}

		$regions = array();
		foreach ( $zone->get_zone_locations() as $loc ) {
			$regions[] = array( 'type' => $loc->type, 'code' => $loc->code );
		}

		return array(
			'id'          => $zone->get_id(),
			'name'        => $zone->get_zone_name(),
			'order'       => $zone->get_zone_order(),
			'is_fallback' => 0 === $zone->get_id(),
			'regions'     => $regions,
			'region_text' => $zone->get_formatted_location(),
			'methods'     => $methods,
		);
	}

	/**
	 * Replace a zone's regions from a payload of {type, code} entries.
	 *
	 * @param WC_Shipping_Zone $zone    Zone.
	 * @param mixed            $regions Raw regions.
	 * @return void
	 */
	private static function apply_regions( WC_Shipping_Zone $zone, $regions ) {
		if ( ! is_array( $regions ) ) {
			return;
		}
		$zone->clear_locations();
		foreach ( $regions as $region ) {
			$type = sanitize_key( $region['type'] ?? 'country' );
			$code = sanitize_text_field( (string) ( $region['code'] ?? '' ) );
			if ( '' === $code || ! in_array( $type, array( 'country', 'state', 'continent', 'postcode' ), true ) ) {
				continue;
			}
			$zone->add_location( $code, $type );
		}
	}

	/**
	 * Find a method instance on a zone.
	 *
	 * @param WC_Shipping_Zone $zone        Zone.
	 * @param int              $instance_id Instance id.
	 * @return WC_Shipping_Method|null
	 */
	private static function find_method( WC_Shipping_Zone $zone, $instance_id ) {
		foreach ( $zone->get_shipping_methods( false ) as $id => $method ) {
			if ( (int) $id === (int) $instance_id ) {
				return $method;
			}
		}
		return null;
	}

	/**
	 * Load a zone by id (0 is valid — the fallback zone).
	 *
	 * @param mixed $id Raw id.
	 * @return WC_Shipping_Zone|WP_Error
	 */
	private static function load_zone( $id ) {
		$zone = WC_Shipping_Zones::get_zone( absint( $id ) );
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return new WP_Error( 'yazan_not_found', __( 'Shipping zone not found.', 'yazan' ), array( 'status' => 404 ) );
		}
		return $zone;
	}
}
