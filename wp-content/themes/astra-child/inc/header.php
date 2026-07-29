<?php
/**
 * Yazan — header integration (Comma-style).
 *
 * Storefront pages get a SOLID, sticky header that compresses on scroll, with a slim announcement
 * bar above it (modelled on commafootball.com — Blueprint Part 3). Checkout/cart get a focused,
 * always-dark header. No transparent-over-hero mode.
 *
 * Visuals live in assets/css/header.css; scroll + announcement rotation in assets/js/header.js
 * (both enqueued from inc/enqueue.php).
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A real GOLD brand logo is now in use — do not darken it to black on the light header.
add_filter( 'yazan_header_darken_logo', '__return_false' );

/**
 * On-brand placeholder for the header search pill (Astra native search element).
 * Filterable/translatable; mirrors the Comma reference's descriptive prompt.
 */
add_filter( 'astra_search_field_placeholder', 'yazan_search_placeholder' );
function yazan_search_placeholder( $placeholder ) {
	return esc_attr_x( 'Search rings, stones, collections…', 'header search placeholder', 'yazan' );
}

/**
 * Checkout / cart family — the "focused" commerce views. Covers native WooCommerce (cart, checkout,
 * order-received) AND CartFlows steps (the `cartflows_step` CPT, where is_checkout() isn't reliable).
 *
 * @return bool
 */
function yazan_is_checkout_view() {
	if ( function_exists( 'is_checkout' ) &&
		( is_checkout() || is_cart() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) )
	) {
		return true;
	}

	// CartFlows funnel steps (checkout / thank-you / etc.).
	if ( is_singular( array( 'cartflows_step', 'cartflows_flow' ) ) ) {
		return true;
	}

	return false;
}

/**
 * The thank-you view specifically — a subset of yazan_is_checkout_view(). Distinguishes the
 * post-purchase page (which shares the `cartflows_step` CPT and `cartflows-instant-checkout` body
 * class with the checkout step) from the checkout form, so thank-you-only CSS loads nowhere else.
 * Covers native WooCommerce order-received AND the CartFlows thank-you step (meta wcf-step-type).
 *
 * @return bool
 */
