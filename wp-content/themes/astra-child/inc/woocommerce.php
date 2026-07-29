<?php
/**
 * Yazan — WooCommerce presentation hooks.
 *
 * Implements the code-reachable parts of the design blueprint (Parts 7, 8, 15) using WooCommerce
 * hooks — no template overrides required. Everything here is DEFENSIVE: it degrades gracefully
 * when optional data (attributes, custom meta) is absent, and depends on no extra plugins.
 *
 * Conventions used (all optional — set them in the product editor to light up features):
 *   - Subject line   : product attributes `pa_stone` and/or `pa_metal` (global attributes).
 *   - "One of One"    : product tag slug `one-of-one` (or `rare`).
 *   - "Certified"     : product meta `_yz_serial` present (non-empty).
 *   - Serial preview  : product meta `_yz_serial` (e.g. "YZ-925-2026-00147").
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return; // Nothing to do without WooCommerce.
}

/* ============================================================================
 * Store-wide adjustments
 * ========================================================================== */

/**
 * Remove WooCommerce's native "Sale!" flash everywhere so our own badge system (below) is the
 * single source of truth — avoids the duplicate "Sale! + OFFER" overlay seen on cards. Filtering
 * the flash HTML itself (rather than un-hooking a specific callback) covers the loop, the single
 * product, and Elementor product widgets alike.
 */
add_filter( 'woocommerce_sale_flash', '__return_empty_string' );

/**
 * Float sold-out (and back-order) pieces to the END of every catalog grid — in-stock first.
 *
 * For one-of-one inventory we keep sold pieces visible (they carry the SOLD badge and tell the
 * story of a living collection) but they should never lead the grid. This runs as the PRIMARY
 * sort key and preserves whatever secondary order the shopper picked (price, popularity, date).
 *
 * Implemented via posts_clauses so we can LEFT JOIN the product's `_stock_status` meta and order by
 * it — WooCommerce's ordering args can't express a cross-meta sort. Products remain a CPT under
 * HPOS, so `_stock_status` postmeta is the correct, stable source. Scoped strictly to WooCommerce's
 * main archive query (`wc_query === 'product_query'`) so related/upsell/widget queries are untouched.
 */
add_filter( 'posts_clauses', 'yazan_instock_first_clauses', 10, 2 );
function yazan_instock_first_clauses( $clauses, $query ) {
	global $wpdb;

	if ( is_admin() || 'product_query' !== $query->get( 'wc_query' ) ) {
		return $clauses;
	}

	$alias = 'yz_stock';
	if ( false === strpos( $clauses['join'], $alias ) ) {
		$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = '_stock_status' ) ";
	}

	// Out-of-stock => 1 (last); everything sellable => 0 (first). Existing orderby stays secondary.
	$primary            = "CASE WHEN {$alias}.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC";
	$clauses['orderby'] = $clauses['orderby'] ? $primary . ', ' . $clauses['orderby'] : $primary;

	// One _stock_status row per product, but keep the JOIN from ever multiplying rows.
	if ( empty( $clauses['groupby'] ) ) {
		$clauses['groupby'] = "{$wpdb->posts}.ID";
	}

	return $clauses;
}

/**
 * Branded placeholder for products with no featured image.
 *
 * Without this, WooCommerce falls back to its (often unset) placeholder, so image-less products
 * collapse to zero height. On the single-product page the gallery column then vanishes and the
 * Related-products block floats up into it; on cards the absolutely-positioned .yz-badges overlay
 * scatters with nothing behind it. Feeding an on-brand SVG (self-contained data URI — no request)
 * into WooCommerce's placeholder pipeline guarantees every image slot reserves its 4:5 box, which
 * fixes the collapse for both the single gallery and loop cards.
 *
 * Only fills genuinely empty slots — a real featured image always wins.
 */
add_filter( 'woocommerce_placeholder_img_src', 'yazan_branded_placeholder_src' );
function yazan_branded_placeholder_src( $src ) {
	if ( function_exists( 'yazan_demo_placeholder' ) ) {
		return yazan_demo_placeholder( 'Yazan' );
	}
	return $src;
}

/**
 * Ensure the placeholder <img> carries real dimensions so the CSS aspect-ratio box applies and the
 * container never collapses, even where WooCommerce builds the tag from the raw src.
 *
 * @param string $html Placeholder image HTML.
 * @param string $size Requested image size.
 * @return string
 */
