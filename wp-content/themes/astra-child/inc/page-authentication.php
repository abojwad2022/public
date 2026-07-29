<?php
/**
 * Yazan — Authentication page (/authentication/) wiring.
 *
 * Renders the luxury Authentication template part in place of the page's editor content, and loads
 * its scoped stylesheet only on that page. The DB content (basic blocks) is kept as a graceful
 * fallback: if this module is removed, the page still shows readable text.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True on the Authentication page (matched by slug, so it survives id changes across environments).
 *
 * @return bool
 */
function yazan_is_auth_page() {
	return is_page() && ( is_page( 'authentication' ) || 'authentication' === get_post_field( 'post_name', get_queried_object_id() ) );
}

/**
 * Enqueue the page's scoped stylesheet (depends on the base sheet so tokens/utilities resolve first).
 */
add_action( 'wp_enqueue_scripts', 'yazan_auth_enqueue', 20 );
function yazan_auth_enqueue() {
	if ( ! yazan_is_auth_page() ) {
		return;
	}
	wp_enqueue_style(
		'yazan-authentication',
		YAZAN_URI . '/assets/css/authentication.css',
		array( 'yazan-main' ),
		YAZAN_VERSION
	);
}

/**
 * Replace the editor content with the designed template part — main query + in-the-loop only, so it
 * never affects excerpts, feeds, or secondary queries.
 *
 * @param string $content Post content.
 * @return string
 */
add_filter( 'the_content', 'yazan_auth_content', 20 );
function yazan_auth_content( $content ) {
	if ( ! yazan_is_auth_page() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	ob_start();
	get_template_part( 'template-parts/pages/authentication' );
	$markup = ob_get_clean();

	return $markup ? $markup : $content;
}
