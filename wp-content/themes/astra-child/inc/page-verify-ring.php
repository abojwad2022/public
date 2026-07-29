<?php
/**
 * Yazan — Verify a Ring page (/verify-ring/) wiring.
 *
 * Renders the serial-lookup + certificate template part in place of the page's editor content, and
 * loads its scoped stylesheet only on that page. The certificate lookup itself lives in the
 * yazan-core plugin (Yazan_Core_Verify); this file is presentation only and degrades gracefully.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True on the /verify-ring/ page (matched by slug, including the pretty /verify-ring/{serial}/ route).
 *
 * @return bool
 */
function yazan_is_verify_page() {
	if ( is_page( 'verify-ring' ) ) {
		return true;
	}
	// Pretty route /verify-ring/{serial}/ resolves to the same page via the plugin's rewrite.
	$qo = get_queried_object();
	return ( $qo instanceof WP_Post && 'verify-ring' === $qo->post_name );
}

/**
 * Enqueue the page's scoped stylesheet.
 */
add_action( 'wp_enqueue_scripts', 'yazan_verify_enqueue', 20 );
function yazan_verify_enqueue() {
	if ( ! yazan_is_verify_page() ) {
		return;
	}
	wp_enqueue_style(
		'yazan-verify',
		YAZAN_URI . '/assets/css/verify.css',
		array( 'yazan-main' ),
		YAZAN_VERSION
	);
}

/**
 * Swap the editor content for the designed template part (main query + loop only).
 *
 * @param string $content Post content.
 * @return string
 */
add_filter( 'the_content', 'yazan_verify_content', 20 );
function yazan_verify_content( $content ) {
	if ( ! yazan_is_verify_page() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	ob_start();
	get_template_part( 'template-parts/pages/verify-ring' );
	$markup = ob_get_clean();
	return $markup ? $markup : $content;
}