add_filter( 'woocommerce_placeholder_img', 'yazan_branded_placeholder_img', 10, 2 );
function yazan_branded_placeholder_img( $html, $size ) {
	if ( ! function_exists( 'yazan_demo_placeholder' ) ) {
		return $html;
	}
	$dims = function_exists( 'wc_get_image_size' ) ? wc_get_image_size( $size ) : array( 'width' => 480, 'height' => 600 );
	$w    = ! empty( $dims['width'] ) ? (int) $dims['width'] : 480;
	$h    = ! empty( $dims['height'] ) ? (int) $dims['height'] : (int) round( $w * 1.25 );
	return sprintf(
		'<img src="%1$s" alt="%2$s" width="%3$d" height="%4$d" class="woocommerce-placeholder wp-post-image" loading="lazy" />',
		esc_url( yazan_demo_placeholder( 'Yazan' ) ),
		esc_attr__( 'Yazan', 'yazan' ),
		$w,
		$h ? $h : (int) round( $w * 1.25 )
	);
}

/**
 * Force a no-sidebar layout on the shop and product archives (Blueprint Part 9 — cards read more
 * premium full-width; filtering moves to chips above the grid, not a sidebar).
 *
 * Astra decides the WooCommerce archive sidebar from the `archive-product-sidebar-layout` option
 * AND the final `astra_page_layout` value, so we override both (high priority) to be certain.
 *
 * @param string $layout Current Astra layout slug.
 * @return string
 */
add_filter( 'astra_page_layout', 'yazan_shop_no_sidebar', 999 );
function yazan_shop_no_sidebar( $layout ) {
	if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
		return 'no-sidebar';
	}
	return $layout;
}

// Override Astra's shop-archive sidebar option directly (belt-and-suspenders with the filter above).
add_filter( 'astra_get_option_archive-product-sidebar-layout', 'yazan_force_no_sidebar_option' );
function yazan_force_no_sidebar_option( $value ) {
	if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
		return 'no-sidebar';
	}
	return $value;
}

/* ============================================================================
 * Helpers
 * ========================================================================== */

/**
 * Build the tracked "subject line" (e.g. "RED AQEEQ · SILVER 925") from product attributes.
 *
 * @param WC_Product $product Product object.
 * @return string Escaped, uppercased subject line, or '' when no attributes exist.
 */
function yazan_subject_line( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$parts = array();
	foreach ( array( 'pa_stone', 'pa_metal' ) as $taxonomy ) {
		$value = $product->get_attribute( $taxonomy );
		if ( $value ) {
			// get_attribute() may return a comma-separated list; take the first term.
			$first   = trim( explode( ',', $value )[0] );
			$parts[] = $first;
		}
	}
	if ( empty( $parts ) ) {
		return '';
	}
	return esc_html( mb_strtoupper( implode( ' · ', $parts ) ) );
}

/**
 * Read a product's Yazan serial, if any.
 *
 * @param int $product_id Product ID.
 * @return string Sanitised serial or ''.
 */
function yazan_get_serial( $product_id ) {
	$serial = get_post_meta( $product_id, '_yz_serial', true );
	return $serial ? sanitize_text_field( $serial ) : '';
}

/* ============================================================================
 * Shop loop (product cards) — Part 7
 * ========================================================================== */

/**
 * Badge overlay on loop items (top-left). Native-Woo driven; no ACF dependency.
 */
add_action( 'woocommerce_before_shop_loop_item_title', 'yazan_loop_badges', 8 );
function yazan_loop_badges() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$badges = array();

	if ( has_term( array( 'one-of-one', 'rare' ), 'product_tag', $product->get_id() ) ) {
		$badges[] = '<span class="yz-badge yz-badge--rare">' . esc_html__( 'One of One', 'yazan' ) . '</span>';
	}

	if ( ! $product->is_in_stock() ) {
		$badges[] = '<span class="yz-badge yz-badge--sold">' . esc_html__( 'Sold', 'yazan' ) . '</span>';
	} elseif ( $product->managing_stock() ) {
		$qty = $product->get_stock_quantity();
		if ( null !== $qty && $qty > 0 && $qty <= 3 ) {
			/* translators: %d: remaining stock quantity. */
			$badges[] = '<span class="yz-badge yz-badge--limited">' . sprintf( esc_html__( 'Limited — %d left', 'yazan' ), (int) $qty ) . '</span>';
		}
	}

	if ( yazan_get_serial( $product->get_id() ) ) {
		$badges[] = '<span class="yz-badge yz-badge--certified">' . esc_html__( 'Certified', 'yazan' ) . '</span>';
	}

	if ( $product->is_on_sale() && $product->is_in_stock() ) {
		$badges[] = '<span class="yz-badge yz-badge--sale">' . esc_html__( 'Offer', 'yazan' ) . '</span>';
	}

	if ( $badges ) {
		echo '<div class="yz-badges">' . implode( '', $badges ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pieces escaped above.
	}
}

