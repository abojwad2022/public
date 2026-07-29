<?php
/**
 * Yazan Core — custom taxonomies.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Collections" taxonomy — a second browse axis alongside product categories.
 *
 * A ring belongs to an audience (category) AND a collection at once; keeping them in separate
 * taxonomies keeps the shop filters clean and gives each collection its own SEO landing page.
 */
class Yazan_Core_Taxonomies {

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	const COLLECTION = 'collection';

	/**
	 * Register the Collections taxonomy against the product post type.
	 *
	 * @return void
	 */
	public static function register() {
		if ( taxonomy_exists( self::COLLECTION ) ) {
			return;
		}

		$labels = array(
			'name'              => _x( 'Collections', 'taxonomy general name', 'yazan' ),
			'singular_name'     => _x( 'Collection', 'taxonomy singular name', 'yazan' ),
			'search_items'      => __( 'Search Collections', 'yazan' ),
			'all_items'         => __( 'All Collections', 'yazan' ),
			'edit_item'         => __( 'Edit Collection', 'yazan' ),
			'update_item'       => __( 'Update Collection', 'yazan' ),
			'add_new_item'      => __( 'Add New Collection', 'yazan' ),
			'new_item_name'     => __( 'New Collection Name', 'yazan' ),
			'menu_name'         => __( 'Collections', 'yazan' ),
			'back_to_items'     => __( '← Back to Collections', 'yazan' ),
			'not_found'         => __( 'No collections found.', 'yazan' ),
		);

		register_taxonomy(
			self::COLLECTION,
			array( 'product' ),
			array(
				'labels'            => $labels,
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true, // Gutenberg / block editor + REST.
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => 'collection',
					'with_front' => false,
				),
			)
		);
	}
}
