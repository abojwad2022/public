<?php
/**
 * Yazan — demo content (dummy images for empty homepage slots).
 *
 * Fills empty image slots with self-contained SVG placeholders (data URIs — NO external
 * requests) so the homepage reads as fully populated during the build. Real ACF images always
 * win (placeholders only fill when a slot is empty). Turn the whole thing off with:
 *   add_filter( 'yazan_enable_demo', '__return_false' );
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build an on-brand placeholder image as an SVG data URI (obsidian ground, a soft "stone"
 * glow, and a label). Safe to use as a CSS background-image or <img> src.
 *
 * @param string $label  Short label to render.
 * @param string $bg     Base background hex.
 * @param string $accent Accent (glow + text) hex.
 * @return string data: URI.
 */
function yazan_demo_placeholder( $label = 'YAZAN', $bg = '#161513', $accent = '#C08A3E' ) {
	$label = strtoupper( trim( preg_replace( '/[^A-Za-z0-9 ]/', '', (string) $label ) ) );

	$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 1500" preserveAspectRatio="xMidYMid slice">';
	$svg .= '<defs><radialGradient id="g" cx="56%" cy="34%" r="82%">';
	$svg .= '<stop offset="0%" stop-color="#2c2521"/><stop offset="62%" stop-color="' . $bg . '"/>';
	$svg .= '</radialGradient></defs>';
	$svg .= '<rect width="1200" height="1500" fill="url(#g)"/>';
	$svg .= '<circle cx="660" cy="520" r="240" fill="none" stroke="' . $accent . '" stroke-opacity="0.22" stroke-width="2"/>';
	$svg .= '<circle cx="660" cy="520" r="118" fill="' . $accent . '" fill-opacity="0.10"/>';
	$svg .= '<text x="600" y="905" text-anchor="middle" fill="' . $accent . '" font-family="Georgia,serif" font-size="66" letter-spacing="8">' . $label . '</text>';
	$svg .= '</svg>';

	return 'data:image/svg+xml,' . rawurlencode( $svg );
}

/**
 * URL for a bundled demo image (theme assets/img/demo/). Falls back to an SVG placeholder
 * if the file is missing, so the page never shows a broken image.
 *
 * @param string $file  Filename in assets/img/demo/.
 * @param string $label Placeholder label if the file is absent.
 * @return string
 */
function yazan_demo_img( $file, $label = 'YAZAN' ) {
	$path = YAZAN_DIR . '/assets/img/demo/' . $file;
	if ( file_exists( $path ) ) {
		return YAZAN_URI . '/assets/img/demo/' . $file;
	}
	return yazan_demo_placeholder( $label );
}

if ( apply_filters( 'yazan_enable_demo', true ) ) {

	// Hero / story / collection-story image slots — real ACF image wins; else a demo photo.
	add_filter( 'yazan_home_image_hero_image', function ( $url ) {
		return $url ? $url : yazan_demo_img( 'hero.jpg', 'YAZAN' );
	} );
	add_filter( 'yazan_home_image_story_image', function ( $url ) {
		return $url ? $url : yazan_demo_img( 'story.jpg', 'The Craft' );
	} );
	add_filter( 'yazan_home_image_cstory_1_image', function ( $url ) {
		return $url ? $url : yazan_demo_img( 'rare.jpg', 'Rare Stones' );
	} );
	add_filter( 'yazan_home_image_cstory_2_image', function ( $url ) {
		return $url ? $url : yazan_demo_img( 'silver.jpg', 'Silver Craft' );
	} );

	// Best Sellers — show the demo ring products (the Astra demo set absurd total_sales on its
	// own products, so ranking can't surface ours; target them by SKU instead).
	add_filter( 'yazan_home_bestsellers_args', function ( $args ) {
		unset( $args['meta_key'] );
		$args['orderby']    = 'title';
		$args['order']      = 'ASC';
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_sku',
				'value'   => 'YZ-DEMO-',
				'compare' => 'LIKE',
			),
		);
		return $args;
	} );

	// Featured collections — replace demo-store categories with four on-brand jewelry collections.
	add_filter( 'yazan_home_collection_cards', function () {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return array(
			array( 'name' => __( 'Yemeni Agate', 'yazan' ),   'url' => $shop, 'image' => yazan_demo_img( 'col-1.jpg', 'Yemeni Agate' ) ),
			array( 'name' => __( 'Silver Rings', 'yazan' ),    'url' => $shop, 'image' => yazan_demo_img( 'col-2.jpg', 'Silver Rings' ) ),
			array( 'name' => __( 'Rare Stones', 'yazan' ),     'url' => $shop, 'image' => yazan_demo_img( 'col-3.jpg', 'Rare Stones' ) ),
			array( 'name' => __( 'Luxury Handmade', 'yazan' ), 'url' => $shop, 'image' => yazan_demo_img( 'col-4.jpg', 'Luxury Handmade' ) ),
		);
	}, 20 );
}
