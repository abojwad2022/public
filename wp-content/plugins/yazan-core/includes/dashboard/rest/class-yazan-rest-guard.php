<?php
/**
 * Yazan Dashboard — central REST authorization.
 *
 * Every controller in the yazan/v1 namespace declares the permission each handler needs, and this
 * filter enforces it in ONE place. The point is that an unprotected route becomes structurally
 * impossible rather than a review checklist item: a handler with no declaration is denied, not
 * allowed.
 *
 * WHY `rest_request_before_callbacks` AND NOT `rest_pre_dispatch`:
 *
 *   `rest_pre_dispatch` fires before WP_REST_Server::match_request_to_handler(), so at that point
 *   $request->get_route() is the raw request path ("/yazan/v1/orders/123") rather than the matched
 *   pattern, url params are empty, and there is no handler. Enforcing there would mean maintaining
 *   a second copy of the routing table as regexes and re-matching raw paths — a mirror guaranteed
 *   to drift.
 *
 *   `rest_request_before_callbacks` fires after matching and BEFORE the route's own
 *   permission_callback, and hands us the exact matched handler. Method-awareness is then free
 *   (GET /orders and POST /orders are different handlers with different declarations), and a lax
 *   permission_callback cannot loosen what we decide here because we run first.
 *
 * The extra `yazan_perm` / `yazan_public` keys survive registration because register_rest_route()
 * merges each handler with array_merge( $defaults, $arg_group ), which preserves unknown keys.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default-deny gate for the yazan/v1 namespace.
 */
class Yazan_REST_Guard {

	/**
	 * 'report' — an undeclared route is ALLOWED but logged, so a missed handler is a warning
	 *            rather than an outage. This is the safe first deploy.
	 * 'enforce' — an undeclared route is denied. Flip once GET /status reports zero untagged
	 *            routes and the log has been clean for a day.
	 *
	 * A declared-but-denied route is refused in BOTH modes; the mode only decides what happens to
	 * a handler nobody labelled.
	 */
	const MODE = 'report';

	/** Handler key naming the required permission. */
	const KEY_PERM = 'yazan_perm';

	/** Handler key marking a deliberately public endpoint. */
	const KEY_PUBLIC = 'yazan_public';

	/**
	 * Route prefixes that are always hard-enforced, whatever MODE says.
	 *
	 * These are the RBAC endpoints themselves. Failing open on them — including when the tables are
	 * missing — would hand out user and role administration to anyone who can reach the dashboard.
	 *
	 * @var string[]
	 */
	const ALWAYS_ENFORCE = array( '/users', '/roles', '/permissions' );

