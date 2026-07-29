<?php
/**
 * Yazan — Shop filter sidebar + toolbar (commafootball-style shop layout).
 *
 * Turns the /store/ archive into a two-column layout: a functional left FILTER SIDEBAR
 * (Stone / Metal / Price) beside the product grid, with a top toolbar (filter toggle + product
 * search + result count + sort). Replaces the previous chip row.
 *
 * Filtering is server-side, plugin-free and SEO-friendly: a GET form submits `yz_stone[]`,
 * `yz_metal[]`, `yz_min`, `yz_max`; the WooCommerce product query is then narrowed via the
 * `woocommerce_product_query_tax_query` / `_meta_query` filters, so it stacks correctly on the
 * shop AND on any product-category archive.
 *
 * DOM is built with balanced hooks (no template override): the wrapper opens on
 * `woocommerce_before_shop_loop` (or `woocommerce_no_products_found`) and closes on
 * `woocommerce_after_shop_loop` (or `_no_products_found`) — so it is always balanced whether or
 * not the current filter set returns any products, and the sidebar never disappears on 0 results.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

/* ============================================================================
 * Config — which attribute taxonomies power the sidebar groups.
 * pa_size is intentionally omitted (no terms in this catalogue).
 * ========================================================================== */

/**
 * Facet map: request param => attribute taxonomy + label.
 *
 * @return array
 */
function yazan_shop_facets() {
	return apply_filters(
		'yazan_shop_facets',
		array(
			'yz_stone' => array( 'taxonomy' => 'pa_stone', 'label' => __( 'Stone', 'yazan' ) ),
			'yz_metal' => array( 'taxonomy' => 'pa_metal', 'label' => __( 'Metal', 'yazan' ) ),
		)
	);
}

/**
 * Read a facet's selected, sanitised term slugs from the request.
 *
 * @param string $param    Request key (e.g. yz_stone).
 * @param string $taxonomy Attribute taxonomy to validate against.
 * @return string[] Valid slugs currently selected.
 */
