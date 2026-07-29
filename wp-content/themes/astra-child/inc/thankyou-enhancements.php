<?php
/**
 * Yazan — CartFlows Instant thank-you page enhancements.
 *
 * Adds the "next steps" block that lifts the thank-you page to global-store standard:
 *   1. Order-progress stepper   (Confirmed → Processing → Shipped → Delivered)
 *   2. Estimated delivery date  (business-day range from the order date)
 *   3. Brand authenticity note  (one-of-a-kind aqeeq, care/packaging)
 *   4. Support / contact line
 *
 * Rendered via the CartFlows `cartflows_instant_thankyou_after` action (fires inside the left
 * column, after the customer details and before the Continue-Shopping button) — no plugin edits.
 * Styling lives in assets/css/thankyou.css (inlined by yazan_thankyou_inline_css()). Every piece
 * is filterable; all output is escaped.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

add_action( 'cartflows_instant_thankyou_after', 'yazan_thankyou_next_steps', 10, 1 );

/**
 * Render the "next steps" block.
 *
 * @param int $order_id The order ID passed by the CartFlows hook.
 * @return void
 */
function yazan_thankyou_next_steps( $order_id ) {
	$order = wc_get_order( $order_id ); // HPOS-safe.
	if ( ! $order ) {
		return;
	}

	$status = $order->get_status();

	echo '<div class="yz-ty-next">';

	// Progress stepper — skip for terminal/failed states where it is meaningless.
	if ( ! in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		yazan_thankyou_render_stepper( $status );
	}

	yazan_thankyou_render_eta( $order );
	yazan_thankyou_render_note();
	yazan_thankyou_render_support();

	echo '</div>';
}

/**
 * Order-progress stepper.
 *
 * WooCommerce has no core "shipped" status, so the current step is derived from the standard
 * statuses: pending/on-hold → Confirmed, processing → Processing, completed → Delivered.
 *
 * @param string $status Order status slug (no `wc-` prefix).
 * @return void
 */
function yazan_thankyou_render_stepper( $status ) {
	$steps = apply_filters(
		'yazan_thankyou_steps',
		array(
			__( 'Confirmed', 'yazan' ),
			__( 'Processing', 'yazan' ),
			__( 'Shipped', 'yazan' ),
			__( 'Delivered', 'yazan' ),
		)
	);

	// 1-based index of the CURRENT step.
	switch ( $status ) {
		case 'completed':
			$current = 4;
			break;
		case 'processing':
			$current = 2;
			break;
		case 'on-hold':
		case 'pending':
		default:
			$current = 1;
			break;
	}
	$current = (int) apply_filters( 'yazan_thankyou_current_step', $current, $status );

	echo '<ol class="yz-ty-steps" aria-label="' . esc_attr__( 'Order progress', 'yazan' ) . '">';
	$i = 0;
	foreach ( $steps as $label ) {
		$i++;
		if ( $i < $current ) {
			$state = 'is-done';
		} elseif ( $i === $current ) {
			$state = 'is-active';
		} else {
			$state = 'is-upcoming';
		}

		$aria = ' aria-current="' . ( 'is-active' === $state ? 'step' : 'false' ) . '"';

		echo '<li class="yz-ty-step ' . esc_attr( $state ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria built from static strings.
		echo '<span class="yz-ty-step__dot" aria-hidden="true"></span>';
		echo '<span class="yz-ty-step__label">' . esc_html( $label ) . '</span>';
		echo '</li>';
	}
	echo '</ol>';
}

/**
 * Estimated-delivery row (business-day range from the order date).
 *
 * @param WC_Order $order The order.
 * @return void
 */
function yazan_thankyou_render_eta( $order ) {
	$min_days = (int) apply_filters( 'yazan_thankyou_delivery_min_days', 3 );
	$max_days = (int) apply_filters( 'yazan_thankyou_delivery_max_days', 5 );

	$created = $order->get_date_created();
	$base_ts = $created ? $created->getTimestamp() : time();

	$from_ts = yazan_add_business_days( $base_ts, max( 1, $min_days ) );
	$to_ts   = yazan_add_business_days( $base_ts, max( $min_days, $max_days ) );

	// Compact range: drop the month on the first date when both fall in the same month.
	$same_month = wp_date( 'n-Y', $from_ts ) === wp_date( 'n-Y', $to_ts );
	$from_fmt   = $same_month ? wp_date( 'j', $from_ts ) : wp_date( 'j M', $from_ts );
	$to_fmt     = wp_date( 'j M', $to_ts );
	$range      = $from_fmt . ' – ' . $to_fmt; // en-dash.

	$icon = '<svg class="yz-ty-eta__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" /></svg>';

	echo '<div class="yz-ty-eta">';
	echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, first-party SVG.
	echo '<div>';
	echo '<span class="yz-ty-eta__label">' . esc_html__( 'Estimated delivery', 'yazan' ) . '</span>';
	echo '<span class="yz-ty-eta__value">' . esc_html( $range ) . '</span>';
	echo '</div>';
	echo '</div>';
}

/**
 * Add N business days (skip Sat/Sun) to a timestamp, in the site timezone.
 *
 * @param int $timestamp Start timestamp.
 * @param int $days      Business days to add.
 * @return int Resulting timestamp.
 */
function yazan_add_business_days( $timestamp, $days ) {
	$ts    = (int) $timestamp;
	$added = 0;
	while ( $added < $days ) {
		$ts += DAY_IN_SECONDS;
		$dow = (int) wp_date( 'N', $ts ); // 1 (Mon) … 7 (Sun).
		if ( $dow < 6 ) {
			$added++;
		}
	}
	return $ts;
}

/**
 * Brand authenticity / care note — in the Yazan voice.
 *
 * @return void
 */
function yazan_thankyou_render_note() {
	$note = apply_filters(
		'yazan_thankyou_note',
		__( 'No two Yemeni agates are alike — yours is entirely one of a kind. It will arrive nestled in our signature packaging with its certificate of authenticity and a note on caring for the stone.', 'yazan' )
	);

	if ( '' === trim( (string) $note ) ) {
		return;
	}

	echo '<p class="yz-ty-note">' . esc_html( $note ) . '</p>';
}

/**
 * Support / contact line. Email falls back to the WooCommerce store email; WhatsApp renders only
 * when a number is supplied via the `yazan_thankyou_whatsapp` filter (digits, country code first).
 *
 * @return void
 */
function yazan_thankyou_render_support() {
	$email = apply_filters(
		'yazan_thankyou_support_email',
		get_option( 'woocommerce_email_from_address' ) ? get_option( 'woocommerce_email_from_address' ) : get_option( 'admin_email' )
	);
	$whatsapp = preg_replace( '/\D+/', '', (string) apply_filters( 'yazan_thankyou_whatsapp', '' ) );

	if ( ! $email && ! $whatsapp ) {
		return;
	}

	echo '<p class="yz-ty-support">';
	echo esc_html__( 'Need help with your order?', 'yazan' ) . ' ';

	$links = array();
	if ( $email ) {
		$links[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html__( 'Email us', 'yazan' ) . '</a>';
	}
	if ( $whatsapp ) {
		$links[] = '<a href="' . esc_url( 'https://wa.me/' . $whatsapp ) . '" target="_blank" rel="noopener">' . esc_html__( 'WhatsApp', 'yazan' ) . '</a>';
	}

	echo implode( '<span class="yz-ty-support__sep">·</span>', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link escaped above.
	echo '</p>';
}
