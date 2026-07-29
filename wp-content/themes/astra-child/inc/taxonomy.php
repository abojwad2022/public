<?php
/**
 * Yazan — taxonomy wiring (redesigned IA).
 *
 * The store's browse spine is: The Stones (primary, colour) · Rings (Men's/Women's) · Collections.
 * Faceted filtering is powered by global attributes. This module registers the additional facets
 * the redesign introduced and keeps the colour category ↔ pa_color pairing coherent.
 *
 * Structural taxonomy (categories, attribute terms) is provisioned by the one-off setup scripts;
 * this file is the durable, in-theme wiring that the storefront reads on every request.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add "Wearer" (pa_wearer: Men / Women / Unisex) to the shop filter sidebar.
 *
 * pa_wearer is backfilled deterministically from the Men's / Women's Rings categories, so it has
 * real term counts and renders as a working facet immediately. Additional facets (pa_style,
 * pa_shape, pa_engraving, pa_rarity) join automatically once products carry those terms — the
 * sidebar hides empty facets, so listing them here early is harmless.
 *
 * @param array $facets Existing facet map (param => [taxonomy,label]).
 * @return array
 */
add_filter( 'yazan_shop_facets', 'yazan_extra_shop_facets' );
function yazan_extra_shop_facets( $facets ) {
	$extra = array(
		'yz_wearer'    => array( 'taxonomy' => 'pa_wearer',    'label' => __( 'Wearer', 'yazan' ) ),
		'yz_style'     => array( 'taxonomy' => 'pa_style',     'label' => __( 'Design Style', 'yazan' ) ),
		'yz_shape'     => array( 'taxonomy' => 'pa_shape',     'label' => __( 'Stone Shape', 'yazan' ) ),
		'yz_engraving' => array( 'taxonomy' => 'pa_engraving', 'label' => __( 'Engraving', 'yazan' ) ),
		'yz_rarity'    => array( 'taxonomy' => 'pa_rarity',    'label' => __( 'Rarity', 'yazan' ) ),
	);

	// Only surface a facet once its taxonomy actually has assigned terms — keeps the sidebar honest
	// while the catalogue is being enriched. (The sidebar itself also hides empty term lists.)
	foreach ( $extra as $param => $facet ) {
		$terms = get_terms( array( 'taxonomy' => $facet['taxonomy'], 'hide_empty' => true, 'number' => 1, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$facets[ $param ] = $facet;
		}
	}

	return $facets;
}
