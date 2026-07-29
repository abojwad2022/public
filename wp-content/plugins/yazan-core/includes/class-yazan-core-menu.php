<?php
/**
 * Yazan Core — primary navigation menu builder.
 *
 * Builds a ready-made "Yazan Primary" nav menu wired to the categories and collections this plugin
 * creates, so the store's header reflects the new architecture without hand-building every item.
 *
 * CREATE-ONCE: if a menu named "Yazan Primary" already exists it is left untouched (so it never
 * fights the user's own edits) — only the theme-location assignment is refreshed.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primary menu builder.
 */
class Yazan_Core_Menu {

	const MENU_NAME = 'Yazan Primary';
	const LOCATION  = 'yazan_primary';

	/**
	 * Build the menu once and assign it to the Yazan location.
	 *
	 * @return bool True when a new menu was created.
	 */
	public static function build() {
		if ( ! function_exists( 'wp_create_nav_menu' ) || ! function_exists( 'wp_update_nav_menu_item' ) ) {
			return false;
		}

		$existing = wp_get_nav_menu_object( self::MENU_NAME );
		if ( $existing ) {
			self::assign_location( (int) $existing->term_id ); // Keep the location wired; don't touch items.
			return false;
		}

		$menu_id = wp_create_nav_menu( self::MENU_NAME );
		if ( is_wp_error( $menu_id ) ) {
			return false;
		}
		$menu_id = (int) $menu_id;

		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		// Home.
		self::add_link( $menu_id, __( 'Home', 'yazan' ), home_url( '/' ) );

		// Rings — the mega panel (audience columns + hero-material children).
		$rings = self::add_link( $menu_id, __( 'Rings', 'yazan' ), $shop, 0, 'yz-mega' );
		$men   = self::add_term( $menu_id, 'product_cat', 'mens-rings', __( "Men's Rings", 'yazan' ), $rings );
		self::add_term( $menu_id, 'product_cat', 'mens-agate-rings', __( "Men's Agate Rings", 'yazan' ), $men );
		self::add_term( $menu_id, 'product_cat', 'mens-silver-rings', __( "Men's Silver Rings", 'yazan' ), $men );
		$women = self::add_term( $menu_id, 'product_cat', 'womens-rings', __( "Women's Rings", 'yazan' ), $rings );
		self::add_term( $menu_id, 'product_cat', 'womens-agate-rings', __( "Women's Agate Rings", 'yazan' ), $women );
		self::add_term( $menu_id, 'product_cat', 'womens-silver-rings', __( "Women's Silver Rings", 'yazan' ), $women );
		self::add_term( $menu_id, 'product_cat', 'unisex-rings', __( 'Unisex Rings', 'yazan' ), $rings );
		self::add_term( $menu_id, 'product_cat', 'new-arrivals', __( 'New Arrivals', 'yazan' ), $rings );

		// Collections — a simple elegant dropdown (single column).
		$collections = self::add_link( $menu_id, __( 'Collections', 'yazan' ), $shop, 0, 'yz-mega yz-mega--simple' );
		self::add_term( $menu_id, 'collection', 'signature', __( 'Signature', 'yazan' ), $collections );
		self::add_term( $menu_id, 'collection', 'heritage', __( 'Heritage', 'yazan' ), $collections );
		self::add_term( $menu_id, 'collection', 'royal', __( 'Royal', 'yazan' ), $collections );
		self::add_term( $menu_id, 'collection', 'limited-edition', __( 'Limited Edition', 'yazan' ), $collections );

		// Brand + trust links (custom URLs — create these pages later; links resolve when they exist).
		self::add_link( $menu_id, __( 'The Aqeeq', 'yazan' ), home_url( '/the-aqeeq/' ) );
		self::add_link( $menu_id, __( 'Authenticity', 'yazan' ), home_url( '/verify-ring/' ) );
		self::add_link( $menu_id, __( 'About', 'yazan' ), home_url( '/about/' ) );
		self::add_link( $menu_id, __( 'Contact', 'yazan' ), home_url( '/contact/' ) );

		self::assign_location( $menu_id );
		return true;
	}

	/**
	 * Add a custom-URL item.
	 *
	 * @param int    $menu_id Menu id.
	 * @param string $title   Label.
	 * @param string $url     URL.
	 * @param int    $parent  Parent item id.
	 * @param string $classes Space-separated CSS classes.
	 * @return int Item id (0 on failure).
	 */
	private static function add_link( $menu_id, $title, $url, $parent = 0, $classes = '' ) {
		$item = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => $title,
				'menu-item-url'       => $url,
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => (int) $parent,
				'menu-item-classes'   => self::classes( $classes ),
			)
		);
		return is_wp_error( $item ) ? 0 : (int) $item;
	}

	/**
	 * Add a taxonomy-term item (product_cat or collection). Skipped if the term is missing.
	 *
	 * @param int    $menu_id  Menu id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Term slug.
	 * @param string $title    Label.
	 * @param int    $parent   Parent item id.
	 * @return int Item id (0 on failure/missing term).
	 */
	private static function add_term( $menu_id, $taxonomy, $slug, $title, $parent = 0 ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return 0;
		}
		$item = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => $title,
				'menu-item-object'    => $taxonomy,
				'menu-item-object-id' => (int) $term->term_id,
				'menu-item-type'      => 'taxonomy',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => (int) $parent,
			)
		);
		return is_wp_error( $item ) ? 0 : (int) $item;
	}

	/**
	 * Normalise a class string into the array the menu meta expects.
	 *
	 * @param string $classes Space-separated classes.
	 * @return array
	 */
	private static function classes( $classes ) {
		if ( '' === $classes ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_html_class', explode( ' ', $classes ) ) ) );
	}

	/**
	 * Assign the menu to the Yazan primary location if that slot is empty.
	 *
	 * @param int $menu_id Menu id.
	 * @return void
	 */
	private static function assign_location( $menu_id ) {
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! is_array( $locations ) ) {
			$locations = array();
		}
		if ( empty( $locations[ self::LOCATION ] ) ) {
			$locations[ self::LOCATION ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}
	}
}
