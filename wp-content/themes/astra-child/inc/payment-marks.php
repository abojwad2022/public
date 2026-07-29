<?php
/**
 * Yazan — accepted-payment marks.
 *
 * A single source of truth for the "Secure checkout · [payment marks]" row that appears under the
 * add-to-cart form, on the cart, on the checkout and (via shortcode) in the footer.
 *
 * Two design rules drive this module:
 *
 *  1. **Truthfulness.** The row is derived from the gateways WooCommerce actually offers, so it can
 *     never quietly claim a payment method the store cannot take. Until a gateway is connected there
 *     is nothing to derive, so a deliberate, filterable pre-launch set stands in — see
 *     {@see yazan_payment_marks_prelaunch()}. The moment WooPayments or PayPal go live the derived
 *     list wins and the placeholder retires itself.
 *  2. **Zero external requests.** Every mark is inline SVG inheriting `currentColor`, so the row
 *     reads correctly in both the obsidian and burgundy token sets and fetches nothing.
 *
 * ⚠️ Before launch: Apple, Google, PayPal and the card networks each require their own official mark
 * artwork under their brand guidelines. The glyphs below are on-brand monochrome stand-ins for
 * design; swap the bodies in {@see yazan_payment_mark_glyph()} for the official assets at go-live.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Every mark this theme can draw, in display order, with its accessible name.
 *
 * @return array<string,string> slug => human label.
 */
function yazan_payment_mark_labels() {
	return array(
		'apple-pay'  => __( 'Apple Pay', 'yazan' ),
		'google-pay' => __( 'Google Pay', 'yazan' ),
		'paypal'     => __( 'PayPal', 'yazan' ),
		'visa'       => __( 'Visa', 'yazan' ),
		'mastercard' => __( 'Mastercard', 'yazan' ),
		'amex'       => __( 'American Express', 'yazan' ),
		'link'       => __( 'Link', 'yazan' ),
	);
}

/**
 * The short caption printed inside the pill next to a glyph. Empty when the glyph stands alone
 * (Mastercard's interlocking circles and PayPal's monogram are unmistakable without a caption).
 *
 * @param string $slug Mark slug.
 * @return string
 */
function yazan_payment_mark_caption( $slug ) {
	$captions = array(
		'apple-pay'  => __( 'Pay', 'yazan' ),
		'google-pay' => __( 'Pay', 'yazan' ),
		'paypal'     => '',
		'mastercard' => '',
		'visa'       => __( 'Visa', 'yazan' ),
		'amex'       => __( 'Amex', 'yazan' ),
		'link'       => __( 'Link', 'yazan' ),
	);

	return isset( $captions[ $slug ] ) ? $captions[ $slug ] : '';
}

/**
 * Inline monochrome glyph for a mark, or '' for the wordmark-only marks.
 *
 * All glyphs are drawn to a square-ish viewBox, inherit `currentColor` and are `aria-hidden` — the
 * accessible name comes from the row's own aria-label, so the glyphs stay decorative.
 *
 * @param string $slug Mark slug.
 * @return string Raw SVG markup (trusted, authored here — never user input).
 */
function yazan_payment_mark_glyph( $slug ) {
	switch ( $slug ) {
		case 'apple-pay':
			// Apple silhouette — leaf + body, filled.
			return '<svg class="yz-pay__glyph" viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true" focusable="false">'
				. '<path d="M17.05 12.53c-.02-2.2 1.8-3.26 1.88-3.31-1.02-1.5-2.62-1.7-3.19-1.72-1.36-.14-2.65.8-3.34.8-.69 0-1.75-.78-2.88-.76-1.48.02-2.85.86-3.61 2.19-1.54 2.67-.39 6.62 1.11 8.79.73 1.06 1.61 2.25 2.76 2.21 1.11-.04 1.53-.72 2.87-.72 1.34 0 1.71.72 2.88.7 1.19-.02 1.94-1.08 2.67-2.14.84-1.23 1.19-2.42 1.21-2.48-.03-.01-2.32-.89-2.34-3.53z"/>'
				. '<path d="M14.9 6.1c.61-.74 1.02-1.77.91-2.8-.88.04-1.94.59-2.57 1.32-.56.65-1.05 1.7-.92 2.7.98.08 1.98-.5 2.58-1.22z"/>'
				. '</svg>';

		case 'google-pay':
			/*
			 * Google's G reduced to its geometry: a ring left open at the upper right plus the bar
			 * that runs from the centre out to the edge. Built from a dashed circle rather than an
			 * arc path so the gap is exact and there are no sweep-flag surprises.
			 * Circumference = 2π × 7.2 ≈ 45.2, so "37 8.2" leaves one clean opening.
			 */
			return '<svg class="yz-pay__glyph" viewBox="0 0 20 20" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true" focusable="false">'
				. '<circle cx="10" cy="10" r="7.2" stroke-dasharray="37 8.2" transform="rotate(-28 10 10)"/>'
				. '<path d="M10 10h7.2"/>'
				. '</svg>';

		case 'paypal':
			// The double-P monogram: back plate held at low opacity, front plate solid.
			return '<svg class="yz-pay__glyph" viewBox="0 0 21 18" width="13" height="13" fill="currentColor" aria-hidden="true" focusable="false">'
				. '<path opacity=".5" d="M1.6 14.6 3.7 1.4h4.6c2.4 0 3.7 1.3 3.3 3.6-.4 2.6-2.3 4-4.9 4H4.9l-.8 5.6H1.6zm3.7-7.7h1.6c1.1 0 1.9-.6 2.1-1.7.2-1-.3-1.5-1.3-1.5H5.9l-.6 3.2z"/>'
				. '<path d="M6.9 17 9 3.8h4.6c2.4 0 3.7 1.3 3.3 3.6-.4 2.6-2.3 4-4.9 4h-1.8L9.4 17H6.9zm3.7-7.7h1.6c1.1 0 1.9-.6 2.1-1.7.2-1-.3-1.5-1.3-1.5h-1.8l-.6 3.2z"/>'
				. '</svg>';

		case 'mastercard':
			// The interlocking circles.
			return '<svg class="yz-pay__glyph" viewBox="0 0 24 16" width="20" height="13" fill="none" stroke="currentColor" stroke-width="1.3" aria-hidden="true" focusable="false">'
				. '<circle cx="9.4" cy="8" r="5.3"/>'
				. '<circle cx="14.6" cy="8" r="5.3"/>'
				. '</svg>';
	}

	return '';
}

