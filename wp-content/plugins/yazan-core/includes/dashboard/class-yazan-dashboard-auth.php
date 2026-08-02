<?php
/**
 * Yazan Dashboard — authentication & authorization.
 *
 * Same-origin, cookie-based. Login wraps wp_signon() and (because it runs over the site's own cookie)
 * needs NO API keys in the browser. CSRF is handled by WordPress itself: a cookie-authenticated REST
 * request is only accepted when it carries a valid `wp_rest` nonce in `X-WP-Nonce`; without it core
 * treats the caller as logged-out and our capability checks return 401. Every privileged route below
 * therefore just needs a capability check in its permission_callback.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auth endpoints + shared permission helpers for the yazan/v1 namespace.
 */
class Yazan_Dashboard_Auth {

	/** REST namespace shared by every dashboard controller. */
	const NS = 'yazan/v1';

	/**
	 * Capability required to reach the dashboard at all.
	 *
	 * Was `edit_products`, which meant every staff member had to be granted product editing purely
	 * to get through the door — the coarsest possible key for the narrowest possible purpose.
	 * Yazan_Cap_Projection has projected `yazan_dashboard_access` onto every non-suspended Yazan
	 * role holder all along; it simply was not the thing being checked.
	 *
	 * ⚠️ Do not check this constant directly — call {@see self::can_access()}. See the note there:
	 * a real WordPress administrator does NOT receive this capability, and gating on it alone
	 * locks every administrator out of /dashboard.
	 *
	 * Written as a literal, not as Yazan_Cap_Projection::ACCESS_CAP, because yazan-core has no
	 * autoloader: a class-constant reference would make this file unloadable if the RBAC files had
	 * not been required first, turning a load-order change into a fatal error on every request.
	 * `tests/run.php guard` asserts the two stay equal.
	 */
	const CAP = 'yazan_dashboard_access';

	/** Max failed logins per IP before a temporary block. */
	const MAX_ATTEMPTS = 6;

	/** Lockout / attempt window in seconds. */
	const WINDOW = 900; // 15 minutes.

	/**
	 * May this user open the dashboard?
	 *
	 * THE ADMINISTRATOR CASE IS WHY THIS FUNCTION EXISTS. Yazan_Cap_Projection::project() returns
	 * early for anyone holding the WordPress `administrator` role — deliberately, because an
	 * administrator already holds everything and resolving permissions inside the `user_has_cap`
	 * filter for them would be work with no effect. The consequence is that an administrator never
	 * receives the projected `yazan_dashboard_access` capability. Gating on ACCESS_CAP alone would
	 * therefore have locked out every WordPress administrator on this site, including the owner,
	 * with no way back in through the dashboard.
	 *
	 * The in-code note calling this flip "a one-line change with no migration" was wrong on that
	 * point. `manage_options` is the second clause that makes it true.
	 *
	 * @param int|WP_User|null $user User or id. Defaults to the current user.
	 * @return bool
	 */
	public static function can_access( $user = null ) {
		if ( null === $user ) {
			return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
		}

		return user_can( $user, self::CAP ) || user_can( $user, 'manage_options' );
	}

	/**
	 * Register auth routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/auth/login',
			Yazan_REST_Guard::public_args(
				'POST',
				array( __CLASS__, 'login' ),
				'Sign-in itself: the credentials are the secret, and failures are rate-limited below.',
				array(
					'args' => array(
						'username' => array( 'required' => true, 'type' => 'string' ),
						'password' => array( 'required' => true, 'type' => 'string' ),
						'remember' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
					),
				)
			)
		);

		register_rest_route(
			self::NS,
			'/auth/logout',
			Yazan_REST_Guard::args( 'POST', array( __CLASS__, 'logout' ), 'dashboard.access' )
		);

		register_rest_route(
			self::NS,
			'/auth/me',
			Yazan_REST_Guard::args( 'GET', array( __CLASS__, 'me' ), 'dashboard.access' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Permission helpers (reused by every controller)                        */
	/* --------------------------------------------------------------------- */

