<?php
/**
 * Security response headers.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The storefront currently returns no security headers at all — no
 * X-Frame-Options, no X-Content-Type-Options, no Referrer-Policy — and leaks
 * the exact PHP build via X-Powered-By.
 *
 * The ideal place for these is the web server (see
 * conf/nginx/yazan-hardening.conf.example), because that also covers static
 * files PHP never sees. This class is the portable fallback so the headers are
 * present even on a host where the server config cannot be edited. Setting a
 * header in both places is harmless — nginx `add_header` wins and the values
 * are identical.
 *
 * WHY NO CONTENT-SECURITY-POLICY BY DEFAULT
 * -----------------------------------------
 * This store runs Elementor, WooCommerce Payments, PayPal and CartFlows, all of
 * which inject inline scripts and third-party iframes. A CSP tight enough to be
 * worth having would break checkout, and a CSP loose enough to be safe here
 * (`unsafe-inline`/`unsafe-eval`) buys almost nothing. Shipping one enabled by
 * default would be security theatre that costs real sales.
 *
 * Instead CSP is opt-in and starts in report-only mode:
 *
 *   define( 'YAZAN_CSP', 'report-only' );  // observe violations, block nothing
 *   define( 'YAZAN_CSP', 'enforce' );      // only after the report log is clean
 *
 * Filter `yazan_core_csp_policy` to tune the directives for the payment
 * gateways actually in use.
 */
class Yazan_Core_Headers {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ), 20 );
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ), 20 );
	}

	/**
	 * Apply headers through the WP_REST/template header filter.
	 *
	 * @param array $headers Existing headers.
	 * @return array
	 */
	public static function filter_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}

		return array_merge( $headers, self::headers() );
	}

	/**
	 * Apply headers on the classic send_headers path, and drop the PHP version
	 * banner. `wp_headers` does not fire for every request type, so both hooks
	 * are used; header() is idempotent for identical values.
	 *
	 * @return void
	 */
	public static function send_headers() {
		if ( headers_sent() ) {
			return;
		}

		// Stop advertising the exact PHP build. Cheap, and removes a free
		// version-matching step for anyone scanning for known CVEs.
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
		}

		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * The header set.
	 *
	 * @return array<string,string>
	 */
	private static function headers() {
		$headers = array(
			// Block MIME sniffing — matters most for uploaded files.
			'X-Content-Type-Options' => 'nosniff',

			// Clickjacking. SAMEORIGIN rather than DENY because the dashboard
			// and some admin previews legitimately frame site pages.
			'X-Frame-Options'        => 'SAMEORIGIN',

			// Don't leak full URLs (which can carry order/token query args) to
			// third parties, while keeping same-origin analytics working.
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',

			// Powerful features nothing on a jewellery store needs.
			'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(self), interest-cohort=()',
		);

		// HSTS only over real HTTPS, and never on a local/dev environment —
		// pinning HSTS against a self-signed local domain wedges the browser
		// for that hostname long after the site is gone.
		if ( is_ssl() && ! self::is_local_env() ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		$csp = self::csp();
		if ( $csp ) {
			$mode = ( defined( 'YAZAN_CSP' ) && 'enforce' === YAZAN_CSP )
				? 'Content-Security-Policy'
				: 'Content-Security-Policy-Report-Only';

			$headers[ $mode ] = $csp;
		}

		/**
		 * Filter the outgoing security headers.
		 *
		 * @param array<string,string> $headers Header name => value.
		 */
		return (array) apply_filters( 'yazan_core_security_headers', $headers );
	}

	/**
	 * The CSP string, or '' when CSP is not enabled.
	 *
	 * @return string
	 */
	private static function csp() {
		if ( ! defined( 'YAZAN_CSP' ) || ! in_array( YAZAN_CSP, array( 'enforce', 'report-only' ), true ) ) {
			return '';
		}

		$policy = array(
			"default-src 'self'",
			// Payment gateways and Elementor both inline scripts.
			"script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://www.paypal.com https://*.paypalobjects.com",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data: blob: https:",
			"font-src 'self' data:",
			"connect-src 'self' https:",
			// Hosted card fields / PayPal buttons render in iframes.
			"frame-src 'self' https://js.stripe.com https://www.paypal.com https://*.paypalobjects.com",
			"frame-ancestors 'self'",
			"base-uri 'self'",
			"form-action 'self'",
			"object-src 'none'",
		);

		/**
		 * Filter the CSP directives.
		 *
		 * @param string[] $policy One directive per entry.
		 */
		$policy = (array) apply_filters( 'yazan_core_csp_policy', $policy );

		return implode( '; ', $policy );
	}

	/**
	 * Is this a local/development environment?
	 *
	 * @return bool
	 */
	private static function is_local_env() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		}

		return false;
	}
}
