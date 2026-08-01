<?php
/**
 * Yazan Dashboard — payment gateways.
 *
 * SCOPE, DELIBERATELY LIMITED: this manages which gateways are on, their order, and the title /
 * description customers see. It does **not** expose or write gateway CREDENTIALS (API keys, secrets,
 * webhook tokens, merchant ids).
 *
 * That is a security decision, not an omission. Credentials are long-lived bearer secrets: surfacing
 * them through a second REST surface widens the blast radius of any dashboard compromise, and each
 * gateway validates its own keys through flows (OAuth handshakes, webhook registration) that a
 * generic settings form would silently break. Connect gateways in wp-admin once; run them from here.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /gateways endpoints.
 */
class Yazan_REST_Gateways {

	/** Payment configuration is a store-owner level action. */
	const CAP = 'manage_woocommerce';

	/**
	 * Setting keys that may be written per gateway. Anything not listed — every credential field —
	 * is unreachable.
	 */
	const EDITABLE_KEYS = array( 'title', 'description', 'instructions' );

	/**
	 * Substrings that mark a field as secret, so it is never echoed back in a response.
	 */
	const SECRET_HINTS = array( 'key', 'secret', 'token', 'password', 'client_id', 'merchant', 'signature', 'certificate', 'api' );

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/gateways',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'gateways.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'reorder' ), 'gateways.edit' ),
			)
		);

		register_rest_route(
			$ns,
			'/gateways/(?P<id>[a-z0-9_\-]+)',
			Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'gateways.edit' )
		);
	}

	/**
	 * GET /gateways.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$items = array();
		$order = (array) get_option( 'woocommerce_gateway_order', array() );

		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$items[] = array(
				'id'           => $id,
				'title'        => $gateway->get_title(),
				'method_title' => $gateway->get_method_title(),
				'description'  => $gateway->get_description(),
				'instructions' => $gateway->get_option( 'instructions', '' ),
				'has_instructions' => array_key_exists( 'instructions', $gateway->get_form_fields() ),
				'enabled'      => 'yes' === $gateway->enabled,
				'order'        => isset( $order[ $id ] ) ? (int) $order[ $id ] : 999,
				'supports_refunds' => $gateway->supports( 'refunds' ),
				// Whether the gateway looks configured, without ever revealing the values.
				'needs_setup'  => method_exists( $gateway, 'needs_setup' ) ? (bool) $gateway->needs_setup() : false,
				'settings_url' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . strtolower( $id ) ),
				'has_credentials' => self::has_secret_fields( $gateway ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * PUT /gateways/{id} — enable/disable and customer-facing copy only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$id       = sanitize_text_field( (string) $request['id'] );
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $id ] ) ) {
			return new WP_Error( 'yazan_not_found', __( 'Payment gateway not found.', 'yazan' ), array( 'status' => 404 ) );
		}
		$gateway = $gateways[ $id ];

		$option_key = 'woocommerce_' . $id . '_settings';
		$settings   = (array) get_option( $option_key, array() );
		$changed    = array();

		if ( null !== $request->get_param( 'enabled' ) ) {
			$enable = wc_string_to_bool( $request->get_param( 'enabled' ) );

			// Refuse to switch on a gateway that has not been connected yet — it would appear at
			// checkout and fail on the customer.
			if ( $enable && method_exists( $gateway, 'needs_setup' ) && $gateway->needs_setup() ) {
				return new WP_Error(
					'yazan_needs_setup',
					sprintf(
						/* translators: %s: gateway name. */
						__( '“%s” is not connected yet. Finish its setup in WooCommerce → Settings → Payments before enabling it.', 'yazan' ),
						$gateway->get_method_title()
					),
					array( 'status' => 409 )
				);
			}

			$settings['enabled'] = $enable ? 'yes' : 'no';
			$changed['enabled']  = $enable ? 'yes' : 'no';
		}

		foreach ( self::EDITABLE_KEYS as $key ) {
			if ( null === $request->get_param( $key ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $gateway->get_form_fields() ) ) {
				continue; // This gateway has no such field.
			}
			$settings[ $key ] = 'title' === $key
				? sanitize_text_field( (string) $request->get_param( $key ) )
				: sanitize_textarea_field( (string) $request->get_param( $key ) );
			$changed[ $key ]  = 'updated';
		}

		if ( empty( $changed ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to change.', 'yazan' ), array( 'status' => 400 ) );
		}

		update_option( $option_key, $settings );

		Yazan_Dashboard_Audit::log( 'gateway.update', 'settings', 0, array( 'gateway' => $id, 'changed' => array_keys( $changed ) ) );

		return self::index();
	}

	/**
	 * PUT /gateways — persist display order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reorder( WP_REST_Request $request ) {
		$ids = (array) $request->get_param( 'order' );
		if ( empty( $ids ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Send the gateway ids in their new order.', 'yazan' ), array( 'status' => 400 ) );
		}

		$known = array_keys( WC()->payment_gateways()->payment_gateways() );
		$order = array();
		$i     = 0;
		foreach ( $ids as $id ) {
			$id = sanitize_text_field( (string) $id );
			if ( in_array( $id, $known, true ) ) {
				$order[ $id ] = $i++;
			}
		}

		update_option( 'woocommerce_gateway_order', $order );
		Yazan_Dashboard_Audit::log( 'gateway.reorder', 'settings', 0, array( 'count' => count( $order ) ) );

		return self::index();
	}

	/**
	 * Whether a gateway declares any credential-looking field (so the UI can point at wp-admin).
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return bool
	 */
	private static function has_secret_fields( $gateway ) {
		foreach ( array_keys( (array) $gateway->get_form_fields() ) as $key ) {
			foreach ( self::SECRET_HINTS as $hint ) {
				if ( false !== strpos( strtolower( $key ), $hint ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