function yazan_is_thankyou_view() {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
		return true;
	}

	if ( is_singular( 'cartflows_step' ) ) {
		$step_id = get_queried_object_id();
		if ( $step_id && 'thankyou' === get_post_meta( $step_id, 'wcf-step-type', true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Solid DARK header: a distraction-free, always-legible bar for checkout/cart. Filterable.
 *
 * @return bool
 */
function yazan_use_solid_header() {
	return (bool) apply_filters( 'yazan_use_solid_header', yazan_is_checkout_view() );
}

/**
 * Comma-style storefront header: solid light, sticky, compresses on scroll, with announcement bar.
 * Everything that isn't a checkout/cart view. Filterable.
 *
 * @return bool
 */
function yazan_use_comma_header() {
	return (bool) apply_filters( 'yazan_use_comma_header', ! yazan_use_solid_header() );
}

/**
 * Add the header body flags.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
add_filter( 'body_class', 'yazan_header_body_classes' );
function yazan_header_body_classes( $classes ) {
	if ( yazan_use_solid_header() ) {
		$classes[] = 'yz-solid-header';

		// Dark bar → whiten a single-color logo (filter off for a colored logo).
		if ( apply_filters( 'yazan_header_invert_logo', true ) ) {
			$classes[] = 'yz-invert-logo';
		}
	} else {
		// Comma-style light header. The DNK/Elementor header ships white menu text + a white
		// logo (built for a dark background); darken them so they read on the light bar. For a
		// colored logo, set a dark logo in the header builder and filter this off.
		$classes[] = 'yz-comma-header';
		if ( apply_filters( 'yazan_header_darken_logo', true ) ) {
			$classes[] = 'yz-darken-logo';
		}
	}

	return $classes;
}

/**
 * Resolve the promo-countdown deadline as a UNIX timestamp, or 0 when no countdown should show.
 *
 * Two modes (a fixed campaign wins over the rolling default):
 *   1. Fixed campaign — filter `yazan_announcement_deadline` returns a date string
 *      (e.g. '2026-08-01 23:59:59', site timezone). Used verbatim while it is in the future.
 *   2. Rolling window — filter `yazan_announcement_window` returns a length in SECONDS; the bar
 *      then shows an always-live countdown that loops every window (prototype-friendly, no dates
 *      to maintain). Defaults to 48h, mirroring the reference's "free shipping" timer. Return 0
 *      from the filter to disable the countdown entirely (bar falls back to rotating messages).
 *
 * @return int UNIX timestamp in the future, or 0.
 */
function yazan_announcement_deadline() {
	$fixed = apply_filters( 'yazan_announcement_deadline', '' );
	if ( is_string( $fixed ) && '' !== trim( $fixed ) ) {
		$ts = strtotime( $fixed );
		return ( $ts && $ts > time() ) ? (int) $ts : 0;
	}

	$window = (int) apply_filters( 'yazan_announcement_window', 48 * HOUR_IN_SECONDS );
	if ( $window > 0 ) {
		// End of the current rolling window → a countdown that resets when it hits zero.
		return (int) ( ceil( time() / $window ) * $window );
	}

	return 0;
}

/**
 * Announcement bar (Comma trait) — a slim strip above the header.
 *
 * Two layouts:
 *   • With an active promo deadline → a single message + a live countdown (D/H/M/S), matching the
 *     reference's shipping-timer bar. The live ticking is done in header.js (data-attribute driven).
 *   • Otherwise → the original rotating-messages behaviour.
 *
 * Storefront only (kept off the focused checkout view). All copy is filterable and translatable.
 * Rendered at wp_body_open so it sits above the header markup and scrolls away on its own.
 */
add_action( 'wp_body_open', 'yazan_announcement_bar' );
function yazan_announcement_bar() {
	if ( ! yazan_use_comma_header() ) {
		return;
	}

	$deadline = yazan_announcement_deadline();

	/* ---- Promo countdown layout --------------------------------------- */
	if ( $deadline ) {
		// Threshold-consistent copy: the store now offers free express shipping OVER a spend threshold
		// (WooCommerce free_shipping min_amount, mirrored by the cart drawer's progress bar), so the bar
		// no longer promises "complimentary for all". Amount kept out of the string so changing the
		// threshold doesn't desync copy; override via the `yazan_announcement_deadline_label` filter.
		$label = sanitize_text_field(
			apply_filters( 'yazan_announcement_deadline_label', __( 'Complimentary express shipping on qualifying orders · ends in', 'yazan' ) )
		);

		echo '<div class="yz-announce yz-announce--promo" role="region" aria-label="' . esc_attr__( 'Store announcement', 'yazan' ) . '">';
		echo '<div class="yz-announce__inner">';

		if ( '' !== $label ) {
			echo '<span class="yz-announce__msg">' . esc_html( $label ) . '</span>';
		}

		$segments = array(
			'd' => _x( 'Days', 'countdown unit', 'yazan' ),
			'h' => _x( 'Hrs', 'countdown unit', 'yazan' ),
			'm' => _x( 'Min', 'countdown unit', 'yazan' ),
			's' => _x( 'Sec', 'countdown unit', 'yazan' ),
		);

		printf(
			'<span class="yz-announce__countdown" data-yz-countdown data-deadline="%1$d" data-window="%2$d" role="timer" aria-live="off">',
			(int) $deadline,
			(int) apply_filters( 'yazan_announcement_window', 48 * HOUR_IN_SECONDS )
		);
		foreach ( $segments as $key => $unit ) {
			printf(
				'<span class="yz-cd__seg"><b class="yz-cd__num" data-%1$s>00</b><i class="yz-cd__unit">%2$s</i></span>',
				esc_attr( $key ),
				esc_html( $unit )
			);
		}
		echo '</span>'; // .yz-announce__countdown

		echo '</div></div>';
		return;
	}

	/* ---- Rotating-messages layout (no active promo) ------------------- */
	$messages = apply_filters(
		'yazan_announcement_messages',
		array(
			__( 'Complimentary insured worldwide shipping', 'yazan' ),
			__( 'Every ring digitally certified', 'yazan' ),
			__( 'Hand-cut Yemeni agate · Sterling silver 925', 'yazan' ),
		)
	);

	$messages = array_values( array_filter( array_map( 'sanitize_text_field', (array) $messages ) ) );
	if ( empty( $messages ) ) {
		return;
	}

	echo '<div class="yz-announce" role="region" aria-label="' . esc_attr__( 'Store announcements', 'yazan' ) . '">';
	echo '<div class="yz-announce__track">';
	foreach ( $messages as $i => $message ) {
		printf(
			'<span class="yz-announce__item%1$s">%2$s</span>',
			0 === $i ? ' is-active' : '',
			esc_html( $message )
		);
	}
	echo '</div></div>';
}

/*
 * ---------------------------------------------------------------------------
 * NOTE — this site's header is built with Header Footer Elementor
 * (.elementor-location-header), not Astra's native header. header.js tags whichever header is
 * present with `.yz-header`, and all CSS targets that, so this works under HFE today and under a
 * native Astra header later.
 *
 * The 3-row Comma structure itself (announcement / centered logo / uppercase nav) is arranged in
 * the header BUILDER (HFE Templates → Header, or Astra Header Builder). This file provides the
 * announcement bar + all the solid/sticky/compress/hover BEHAVIOUR on top of whatever layout the
 * builder produces.
 *
 * If you switch to ASTRA'S NATIVE header you can also drive it with Astra hooks, e.g. inject CSS
 * through `astra_dynamic_theme_css`, or add markup with `astra_header_before` / `astra_header_after`.
 * ---------------------------------------------------------------------------
 */
