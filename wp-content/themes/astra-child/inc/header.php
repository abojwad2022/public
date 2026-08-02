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
 * NOTE — the header is built with ASTRA'S NATIVE Header Builder.
 *
 * An earlier version of this note claimed it was Header Footer Elementor; that was wrong and cost
 * a debugging session. Ground truth from the rendered page: <body> carries `ast-hfb-header` and
 * there is NO `ehf-header` and no `.elementor-location-header` anywhere in the output. HFE owns
 * the FOOTER only (`ehf-footer`, swapping #colophon) — which is what caused the confusion.
 *
 * So the header layout lives in Astra Customizer options (in the DB), not in `_elementor_data`.
 * header.js tags the header with `.yz-header` and all CSS targets that, so the styling would
 * survive a move to another builder.
 *
 * Because it IS Astra, Astra's own hooks are available and are the right tool here:
 * `astra_get_option_{$option}` filters any Customizer value (see the account link below),
 * `astra_dynamic_theme_css` injects CSS, `astra_header_before` / `astra_header_after` add markup.
 * ---------------------------------------------------------------------------
 */

/**
 * Point the header account icon at the branded My Account page.
 *
 * Astra's account widget has no filter of its own on the link — Astra_Builder_UI_Controller::
 * render_account() reads the URL straight from Customizer options, and for a logged-out visitor
 * rewrites it to wp_login_url( $current_url ). That dropped shoppers on the raw wp-login.php
 * screen: no brand, and none of the one-tap Google / Apple buttons that live on /my-account/.
 *
 * Astra's ready-made "WooCommerce" account type would fix it, but it is gated behind
 * `defined( 'ASTRA_EXT_VER' )` (Astra Pro), which this site does not have.
 *
 * What IS available is the generic value filter every Customizer option passes through —
 * astra_get_option() ends in apply_filters( "astra_get_option_{$option}", … ). Filtering the two
 * link options is therefore a supported hook, not a patch, and needs no change to the parent theme.
 *
 * @param mixed $value Stored option value: array{url:string,new_tab:bool,link_rel:string}.
 * @return mixed
 */
function yazan_account_link_url( $value ) {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return $value; // No WooCommerce — leave Astra's default behaviour alone.
	}

	$my_account = wc_get_page_permalink( 'myaccount' );

	if ( ! $my_account ) {
		return $value; // My Account page missing/unpublished — same.
	}

	// Preserve the option's shape so Astra's own new_tab / rel handling keeps working.
	$link = is_array( $value ) ? $value : array();

	$link['url']      = $my_account;
	$link['new_tab']  = false; // Signing in should never leave the shop in a stray tab.
	$link['link_rel'] = isset( $link['link_rel'] ) ? $link['link_rel'] : '';

	return $link;
}
add_filter( 'astra_get_option_header-account-logout-link', 'yazan_account_link_url' );
add_filter( 'astra_get_option_header-account-login-link', 'yazan_account_link_url' );

/**
 * Give the account avatar a usable size on phones.
 *
 * The mobile value of `header-account-image-width` was never set in the Customizer, and Astra's
 * dynamic CSS substitutes a hardcoded 20 for an empty one (account/dynamic-css/dynamic.css.php),
 * emitting `@media (max-width:544px){ … .avatar { width:20px } }`. A 20px avatar inside a drawer row
 * that Astra pads to `15px 20px` reads as a stray dot, which is exactly what it looked like.
 *
 * Filtered rather than overridden in CSS on purpose: Astra's rule lives inside its own
 * `max-width:544px` block at specificity 0,3,0, so a child-theme override would have to match that
 * breakpoint AND out-specify it — two things to get wrong every time Astra changes. Feeding the
 * option the right number instead means Astra generates the correct CSS itself, and the Customizer
 * still wins if someone later sets a value there.
 *
 * @param mixed $value Stored responsive value: array{desktop:int,tablet:int,mobile:int|string}.
 * @return mixed
 */
function yazan_account_avatar_size( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	// Only fill a blank — never overwrite a real choice made in the Customizer.
	if ( '' === ( $value['mobile'] ?? '' ) || null === ( $value['mobile'] ?? null ) ) {
		$value['mobile'] = 44;
	}

	return $value;
}
add_filter( 'astra_get_option_header-account-image-width', 'yazan_account_avatar_size' );

/**
 * Match the logged-out account icon to the logged-in avatar on phones.
 *
 * Without this the drawer jumps between a 44px photo and an 18px glyph depending on who is looking,
 * and the row height changes with it.
 *
 * @param mixed $value Stored responsive value.
 * @return mixed
 */
function yazan_account_icon_size( $value ) {
	if ( is_array( $value ) ) {
		$value['mobile'] = 34; // Slightly smaller than the photo: the glyph is a filled silhouette.
	}
	return $value;
}
add_filter( 'astra_get_option_header-account-icon-size', 'yazan_account_icon_size' );

/**
 * Print the account name beside the avatar, in the mobile drawer only.
 *
 * Astra renders the account element as an avatar OR an icon OR a text label — never an avatar
 * *with* a name — and Astra_Builder_UI_Controller::render_account() contains no filter or action to
 * inject into (verified: zero apply_filters/do_action in the whole method). The one supported seam
 * is `astra_header_account`, the action components.php fires inside `.ast-header-account`, so this
 * appends a sibling block rather than reaching into Astra's anchor.
 *
 * It is emitted for every header row Astra builds — desktop bar included — and hidden everywhere
 * except `.ast-mobile-popup-content` by CSS. Scoping by ancestor is what makes it correct no matter
 * how many times, or in what order, the action fires.
 *
 * @return void
 */
function yazan_account_drawer_identity() {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}

	$href = wc_get_page_permalink( 'myaccount' );
	if ( ! $href ) {
		return;
	}

	if ( is_user_logged_in() ) {
		$user     = wp_get_current_user();
		$title    = $user->display_name ? $user->display_name : $user->user_login;
		$subtitle = __( 'View account', 'yazan' );
		$attrs    = '';
	} else {
		$title    = __( 'Log in', 'yazan' );
		$subtitle = __( 'Orders and favourites', 'yazan' );
		/*
		 * signin-card.js delegates clicks on `.ast-header-account-link, [data-yz-signin-open]` and
		 * opens the modal instead of navigating. Carrying the documented attribute keeps this text
		 * behaving exactly like the avatar next to it; without it, tapping the name would leave the
		 * drawer for wp-login while tapping the photo opened the dialog.
		 *
		 * (The script is only enqueued for signed-out visitors, so the logged-in branch above needs
		 * nothing — its link simply navigates.)
		 */
		$attrs = ' data-yz-signin-open';
	}

	printf(
		'<a class="yz-account-id" href="%1$s"%2$s><span class="yz-account-id__name">%3$s</span><span class="yz-account-id__sub">%4$s</span></a>',
		esc_url( $href ),
		$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal above.
		esc_html( $title ),
		esc_html( $subtitle )
	);
}
add_action( 'astra_header_account', 'yazan_account_drawer_identity', 15 );
