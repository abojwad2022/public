<?php
/**
 * Yazan — real homepage content.
 *
 * The store now has a real, divided catalogue, so the homepage's Featured-Collections and
 * Best-Sellers rows should reflect it instead of the placeholders in inc/demo-content.php.
 * These overrides run at priority 99 (after the demo filters at 20) and win. The demo HERO /
 * story / collection-story image placeholders are intentionally left in place.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Featured Collections → curated real product categories (each links to its now-live archive
 * and uses its category thumbnail). Falls back to the passed-in cards if none resolve.
 *
 * @param array $cards Cards from earlier filters (demo placeholders).
 * @return array
 */
add_filter( 'yazan_home_collection_cards', 'yazan_real_collection_cards', 99 );
function yazan_real_collection_cards( $cards ) {
	$slugs = apply_filters(
		'yazan_home_featured_slugs',
		array(
			'red-aqeeq',
			'blue-aqeeq',
			'honey-aqeeq',
			'green-aqeeq',
			'yemeni-mocha',
			'heritage-collection',
			'signature-collection',
			'limited-editions',
		)
	);

	$real = array();
	foreach ( $slugs as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}
		$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		$real[]   = array(
			'name'  => $term->name,
			'url'   => get_term_link( $term ),
			'image' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '',
		);
	}

	return $real ? $real : $cards;
}

/**
 * Best Sellers → real top-selling products (undo the demo filter that targeted YZ-DEMO- SKUs),
 * ordering by the total_sales meta the catalogue now carries.
 *
 * @param array $args WP_Query args.
 * @return array
 */
add_filter( 'yazan_home_bestsellers_args', 'yazan_real_bestsellers_args', 99 );
function yazan_real_bestsellers_args( $args ) {
	unset( $args['meta_query'] );
	$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$args['orderby']  = 'meta_value_num';
	$args['order']    = 'DESC';
	return $args;
}
