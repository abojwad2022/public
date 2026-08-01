<?php
/**
 * One-tap sign-in with Google and Apple — flow controller.
 *
 * Owns the two routes the whole feature runs on:
 *
 *   /yazan-auth/{provider}/start/     — mint flow state, then hand the shopper to the provider's
 *                                       own account picker.
 *   /yazan-auth/{provider}/callback/  — validate what comes back, resolve the account, sign in,
 *                                       and return them to where they were.
 *
 * The shopper sees one tap, the account chooser, and then the page they were already on. There is
 * no registration form, no password, and no confirmation screen anywhere in that path; everything
 * an account normally needs (username, password, display name, billing name/email) is derived from
 * the verified identity token.
 *
 * WHAT KEEPS IT HONEST
 * --------------------
 * Three independent bindings have to line up before anyone is logged in:
 *
 *   - `state`, echoed by the provider, matched against a single-use server-side record. Stops a
 *     replayed or fabricated callback.
 *   - a per-flow secret in an HttpOnly cookie, matched against its hash in that record. Ties the
 *     callback to the browser that actually started the flow, so a callback URL captured from
 *     someone else's redirect is inert.
 *   - `nonce`, carried inside the signed id_token itself and matched against the same record.
 *     Stops a token minted for a different sign-in attempt being replayed into this one.
 *
 * Whichever fails, the answer is the same: nothing is created, nothing is linked, and the shopper
 * is returned to My Account with a notice.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social sign-in flow controller.
 */
class Yazan_Social_Auth {

	/** Rewrite/route prefix. Changing this changes the redirect URI registered with both providers. */
	const ROUTE = 'yazan-auth';

	/** Bumped whenever the rewrite rules change, to trigger a single flush. */
	const REWRITE_VERSION = '1.0.0';

	/** Transient prefix for one flow's server-side state. */
	const FLOW_PREFIX = 'yazan_sa_flow_';

	/** How long a shopper has to complete the provider round trip. */
	const FLOW_TTL = 15 * MINUTE_IN_SECONDS;

	/** Sign-in attempts allowed per IP inside the rate-limit window. */
	const MAX_ATTEMPTS = 20;

	/** Rate-limit window, in seconds. */
	const WINDOW = 900;

	/**
	 * Instantiated providers, keyed by provider key.
	 *
	 * @var array<string,Yazan_Social_Auth_Provider>|null
	 */
	private static $providers = null;

	/* --------------------------------------------------------------------- */
	/* Bootstrap                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 6 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Priority 1: ahead of redirect_canonical, and long before any template output, because the
		// callback both sets an auth cookie and issues a redirect.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );

		Yazan_Social_Auth_Cart::register();
		Yazan_Social_Auth_UI::register();
	}

	/**
	 * Register the routes. Hook: init.
	 *
	 * @return void
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . self::ROUTE . '/([a-z0-9\-]+)/(start|callback)/?$',
			'index.php?yazan_auth_provider=$matches[1]&yazan_auth_action=$matches[2]',
			'top'
		);

		if ( get_option( 'yazan_social_auth_rewrites' ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'yazan_social_auth_rewrites', self::REWRITE_VERSION, false );
		}
	}

	/**
	 * Whitelist the route's query vars. Hook: query_vars.
	 *
	 * @param string[] $vars Registered vars.
	 * @return string[]
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'yazan_auth_provider';
		$vars[] = 'yazan_auth_action';

		return $vars;
	}

	/* --------------------------------------------------------------------- */
	/* Configuration                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Provider credentials.
	 *
	 * Two sources, in this order of authority:
	 *   1. wp-config.php constants — never touch the database, so they cannot leak through a DB
	 *      export or a backup archive. Still the right place for a real deployment.
	 *   2. The encrypted option written by the dashboard's Social login panel, for the far more
	 *      common case where nobody wants to edit wp-config.php on a live server.
	 *
	 * Yazan_Social_Auth_Store::all() resolves that precedence and decrypts as needed; a constant
	 * always beats a stored value, so a credential pinned in the file system cannot be overridden
	 * from the dashboard.
	 *
	 * @return array
	 */
	public static function config() {
		$config = Yazan_Social_Auth_Store::all();

		/**
		 * Filter the social sign-in credentials.
		 *
		 * @param array $config Provider key => credentials.
		 */
		return (array) apply_filters( 'yazan_social_auth_config', $config );
	}

