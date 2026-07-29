<?php
/**
 * CartFlows compatibility fixes.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Fix the CartFlows "Instant Checkout" thank-you template on Windows (Local by Flywheel).
 *
 * CartFlows guards its custom thank-you include with a path-traversal check that compares
 * realpath() against `WP_CONTENT_DIR . DIRECTORY_SEPARATOR` (see
 * modules/thankyou/classes/class-cartflows-thankyou-markup.php). On Windows, ABSPATH is
 * `__DIR__ . '/'`, so WP_CONTENT_DIR keeps a forward slash before "wp-content" while
 * realpath() returns all backslashes. The strpos() prefix check therefore never matches, and
 * CartFlows falls back to `wc_get_template( 'templates/instant-thankyou.php', ... )`. WooCommerce
 * then resolves that against its own templates dir → `woocommerce/templates/templates/
 * instant-thankyou.php`, which does not exist → a fatal "Failed to open stream" warning.
 *
 * This filter intercepts that fallback lookup and points it at the real CartFlows file. It only
 * fires when WooCommerce could not locate the template itself, so it is a no-op on Linux (where
 * the plugin's own custom-template branch already works). Remove once CartFlows normalises the
 * separator in its security check.
 */
add_filter(
	'woocommerce_locate_template',
	function ( $template, $template_name ) {
		if ( 'templates/instant-thankyou.php' === $template_name
			&& ! file_exists( $template )
			&& defined( 'CARTFLOWS_THANKYOU_DIR' ) ) {
			$real = CARTFLOWS_THANKYOU_DIR . 'templates/instant-thankyou.php';
			if ( file_exists( $real ) ) {
				return $real;
			}
		}
		return $template;
	},
	10,
	2
);