/**
 * Which marks each known gateway id stands for.
 *
 * Filterable so a gateway this theme has never heard of can register itself rather than needing a
 * change here — `yazan-test-gateways` uses exactly that to keep the row derived while developing.
 *
 * @return array<string,string[]> gateway id => mark slugs.
 */
function yazan_payment_gateway_mark_map() {
	$card = array( 'visa', 'mastercard', 'amex' );

	$map = array(
		'woocommerce_payments'             => $card,   // WooPayments — cards.
		'ppcp-credit-card-gateway'         => $card,   // PayPal Advanced Card Processing.
		'ppcp-card-button-gateway'         => $card,
		'ppcp-gateway'                     => array( 'paypal' ),
		'paypal'                           => array( 'paypal' ), // Legacy PayPal Standard.
		'woocommerce_payments_apple_pay'   => array( 'apple-pay' ),
		'woocommerce_payments_google_pay'  => array( 'google-pay' ),
		'woocommerce_payments_link'        => array( 'link' ),
	);

	/**
	 * Filter the gateway-id → payment-mark map.
	 *
	 * @param array<string,string[]> $map Gateway id => mark slugs.
	 */
	return (array) apply_filters( 'yazan_payment_gateway_marks', $map );
}

/**
 * Marks derived from the gateways WooCommerce is actually offering right now.
 *
 * Returns an empty array when no electronic gateway is live — which is the honest answer, and what
 * makes the pre-launch set below an explicit choice rather than a silent lie.
 *
 * @return string[] Mark slugs.
 */
function yazan_payment_marks_from_gateways() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
		return array();
	}

	$available = WC()->payment_gateways()->get_available_payment_gateways();
	if ( ! is_array( $available ) ) {
		return array();
	}

	$map   = yazan_payment_gateway_mark_map();
	$marks = array();

	foreach ( array_keys( $available ) as $id ) {
		if ( isset( $map[ $id ] ) ) {
			$marks = array_merge( $marks, (array) $map[ $id ] );
		}
	}

	/*
	 * WooPayments renders Apple Pay / Google Pay / Link as express buttons off the main gateway
	 * rather than as separately-listed gateways, so their switches live in sibling options. Only
	 * consult them once the parent gateway is genuinely available.
	 */
	if ( isset( $available['woocommerce_payments'] ) ) {
		$express = array(
			'apple_pay'  => 'apple-pay',
			'google_pay' => 'google-pay',
			'link'       => 'link',
		);

		foreach ( $express as $option_key => $slug ) {
			$settings = get_option( 'woocommerce_woocommerce_payments_' . $option_key . '_settings' );
			if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
				$marks[] = $slug;
			}
		}
	}

	return array_values( array_unique( $marks ) );
}

/**
 * The stand-in set shown while no gateway is connected yet.
 *
 * Filter to `false` (or to a shorter list) the day the store must only advertise what it can take:
 *
 *     add_filter( 'yazan_payment_marks_prelaunch', '__return_false' );
 *
 * @return string[] Mark slugs — empty array disables the stand-in entirely.
 */
function yazan_payment_marks_prelaunch() {
	$marks = array( 'apple-pay', 'google-pay', 'paypal', 'visa', 'mastercard', 'amex', 'link' );

	/**
	 * Filter the pre-launch payment marks.
	 *
	 * @param string[]|false $marks Mark slugs, or false to show nothing before gateways are live.
	 */
	$marks = apply_filters( 'yazan_payment_marks_prelaunch', $marks );

	return is_array( $marks ) ? $marks : array();
}