	/**
	 * Forget the memoised provider list.
	 *
	 * The dashboard saves credentials and then re-reads status in the SAME request, so without this
	 * the response would still describe the previous configuration and a freshly-configured
	 * provider would look inactive until the next page load.
	 *
	 * @return void
	 */
	public static function flush_providers() {
		self::$providers = null;
	}

	/**
	 * All providers that are configured well enough to be offered.
	 *
	 * @return array<string,Yazan_Social_Auth_Provider>
	 */
	public static function providers() {
		if ( null !== self::$providers ) {
			return self::$providers;
		}

		$config = self::config();

		$candidates = array(
			'google' => new Yazan_Social_Auth_Google( isset( $config['google'] ) ? (array) $config['google'] : array() ),
			'apple'  => new Yazan_Social_Auth_Apple( isset( $config['apple'] ) ? (array) $config['apple'] : array() ),
		);

		self::$providers = array();

		foreach ( $candidates as $key => $provider ) {
			// An unconfigured provider is simply never offered. That is what lets this module ship
			// inert: with no constants defined, the login page is byte-for-byte what it was before.
			if ( $provider->is_configured() ) {
				self::$providers[ $key ] = $provider;
			}
		}

		return self::$providers;
	}

	/**
	 * One configured provider, or null.
	 *
	 * @param string $key Provider key.
	 * @return Yazan_Social_Auth_Provider|null
	 */
	public static function provider( $key ) {
		$providers = self::providers();

		return isset( $providers[ $key ] ) ? $providers[ $key ] : null;
	}

	/** @return bool Is any provider available? */
	public static function is_enabled() {
		return ! empty( self::providers() );
	}