/**
 * Second-image markup for the hover crossfade (Part 9 motion). Sits inside the product link,
 * absolutely positioned over the main image via CSS.
 */
add_action( 'woocommerce_before_shop_loop_item_title', 'yazan_loop_secondary_image', 12 );
function yazan_loop_secondary_image() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$gallery = $product->get_gallery_image_ids();
	if ( empty( $gallery ) ) {
		return;
	}
	echo wp_get_attachment_image(
		(int) $gallery[0],
		'woocommerce_thumbnail',
		false,
		array(
			'class'   => 'yz-card__img--alt',
			'aria-hidden' => 'true',
			// The hover crossfade must reveal a READY image. `loading="lazy"` deferred this until the
			// first hover, so on a fresh page load the first hover crossfaded to a blank/late-popping
			// image — the "fast and wrong" first-hover animation. Load it eagerly, but at low fetch
			// priority + async decode so it never competes with the primary/LCP imagery.
			'loading' => 'eager',
			'fetchpriority' => 'low',
			'decoding' => 'async',
		)
	);
}

/**
 * Tracked subject line above the product title in the loop.
 *
 * Hooked to Astra's `astra_woo_shop_title_before`, which fires INSIDE `.astra-shop-summary-wrap`
 * immediately before the title — the reference's eyebrow-above-title rhythm. The obvious hook,
 * `woocommerce_shop_loop_item_title`, is wrong here: in Astra's modern/grid layout the thumbnail
 * wrap does not close until `woocommerce_after_shop_loop_item` @8, so anything on the title hook
 * renders over the image instead of in the card body. Astra's summary-content builder
 * (`astra_woo_woocommerce_shop_product_content`) runs in both shop styles, so this hook is safe.
 */
add_action( 'astra_woo_shop_title_before', 'yazan_loop_subject_line' );
function yazan_loop_subject_line() {
	global $product;
	$subject = yazan_subject_line( $product );
	if ( $subject ) {
		echo '<span class="yz-subject">' . $subject . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper.
	}
}

/**
 * Mark the loop <li> so CSS/JS can target Yazan cards (and flag rare ones for the sheen effect).
 */
add_filter( 'woocommerce_post_class', 'yazan_loop_item_class', 10, 2 );
function yazan_loop_item_class( $classes, $product ) {
	$classes[] = 'yz-card';
	// Scroll-reveal each card via the site-wide observer (Motion Part 7) — cards fade/rise as they
	// enter the viewport, giving the shop grid the same cascade as the homepage. Degrades gracefully:
	// no-JS and reduced-motion both force .yz-reveal visible (see motion.css).
	$classes[] = 'yz-reveal';
	if ( $product instanceof WC_Product && has_term( array( 'one-of-one', 'rare' ), 'product_tag', $product->get_id() ) ) {
		$classes[] = 'yz-card--rare';
	}
	return $classes;
}

/* ============================================================================
 * Shop / archive filter chips — Part 9
 * ========================================================================== */

/**
 * Stone filter chips above the product grid (reference's chip row, not a sidebar).
 *
 * The store is organised BY STONE: each stone is a child of the `the-stones` product category, and
 * those archives render the correctly-coloured subset. So the chips are plain links to those existing
 * category archives — no plugin, no AJAX, fully robust and SEO-friendly — with the current one marked
 * active. "All Stones" returns to the shop. Shown on the shop and any product taxonomy archive.
 */
