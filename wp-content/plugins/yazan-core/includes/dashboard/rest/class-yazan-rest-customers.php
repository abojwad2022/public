<?php
/**
 * Yazan Dashboard — customers (read-only).
 *
 * Currently scoped to what order creation needs: searching WordPress users to attach an order to,
 * plus their WooCommerce order history summary. Full customer management is a later phase.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /customers endpoints.
 */
class Yazan_REST_Customers {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns   = Yazan_Dashboard_Auth::NS;
		$perm = Yazan_Dashboard_Auth::require_cap( 'edit_shop_orders' );

		register_rest_route(
			$ns,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'show' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * GET /customers — search users by name/email/login.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$role     = sanitize_key( (string) $request->get_param( 'role' ) );

		// Order stats cost a couple of queries per row, so the order-picker (which does not
		// need them) can leave them off; the Customers screen asks for them explicitly.
		$with_stats = in_array( $request->get_param( 'stats' ), array( '1', 1, true, 'true' ), true );

		$orderby_map = array(
			'name'       => 'display_name',
			'registered' => 'user_registered',
			'email'      => 'user_email',
		);
		$orderby = $orderby_map[ (string) $request->get_param( 'orderby' ) ] ?? 'display_name';
		$order   = 'desc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'DESC' : 'ASC';

		$args = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => $orderby,
			'order'   => $order,
			'fields'  => 'all',
		);
		if ( '' !== $search ) {
			// Wildcard search across the useful identity columns.
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}
		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		$query = new WP_User_Query( $args );
		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = self::payload( $user, $with_stats );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->get_total(),
				'total_pages' => (int) ceil( $query->get_total() / $per_page ),
				'page'        => $page,
			),
			200
		);
	}

	/**
	 * GET /customers/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$user = get_userdata( absint( $request['id'] ) );
		if ( ! $user ) {
			return new WP_Error( 'yazan_not_found', __( 'Customer not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$data                = self::payload( $user, true );
		$customer            = new WC_Customer( $user->ID );
		$data['billing']     = $customer->get_billing();
		$data['shipping']    = $customer->get_shipping();
		$data['currency_symbol'] = html_entity_decode( get_woocommerce_currency_symbol() );

		$last = wc_get_customer_last_order( $user->ID );
		$data['last_order'] = $last instanceof WC_Order
			? array(
				'id'     => $last->get_id(),
				'number' => $last->get_order_number(),
				'date'   => $last->get_date_created() ? $last->get_date_created()->date( 'Y-m-d' ) : '',
				'status' => $last->get_status(),
				'total'  => wc_format_decimal( $last->get_total(), wc_get_price_decimals() ),
			)
			: null;

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Public-safe user summary.
	 *
	 * @param WP_User $user       User.
	 * @param bool    $with_stats Include WooCommerce order count / lifetime spend.
	 * @return array
	 */
	private static function payload( $user, $with_stats = false ) {
		$data = array(
			'id'         => $user->ID,
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'login'      => $user->user_login,
			'roles'      => array_values( (array) $user->roles ),
			'avatar'     => get_avatar_url( $user->ID, array( 'size' => 40 ) ),
			'registered' => $user->user_registered ? gmdate( 'Y-m-d', strtotime( $user->user_registered ) ) : '',
		);

		if ( $with_stats ) {
			$data['order_count'] = (int) wc_get_customer_order_count( $user->ID );
			$data['total_spent'] = wc_format_decimal( wc_get_customer_total_spent( $user->ID ), wc_get_price_decimals() );
		}

		return $data;
	}
}
