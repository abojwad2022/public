<?php
/**
 * Yazan Dashboard — settings (Phase 4).
 *
 * Exposes a curated set of WooCommerce + Yazan options. The critical design point is the REGISTRY
 * below: it is a strict allow-list. A request can only write an option that appears in it, and each
 * value is coerced by its declared type (and validated against `choices` where present). There is no
 * code path that lets a caller name an arbitrary option — which is what makes a generic
 * "settings" endpoint safe.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /settings endpoints.
 */
class Yazan_REST_Settings {

	/** Managing store settings is a WooCommerce-admin level action. */
	const CAP = 'manage_woocommerce';

	/**
	 * The allow-list. Keys are real option names; nothing outside this map is readable or writable.
	 *
	 * type: text | textarea | email | number | bool_yesno | select
	 *
	 * @return array<string,array<string,array>>
	 */
	public static function registry() {
		return array(
			'store' => array(
				'label'  => __( 'Store', 'yazan' ),
				'fields' => array(
					'woocommerce_store_address'   => array( 'type' => 'text', 'label' => __( 'Address', 'yazan' ) ),
					'woocommerce_store_city'      => array( 'type' => 'text', 'label' => __( 'City', 'yazan' ) ),
					'woocommerce_store_postcode'  => array( 'type' => 'text', 'label' => __( 'Postcode', 'yazan' ) ),
					'woocommerce_default_country' => array( 'type' => 'text', 'label' => __( 'Country / region code', 'yazan' ), 'help' => __( 'e.g. YE or YE:SA', 'yazan' ) ),
					'woocommerce_currency'        => array( 'type' => 'select', 'label' => __( 'Currency', 'yazan' ), 'choices' => 'currencies' ),
					'woocommerce_currency_pos'    => array(
						'type'    => 'select',
						'label'   => __( 'Currency position', 'yazan' ),
						'choices' => array( 'left' => 'left', 'right' => 'right', 'left_space' => 'left with space', 'right_space' => 'right with space' ),
					),
					'woocommerce_price_num_decimals' => array( 'type' => 'number', 'label' => __( 'Decimals', 'yazan' ), 'min' => 0, 'max' => 4 ),
					'woocommerce_weight_unit'     => array(
						'type'    => 'select',
						'label'   => __( 'Weight unit', 'yazan' ),
						'choices' => array( 'kg' => 'kg', 'g' => 'g', 'lbs' => 'lbs', 'oz' => 'oz' ),
					),
					'woocommerce_dimension_unit'  => array(
						'type'    => 'select',
						'label'   => __( 'Dimension unit', 'yazan' ),
						'choices' => array( 'm' => 'm', 'cm' => 'cm', 'mm' => 'mm', 'in' => 'in', 'yd' => 'yd' ),
					),
				),
			),

			'inventory' => array(
				'label'  => __( 'Inventory', 'yazan' ),
				'fields' => array(
					'woocommerce_manage_stock'            => array( 'type' => 'bool_yesno', 'label' => __( 'Manage stock', 'yazan' ), 'help' => __( 'Track quantity for products by default.', 'yazan' ) ),
					'woocommerce_notify_low_stock_amount' => array( 'type' => 'number', 'label' => __( 'Low stock threshold', 'yazan' ), 'min' => 0 ),
					'woocommerce_notify_no_stock_amount'  => array( 'type' => 'number', 'label' => __( 'Out of stock threshold', 'yazan' ), 'min' => 0 ),
					'woocommerce_hide_out_of_stock_items' => array( 'type' => 'bool_yesno', 'label' => __( 'Hide out-of-stock items from the catalogue', 'yazan' ) ),
					'woocommerce_stock_format'            => array(
						'type'    => 'select',
						'label'   => __( 'Stock display format', 'yazan' ),
						'choices' => array( '' => 'Always show quantity', 'low_amount' => 'Only show when low', 'no_amount' => 'Never show quantity' ),
					),
				),
			),

			'notifications' => array(
				'label'  => __( 'Order alerts', 'yazan' ),
				'fields' => array(
					'yazan_owner_alert_email' => array( 'type' => 'email', 'label' => __( 'Owner alert email', 'yazan' ), 'help' => __( 'New-order emails are forced to this address. Blank falls back to the site admin email.', 'yazan' ) ),
					'yazan_owner_alert_phone' => array( 'type' => 'text', 'label' => __( 'Owner phone (SMS)', 'yazan' ) ),
					'yazan_sms_enabled'       => array( 'type' => 'bool_yesno', 'label' => __( 'Enable SMS alerts', 'yazan' ), 'help' => __( 'Requires an SMS gateway wired to the yazan_sms_gateway filter — off, nothing is sent.', 'yazan' ) ),
				),
			),

			'printing' => array(
				'label'  => __( 'Invoice printing', 'yazan' ),
				'fields' => array(
					'yazan_autoprint_enabled' => array( 'type' => 'bool_yesno', 'label' => __( 'Auto-print invoice when an order completes', 'yazan' ) ),
					'yazan_autoprint_format'  => array(
						'type'    => 'select',
						'label'   => __( 'Paper format', 'yazan' ),
						'choices' => array( 'a4' => 'A4', 'thermal' => 'Thermal receipt' ),
					),
				),
			),
		);
	}

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/settings',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'settings.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'settings.edit' ),
			)
		);
	}

	/**
	 * GET /settings — the schema plus current values.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$groups = array();

		foreach ( self::registry() as $key => $group ) {
			$fields = array();
			foreach ( $group['fields'] as $option => $spec ) {
				$fields[] = array(
					'option'  => $option,
					'type'    => $spec['type'],
					'label'   => $spec['label'],
					'help'    => $spec['help'] ?? '',
					'choices' => self::resolve_choices( $spec ),
					'min'     => $spec['min'] ?? null,
					'max'     => $spec['max'] ?? null,
					'value'   => self::read( $option, $spec ),
				);
			}
			$groups[] = array( 'key' => $key, 'label' => $group['label'], 'fields' => $fields );
		}

		return new WP_REST_Response( array( 'groups' => $groups ), 200 );
	}

	/**
	 * PUT /settings — write only the options present in the payload AND in the registry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$incoming = (array) $request->get_param( 'settings' );
		if ( empty( $incoming ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}

		// Flatten the registry into option => spec for lookup.
		$allowed = array();
		foreach ( self::registry() as $group ) {
			foreach ( $group['fields'] as $option => $spec ) {
				$allowed[ $option ] = $spec;
			}
		}

		$saved    = array();
		$rejected = array();

		foreach ( $incoming as $option => $value ) {
			$option = (string) $option;
			if ( ! isset( $allowed[ $option ] ) ) {
				// Silently ignoring would hide mistakes; report it back instead.
				$rejected[] = $option;
				continue;
			}
			$clean = self::sanitize( $value, $allowed[ $option ] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			update_option( $option, $clean );
			$saved[ $option ] = $clean;
		}

		if ( empty( $saved ) && $rejected ) {
			return new WP_Error(
				'yazan_unknown_settings',
				__( 'None of those settings can be changed from the dashboard.', 'yazan' ),
				array( 'status' => 400, 'rejected' => $rejected )
			);
		}

		Yazan_Dashboard_Audit::log( 'settings.update', 'settings', 0, array( 'changed' => array_keys( $saved ) ) );

		$response             = self::index()->get_data();
		$response['saved']    = array_keys( $saved );
		$response['rejected'] = $rejected;

		return new WP_REST_Response( $response, 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Current value of an option, normalised for the UI.
	 *
	 * @param string $option Option name.
	 * @param array  $spec   Field spec.
	 * @return mixed
	 */
	private static function read( $option, array $spec ) {
		$raw = get_option( $option, '' );

		if ( 'bool_yesno' === $spec['type'] ) {
			return in_array( $raw, array( 'yes', '1', 1, true ), true );
		}
		if ( 'number' === $spec['type'] ) {
			return '' === $raw ? '' : (string) $raw;
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Coerce and validate an incoming value against its declared type.
	 *
	 * @param mixed $value Raw value.
	 * @param array $spec  Field spec.
	 * @return mixed|WP_Error
	 */
	private static function sanitize( $value, array $spec ) {
		switch ( $spec['type'] ) {
			case 'bool_yesno':
				// WooCommerce stores these as the strings 'yes'/'no'.
				return ( true === $value || 'yes' === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';

			case 'email':
				$email = sanitize_email( (string) $value );
				if ( '' !== trim( (string) $value ) && ! is_email( $email ) ) {
					return new WP_Error( 'yazan_invalid', __( 'That email address is not valid.', 'yazan' ), array( 'status' => 400 ) );
				}
				return $email;

			case 'number':
				if ( '' === $value || null === $value ) {
					return '';
				}
				$number = is_numeric( $value ) ? $value + 0 : null;
				if ( null === $number ) {
					return new WP_Error( 'yazan_invalid', __( 'That value must be a number.', 'yazan' ), array( 'status' => 400 ) );
				}
				if ( isset( $spec['min'] ) && $number < $spec['min'] ) {
					$number = $spec['min'];
				}
				if ( isset( $spec['max'] ) && $number > $spec['max'] ) {
					$number = $spec['max'];
				}
				return (string) $number;

			case 'select':
				$choices = self::resolve_choices( $spec );
				$clean   = sanitize_text_field( (string) $value );
				if ( ! array_key_exists( $clean, $choices ) ) {
					return new WP_Error(
						'yazan_invalid',
						sprintf(
							/* translators: %s: field label. */
							__( 'That is not an allowed value for %s.', 'yazan' ),
							$spec['label']
						),
						array( 'status' => 400 )
					);
				}
				return $clean;

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Resolve a field's choice list, expanding the special 'currencies' token.
	 *
	 * @param array $spec Field spec.
	 * @return array<string,string>
	 */
	private static function resolve_choices( array $spec ) {
		if ( ! isset( $spec['choices'] ) ) {
			return array();
		}
		if ( 'currencies' === $spec['choices'] ) {
			return function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : array();
		}
		return (array) $spec['choices'];
	}
}