/**
 * The marks to render, in the canonical display order.
 *
 * @return string[] Mark slugs.
 */
function yazan_payment_marks() {
	$marks = yazan_payment_marks_from_gateways();

	if ( ! $marks ) {
		$marks = yazan_payment_marks_prelaunch();
	}

	/**
	 * Filter the resolved payment marks.
	 *
	 * @param string[] $marks Mark slugs.
	 */
	$marks = apply_filters( 'yazan_payment_marks', $marks );

	// Keep the canonical order and drop anything we cannot draw.
	$known = yazan_payment_mark_labels();

	return array_values( array_intersect( array_keys( $known ), (array) $marks ) );
}

/**
 * Render the accepted-payment row.
 *
 * The row is a single `role="img"` whose aria-label names every method, so the decorative glyphs and
 * captions inside stay out of the accessibility tree instead of being announced as loose fragments.
 *
 * @param array $args {
 *     @type string $label Leading caption. Default "Secure checkout".
 *     @type bool   $lock  Whether to show the padlock glyph. Default true.
 *     @type string $class Extra class on the wrapper.
 * }
 * @return string Markup, or '' when there is nothing to show.
 */
function yazan_payment_marks_html( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'label' => __( 'Secure checkout', 'yazan' ),
			'lock'  => true,
			'class' => '',
		)
	);

	$marks = yazan_payment_marks();
	if ( ! $marks ) {
		return '';
	}

	$labels = yazan_payment_mark_labels();
	$names  = array();
	foreach ( $marks as $slug ) {
		$names[] = $labels[ $slug ];
	}

	$aria = sprintf(
		/* translators: %s: comma-separated list of accepted payment methods. */
		__( 'Accepted payment methods: %s', 'yazan' ),
		implode( ', ', $names )
	);

	$classes = trim( 'yz-pay ' . $args['class'] );

	$html  = '<div class="' . esc_attr( $classes ) . '" role="img" aria-label="' . esc_attr( $aria ) . '">';

	if ( $args['lock'] ) {
		$html .= '<span class="yz-pay__lock" aria-hidden="true">'
			. '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>'
			. '</span>';
	}

	if ( '' !== $args['label'] ) {
		$html .= '<span class="yz-pay__label">' . esc_html( $args['label'] ) . '</span>';
	}

	$html .= '<span class="yz-pay__marks">';

	foreach ( $marks as $slug ) {
		$glyph   = yazan_payment_mark_glyph( $slug );
		$caption = yazan_payment_mark_caption( $slug );

		$html .= '<span class="yz-pay__mark yz-pay__mark--' . esc_attr( $slug ) . ( $glyph ? ' yz-pay__mark--svg' : '' ) . '">';
		$html .= $glyph; // Authored above — not user input.
		if ( '' !== $caption ) {
			$html .= '<span class="yz-pay__name">' . esc_html( $caption ) . '</span>';
		}
		$html .= '</span>';
	}

	$html .= '</span></div>';

	return $html;
}

/**
 * Echo helper for hook callbacks.
 *
 * @param array $args See {@see yazan_payment_marks_html()}.
 * @return void
 */
function yazan_the_payment_marks( $args = array() ) {
	echo yazan_payment_marks_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled and escaped above.
}

/* -------------------------------------------------------------------------
 * Placements
 * ---------------------------------------------------------------------- */

/**
 * Cart page — under the totals block, where the customer is deciding to proceed.
 */
add_action( 'woocommerce_after_cart_totals', 'yazan_payment_marks_cart', 20 );
function yazan_payment_marks_cart() {
	yazan_the_payment_marks( array( 'class' => 'yz-pay--cart' ) );
}

/**
 * Checkout — directly beneath the place-order button. Also covers the CartFlows Instant Checkout
 * form, which is the classic WooCommerce review-order markup and so fires this same hook.
 */
add_action( 'woocommerce_review_order_after_submit', 'yazan_payment_marks_checkout', 20 );
function yazan_payment_marks_checkout() {
	yazan_the_payment_marks( array( 'class' => 'yz-pay--checkout' ) );
}

/**
 * `[yazan_payment_marks]` — for the Elementor/HFE footer, where the row belongs but the markup is
 * owned by a saved template rather than by this theme.
 *
 * Attributes: label (string, '' hides it), lock ("no" hides it), class (extra class).
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
add_shortcode( 'yazan_payment_marks', 'yazan_payment_marks_shortcode' );
function yazan_payment_marks_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'label' => __( 'Secure checkout', 'yazan' ),
			'lock'  => 'yes',
			'class' => 'yz-pay--footer',
		),
		$atts,
		'yazan_payment_marks'
	);

	return yazan_payment_marks_html(
		array(
			'label' => sanitize_text_field( $atts['label'] ),
			'lock'  => 'no' !== strtolower( (string) $atts['lock'] ),
			'class' => sanitize_html_class( $atts['class'] ),
		)
	);
}
