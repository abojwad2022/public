<?php
/**
 * Keeping the basket across a social sign-in.
 *
 * WooCommerce keys its session on the customer id, and a shopper who signs in mid-visit changes
 * customer id. Core already merges a guest session cart with the account's saved persistent cart,
 * but that merge depends on the guest session surviving the identity change — and a social sign-in
 * leaves the site entirely and comes back on a fresh top-level navigation (for Apple, a cross-site
 * POST). Relying on the session cookie to make that round trip intact, in every browser, is exactly
 * the kind of assumption that shows up later as "my basket emptied when I logged in".
 *
 * So the contents are stashed server-side before the redirect and merged back on the first request
 * after login. The merge only ever adds lines the cart does not already hold, which makes it safe
 * if core's own merge got there first — the common case, and this is the belt to its braces.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guest-cart preservation across the OAuth round trip.
 */
class Yazan_Social_Auth_Cart {

	/** Transient prefix for a stashed cart. */
	const STASH_PREFIX = 'yazan_sa_cart_';

	/** User meta naming the stash waiting to be merged into this account. */
	const PENDING_META = '_yazan_social_cart_pending';

	/** Stash lifetime — comfortably longer than any sane trip through a provider. */
	const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'maybe_merge' ), 20 );
	}

	/**
	 * Snapshot the current (guest) cart. Called just before leaving for the provider.
	 *
	 * @return string Stash token, or '' when there was nothing worth keeping.
	 */
	public static function stash() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$lines = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) ) {
				continue;
			}

			$lines[] = array(
				'product_id'   => absint( $item['product_id'] ),
				'variation_id' => isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
				'quantity'     => isset( $item['quantity'] ) ? (float) $item['quantity'] : 1,
				'variation'    => isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array(),
			);
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$token = wp_generate_password( 32, false );
		set_transient( self::STASH_PREFIX . $token, $lines, self::TTL );

		return $token;
	}

	/**
	 * Mark a stash as belonging to a freshly signed-in account.
	 *
	 * @param int    $user_id Account id.
	 * @param string $token   Stash token from stash().
	 * @return void
	 */
	public static function schedule_merge( $user_id, $token ) {
		$user_id = absint( $user_id );
		$token   = sanitize_text_field( (string) $token );

		if ( ! $user_id || '' === $token ) {
			return;
		}

		update_user_meta( $user_id, self::PENDING_META, $token );
	}

	/**
	 * Merge a pending stash into the cart. Hook: woocommerce_cart_loaded_from_session.
	 *
	 * @param WC_Cart|null $cart Cart instance passed by the hook.
	 * @return void
	 */
	public static function maybe_merge( $cart = null ) {
		static $done = false;

		// add_to_cart() below can re-enter the session/cart machinery; one pass per request is both
		// sufficient and the only safe thing to allow.
		if ( $done || ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$token   = (string) get_user_meta( $user_id, self::PENDING_META, true );

		if ( '' === $token ) {
			return;
		}

		$done = true;

		delete_user_meta( $user_id, self::PENDING_META );

		$lines = get_transient( self::STASH_PREFIX . $token );
		delete_transient( self::STASH_PREFIX . $token );

		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return;
		}

		$cart = $cart instanceof WC_Cart ? $cart : WC()->cart;
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$present = array();
		foreach ( $cart->get_cart() as $item ) {
			$present[ absint( $item['product_id'] ) . ':' . ( isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0 ) ] = true;
		}

		/*
		 * add_to_cart() queues its own notices ("… has been added to your basket", or an error for a
		 * line that sold out while the shopper was at the provider). Neither belongs on the page
		 * they land on, so the notice queue is snapshotted and restored around the merge — that
		 * drops exactly what the merge added and keeps the sign-in confirmation intact.
		 */
		$notices_before = function_exists( 'wc_get_notices' ) ? wc_get_notices() : null;

		foreach ( $lines as $line ) {
			$signature = $line['product_id'] . ':' . $line['variation_id'];

			// Already in the cart (core's own merge, or the shopper re-added it) — leave the
			// existing quantity alone rather than doubling it.
			if ( isset( $present[ $signature ] ) ) {
				continue;
			}

			$product = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$cart->add_to_cart( $line['product_id'], $line['quantity'], $line['variation_id'], $line['variation'] );
		}

		if ( null !== $notices_before && function_exists( 'wc_set_notices' ) ) {
			wc_set_notices( $notices_before );
		}
	}
}