	/* --------------------------------------------------------------------- */
	/* URLs                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * The link behind a "Continue with …" button.
	 *
	 * @param string $provider    Provider key.
	 * @param string $redirect_to Where to land afterwards.
	 * @return string
	 */
	public static function start_url( $provider, $redirect_to = '' ) {
		$url = self::base_url() . self::ROUTE . '/' . sanitize_key( $provider ) . '/start/';

		$args = array( '_wpnonce' => wp_create_nonce( 'yazan_social_auth' ) );

		if ( '' !== $redirect_to ) {
			$args['redirect_to'] = rawurlencode( $redirect_to );
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * The callback URL registered with the provider. Must match theirs byte for byte.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function redirect_uri( $provider ) {
		return self::base_url() . self::ROUTE . '/' . sanitize_key( $provider ) . '/callback/';
	}

	/**
	 * Site base URL for the auth routes, with a trailing slash.
	 *
	 * Overridable because Google and Apple both refuse a non-HTTPS, non-public redirect URI, so a
	 * local install has to advertise its tunnel hostname while home_url() still says yazan.local.
	 *
	 * @return string
	 */
	private static function base_url() {
		$base = defined( 'YAZAN_SOCIAL_AUTH_BASE_URL' ) ? YAZAN_SOCIAL_AUTH_BASE_URL : home_url( '/' );

		return trailingslashit( (string) $base );
	}

	/* --------------------------------------------------------------------- */
	/* Routing                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Dispatch a request on one of our routes. Hook: template_redirect.
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		$provider_key = sanitize_key( (string) get_query_var( 'yazan_auth_provider' ) );
		$action       = sanitize_key( (string) get_query_var( 'yazan_auth_action' ) );

		if ( '' === $provider_key || '' === $action ) {
			return;
		}

		$provider = self::provider( $provider_key );

		if ( null === $provider ) {
			self::bail( new WP_Error( 'yazan_sa_unknown_provider', __( 'That sign-in method is not available.', 'yazan' ) ), $provider_key, home_url( '/' ) );
		}

		if ( 'start' === $action ) {
			self::start( $provider );
		} elseif ( 'callback' === $action ) {
			self::callback( $provider );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Step 1 — start                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Mint flow state and redirect to the provider's account picker.
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @return void
	 */
	private static function start( Yazan_Social_Auth_Provider $provider ) {
		$fallback = self::default_destination();

		// A shopper who is already signed in tapped the button by accident (or came back on the
		// browser's back button). Nothing to do — send them on.
		if ( is_user_logged_in() ) {
			self::redirect( self::requested_destination( $fallback ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'yazan_social_auth' ) ) {
			// Login CSRF: without this an attacker can silently drive a visitor's browser through a
			// full sign-in and land them in the attacker's account.
			self::bail( new WP_Error( 'yazan_sa_bad_nonce', __( 'That sign-in link has expired. Please try again.', 'yazan' ) ), $provider->key(), $fallback );
		}

		if ( self::is_rate_limited() ) {
			self::bail( new WP_Error( 'yazan_sa_rate_limited', __( 'Too many sign-in attempts. Please try again in a few minutes.', 'yazan' ) ), $provider->key(), $fallback );
		}

		self::record_attempt();

		$browser_secret = wp_generate_password( 48, false );
		$state          = wp_generate_password( 40, false );

		$flow = array(
			'provider'      => $provider->key(),
			'nonce'         => wp_generate_password( 40, false ),
			'code_verifier' => wp_generate_password( 64, false ),
			'browser_hash'  => hash( 'sha256', $browser_secret ),
			'redirect_to'   => self::requested_destination( $fallback ),
			'cart_token'    => Yazan_Social_Auth_Cart::stash(),
			'state'         => $state,
		);

		set_transient( self::FLOW_PREFIX . hash( 'sha256', $state ), $flow, self::FLOW_TTL );
		self::set_state_cookie( $provider, $browser_secret );

		self::redirect( $provider->authorize_url( $flow ) );
	}

	/* --------------------------------------------------------------------- */
	/* Step 2 — callback                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate the provider's response and sign the shopper in.
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @return void
	 */
	private static function callback( Yazan_Social_Auth_Provider $provider ) {
		$fallback = self::default_destination();

		// Apple returns via a cross-site form POST; Google via a GET. Read whichever applies.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- The `state` parameter plus the paired HttpOnly cookie are this flow's CSRF control; a WP nonce cannot survive a redirect through a third party.
		$request = $provider->uses_form_post() ? $_POST : $_GET;

		$state = isset( $request['state'] ) ? sanitize_text_field( wp_unslash( $request['state'] ) ) : '';
		$code  = isset( $request['code'] ) ? sanitize_text_field( wp_unslash( $request['code'] ) ) : '';
		$error = isset( $request['error'] ) ? sanitize_text_field( wp_unslash( $request['error'] ) ) : '';
		$user_blob = isset( $request['user'] ) ? wp_unslash( $request['user'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		$flow = self::consume_flow( $state );

		// Retrieve the destination from the flow when we can, so even a failure returns the shopper
		// to a sensible page rather than dumping them on the account screen.
		$destination = ( is_array( $flow ) && ! empty( $flow['redirect_to'] ) ) ? $flow['redirect_to'] : $fallback;

		self::clear_state_cookie( $provider );

		if ( '' !== $error ) {
			// `access_denied` is the shopper closing the picker — expected, not a failure worth a
			// scary message.
			if ( 'access_denied' === $error || 'user_cancelled_authorize' === $error ) {
				self::redirect( $destination );
			}

			self::bail( new WP_Error( 'yazan_sa_provider_error', __( 'Sign-in was not completed. Please try again.', 'yazan' ), array( 'detail' => $error ) ), $provider->key(), $destination );
		}

		if ( ! is_array( $flow ) ) {
			self::bail( new WP_Error( 'yazan_sa_state_unknown', __( 'This sign-in attempt has expired. Please try again.', 'yazan' ) ), $provider->key(), $fallback );
		}

		if ( $flow['provider'] !== $provider->key() ) {
			self::bail( new WP_Error( 'yazan_sa_state_provider', __( 'This sign-in attempt did not match. Please try again.', 'yazan' ) ), $provider->key(), $destination );
		}

		if ( ! self::verify_browser( $provider, $flow ) ) {
			self::bail( new WP_Error( 'yazan_sa_state_browser', __( 'We could not confirm this sign-in came from your browser. Please try again.', 'yazan' ) ), $provider->key(), $destination );
		}

		if ( '' === $code ) {
			self::bail( new WP_Error( 'yazan_sa_no_code', __( 'Sign-in was not completed. Please try again.', 'yazan' ) ), $provider->key(), $destination );
		}

		if ( '' !== $user_blob ) {
			// Apple's first-authorisation name payload. Passed through raw here; the provider
			// json_decodes it and sanitises each field it actually uses.
			$provider->set_callback_extra( array( 'user' => (string) $user_blob ) );
		}

		$identity = $provider->exchange( $code, $flow );
		if ( is_wp_error( $identity ) ) {
			self::bail( $identity, $provider->key(), $destination );
		}

		$result = Yazan_Social_Auth_Users::resolve( $identity );
		if ( is_wp_error( $result ) ) {
			self::bail( $result, $provider->key(), $destination );
		}

		self::sign_in( $result['user'], $provider, $flow, $result );

		self::redirect( $destination );
	}

	/* --------------------------------------------------------------------- */
	/* Sign-in                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Establish the session.
	 *
	 * @param WP_User                    $user     Account.
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @param array                      $flow     Flow state.
	 * @param array                      $result   Resolver result (created/linked flags).
	 * @return void
	 */
	private static function sign_in( WP_User $user, Yazan_Social_Auth_Provider $provider, array $flow, array $result ) {
		if ( ! empty( $flow['cart_token'] ) ) {
			Yazan_Social_Auth_Cart::schedule_merge( $user->ID, $flow['cart_token'] );
		}

		/*
		 * wc_set_customer_auth_cookie() is WooCommerce's own helper: it sets the auth cookie and
		 * re-keys the WC session onto the new customer id in one step. Using it rather than a bare
		 * wp_set_auth_cookie() is what keeps the session, and everything hanging off it, coherent.
		 */
		if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
			wc_set_customer_auth_cookie( $user->ID );
		} else {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}

		/*
		 * wc_set_customer_auth_cookie() does not fire `wp_login`, and plenty already depends on it —
		 * the rewards plugin's LoginObserver awards streaks there, and any future integration will
		 * expect it too. A social sign-in is a login and must look like one to the rest of the site.
		 */
		do_action( 'wp_login', $user->user_login, $user );

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log(
				'auth.social_login',
				'auth',
				$user->ID,
				array(
					'provider' => $provider->key(),
					'created'  => ! empty( $result['created'] ),
					'linked'   => ! empty( $result['linked'] ),
				)
			);
		}

		/**
		 * Fires after a shopper is signed in through a social provider.
		 *
		 * @param WP_User $user     The account.
		 * @param string  $provider Provider key.
		 * @param array   $result   Resolver result.
		 */
		do_action( 'yazan_social_auth_signed_in', $user, $provider->key(), $result );

		if ( function_exists( 'wc_add_notice' ) ) {
			$name = $user->first_name ? $user->first_name : $user->display_name;

			wc_add_notice(
				! empty( $result['created'] )
					/* translators: %s: customer first name. */
					? sprintf( esc_html__( 'Welcome to Yazan, %s.', 'yazan' ), esc_html( $name ) )
					/* translators: %s: customer first name. */
					: sprintf( esc_html__( 'Welcome back, %s.', 'yazan' ), esc_html( $name ) ),
				'success'
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Flow state                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Read and immediately destroy a flow record — single use, so a captured callback URL cannot
	 * be replayed even within its lifetime.
	 *
	 * @param string $state State value echoed by the provider.
	 * @return array|null
	 */
	private static function consume_flow( $state ) {
		if ( '' === $state ) {
			return null;
		}

		$key  = self::FLOW_PREFIX . hash( 'sha256', $state );
		$flow = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $flow ) || empty( $flow['state'] ) ) {
			return null;
		}

		return hash_equals( (string) $flow['state'], $state ) ? $flow : null;
	}

	/**
	 * Does the callback carry the cookie this flow was started with?
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @param array                      $flow     Flow state.
	 * @return bool
	 */
	private static function verify_browser( Yazan_Social_Auth_Provider $provider, array $flow ) {
		$name = self::cookie_name( $provider );

		if ( empty( $_COOKIE[ $name ] ) || empty( $flow['browser_hash'] ) ) {
			return false;
		}

		$secret = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

		return hash_equals( (string) $flow['browser_hash'], hash( 'sha256', $secret ) );
	}

	/**
	 * Cookie name for a provider's in-flight state.
	 *
	 * Per provider, so starting a Google sign-in does not invalidate an Apple one begun in another
	 * tab.
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @return string
	 */
	private static function cookie_name( Yazan_Social_Auth_Provider $provider ) {
		return 'yazan_sa_' . $provider->key();
	}

	/**
	 * Set the flow's browser-binding cookie.
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @param string                     $secret   Per-flow secret.
	 * @return void
	 */
	private static function set_state_cookie( Yazan_Social_Auth_Provider $provider, $secret ) {
		/*
		 * Apple returns through a cross-site form POST, and a Lax cookie is not sent on a
		 * cross-site POST — the flow would fail every time with a "not your browser" error. None
		 * requires Secure, which is fine: Apple will not accept a non-HTTPS redirect URI anyway.
		 * Google comes back on a top-level GET, where Lax is both sufficient and tighter.
		 */
		$same_site = ( $provider->uses_form_post() && is_ssl() ) ? 'None' : 'Lax';

		setcookie(
			self::cookie_name( $provider ),
			$secret,
			array(
				'expires'  => time() + self::FLOW_TTL,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => $same_site,
			)
		);
	}

	/**
	 * Expire the flow cookie.
	 *
	 * @param Yazan_Social_Auth_Provider $provider Provider.
	 * @return void
	 */
	private static function clear_state_cookie( Yazan_Social_Auth_Provider $provider ) {
		setcookie(
			self::cookie_name( $provider ),
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Destinations                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Where to land when nothing better is known.
	 *
	 * @return string
	 */
	private static function default_destination() {
		return function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'myaccount' )
			: home_url( '/' );
	}

	/**
	 * The page the shopper should come back to: an explicit redirect_to, else where they tapped
	 * the button, else My Account.
	 *
	 * Always run through wp_validate_redirect(), which rejects any host but our own — an
	 * attacker-controlled redirect_to would otherwise turn this into an open redirect off the back
	 * of a trusted login URL.
	 *
	 * @param string $fallback Default destination.
	 * @return string
	 */
	private static function requested_destination( $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce for this route is verified by the caller; this only reads a destination that is then validated against the site host.
		$requested = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		if ( '' === $requested ) {
			$requested = (string) wp_get_referer();
		}

		// Coming back to the login screen itself would be a dead end.
		if ( '' !== $requested && untrailingslashit( $requested ) === untrailingslashit( $fallback ) ) {
			$requested = '';
		}

		$validated = '' !== $requested ? wp_validate_redirect( $requested, '' ) : '';

		return '' !== $validated ? $validated : $fallback;
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Destination.
	 * @return void
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Redirect to the identity provider — deliberately off-site, so wp_safe_redirect() is the
	 * wrong tool here.
	 *
	 * wp_safe_redirect() only allows the site's own host by default; anything else (unless added
	 * via the `allowed_redirect_hosts` filter) is silently swapped for admin_url(). That is exactly
	 * the right behaviour for redirect_to-style values that trace back to user input, and exactly
	 * the wrong one for this URL, which is never influenced by a request parameter — it is built
	 * entirely by Yazan_Social_Auth_Provider::authorize_url() from a hardcoded provider endpoint
	 * (accounts.google.com / appleid.apple.com) plus our own generated state.
	 *
	 * Caught live on 2026-08-01: with real Google credentials configured, tapping the button
	 * silently landed the shopper on /wp-admin/ instead of Google's account picker — no error, no
	 * log entry pointing at the cause, just a dead end. Every check before this line (nonce, rate
	 * limit, provider lookup) had already passed, which is what made it easy to miss in a codebase
	 * where wp_safe_redirect() is everywhere else the correct choice.
	 *
	 * @param string $url Absolute provider URL.
	 * @return void
	 */
	private static function redirect_external( $url ) {
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentionally off-site; see docblock.
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Failure handling                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Log the real cause, show the shopper something human, and send them somewhere useful.
	 *
	 * Every failure path in this module ends here, so a broken sign-in is never a white screen and
	 * never leaks provider internals into the page.
	 *
	 * @param WP_Error $error       What went wrong.
	 * @param string   $provider    Provider key.
	 * @param string   $destination Where to send the shopper.
	 * @return void
	 */
	private static function bail( WP_Error $error, $provider, $destination ) {
		$data   = $error->get_error_data();
		$detail = is_array( $data ) && isset( $data['detail'] ) ? (string) $data['detail'] : '';

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf(
					'%s sign-in failed [%s] %s%s',
					$provider,
					$error->get_error_code(),
					$error->get_error_message(),
					'' !== $detail ? ' (' . $detail . ')' : ''
				),
				array( 'source' => 'yazan-social-auth' )
			);
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( esc_html( $error->get_error_message() ), 'error' );
		}

		self::redirect( $destination );
	}

	/* --------------------------------------------------------------------- */
	/* Rate limiting                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Transient key for this client's attempt counter.
	 *
	 * @return string
	 */
	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';

		return 'yazan_sa_rl_' . md5( $ip );
	}

	/** @return bool */
	private static function is_rate_limited() {
		return (int) get_transient( self::rate_key() ) >= self::MAX_ATTEMPTS;
	}

	/** @return void */
	private static function record_attempt() {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::WINDOW );
	}
}