add_action( 'woocommerce_before_shop_loop', 'yazan_stone_filter_chips', 5 );
function yazan_stone_filter_chips() {
	if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
		return;
	}

	$parent = get_term_by( 'slug', 'the-stones', 'product_cat' );
	if ( ! $parent instanceof WP_Term ) {
		return;
	}

	$stones = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => $parent->term_id,
		'hide_empty' => true,
		'orderby'    => 'count',
		'order'      => 'DESC',
	) );
	if ( is_wp_error( $stones ) || empty( $stones ) ) {
		return;
	}

	// Which chip is active? Only product_cat archives map to a stone chip.
	$current_id = 0;
	if ( is_tax( 'product_cat' ) ) {
		$obj = get_queried_object();
		if ( $obj instanceof WP_Term ) {
			$current_id = (int) $obj->term_id;
		}
	}
	$shop_url  = get_permalink( wc_get_page_id( 'shop' ) );
	$is_all    = is_shop();

	echo '<nav class="yz-chips" aria-label="' . esc_attr__( 'Filter by stone', 'yazan' ) . '">';

	printf(
		'<a class="yz-chip%1$s" href="%2$s"%3$s>%4$s</a>',
		$is_all ? ' is-active' : '',
		esc_url( $shop_url ),
		$is_all ? ' aria-current="page"' : '',
		esc_html__( 'All Stones', 'yazan' )
	);

	foreach ( $stones as $term ) {
		$active   = ( (int) $term->term_id === $current_id );
		$link     = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			continue;
		}
		printf(
			'<a class="yz-chip%1$s" href="%2$s"%3$s>%4$s <span class="yz-chip__n">%5$d</span></a>',
			$active ? ' is-active' : '',
			esc_url( $link ),
			$active ? ' aria-current="page"' : '',
			esc_html( $term->name ),
			(int) $term->count
		);
	}

	echo '</nav>';
}

/* ============================================================================
 * Single product page — Part 8
 * ========================================================================== */

/**
 * Drop the redundant category line Astra prints above the product title.
 *
 * Astra's single-product structure opens with a `category` element, which renders a
 * `.single-product-category` link ("Uncategorized") wedged between our eyebrow (pa_stone · pa_metal)
 * and the H1 — breaking the designed eyebrow→title rhythm and duplicating the category already shown
 * in the breadcrumb and in the product meta below the form. Remove just that element; every other
 * element keeps its order.
 *
 * @param array  $structure    Ordered summary element keys.
 * @param string $product_type Current product type (unused).
 * @return array
 */
add_filter( 'astra_woo_single_product_structure', 'yazan_single_summary_structure', 10, 2 );
function yazan_single_summary_structure( $structure, $product_type ) {
	if ( is_array( $structure ) ) {
		return array_values( array_diff( $structure, array( 'category' ) ) );
	}
	return $structure;
}

/**
 * Eyebrow / subject line above the product title.
 */
add_action( 'woocommerce_single_product_summary', 'yazan_single_subject_line', 4 );
function yazan_single_subject_line() {
	global $product;
	$subject = yazan_subject_line( $product );
	if ( $subject ) {
		echo '<p class="yz-eyebrow">' . $subject . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper.
	}
}

/**
 * Serial / certification line under the title — the "LICENSED" equivalent (Part 8, step 3).
 * Renders only when a real serial exists on the product, so it never fabricates certification.
 */
add_action( 'woocommerce_single_product_summary', 'yazan_single_serial_line', 6 );
function yazan_single_serial_line() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$serial = yazan_get_serial( $product->get_id() );
	if ( ! $serial ) {
		return;
	}
	// Deep-link to this ring's certificate via the pretty /verify-ring/{serial}/ route.
	$verify_url = home_url( '/verify-ring/' . rawurlencode( $serial ) . '/' );
	printf(
		'<p class="yz-serial"><span class="yz-serial__icon" aria-hidden="true">&#9733;</span> %1$s <a href="%2$s">%3$s</a></p>',
		sprintf(
			/* translators: %s: serial number. */
			esc_html__( 'Serial %s · Digitally certified', 'yazan' ),
			'<strong>' . esc_html( $serial ) . '</strong>'
		),
		esc_url( $verify_url ),
		esc_html__( 'Verify', 'yazan' )
	);
}

/* ============================================================================
 * Single product — Comma-style commerce block (arrows · save badge · reassurance ·
 * payment strip · accordion sections)
 * ========================================================================== */

