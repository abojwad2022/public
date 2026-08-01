<?php
/**
 * Yazan Dashboard — social sign-in credentials (Google / Apple).
 *
 * A deliberate exception to the rule stated in class-yazan-rest-gateways.php, which refuses to
 * expose payment credentials over REST. The reasoning there still holds and is why this controller
 * is shaped the way it is:
 *
 *   - **Write-only.** No secret is ever returned. GET reports whether a field is set, how long it
 *     is, and where it came from — never its value. A dashboard compromise cannot READ the Google
 *     client secret or the Apple signing key back out.
 *   - **Encrypted at rest** by Yazan_Social_Auth_Store, so a database dump alone is not enough.
 *   - **Constants win.** A field pinned in wp-config.php is reported as locked and writes to it are
 *     refused, so the file system remains the stronger authority.
 *
 * What makes this worth doing at all, where payment gateways were not: these two credentials have
 * no OAuth handshake or webhook registration to break, the provider consoles hand you plain strings
 * to paste, and the alternative is asking a shop owner to edit wp-config.php on a live server.
 *
 * Governed by the existing `settings.view` / `settings.edit` permissions — this is store
 * configuration, and inventing a parallel permission for it would only fragment the catalog.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /social-auth endpoints.
 */
class Yazan_REST_Social_Auth {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Yazan_Dashboard_Auth::NS,
			'/social-auth',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'settings.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'settings.edit' ),
			)
		);
	}

	/**
	 * GET /social-auth — configuration status, with no secret values in it.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		return new WP_REST_Response( self::payload(), 200 );
	}

	/**
	 * PUT /social-auth — store credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$providers = $request->get_param( 'providers' );

		if ( ! is_array( $providers ) ) {
			return new WP_Error(
				'yazan_social_auth_bad_payload',
				__( 'Expected a "providers" object.', 'yazan' ),
				array( 'status' => 400 )
			);
		}

		$result = Yazan_Social_Auth_Store::save( $providers );

		// The provider list is memoised per request; without this the response below would still
		// describe the configuration as it was before this very save.
		Yazan_Social_Auth::flush_providers();

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			// Field NAMES only. Logging a value — or even its length — would put the secret, or a
			// clue about it, into a table built to be widely readable.
			Yazan_Dashboard_Audit::log(
				'settings.social_auth',
				'settings',
				0,
				array( 'fields' => $result['saved'] )
			);
		}

		$payload            = self::payload();
		$payload['saved']   = $result['saved'];
		$payload['skipped'] = $result['skipped'];

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * The shared response body.
	 *
	 * @return array
	 */
	private static function payload() {
		return array(
			'providers' => Yazan_Social_Auth_Store::status(),
			'enabled'   => Yazan_Social_Auth::is_enabled(),
			/*
			 * Surfaced because it is the single most common cause of a failed first setup: the
			 * redirect URI registered in the provider console must match this string byte for byte,
			 * trailing slash included. Showing it (instead of describing it) removes the guesswork.
			 */
			'notes'     => array(
				'base_url'      => defined( 'YAZAN_SOCIAL_AUTH_BASE_URL' ) ? YAZAN_SOCIAL_AUTH_BASE_URL : home_url( '/' ),
				'base_is_local' => self::is_local_host(),
				'is_ssl'        => is_ssl(),
			),
		);
	}

	/**
	 * Is the site currently on a hostname the providers will refuse?
	 *
	 * Google accepts only HTTPS or a loopback host; Apple demands a public HTTPS domain. Flagging
	 * this in the UI is what stops someone entering perfectly good credentials on yazan.local and
	 * concluding the feature is broken.
	 *
	 * @return bool
	 */
	private static function is_local_host() {
		$base = defined( 'YAZAN_SOCIAL_AUTH_BASE_URL' ) ? YAZAN_SOCIAL_AUTH_BASE_URL : home_url( '/' );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );

		if ( '' === $host ) {
			return true;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return false; // Loopback is explicitly allowed by Google.
		}

		// .local / .test / .localhost and bare hostnames are not resolvable by a provider.
		return (bool) preg_match( '/\.(local|test|localhost|invalid|example)$/i', $host ) || false === strpos( $host, '.' );
	}
}
