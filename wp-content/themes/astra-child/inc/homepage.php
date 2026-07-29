<?php
/**
 * Yazan — homepage (front-page.php) support.
 *
 * Content getters are ACF-ready: each reads an ACF Options field first, then falls back to a
 * filterable default. This keeps the homepage fully editable from wp-admin (once ACF + the
 * "YAZAN Homepage" options page exist) WITHOUT hardcoding content in the templates.
 *
 * Also forces a full-width (stretched, no-sidebar) layout on the front page so the section
 * templates can go full-bleed.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a homepage text value: ACF option → filter → default.
 *
 * @param string $key     Field key (without the `home_` prefix).
 * @param string $default Fallback copy.
 * @return string Raw value (escape at output).
 */
function yazan_home_text( $key, $default = '' ) {
	$value = '';
	if ( function_exists( 'get_field' ) ) {
		$value = (string) get_field( 'home_' . $key, 'option' );
	}
	if ( '' === $value ) {
		$value = $default;
	}
	return apply_filters( 'yazan_home_text_' . $key, $value );
}

/**
 * Get a homepage image URL: ACF option (image field) → filter → ''.
 *
 * @param string $key Field key (without the `home_` prefix).
 * @return string Image URL or '' (callers provide a graceful fallback).
 */
function yazan_home_image( $key ) {
	$url = '';
	if ( function_exists( 'get_field' ) ) {
		$img = get_field( 'home_' . $key, 'option' );
		if ( is_array( $img ) && ! empty( $img['url'] ) ) {
			$url = $img['url'];
		} elseif ( is_numeric( $img ) ) {
			$url = (string) wp_get_attachment_image_url( (int) $img, 'full' );
		} elseif ( is_string( $img ) ) {
			$url = $img;
		}
	}
	return apply_filters( 'yazan_home_image_' . $key, $url );
}

/**
 * Section wrapper helper — opens a full-bleed <section> with reveal + rhythm classes.
 *
 * @param string $slug      Section slug (adds .yz-<slug>).
 * @param string $tone      'ink' | 'ivory' | '' (background rhythm).
 * @param string $extra     Extra classes.
 * @return string Opening tag.
 */
function yazan_section_open( $slug, $tone = '', $extra = '' ) {
	$classes = array( 'yz-home-section', 'yz-' . sanitize_html_class( $slug ) );
	if ( $tone ) {
		$classes[] = 'yz-section--' . sanitize_html_class( $tone );
	}
	if ( $extra ) {
		$classes[] = $extra;
	}
	return '<section class="' . esc_attr( implode( ' ', $classes ) ) . '">';
}

/**
 * Register the ACF Options page so the homepage becomes editable once ACF Pro is active.
 * (Field groups themselves are added in a later phase; the getters above already fall back.)
 */
add_action( 'acf/init', 'yazan_register_home_options' );
function yazan_register_home_options() {
	if ( function_exists( 'acf_add_options_page' ) ) {
		acf_add_options_page(
			array(
				'page_title' => __( 'YAZAN Homepage', 'yazan' ),
				'menu_title' => __( 'Homepage', 'yazan' ),
				'menu_slug'  => 'yazan-homepage',
				'capability' => 'edit_theme_options',
				'icon_url'   => 'dashicons-store',
				'position'   => 59,
			)
		);
	}
}

/**
 * Force the front page to a full-width, no-sidebar, stretched layout so section templates
 * can render edge-to-edge (Comma-style full-bleed).
 */
add_filter( 'astra_page_layout', 'yazan_front_page_layout', 20 );
function yazan_front_page_layout( $layout ) {
	return is_front_page() ? 'no-sidebar' : $layout;
}

add_filter( 'astra_get_content_layout', 'yazan_front_content_layout' );
function yazan_front_content_layout( $layout ) {
	return is_front_page() ? 'page-builder' : $layout; // 'page-builder' = stretched, no container.
}