/**
 * Enable flexslider prev/next arrows on the main product image (off by default in WooCommerce).
 * Styled to on-brand ink discs in woocommerce.css.
 *
 * @param array $options Flexslider options passed to the gallery script.
 * @return array
 */
add_filter( 'woocommerce_single_product_carousel_options', 'yazan_gallery_arrows' );
function yazan_gallery_arrows( $options ) {
	$options['directionNav'] = true;
	return $options;
}

/**
 * Append a "Save N%" pill inside the MAIN product's price HTML (Comma's "SAVE 20%" chip), inline with
 * the sale price. Scoped to the queried product on its own page so it never touches related/upsell
 * cards, the loop, or variation price templates. Numeric-guarded, so variable products (whose
 * regular price is a range, not a scalar) are skipped rather than mis-computed.
 *
 * @param string     $html    Price HTML.
 * @param WC_Product $product Product being priced.
 * @return string
 */
add_filter( 'woocommerce_get_price_html', 'yazan_inline_save_badge', 20, 2 );
function yazan_inline_save_badge( $html, $product ) {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	if ( ! $product instanceof WC_Product || (int) $product->get_id() !== (int) get_queried_object_id() ) {
		return $html;
	}
	if ( ! $product->is_on_sale() ) {
		return $html;
	}
	$regular = (float) $product->get_regular_price();
	$sale    = (float) $product->get_price();
	if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
		return $html;
	}
	$pct = (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
	if ( $pct <= 0 ) {
		return $html;
	}
	/* translators: %d: discount percentage. */
	$label = sprintf( esc_html__( 'Save %d%%', 'yazan' ), $pct );
	return $html . ' <span class="yz-save">' . $label . '</span>';
}

/**
 * Reassurance sub-line directly under the Add-to-Cart button (Comma's "Wait & Save…" line), tuned to
 * Yazan's real trust signals. Renders inside the cart form. Only shows truthful items: the certificate
 * line appears only for products that actually carry a serial; the shipping line is generic and safe.
 */
add_action( 'woocommerce_after_add_to_cart_button', 'yazan_atc_reassurance' );
function yazan_atc_reassurance() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$items = array();
	if ( yazan_get_serial( $product->get_id() ) ) {
		$items[] = esc_html__( 'Certificate of authenticity included', 'yazan' );
	}
	$items[] = esc_html__( 'Complimentary insured shipping over $500', 'yazan' );

	echo '<p class="yz-atc-note">';
	foreach ( $items as $i => $text ) {
		if ( $i > 0 ) {
			echo '<span class="yz-atc-note__sep" aria-hidden="true">·</span>';
		}
		echo '<span class="yz-atc-note__item">' . $text . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}
	echo '</p>';
}

/**
 * Muted payment / trust strip after the cart form (Comma's card-icon row, restyled monochrome so it
 * reads luxe on the dark theme). Pure inline SVG (no external requests); marks inherit currentColor.
 */
add_action( 'woocommerce_after_add_to_cart_form', 'yazan_payment_strip' );
function yazan_payment_strip() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$marks = array( 'Visa', 'Mastercard', 'Amex', 'PayPal', 'Apple Pay' );
	echo '<div class="yz-pay" role="img" aria-label="' . esc_attr__( 'Accepted payment methods', 'yazan' ) . '">';
	echo '<span class="yz-pay__lock" aria-hidden="true">'
		. '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>'
		. '</span>';
	echo '<span class="yz-pay__label">' . esc_html__( 'Secure checkout', 'yazan' ) . '</span>';
	echo '<span class="yz-pay__marks">';
	foreach ( $marks as $mark ) {
		echo '<span class="yz-pay__mark">' . esc_html( $mark ) . '</span>';
	}
	echo '</span>';
	echo '</div>';
}

/**
 * Add on-brand accordion sections to the product tabs (the JS in interactions.js turns the whole tab
 * block into a Comma-style accordion). Content is truthful and generic: the authenticity panel ties
 * into the store's real serial/verify system; care advice is factually correct for silver + agate.
 *
 * @param array $tabs Registered WooCommerce product tabs.
 * @return array
 */
add_filter( 'woocommerce_product_tabs', 'yazan_product_tabs', 20 );
function yazan_product_tabs( $tabs ) {
	$tabs['yz_authenticity'] = array(
		'title'    => __( 'Authenticity & Certificate', 'yazan' ),
		'priority' => 25,
		'callback' => 'yazan_tab_authenticity',
	);
	$tabs['yz_care'] = array(
		'title'    => __( 'Care & Handling', 'yazan' ),
		'priority' => 30,
		'callback' => 'yazan_tab_care',
	);
	return $tabs;
}

