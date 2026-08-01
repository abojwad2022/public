<?php
/**
 * Send a front-end request to the host WordPress actually calls home.
 *
 * WHY THIS EXISTS
 * ---------------
 * A local install answers on more than one name — `yazan.local`, `localhost:10029`, `127.0.0.1` —
 * but `home_url()` names exactly one of them, and every URL WordPress prints is built from it. Ask
 * for a page on one of the others and it does not fail: the HTML arrives, the stylesheets load,
 * most of the page is fine. Only two things break, and both break in silence:
 *
 *   1. `/dashboard/` — its bundle is a `<script type="module">`, and a module is only executed when
 *      it comes from the page's own origin. Served from the other name, the browser refuses it with
 *      nothing printed on the page: a blank screen, no error, no clue. This cost a debugging
 *      session on 2026-08-01.
 *   2. Anything that calls `fetch()` with `credentials: 'same-origin'` against `admin_url()` — the
 *      storefront sign-in card, the concierge chat — sends no cookie and gets no response.
 *
 * WordPress will not fix this itself. `redirect_canonical()` corrects a wrong PORT but deliberately
 * leaves a wrong HOST alone, because rewriting the host of an arbitrary request is how a site
 * hijacks domains it was never meant to answer for.
 *
 * So this does it, narrowly: only for hosts we know are this same local install, only for requests
 * that are safe to redirect, and never for the OAuth routes, which have to answer on whatever host
 * was registered with the provider.
 *
 * The situation this is written for: Google refuses any redirect URI that is neither HTTPS nor a
 * loopback host, so testing Google sign-in locally means pointing WP_HOME at `http://localhost:PORT`
 * while the shop is still bookmarked as `yazan.local`. With this guard the two names become
 * interchangeable — type either, land on the right one.
 *
 * Disable with:  add_filter( 'yazan_canonical_host_enabled', '__return_false' );
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end canonical-host redirect.
 */
class Yazan_Canonical_Host {

	/**
	 * Hosts this install answers to. A request arriving on any OTHER name is left alone — this
	 * class exists to untangle a local mix-up, not to enforce a domain on the world.
	 *
	 * Compared without the port, so any port of these names qualifies.
	 *
	 * @var string[]
	 */
	const KNOWN_HOSTS = array( 'yazan.local', 'localhost', '127.0.0.1', '::1' );

	/**
	 * Hook registration.
	 *
	 * Priority 5: ahead of `redirect_canonical()` and of `Yazan_Dashboard::maybe_render()` (both at
	 * 10), so the dashboard shell is never emitted on a host whose browser will refuse its script.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 5 );
	}

	/**
	 * Redirect to the canonical host when the request came in on another name for this install.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( ! self::should_run() ) {
			return;
		}

		$requested = self::requested_host();
		$canonical = self::canonical_host();

		if ( '' === $requested || '' === $canonical || $requested === $canonical ) {
			return;
		}

		if ( ! self::is_known_host( $requested ) ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		/*
		 * 302, never 301. The destination follows WP_HOME, which is a setting that gets flipped back
		 * and forth while OAuth is being tested — and a 301 would stay burned into the browser's
		 * cache long after the flip, sending the owner to a host the site no longer answers on.
		 */
		wp_safe_redirect( home_url( $path ), 302 );
		exit;
	}

	/**
	 * Is this a request we may redirect at all?
	 *
	 * @return bool
	 */
	private static function should_run() {
		// A redirect drops the body, so a POST must never be touched.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		/*
		 * The OAuth start/callback pair must answer on the host that was registered with the
		 * provider, whichever host that is — moving them would produce a redirect_uri_mismatch, and
		 * moving the callback specifically would strand the browser-binding cookie set at /start/.
		 * They exit at template_redirect priority 1, before this runs, so this is belt and braces.
		 */
		if ( get_query_var( 'yazan_auth_provider' ) || get_query_var( 'yazan_auth_action' ) ) {
			return false;
		}

		/**
		 * Whether the canonical-host redirect is active.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'yazan_canonical_host_enabled', true );
	}

	/**
	 * The host:port this request arrived on, lowercased.
	 *
	 * @return string
	 */
	private static function requested_host() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
	}

	/**
	 * The host:port `home_url()` names, lowercased — port included only when it is explicit there.
	 *
	 * @return string
	 */
	private static function canonical_host() {
		$parts = wp_parse_url( home_url( '/' ) );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );

		return empty( $parts['port'] ) ? $host : $host . ':' . $parts['port'];
	}

	/**
	 * Is this one of the names we know to be this same install?
	 *
	 * @param string $host Requested host, possibly with a port.
	 * @return bool
	 */
	private static function is_known_host( $host ) {
		$bare = strtok( $host, ':' ); // Port is irrelevant to the identity of the name.

		/**
		 * Hosts treated as alternative names for this install.
		 *
		 * @param string[] $hosts Known host names, without ports.
		 */
		$known = (array) apply_filters( 'yazan_canonical_known_hosts', self::KNOWN_HOSTS );

		return in_array( $bare, array_map( 'strtolower', $known ), true );
	}
}
