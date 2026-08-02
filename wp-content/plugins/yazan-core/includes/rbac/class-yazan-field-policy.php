<?php
/**
 * Field-level permissions — which fields a response may carry.
 *
 * WHY CENTRAL AND NOT PER-CONTROLLER
 * ----------------------------------
 * The codebase already has the inbound half of this idea: `Yazan_REST_Products` strips
 * `regular_price` from an incoming payload when the caller lacks `products.change_price`, and drops
 * it silently rather than failing the whole batch. This is the outbound mirror.
 *
 * It is applied in ONE place — a filter over every REST response — rather than as a condition
 * inside each controller, for a reason that is not tidiness: a controller-by-controller rule has to
 * be remembered by whoever adds the next endpoint, and the failure mode of forgetting is a silent
 * data leak that no test written today would catch. A central filter covers routes that do not
 * exist yet.
 *
 * WHAT IT IS NOT
 * --------------
 * It is not a substitute for route permissions. A caller who cannot reach `/orders` at all is
 * refused by the guard long before this runs. This governs the finer question of what a caller who
 * MAY read a record is allowed to see inside it — the transaction id, the customer's address, the
 * lifetime value.
 *
 * ⚠️ REDACT BOTH THE STRUCTURED VALUE AND ITS RENDERED TWIN. WooCommerce hands back
 * `billing_formatted` alongside `billing`; removing the object and leaving the string is not
 * redaction, it is the appearance of redaction. Every entry below that has a rendered sibling
 * lists both.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips fields the caller may not read.
 */
class Yazan_Field_Policy {

	/**
	 * Field name => permission required to see it.
	 *
	 * Matched by KEY NAME anywhere in the response tree, at any depth. That is deliberately blunt:
	 * an order's `transaction_id` and a payment event's `transaction_id` are the same secret, and a
	 * path-based rule would have to enumerate every shape that carries it — including the ones added
	 * next month.
	 *
	 * The cost of bluntness is a false positive: an unrelated field that happens to share a name is
	 * redacted too. Every key here was chosen to be specific enough that this is unlikely, and the
	 * failure direction is "an admin sees a dash" rather than "a leak".
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		$map = array(
			/*
			 * Payment identifiers. A transaction id is the handle to a real money movement in the
			 * gateway's dashboard; reading an order should not imply reading that.
			 */
			'transaction_id'      => 'orders.view_payment',

			/*
			 * Customer PII. `orders.view` is held by Sales, Customer Service and Marketing — roles
			 * that need to see that an order exists without needing the customer's home address.
			 */
			'billing'             => 'customers.view_pii',
			'billing_formatted'   => 'customers.view_pii',
			'shipping'            => 'customers.view_pii',
			'shipping_formatted'  => 'customers.view_pii',
			'billing_email'       => 'customers.view_pii',
			'billing_phone'       => 'customers.view_pii',

			/*
			 * Commercial figures. Lifetime value and unit sales are the numbers a competitor would
			 * want, and they ride along in list payloads that many roles can reach.
			 */
			'total_spent'         => 'reports.view',
			'total_sales'         => 'reports.view',

			/*
			 * The authenticity-certificate serial. It is the thing a counterfeit would need to
			 * clone, and it was being emitted on every product list row.
			 */
			'edit_serial'         => 'products.view_serial',
		);

		/**
		 * Filter the sensitive-field map.
		 *
		 * A module adds its own fields here rather than editing this class.
		 *
		 * @param array<string,string> $map field name => required permission slug.
		 */
		return (array) apply_filters( 'yazan_sensitive_fields', $map );
	}

	/**
	 * Hook registration.
	 *
	 * `rest_post_dispatch` runs after the handler and before serialisation, so it sees every
	 * response from every route — including routes registered by plugins that know nothing about
	 * this class.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_response' ), 10, 3 );
	}

	/**
	 * Remove fields the current user may not read.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function filter_response( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! class_exists( 'Yazan_Permissions' ) ) {
			return $response;
		}

		// Only our own surfaces. WooCommerce's and WordPress's own routes have their own rules, and
		// silently reshaping another plugin's contract is how integrations break mysteriously.
		$route = (string) $request->get_route();

		if ( 0 !== strpos( $route, '/yazan/' ) && 0 !== strpos( $route, '/yazan-' ) ) {
			return $response;
		}

		$blocked = self::blocked_fields();

		if ( array() === $blocked ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$response->set_data( self::strip( $data, $blocked ) );

		return $response;
	}

	/**
	 * The field names the current user may NOT see, resolved once per request.
	 *
	 * Memoised because a list response walks hundreds of nodes and each would otherwise re-ask the
	 * permission API. The memo is keyed by user and store so it cannot survive a
	 * `Yazan_Store_Context::run_for()` switch.
	 *
	 * @return array<string,true>
	 */
	private static function blocked_fields() {
		static $memo = array();

		$user  = get_current_user_id();
		$store = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
		$key   = $user . '|' . $store;

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$blocked = array();

		foreach ( self::map() as $field => $permission ) {
			if ( ! Yazan_Permissions::can( $permission, $user, $store ) ) {
				$blocked[ $field ] = true;
			}
		}

		$memo[ $key ] = $blocked;

		return $blocked;
	}

	/**
	 * Recursively drop blocked keys.
	 *
	 * Keys are REMOVED rather than nulled. A null would tell the client the field exists and is
	 * empty, which is a different and wrong statement — and a UI that renders "Phone: —" for a
	 * redacted value looks like missing data rather than withheld data.
	 *
	 * @param mixed                $data    Response fragment.
	 * @param array<string,true>   $blocked Field names to drop.
	 * @param int                  $depth   Recursion guard.
	 * @return mixed
	 */
	private static function strip( $data, array $blocked, $depth = 0 ) {
		// Response trees are shallow; anything deeper is a cycle or a payload no UI reads.
		if ( $depth > 12 || ! is_array( $data ) ) {
			return $data;
		}

		$out = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && isset( $blocked[ $key ] ) ) {
				continue;
			}

			$out[ $key ] = is_array( $value ) ? self::strip( $value, $blocked, $depth + 1 ) : $value;
		}

		return $out;
	}
}