/**
 * Authenticity panel — references the real per-piece serial + /verify-ring/ system when present.
 */
function yazan_tab_authenticity() {
	global $product;
	$serial = $product instanceof WC_Product ? yazan_get_serial( $product->get_id() ) : '';
	echo '<p>' . esc_html__( 'Every Yazan piece is hallmarked sterling silver set with genuine Yemeni agate (aqeeq), documented and photographed before it leaves the atelier.', 'yazan' ) . '</p>';
	if ( $serial ) {
		$verify_url = home_url( '/verify-ring/' . rawurlencode( $serial ) . '/' );
		printf(
			'<p>%1$s <a href="%2$s"><strong>%3$s</strong></a>.</p>',
			esc_html__( 'This ring carries serial', 'yazan' ),
			esc_url( $verify_url ),
			esc_html( $serial )
		);
	} else {
		echo '<p>' . esc_html__( 'A digital certificate of authenticity accompanies certified pieces.', 'yazan' ) . '</p>';
	}
}

/**
 * Care panel — factual guidance for sterling silver + agate.
 */
function yazan_tab_care() {
	echo '<ul>';
	echo '<li>' . esc_html__( 'Store in the supplied pouch, away from moisture and direct sunlight.', 'yazan' ) . '</li>';
	echo '<li>' . esc_html__( 'Remove before showering, swimming, or applying perfume and lotions.', 'yazan' ) . '</li>';
	echo '<li>' . esc_html__( 'Polish the silver gently with a soft dry cloth; avoid chemical dips on the stone.', 'yazan' ) . '</li>';
	echo '</ul>';
}

/* ============================================================================
 * Single product — gallery + summary wrapper (Comma-style sticky column)
 * ========================================================================== */

/**
 * Wrap ONLY the gallery + `.summary` in a flex row so the summary can be `position: sticky`
 * bounded to that row (not the whole `div.product`, which also contains the full-width tabs and
 * related rows). This is the template wrapper the old CSS NOTE said pure CSS couldn't provide —
 * built with hooks instead of a template override, so it degrades to a harmless empty wrapper if
 * WooCommerce ever changes the surrounding markup.
 *
 * Open at priority 4 on `woocommerce_before_single_product_summary` — before the sale flash (@10)
 * and the product images (@20), so the wrapper encloses the gallery. Close at priority 4 on
 * `woocommerce_after_single_product_summary` — before the tabs (@10), upsells (@15) and related
 * (@20), so those stay OUTSIDE the wrapper and remain full-width. The core `.summary` div sits
 * between the two action hooks in content-single-product.php, so it nests correctly inside.
 *
 * Scoped to real product pages only; never touches archives or shortcodes.
 */
add_action( 'woocommerce_before_single_product_summary', 'yazan_open_product_top', 4 );
function yazan_open_product_top() {
	if ( function_exists( 'is_product' ) && is_product() ) {
		echo '<div class="yz-product-top">';
	}
}

add_action( 'woocommerce_after_single_product_summary', 'yazan_close_product_top', 4 );
function yazan_close_product_top() {
	if ( function_exists( 'is_product' ) && is_product() ) {
		echo '</div><!-- /.yz-product-top -->';
	}
}

/* ============================================================================
 * Sticky mobile Add-to-Cart bar — Part 15 / motion Part 12
 * ========================================================================== */

/**
 * Output a lightweight sticky bar on single product pages. Hidden by default; revealed by JS
 * once the main add-to-cart form scrolls out of view. Submits the real product form.
 */
add_action( 'wp_footer', 'yazan_sticky_atc_bar' );
function yazan_sticky_atc_bar() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}
	?>
	<div class="yz-sticky-atc" data-yz-sticky-atc hidden aria-hidden="true">
		<div class="yz-sticky-atc__info">
			<span class="yz-sticky-atc__name"><?php echo esc_html( $product->get_name() ); ?></span>
			<span class="yz-sticky-atc__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		</div>
		<button type="button" class="yz-sticky-atc__btn"><?php esc_html_e( 'Add to Cart', 'yazan' ); ?></button>
	</div>
	<?php
}