	/**
	 * Sub-routes inside yazan/v1 that this plugin does not own.
	 *
	 * The namespace is shared: yazan-social-rewards publishes its CUSTOMER-facing endpoints here
	 * (its `PUBLIC_REST_NS`), keeping its staff endpoints on `yazan-rewards/v1`. Those callers are
	 * shoppers, so measuring them against the staff permission catalog would deny every one of them
	 * — a customer has no staff role by definition. Each carries its own permission_callback
	 * (`require_customer()`, or `require_cap( view_yazan_rewards )` for /statistics), which stays
	 * the authority.
	 *
	 * The empty string matches the bare `/yazan/v1` index route, which WordPress generates itself
	 * for namespace discovery.
	 *
	 * Adding a route here is a deliberate act: anything NOT listed and NOT tagged shows up in
	 * GET /status as an unprotected endpoint.
	 *
	 * @var string[]
	 */
	const FOREIGN_PREFIXES = array(
		'',                    // WordPress's own namespace index.
		'/customer',           // yazan-social-rewards — customer wallet/profile.
		'/campaign',           // yazan-social-rewards — campaign submissions.
		'/reward',             // yazan-social-rewards — redemptions.
		'/statistics',         // yazan-social-rewards — public loyalty stats.
	);

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enforce' ), 5, 3 );
	}

	/* --------------------------------------------------------------------- */
	/* Declaration helpers                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Build a protected route handler from a single permission slug.
	 *
	 * Emitting the callback, the permission_callback AND the guard tag from one argument is what
	 * removes the usual "two places to keep in sync" objection to defence in depth.
	 *
	 * @param string   $methods  WP_REST_Server::READABLE etc.
	 * @param callable $callback Route callback.
	 * @param string   $perm     Required permission slug.
	 * @param array    $extra    Extra handler keys (args, validate_callback…).
	 * @return array
	 */
	public static function args( $methods, $callback, $perm, array $extra = array() ) {
		/*
		 * $extra is merged FIRST so it can supply `args`, `validate_callback` and friends, but the
		 * three keys that constitute the guarantee are written afterwards and therefore win.
		 *
		 * Before, $extra was merged second: a call site passing `'yazan_perm' => ''` or
		 * `'permission_callback' => '__return_true'` silently disabled both layers of defence with
		 * no warning anywhere. No call site did — but "no call site does it today" is not a
		 * security property, and this is the one helper every protected route flows through.
		 */
		return array_merge(
			$extra,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => Yazan_Dashboard_Auth::require_perm( $perm ),
				self::KEY_PERM        => $perm,
			)
		);
	}

	/**
	 * Build a handler that RBAC does not govern.
	 *
	 * "Public" here means "not subject to a permission check" — the handler's own
	 * permission_callback stays authoritative. Two shapes use it: genuinely anonymous endpoints
	 * (sign-in, the storefront chat widget), and customer-scoped storefront endpoints such as the
	 * wishlist, whose callers are shoppers with no staff role at all and who must not be measured
	 * against the staff permission catalog.
	 *
	 * Every call passes a justification, which stays on the handler so `audit_routes()` output and
	 * a reader of the controller see the same reasoning.
	 *
	 * @param string   $methods  Methods.
	 * @param callable $callback Route callback.
	 * @param string   $why      One-line justification.
	 * @param array    $extra    Extra handler keys — pass `permission_callback` here when the route
	 *                           still needs one of its own.
	 * @return array
	 */
	public static function public_args( $methods, $callback, $why, array $extra = array() ) {
		return array_merge(
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => '__return_true',
				self::KEY_PUBLIC      => true,
				'yazan_public_reason' => $why,
			),
			$extra
		);
	}

	/* --------------------------------------------------------------------- */
	/* Enforcement                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * `rest_request_before_callbacks`.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result so far.
	 * @param array                                           $handler  Matched route handler.
	 * @param WP_REST_Request                                 $request  Request.
	 * @return mixed
	 */
	public static function enforce( $response, $handler, $request ) {
		// Something upstream already failed; do not mask its error with ours.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$route = '/' . ltrim( (string) $request->get_route(), '/' );
		$ns    = '/' . Yazan_Dashboard_Auth::NS;

		if ( 0 !== strpos( $route, $ns . '/' ) && $route !== $ns ) {
			return $response; // Not ours.
		}

		// Preflight carries no credentials by design.
		if ( 'OPTIONS' === $request->get_method() ) {
			return $response;
		}

		$sub_route = substr( $route, strlen( $ns ) );

		// Another plugin's route sharing our namespace — leave its own permission_callback to it.
		if ( self::is_foreign( $sub_route ) ) {
			return $response;
		}

		if ( ! empty( $handler[ self::KEY_PUBLIC ] ) ) {
			return $response;
		}

		$perm = isset( $handler[ self::KEY_PERM ] ) ? (string) $handler[ self::KEY_PERM ] : '';

		if ( '' === $perm ) {
			return self::handle_untagged( $response, $route, $request );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'yazan_unauthenticated',
				__( 'You need to sign in.', 'yazan' ),
				array( 'status' => 401 )
			);
		}

		$user_id = get_current_user_id();

		if ( Yazan_Users::is_suspended( $user_id ) ) {
			return new WP_Error(
				'yazan_suspended',
				__( 'This account is suspended. Contact an administrator.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		// The RBAC endpoints must never fail open, not even before install.
		if ( self::always_enforced( $sub_route ) && ! Yazan_RBAC_Schema::is_installed() ) {
			return new WP_Error(
				'yazan_rbac_missing',
				__( 'The roles system is not installed yet.', 'yazan' ),
				array( 'status' => 503 )
			);
		}

		if ( ! Yazan_Permissions::can( $perm, $user_id ) ) {
			return new WP_Error(
				'yazan_forbidden',
				__( 'You do not have permission to do that.', 'yazan' ),
				array(
					'status'     => 403,
					'permission' => $perm,
				)
			);
		}

		return $response;
	}

	/**
	 * A handler nobody labelled: allow-and-shout, or deny, depending on MODE.
	 *
	 * @param mixed           $response Result so far.
	 * @param string          $route    Full route.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	private static function handle_untagged( $response, $route, $request ) {
		$sub_route = substr( $route, strlen( '/' . Yazan_Dashboard_Auth::NS ) );
		$method    = $request->get_method();

		self::report_untagged( $route, $method );

		if ( 'enforce' === self::MODE || self::always_enforced( $sub_route ) ) {
			return new WP_Error(
				'yazan_route_unprotected',
				__( 'This endpoint has no permission declaration and was refused.', 'yazan' ),
				array( 'status' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 500 : 403 )
			);
		}

		return $response;
	}

	/**
	 * Is this sub-route one of the always-enforced RBAC endpoints?
	 *
	 * @param string $sub_route Route with the namespace stripped, e.g. '/users/12'.
	 * @return bool
	 */
	private static function always_enforced( $sub_route ) {
		foreach ( self::ALWAYS_ENFORCE as $prefix ) {
			if ( $sub_route === $prefix || 0 === strpos( $sub_route, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is this sub-route owned by another plugin sharing the namespace?
	 *
	 * @param string $sub_route Route with the namespace stripped ('' for the index route).
	 * @return bool
	 */
	private static function is_foreign( $sub_route ) {
		foreach ( self::FOREIGN_PREFIXES as $prefix ) {
			if ( '' === $prefix ) {
				if ( '' === $sub_route || '/' === $sub_route ) {
					return true;
				}
				continue;
			}
			if ( $sub_route === $prefix || 0 === strpos( $sub_route, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record an untagged route once per route+method per hour.
	 *
	 * Rate-limited because in `report` mode a single missed handler on a polled endpoint would
	 * otherwise write an audit row every thirty seconds.
	 *
	 * @param string $route  Route.
	 * @param string $method HTTP method.
	 * @return void
	 */
	private static function report_untagged( $route, $method ) {
		$key = 'yazan_untagged_' . md5( $method . ' ' . $route );

		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[yazan-rbac] Unprotected REST route: %s %s', $method, $route ) );
		}

		if ( class_exists( 'Yazan_Dashboard_Audit' ) ) {
			Yazan_Dashboard_Audit::log(
				'rbac.route_untagged',
				'role',
				0,
				array(
					'route'  => $route,
					'method' => $method,
					'mode'   => self::MODE,
				)
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Self-check                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Every registered handler in our namespace that declares neither a permission nor public.
	 *
	 * Surfaced in GET /status so an unprotected endpoint is visible from the dashboard rather than
	 * requiring someone to grep the source over SSH.
	 *
	 * @return array<int,array{route:string,methods:string}>
	 */
	public static function audit_routes() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$server = rest_get_server();
		$routes = $server->get_routes( Yazan_Dashboard_Auth::NS );
		$gaps   = array();

		$prefix = '/' . Yazan_Dashboard_Auth::NS;

		foreach ( (array) $routes as $route => $handlers ) {
			// Foreign routes are excluded, or the count could never reach zero and MODE could never
			// be flipped to 'enforce'.
			if ( self::is_foreign( substr( '/' . ltrim( $route, '/' ), strlen( $prefix ) ) ) ) {
				continue;
			}

			foreach ( (array) $handlers as $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				if ( ! empty( $handler[ self::KEY_PUBLIC ] ) || ! empty( $handler[ self::KEY_PERM ] ) ) {
					continue;
				}

				$methods = isset( $handler['methods'] ) && is_array( $handler['methods'] )
					? implode( ',', array_keys( array_filter( $handler['methods'] ) ) )
					: (string) ( $handler['methods'] ?? '' );

				$gaps[] = array(
					'route'   => $route,
					'methods' => $methods,
				);
			}
		}

		return $gaps;
	}

	/**
	 * Guard health for GET /status.
	 *
	 * @return array
	 */
	public static function status() {
		$gaps = self::audit_routes();

		return array(
			'mode'      => self::MODE,
			'untagged'  => count( $gaps ),
			'routes'    => array_slice( $gaps, 0, 25 ),
		);
	}
}
