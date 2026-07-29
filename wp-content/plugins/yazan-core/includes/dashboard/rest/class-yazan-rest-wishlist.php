<?php
/**
 * Yazan — customer wishlist REST controller.
 *
 * Per-customer saved products ("favourites"), stored in the `_yazan_wishlist` user-meta as an array of
 * product ids. Signed-in shoppers only; the owner is ALWAYS derived from get_current_user_id()
 * server-side (never a client-supplied id). Cookie-auth from the storefront requires a valid wp_rest
 * nonce in the X-WP-Nonce header, else core treats the caller as logged-out.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wishlist storage + REST.
 */
class Yazan_REST_Wishlist {

	/** User-meta key: an array of product ids (follows the plugin's private-meta underscore convention). */
	const META = '_yazan_wishlist';

	/** Max saved items per customer. */
	const MAX = 100;

	/**
	 * Register the two customer routes.
	 */
	public static function register_routes() {
		$ns   = Yazan_Dashboard_Auth::NS;
		$auth = static function () {
			return is_user_logged_in()
				? true
				: new WP_Error( 'yazan_auth', __( 'Please sign in to use favourites.', 'yazan' ), array( 'status' => rest_authorization_required_code() ) );
		};

		register_rest_route(
			$ns,
			'/wishlist',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_list' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			$ns,
			'/wishlist/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'toggle' ),
				'permission_callback' => $auth,
				'args'                => array(
					'product_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * The saved product ids for a user. Public so the concierge can personalise from the same source.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function ids( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}
		$raw = get_user_meta( $user_id, self::META, true );
		$ids = is_array( $raw ) ? array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ) : array();
		return array_slice( $ids, 0, self::MAX );
	}

	/**
	 * Whether a product is in the user's wishlist (for server-side initial render of the heart state).
	 *
	 * @param int $user_id    User id.
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function has( $user_id, $product_id ) {
		return in_array( absint( $product_id ), self::ids( $user_id ), true );
	}

	/**
	 * GET /wishlist — the current customer's saved pieces as cards.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_list() {
		$ids   = self::ids( get_current_user_id() );
		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p instanceof WC_Product && 'publish' === $p->get_status() ) {
				$img     = $p->get_image_id();
				$items[] = array(
					'id'    => $p->get_id(),
					'name'  => wp_strip_all_tags( $p->get_name() ),
					'price' => html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES ),
					'url'   => get_permalink( $p->get_id() ),
					'thumb' => $img ? wp_get_attachment_image_url( $img, 'woocommerce_thumbnail' ) : '',
				);
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'count' => count( $items ), 'items' => $items ), 200 );
	}

	/**
	 * POST /wishlist/toggle — add or remove a product for the current customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function toggle( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$product_id = absint( $request->get_param( 'product_id' ) );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'That piece could not be found.', 'yazan' ) ), 404 );
		}

		$ids = self::ids( $user_id );
		$pos = array_search( $product_id, $ids, true );
		if ( false !== $pos ) {
			unset( $ids[ $pos ] );
			$in = false;
		} else {
			array_unshift( $ids, $product_id );
			$ids = array_slice( $ids, 0, self::MAX );
			$in  = true;
		}
		update_user_meta( $user_id, self::META, array_values( $ids ) );

		return new WP_REST_Response( array( 'ok' => true, 'in' => $in, 'count' => count( $ids ) ), 200 );
	}
}