	/**
	 * permission_callback: caller must be logged in AND hold the dashboard capability.
	 *
	 * @return true|WP_Error
	 */
	public static function require_login() {
		if ( ! self::can_access() ) {
			return new WP_Error( 'yazan_forbidden', __( 'You do not have access to the Yazan dashboard.', 'yazan' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Build a permission_callback that requires a specific capability.
	 *
	 * @deprecated 1.8.0 Use require_perm() with a Yazan permission slug instead.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	public static function require_cap( $cap ) {
		return static function () use ( $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new WP_Error( 'yazan_forbidden', __( 'Insufficient permissions.', 'yazan' ), array( 'status' => rest_authorization_required_code() ) );
			}
			return true;
		};
	}

	/**
	 * Build a permission_callback that requires a Yazan permission.
	 *
	 * {@see Yazan_REST_Guard} already refuses the request before this runs, so this is defence in
	 * depth rather than the primary boundary. It matters because a future refactor could call a
	 * controller method directly (bypassing the filter but not the callback), and because a single
	 * core filter's ordering should never be the only thing standing between a role and an action.
	 *
	 * @param string $perm Permission slug, e.g. 'orders.refund'.
	 * @return callable
	 */
	public static function require_perm( $perm ) {
		return static function () use ( $perm ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'yazan_unauthenticated', __( 'You need to sign in.', 'yazan' ), array( 'status' => 401 ) );
			}

			$user_id = get_current_user_id();

			if ( Yazan_Users::is_suspended( $user_id ) ) {
				return new WP_Error( 'yazan_suspended', __( 'This account is suspended. Contact an administrator.', 'yazan' ), array( 'status' => 403 ) );
			}

			if ( ! Yazan_Permissions::can( $perm, $user_id ) ) {
				return new WP_Error(
					'yazan_forbidden',
					__( 'You do not have permission to do that.', 'yazan' ),
					array(
						'status'     => rest_authorization_required_code(),
						'permission' => $perm,
					)
				);
			}

			return true;
		};
	}

	/* --------------------------------------------------------------------- */
	/* Endpoints                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /auth/login — authenticate and set the WordPress auth cookie.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( WP_REST_Request $request ) {
		$ip       = self::client_ip();
		$username = sanitize_user( (string) $request->get_param( 'username' ) );

		if ( self::is_locked_out( $ip, $username ) ) {
			return new WP_Error(
				'yazan_locked',
				__( 'Too many attempts. Please try again in a few minutes.', 'yazan' ),
				array( 'status' => 429 )
			);
		}

		$creds = array(
			'user_login'    => $username,
			'user_password' => (string) $request->get_param( 'password' ), // Never sanitized/altered.
			'remember'      => (bool) $request->get_param( 'remember' ),
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			/*
			 * A suspension refusal comes from Yazan_Users::block_suspended_login() on the
			 * `authenticate` filter, which means the credentials were correct. Reporting it as a
			 * bad password would send the operator chasing a password reset, and counting it as a
			 * failed attempt would lock out the IP for a policy decision rather than an attack.
			 */
			if ( 'yazan_suspended' === $user->get_error_code() ) {
				return new WP_Error(
					'yazan_suspended',
					__( 'This account is suspended. Contact an administrator.', 'yazan' ),
					array( 'status' => 403 )
				);
			}

			self::record_failure( $ip, $username );
			// Generic message — never reveal whether the username exists.
			return new WP_Error(
				'yazan_login_failed',
				__( 'Invalid username or password.', 'yazan' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::can_access( $user ) ) {
			// Authenticated but not allowed here — don't leave them half-logged-into the dashboard.
			return new WP_Error(
				'yazan_forbidden',
				__( 'This account cannot access the Yazan dashboard.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		self::clear_failures( $ip, $username );

		// Make this same request "be" the user so the nonce we mint is valid for their next call.
		wp_set_current_user( $user->ID );
		Yazan_Dashboard_Audit::log( 'auth.login', 'auth', $user->ID );

		return new WP_REST_Response(
			array(
				'user'  => self::user_payload( $user ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * POST /auth/logout.
	 *
	 * @return WP_REST_Response
	 */
	public static function logout() {
		Yazan_Dashboard_Audit::log( 'auth.logout', 'auth', get_current_user_id() );
		wp_logout();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /auth/me — current user + capabilities + a fresh nonce.
	 *
	 * @return WP_REST_Response
	 */
	public static function me() {
		$user = wp_get_current_user();
		return new WP_REST_Response(
			array(
				'user'  => self::user_payload( $user ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Public-safe view of a user + the capabilities the UI branches on.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function user_payload( $user ) {
		$set = Yazan_Permissions::for_user( $user->ID );

		$yazan_roles = array();
		foreach ( Yazan_Roles::for_user( $user->ID ) as $role_id ) {
			$role = Yazan_Roles::get( $role_id );
			if ( $role ) {
				$yazan_roles[] = array(
					'id'   => $role['id'],
					'slug' => $role['slug'],
					'name' => $role['name'],
				);
			}
		}

		return array(
			'id'           => $user->ID,
			'name'         => $user->display_name,
			'email'        => $user->user_email,
			'phone'        => Yazan_Users::phone( $user->ID ),
			'avatar'       => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
			'roles'        => array_values( (array) $user->roles ),
			'status'       => Yazan_Users::status( $user->ID ),
			'lastLogin'    => Yazan_Users::last_login( $user->ID ),
			// The RBAC payload the SPA branches on. A flat slug map so every can() is O(1).
			'isSuper'      => (bool) $set['super'],
			'permissions'  => $set['perms'],
			'yazanRoles'   => $yazan_roles,
			/*
			 * Legacy capability flags. Kept for one release so nothing breaks mid-migration —
			 * every consumer has moved to `permissions` above. Delete in 1.9.0.
			 */
			'capabilities' => array(
				'edit_products'         => user_can( $user, 'edit_products' ),
				'delete_products'       => user_can( $user, 'delete_products' ),
				'upload_files'          => user_can( $user, 'upload_files' ),
				'manage_woo'            => user_can( $user, 'manage_woocommerce' ),
				'edit_shop_orders'      => user_can( $user, 'edit_shop_orders' ),
				// Yazan Rewards plugin caps, so the dashboard can gate its embedded screens.
				'manage_yazan_rewards'  => user_can( $user, 'manage_yazan_rewards' ),
				'view_yazan_rewards'    => user_can( $user, 'view_yazan_rewards' ),
			),
		);
	}

	/**
	 * Direct connection IP (proxy headers are spoofable and not trusted).
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Transient key for a failure counter, scoped to BOTH the IP and the submitted username.
	 *
	 * Scoping by username too means a shared upstream IP (a CDN/reverse proxy, where REMOTE_ADDR is
	 * the proxy for everyone) no longer collapses all staff into one bucket — one account's failed
	 * attempts can't lock every other operator out. Each (IP, username) pair throttles independently.
	 *
	 * @param string $ip       Client IP.
	 * @param string $username Submitted username (may be '').
	 * @return string
	 */
	private static function key( $ip, $username = '' ) {
		return 'yazan_login_fail_' . md5( $ip . '|' . strtolower( (string) $username ) );
	}

	/**
	 * @param string $ip       Client IP.
	 * @param string $username Submitted username.
	 * @return bool
	 */
	private static function is_locked_out( $ip, $username = '' ) {
		return (int) get_transient( self::key( $ip, $username ) ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * @param string $ip       Client IP.
	 * @param string $username Submitted username.
	 * @return void
	 */
	private static function record_failure( $ip, $username = '' ) {
		$key   = self::key( $ip, $username );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::WINDOW );
	}

	/**
	 * @param string $ip       Client IP.
	 * @param string $username Submitted username.
	 * @return void
	 */
	private static function clear_failures( $ip, $username = '' ) {
		delete_transient( self::key( $ip, $username ) );
	}
}