function yazan_facet_selected( $param, $taxonomy ) {
	if ( empty( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only browse filter.
		return array();
	}
	$raw   = wp_unslash( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
	$slugs = array_map( 'sanitize_title', (array) $raw );
	$slugs = array_values( array_unique( array_filter( $slugs ) ) );
	if ( empty( $slugs ) ) {
		return array();
	}
	// Keep only slugs that are real terms in this taxonomy.
	$valid = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs' ) );
	if ( is_wp_error( $valid ) ) {
		return array();
	}
	return array_values( array_intersect( $slugs, $valid ) );
}

/**
 * Read the selected price bounds from the request.
 *
 * @return array{0: float|null, 1: float|null} [min, max].
 */
function yazan_price_selected() {
	$min = isset( $_GET['yz_min'] ) && '' !== $_GET['yz_min'] ? (float) $_GET['yz_min'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$max = isset( $_GET['yz_max'] ) && '' !== $_GET['yz_max'] ? (float) $_GET['yz_max'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( null !== $min && $min < 0 ) {
		$min = 0.0;
	}
	if ( null !== $min && null !== $max && $max < $min ) {
		// Swap if the user inverted them.
		$tmp = $min;
		$min = $max;
		$max = $tmp;
	}
	return array( $min, $max );
}

/**
 * The catalogue-wide min/max price (cached per request), for the price input bounds.
 *
 * @return array{0: int, 1: int}
 */
function yazan_price_range() {
	static $range = null;
	if ( null !== $range ) {
		return $range;
	}
	global $wpdb;
	$min = (float) $wpdb->get_var( "SELECT MIN(meta_value+0) FROM {$wpdb->postmeta} WHERE meta_key='_price' AND meta_value!=''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$max = (float) $wpdb->get_var( "SELECT MAX(meta_value+0) FROM {$wpdb->postmeta} WHERE meta_key='_price' AND meta_value!=''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$range = array( (int) floor( $min ), (int) ceil( $max ) );
	return $range;
}

/**
 * True on the shop or any product taxonomy archive.
 *
 * @return bool
 */
function yazan_is_shop_archive() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

/* ============================================================================
 * Query narrowing — apply the selected facets to the product archive query.
 * ========================================================================== */

add_filter( 'woocommerce_product_query_tax_query', 'yazan_apply_facet_tax_query', 10, 2 );
/**
 * Add a tax clause per selected facet (OR within a facet, AND across facets).
 *
 * @param array $tax_query Existing tax query.
 * @return array
 */
function yazan_apply_facet_tax_query( $tax_query ) {
	foreach ( yazan_shop_facets() as $param => $facet ) {
		$slugs = yazan_facet_selected( $param, $facet['taxonomy'] );
		if ( $slugs ) {
			$tax_query[] = array(
				'taxonomy' => $facet['taxonomy'],
				'field'    => 'slug',
				'terms'    => $slugs,
				'operator' => 'IN',
			);
		}
	}
	return $tax_query;
}

add_filter( 'woocommerce_product_query_meta_query', 'yazan_apply_price_meta_query', 10, 2 );
/**
 * Add a `_price` range clause when the price filter is used.
 *
 * @param array $meta_query Existing meta query.
 * @return array
 */
function yazan_apply_price_meta_query( $meta_query ) {
	list( $min, $max ) = yazan_price_selected();
	if ( null === $min && null === $max ) {
		return $meta_query;
	}
	$clause = array( 'key' => '_price', 'type' => 'NUMERIC' );
	if ( null !== $min && null !== $max ) {
		$clause['value']   = array( $min, $max );
		$clause['compare'] = 'BETWEEN';
	} elseif ( null !== $min ) {
		$clause['value']   = $min;
		$clause['compare'] = '>=';
	} else {
		$clause['value']   = $max;
		$clause['compare'] = '<=';
	}
	$meta_query[] = $clause;
	return $meta_query;
}

/* ============================================================================
 * Layout — replace chips, force 3-up grid, left-align header + intro.
 * ========================================================================== */

// The sidebar supersedes the stone chip row.
remove_action( 'woocommerce_before_shop_loop', 'yazan_stone_filter_chips', 5 );

// Three columns beside the sidebar (CSS also enforces this; belt-and-suspenders).
add_filter( 'loop_shop_columns', 'yazan_shop_columns', 20 );
function yazan_shop_columns( $cols ) {
	return yazan_is_shop_archive() ? 3 : $cols;
}

// Big left-aligned title + intro lede in the shop header (Astra suppresses the shop H1, and the
// reference leads with a large "All Products" heading). Only on the main shop archive.
add_action( 'woocommerce_archive_description', 'yazan_shop_header', 12 );
function yazan_shop_header() {
	if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
		return;
	}

	$title = apply_filters( 'yazan_shop_title_text', function_exists( 'woocommerce_page_title' ) ? woocommerce_page_title( false ) : __( 'Store', 'yazan' ) );
	if ( $title ) {
		echo '<h1 class="woocommerce-products-header__title page-title yz-shop-title">' . esc_html( $title ) . '</h1>';
	}

	$intro = apply_filters(
		'yazan_shop_intro_text',
		__( 'Every ring is a single Yemeni agate, hand-cut and set in sterling silver. Filter by stone, metal or price to find the one that is yours alone.', 'yazan' )
	);
	if ( $intro ) {
		echo '<p class="yz-shop-intro">' . esc_html( $intro ) . '</p>';
	}
}

/* ============================================================================
 * Toolbar — swap Woo's loose result-count/ordering for a single styled row.
 * ========================================================================== */

add_action( 'woocommerce_before_shop_loop', 'yazan_shop_dequeue_default_toolbar', 1 );
function yazan_shop_dequeue_default_toolbar() {
	if ( ! yazan_is_shop_archive() ) {
		return;
	}
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

	// Suppress stray session notices (e.g. leftover cart/coupon errors) leaking onto the shop grid.
	// Astra modern add-to-cart is AJAX (feedback shows in the mini-cart drawer), so the notice bar
	// is redundant on the archive anyway. Notices still render on cart/checkout where they belong.
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_output_all_notices', 10 );
}

/* ============================================================================
 * Wrapper open/close — balanced across the products and no-products branches.
 * ========================================================================== */

add_action( 'woocommerce_before_shop_loop', 'yazan_shop_open', 2 );
add_action( 'woocommerce_no_products_found', 'yazan_shop_open', 2 );
/**
 * Open <div.yz-shop> → full-width toolbar → <div.yz-shop__body> → <aside sidebar> →
 * <div.yz-shop__main> → <div.yz-shop__results>. Leaves results open for the WooCommerce grid.
 *
 * The toolbar sits ABOVE the sidebar+grid row (commafootball layout), so the filter toggle keeps
 * a fixed top-left position whether the sidebar is shown or collapsed — it never shifts sideways.
 */
function yazan_shop_open() {
	if ( ! yazan_is_shop_archive() ) {
		return;
	}
	echo '<div class="yz-shop">';
	// No-FOUC filter state: on mobile the sidebar starts collapsed (drawer) before first paint, so the
	// grid never flashes wide→narrow. Desktop keeps it open by default. With JS disabled the class is
	// never added, so the sidebar stays visible and GET filtering still works.
	echo '<script>(function(){try{var d=document.currentScript.parentNode;if(!window.matchMedia("(min-width: 992px)").matches){d.className+=" yz-shop--nofilters";}}catch(e){}})();</script>';
	yazan_render_shop_toolbar();
	echo '<div class="yz-shop__body">';
	yazan_render_filter_sidebar();
	echo '<div class="yz-shop__main">';
	// Everything inside .yz-shop__results is what AJAX swaps in on filter change (the grid,
	// pagination and the applied-filter chips) — the toolbar (search + sort) stays put and bound.
	echo '<div class="yz-shop__results">';
	yazan_render_applied_filters();
}

/**
 * Build a shop URL for a given set of facet selections + price + sort (AliExpress-style
 * remove/clear links).
 *
 * @param string $base   Shop base URL.
 * @param array  $facets Map of param => slug[] to keep.
 * @param float|null $min Price min or null.
 * @param float|null $max Price max or null.
 * @param string $orderby Current sort or ''.
 * @return string
 */
function yazan_build_filter_url( $base, $facets, $min, $max, $orderby ) {
	$args = array();
	foreach ( $facets as $param => $slugs ) {
		$slugs = array_values( array_filter( (array) $slugs ) );
		if ( $slugs ) {
			$args[ $param ] = $slugs;
		}
	}
	if ( null !== $min ) {
		$args['yz_min'] = (int) $min;
	}
	if ( null !== $max ) {
		$args['yz_max'] = (int) $max;
	}
	if ( $orderby ) {
		$args['orderby'] = $orderby;
	}
	return $args ? add_query_arg( $args, $base ) : $base;
}

/**
 * The "applied filters" chip row above the grid: one removable chip per active value + Clear all.
 */
function yazan_render_applied_filters() {
	$facets  = yazan_shop_facets();
	$base    = get_permalink( wc_get_page_id( 'shop' ) );
	$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Current selections.
	$current = array();
	foreach ( $facets as $param => $facet ) {
		$sel = yazan_facet_selected( $param, $facet['taxonomy'] );
		if ( $sel ) {
			$current[ $param ] = $sel;
		}
	}
	list( $min, $max ) = yazan_price_selected();

	$chips = array();

	// One chip per selected facet value (its link removes just that value).
	foreach ( $facets as $param => $facet ) {
		if ( empty( $current[ $param ] ) ) {
			continue;
		}
		foreach ( $current[ $param ] as $slug ) {
			$term      = get_term_by( 'slug', $slug, $facet['taxonomy'] );
			$label     = $term instanceof WP_Term ? $term->name : $slug;
			$remaining = $current;
			$remaining[ $param ] = array_values( array_diff( $current[ $param ], array( $slug ) ) );
			$chips[]   = array(
				'label' => $label,
				'url'   => yazan_build_filter_url( $base, $remaining, $min, $max, $orderby ),
			);
		}
	}

	// Price chip (its link removes price only).
	if ( null !== $min || null !== $max ) {
		$cur = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		if ( null !== $min && null !== $max ) {
			$plabel = $cur . (int) $min . ' – ' . $cur . (int) $max;
		} elseif ( null !== $min ) {
			$plabel = $cur . (int) $min . '+';
		} else {
			$plabel = '≤ ' . $cur . (int) $max;
		}
		$chips[] = array(
			'label' => $plabel,
			'url'   => yazan_build_filter_url( $base, $current, null, null, $orderby ),
		);
	}

	if ( empty( $chips ) ) {
		return;
	}

	echo '<div class="yz-applied">';
	echo '<span class="yz-applied__label">' . esc_html__( 'Filters:', 'yazan' ) . '</span>';
	foreach ( $chips as $chip ) {
		printf(
			'<a class="yz-applied__chip" href="%1$s"><span class="yz-applied__text">%2$s</span><span class="yz-applied__x" aria-hidden="true">&times;</span><span class="screen-reader-text">%3$s</span></a>',
			esc_url( $chip['url'] ),
			esc_html( $chip['label'] ),
			esc_html__( 'Remove filter', 'yazan' )
		);
	}
	printf(
		'<a class="yz-applied__clear" href="%1$s">%2$s</a>',
		esc_url( yazan_build_filter_url( $base, array(), null, null, $orderby ) ),
		esc_html__( 'Clear all', 'yazan' )
	);
	echo '</div>';
}

add_action( 'woocommerce_after_shop_loop', 'yazan_shop_close', 999 );
add_action( 'woocommerce_no_products_found', 'yazan_shop_close', 999 );
/**
 * Close <div.yz-shop__main> and <div.yz-shop>.
 */
function yazan_shop_close() {
	if ( ! yazan_is_shop_archive() ) {
		return;
	}
	echo '</div><!-- .yz-shop__results --></div><!-- .yz-shop__main --></div><!-- .yz-shop__body --></div><!-- .yz-shop -->';
}

/**
 * The top toolbar: product search + result count + ordering. (The filter toggle lives inside the
 * sidebar form now — see yazan_render_filter_sidebar().)
 */
function yazan_render_shop_toolbar() {
	?>
	<div class="yz-shop__toolbar">
		<form class="yz-shop__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'Search rings, stones, collections…', 'yazan' ); ?>" aria-label="<?php esc_attr_e( 'Search products', 'yazan' ); ?>">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" class="yz-shop__search-btn" aria-label="<?php esc_attr_e( 'Search', 'yazan' ); ?>">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
			</button>
		</form>

		<div class="yz-shop__tools">
			<?php
			// Render the count from the main query directly: at this early hook priority WooCommerce's
			// loop props aren't set yet, so woocommerce_result_count() would print an empty element.
			$total = isset( $GLOBALS['wp_query'] ) ? (int) $GLOBALS['wp_query']->found_posts : 0;
			printf(
				'<p class="woocommerce-result-count" role="status">%s</p>',
				esc_html( sprintf( /* translators: %s: number of products. */ _n( '%s result', '%s results', $total, 'yazan' ), number_format_i18n( $total ) ) )
			);
			if ( function_exists( 'woocommerce_catalog_ordering' ) ) {
				woocommerce_catalog_ordering();
			}
			?>
		</div>
	</div>
	<?php
}

/**
 * Count of currently-applied filter values (facets + price), for the toggle badge.
 *
 * @return int
 */
function yazan_active_filter_count() {
	$n = 0;
	foreach ( yazan_shop_facets() as $param => $facet ) {
		$n += count( yazan_facet_selected( $param, $facet['taxonomy'] ) );
	}
	list( $min, $max ) = yazan_price_selected();
	if ( null !== $min || null !== $max ) {
		$n++;
	}
	return $n;
}

/**
 * The functional filter sidebar (GET form). Groups: each facet's terms + a price range.
 */
function yazan_render_filter_sidebar() {
	$facets    = yazan_shop_facets();
	$action    = get_permalink( wc_get_page_id( 'shop' ) );
	$has_active = yazan_active_filter_count() > 0;

	// Preserve the current sort when applying filters.
	$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<aside class="yz-shop__sidebar" id="yz-shop-filters">
		<button type="button" class="yz-shop__filter-toggle" data-yz-filter-toggle aria-expanded="true" aria-controls="yz-filters-body">
			<svg class="yz-shop__filter-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><polygon points="3 5 21 5 14 13 14 20 10 22 10 13 3 5"/></svg>
			<span class="yz-shop__filter-toggle-label" data-label-shown="<?php esc_attr_e( 'Close filters', 'yazan' ); ?>" data-label-hidden="<?php esc_attr_e( 'Filters', 'yazan' ); ?>"><?php esc_html_e( 'Close filters', 'yazan' ); ?></span>
			<?php if ( $has_active ) : ?><span class="yz-shop__filter-count"><?php echo (int) yazan_active_filter_count(); ?></span><?php endif; ?>
			<svg class="yz-shop__filter-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
		</button>
		<form class="yz-filters" method="get" action="<?php echo esc_url( $action ); ?>">
			<div class="yz-filters__groups" id="yz-filters-body">
			<?php
			foreach ( $facets as $param => $facet ) :
				$terms = get_terms( array( 'taxonomy' => $facet['taxonomy'], 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$selected  = yazan_facet_selected( $param, $facet['taxonomy'] );
				$threshold = 6; // AliExpress-style: show a few, then "View more".
				$idx       = 0;
				$has_more  = count( $terms ) > $threshold;
				?>
				<div class="yz-filter-group" data-yz-filter-group>
					<button type="button" class="yz-filter-group__head" aria-expanded="true">
						<span><?php echo esc_html( $facet['label'] ); ?></span>
						<svg class="yz-filter-group__chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
					</button>
					<ul class="yz-filter-group__body">
						<?php
						foreach ( $terms as $term ) :
							// Always keep a selected term visible even if it's past the fold.
							$is_extra = ( $idx >= $threshold ) && ! in_array( $term->slug, $selected, true );
							$idx++;
							?>
							<li class="<?php echo $is_extra ? 'yz-opt--extra' : ''; ?>">
								<label class="yz-filter-option">
									<input type="checkbox" name="<?php echo esc_attr( $param ); ?>[]" value="<?php echo esc_attr( $term->slug ); ?>"<?php checked( in_array( $term->slug, $selected, true ) ); ?>>
									<span class="yz-filter-option__name"><?php echo esc_html( $term->name ); ?></span>
									<span class="yz-filter-option__n"><?php echo (int) $term->count; ?></span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $has_more ) : ?>
						<button type="button" class="yz-filter-more" data-yz-filter-more aria-expanded="false">
							<span class="yz-filter-more__show">
								<?php
								/* translators: %d: number of hidden filter options. */
								printf( esc_html__( 'View more (%d)', 'yazan' ), (int) ( count( $terms ) - $threshold ) );
								?>
							</span>
							<span class="yz-filter-more__hide"><?php esc_html_e( 'View less', 'yazan' ); ?></span>
						</button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php list( $pmin, $pmax ) = yazan_price_range(); list( $smin, $smax ) = yazan_price_selected(); ?>
			<div class="yz-filter-group" data-yz-filter-group>
				<button type="button" class="yz-filter-group__head" aria-expanded="true">
					<span><?php esc_html_e( 'Price', 'yazan' ); ?></span>
					<svg class="yz-filter-group__chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
				</button>
				<div class="yz-filter-group__body yz-price">
					<div class="yz-price__inputs">
						<label class="yz-price__field">
							<span class="screen-reader-text"><?php esc_html_e( 'Minimum price', 'yazan' ); ?></span>
							<span class="yz-price__cur"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?></span>
							<input type="number" name="yz_min" inputmode="numeric" min="<?php echo esc_attr( $pmin ); ?>" max="<?php echo esc_attr( $pmax ); ?>" step="1" placeholder="<?php echo esc_attr( $pmin ); ?>" value="<?php echo null !== $smin ? esc_attr( (int) $smin ) : ''; ?>">
						</label>
						<span class="yz-price__dash" aria-hidden="true">—</span>
						<label class="yz-price__field">
							<span class="screen-reader-text"><?php esc_html_e( 'Maximum price', 'yazan' ); ?></span>
							<span class="yz-price__cur"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?></span>
							<input type="number" name="yz_max" inputmode="numeric" min="<?php echo esc_attr( $pmin ); ?>" max="<?php echo esc_attr( $pmax ); ?>" step="1" placeholder="<?php echo esc_attr( $pmax ); ?>" value="<?php echo null !== $smax ? esc_attr( (int) $smax ) : ''; ?>">
						</label>
					</div>
				</div>
			</div>

			<?php if ( $orderby ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
			<?php endif; ?>

			<div class="yz-filters__actions">
				<button type="submit" class="yz-btn-solid"><?php esc_html_e( 'Apply filters', 'yazan' ); ?></button>
			</div>
			</div><!-- .yz-filters__groups -->
		</form>
	</aside>
	<?php
}

/* ============================================================================
 * Live (instant) product search — AJAX endpoint for the toolbar search box.
 * ========================================================================== */

add_action( 'wp_ajax_yazan_live_search', 'yazan_live_search' );
add_action( 'wp_ajax_nopriv_yazan_live_search', 'yazan_live_search' );
/**
 * Return up to N matching products as JSON for the as-you-type dropdown.
 *
 * Read-only, nonce-guarded, catalogue-visibility aware. Responds to GET `q`.
 */
function yazan_live_search() {
	check_ajax_referer( 'yazan_live_search', 'nonce' );

	$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) < 2 : strlen( $term ) < 2 ) {
		wp_send_json( array( 'items' => array() ) );
	}

	$query = new WP_Query(
		array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => 6,
			's'                   => $term,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-search' ),
					'operator' => 'NOT IN',
				),
			),
		)
	);

	$items = array();
	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$thumb = get_the_post_thumbnail_url( $post->ID, 'woocommerce_gallery_thumbnail' );
		if ( ! $thumb && function_exists( 'wc_placeholder_img_src' ) ) {
			$thumb = wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' );
		}
		$items[] = array(
			'title' => $product->get_name(),
			'url'   => get_permalink( $post->ID ),
			'price' => $product->get_price_html(),
			'thumb' => $thumb ? $thumb : '',
		);
	}
	wp_reset_postdata();

	wp_send_json(
		array(
			'items' => $items,
			'more'  => add_query_arg(
				array(
					's'         => rawurlencode( $term ),
					'post_type' => 'product',
				),
				home_url( '/' )
			),
		)
	);
}
