<?php
/**
 * Yazan — customer wishlist (favourites) storefront layer.
 *
 * A heart toggle on product pages + shop cards, a "Favourites" tab in My Account, and the assets that
 * talk to yazan-core's /wishlist REST routes. Signed-in shoppers save pieces (server-side, per user);
 * guests are invited to sign in. Fully token-driven, so hearts + the account grid re-tint for both
 * themes and mirror under RTL.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The heart button. Its active state is rendered server-side so it is correct on first paint.
 *
 * @param int    $product_id  Product id.
 * @param string $extra_class Extra CSS class (e.g. yz-wish--overlay / yz-wish--inline).
 */
function yazan_wishlist_button( $product_id, $extra_class = '' ) {
	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return;
	}
	$active = is_user_logged_in() && class_exists( 'Yazan_REST_Wishlist' )
		&& Yazan_REST_Wishlist::has( get_current_user_id(), $product_id );

	printf(
		'<button type="button" class="yz-wish %1$s%2$s" data-yz-wish="%3$d" aria-pressed="%4$s" aria-label="%5$s" title="%5$s">'
			. '<svg class="yz-wish__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">'
			. '<path stroke-linejoin="round" d="M12 20.5S3.5 15.5 3.5 9.6C3.5 6.9 5.6 5 8.2 5c1.7 0 3.1.9 3.8 2.2C12.7 5.9 14.1 5 15.8 5c2.6 0 4.7 1.9 4.7 4.6 0 5.9-8.5 10.9-8.5 10.9z"/>'
			. '</svg></button>',
		esc_attr( trim( $extra_class ) ),
		$active ? ' is-active' : '',
		(int) $product_id,
		$active ? 'true' : 'false',
		esc_attr__( 'Save to favourites', 'yazan' )
	);
}

/* ------------------------------------------------------------------ */
/* Assets                                                             */
/* ------------------------------------------------------------------ */

add_action( 'wp_enqueue_scripts', 'yazan_wishlist_enqueue', 16 );
function yazan_wishlist_enqueue() {
	// The heart prints in EVERY product loop — yazan_wishlist_loop() below is hooked to
	// woocommerce_before_shop_loop_item_title with no page condition. So the assets have to be
	// available wherever a loop can appear: the front page's Best Sellers grid, search results,
	// cart cross-sells, and any section added later. Gating on a hand-listed set of views
	// (is_product/is_shop/is_product_taxonomy/is_account_page) let the renderer and the enqueue
	// drift apart — the button printed on the front page with no CSS (a solid black blob in normal
	// flow, since the SVG carries no fill/stroke of its own) and no JS (the click bubbled to the
	// enclosing product link and navigated away instead of toggling). Same site-wide gate as
	// yazan-woo in inc/enqueue.php, which is why the badges looked right there while the heart did not.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	wp_enqueue_style( 'yazan-wishlist', YAZAN_URI . '/assets/css/wishlist.css', array( 'yazan-main' ), yazan_asset_ver( 'assets/css/wishlist.css' ) );
	wp_enqueue_script( 'yazan-wishlist', YAZAN_URI . '/assets/js/wishlist.js', array(), yazan_asset_ver( 'assets/js/wishlist.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	wp_localize_script(
		'yazan-wishlist',
		'YazanWish',
		array(
			'toggle'   => esc_url_raw( rest_url( 'yazan/v1/wishlist/toggle' ) ),
			// wp_rest nonce → sent as X-WP-Nonce so WP honors the login cookie for the customer route.
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loggedIn' => is_user_logged_in() ? 1 : 0,
			'account'  => esc_url_raw( wc_get_page_permalink( 'myaccount' ) ),
		)
	);
}

/* ------------------------------------------------------------------ */
/* Heart placement — single product + loop cards                      */
/* ------------------------------------------------------------------ */

add_action( 'woocommerce_single_product_summary', 'yazan_wishlist_single', 35 );
function yazan_wishlist_single() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	echo '<div class="yz-wish-single">';
	yazan_wishlist_button( $product->get_id(), 'yz-wish--inline' );
	echo '<span class="yz-wish-single__label">' . esc_html__( 'Add to favourites', 'yazan' ) . '</span></div>';
}

// Image-overlay heart on shop/archive cards — same hook the badges use (renders inside the thumbnail).
add_action( 'woocommerce_before_shop_loop_item_title', 'yazan_wishlist_loop', 9 );
function yazan_wishlist_loop() {
	global $product;
	if ( $product instanceof WC_Product ) {
		yazan_wishlist_button( $product->get_id(), 'yz-wish--overlay' );
	}
}

/* ------------------------------------------------------------------ */
/* My Account → Favourites tab                                        */
/* ------------------------------------------------------------------ */

// Register the endpoint on every load; flush rewrite rules once per theme version.
add_action( 'init', 'yazan_wishlist_endpoint' );
function yazan_wishlist_endpoint() {
	add_rewrite_endpoint( 'favourites', EP_ROOT | EP_PAGES );
	if ( get_option( 'yazan_wishlist_flushed' ) !== YAZAN_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'yazan_wishlist_flushed', YAZAN_VERSION );
	}
}

// Insert the menu item just before "Log out".
add_filter( 'woocommerce_account_menu_items', 'yazan_wishlist_menu_item' );
function yazan_wishlist_menu_item( $items ) {
	$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
	unset( $items['customer-logout'] );
	$items['favourites'] = __( 'Favourites', 'yazan' );
	if ( null !== $logout ) {
		$items['customer-logout'] = $logout;
	}
	return $items;
}

// Render the tab body.
add_action( 'woocommerce_account_favourites_endpoint', 'yazan_wishlist_account_content' );
function yazan_wishlist_account_content() {
	$ids = class_exists( 'Yazan_REST_Wishlist' ) ? Yazan_REST_Wishlist::ids( get_current_user_id() ) : array();

	echo '<h2 class="yz-wish-title">' . esc_html__( 'Your favourites', 'yazan' ) . '</h2>';

	if ( empty( $ids ) ) {
		echo '<p class="yz-wish-empty">' . esc_html__( 'You haven’t saved any pieces yet — tap the heart on a ring you love.', 'yazan' ) . '</p>';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wc_get_page_permalink( 'shop' ) ),
			esc_html__( 'Explore the collection', 'yazan' )
		);
		return;
	}

	echo '<div class="yz-wish-grid">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p instanceof WC_Product || 'publish' !== $p->get_status() ) {
			continue;
		}
		$img = $p->get_image_id();
		echo '<div class="yz-wish-card">';
		printf(
			'<a class="yz-wish-card__media" href="%s">%s</a>',
			esc_url( get_permalink( $id ) ),
			$img ? wp_get_attachment_image( $img, 'woocommerce_thumbnail' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '<div class="yz-wish-card__body">';
		printf( '<a class="yz-wish-card__name" href="%s">%s</a>', esc_url( get_permalink( $id ) ), esc_html( $p->get_name() ) );
		echo '<span class="yz-wish-card__price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
		echo '<div class="yz-wish-card__actions">';
		yazan_wishlist_button( $id, 'yz-wish--inline' );
		if ( $p->is_purchasable() && $p->is_in_stock() ) {
			printf(
				'<a class="button yz-wish-card__cart" href="%s" data-quantity="1" rel="nofollow">%s</a>',
				esc_url( $p->add_to_cart_url() ),
				esc_html__( 'Add to cart', 'yazan' )
			);
		}
		echo '</div></div></div>';
	}
	echo '</div>';
}
