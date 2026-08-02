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
	 *            rather than an outage. This was the safe first deploy.
	 * 'enforce' — an undeclared route is denied.
	 *
	 * A declared-but-denied route is refused in BOTH modes; the mode only decides what happens to
	 * a handler nobody labelled.
	 *
	 * NOW 'enforce'. The preconditions were met and are now enforced continuously rather than
	 * checked by eye:
	 *
	 *   - Zero untagged handlers. All 125 registrations in this namespace go through args() or
	 *     public_args(); there is not one raw handler literal in the plugin. `tests/run.php golden`
	 *     asserts audit_routes() === [] on every run, so a new untagged route fails the build
	 *     instead of quietly becoming publicly reachable.
	 *   - The namespace can no longer be dodged by changing the case of the URL — see enforce().
	 *     Without that fix this flip would have been theatre.
	 *   - yazan-payment-bridge-lite and the rewards public controllers moved to namespaces they
	 *     own, so enforcement here cannot break a sibling plugin's routes.
	 *
	 * 'report' was the right default while the count was unknown. It is a fail-open, and a
	 * fail-open is invisible with one tenant and a breach with two.
	 */
	const MODE = 'enforce';

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

		/*
		 * MATCH CASE-INSENSITIVELY, because WordPress does.
		 *
		 * WP_REST_Server::match_request_to_handler() looks for a namespace with a case-SENSITIVE
		 * str_starts_with(); when that finds nothing it falls back to *every* registered route
		 * (class-wp-rest-server.php:1167) and then matches with `preg_match( '@^' . $route . '$@i' )`
		 * — case-INSENSITIVE. So `GET /YAZAN/V1/users` resolves to the `/yazan/v1/users` handler.
		 *
		 * This guard used to compare with a case-sensitive strpos and return unchecked on a miss,
		 * which meant a request could opt out of the entire guard by changing one letter's case:
		 * no suspension check, no ALWAYS_ENFORCE 503, and — once MODE is 'enforce' — no untagged
		 * deny. The per-route permission_callback still held the line, so this was a loss of
		 * defence in depth rather than an open door; it becomes an open door the moment any
		 * untagged handler exists, which is precisely the state 'enforce' mode exists to make safe.
		 */
		$route_lc = strtolower( $route );
		$ns_lc    = strtolower( $ns );

		if ( 0 !== strpos( $route_lc, $ns_lc . '/' ) && $route_lc !== $ns_lc ) {
			return $response; // Not ours.
		}

		// Preflight carries no credentials by design.
		if ( 'OPTIONS' === $request->get_method() ) {
			return $response;
		}

		// Compare sub-routes in lower case for the same reason as above.
		$sub_route = substr( $route_lc, strlen( $ns_lc ) );

		$is_public = ! empty( $handler[ self::KEY_PUBLIC ] );
		$perm      = isset( $handler[ self::KEY_PERM ] ) ? (string) $handler[ self::KEY_PERM ] : '';

		/*
		 * A handler carrying BOTH tags is a contradiction, and the public check runs first, so the
		 * accident would silently resolve in favour of "public". Refuse it instead: a mistake in a
		 * route declaration should be a loud 500 in development, not a quiet exemption.
		 */
		if ( $is_public && '' !== $perm ) {
			return new WP_Error(
				'yazan_route_contradiction',
				__( 'This endpoint is declared both public and permission-gated, so it was refused.', 'yazan' ),
				array( 'status' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 500 : 403 )
			);
		}

		/*
		 * The WordPress-generated namespace index (`/yazan/v1`) is genuinely anonymous — it is core's
		 * own route listing, not ours to gate.
		 */
		if ( self::is_namespace_index( $sub_route ) ) {
			return $response;
		}

		/*
		 * Another plugin's route sharing our namespace.
		 *
		 * It keeps its own permission_callback and we do NOT apply a permission slug to it — its
		 * callers are shoppers who hold no staff role and must not be measured against the staff
		 * catalog. But identity is not the sibling plugin's business to opt out of: this check used
		 * to `return` before authentication and suspension ran, so every foreign route was entirely
		 * invisible to central enforcement. Those are exactly the routes that carry customer points
		 * and wallet balances.
		 *
		 * So: skip the PERMISSION check, never the IDENTITY checks.
		 */
		if ( self::is_foreign( $sub_route ) ) {
			return self::assert_identity( $response );
		}

		if ( $is_public ) {
			return $response;
		}

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
	 * Is this the WordPress-generated namespace index (`/yazan/v1`)?
	 *
	 * Split out of is_foreign() so the two can be answered differently: the index is genuinely
	 * anonymous, while a sibling plugin's route still has to prove identity.
	 *
	 * @param string $sub_route Route with the namespace stripped.
	 * @return bool
	 */
	private static function is_namespace_index( $sub_route ) {
		return '' === $sub_route || '/' === $sub_route;
	}

	/**
	 * Authentication and suspension, with no permission check.
	 *
	 * Applied to routes another plugin owns inside our namespace. They keep their own
	 * permission_callback, but "who are you" is not theirs to waive.
	 *
	 * @param mixed $response Result so far.
	 * @return mixed
	 */
	private static function assert_identity( $response ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'yazan_unauthenticated',
				__( 'You must be signed in.', 'yazan' ),
				array( 'status' => 401 )
			);
		}

		if ( Yazan_Users::is_suspended( get_current_user_id() ) ) {
			return new WP_Error(
				'yazan_suspended',
				__( 'This account is suspended.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		return $response;
	}

	/**
	 * Is this sub-route owned by another plugin sharing the namespace?
	 *
	 * Compared in lower case — the caller lower-cases the sub-route because WordPress matches
	 * routes case-insensitively (see enforce()).
	 *
	 * @param string $sub_route Route with the namespace stripped ('' for the index route).
	 * @return bool
	 */
	private static function is_foreign( $sub_route ) {
		foreach ( self::FOREIGN_PREFIXES as $prefix ) {
			if ( '' === $prefix ) {
				if ( self::is_namespace_index( $sub_route ) ) {
					return true;
				}
				continue;
			}

			$prefix = strtolower( $prefix );

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
		/*
		 * Rate-limit on the route's SHAPE, not its concrete path.
		 *
		 * `$request->get_route()` is the raw request path — core never calls set_route() with the
		 * pattern — so keying on it meant `/orders/1`, `/orders/2`, … each got their own hourly
		 * bucket. One unguarded parameterised endpoint under load would write an audit row per id
		 * per hour, which is how a rate limit turns into the flood it was meant to prevent.
		 *
		 * Collapsing numeric and uuid segments recovers the pattern closely enough to bucket by.
		 */
		$shape = preg_replace( '#/\d+(?=/|$)#', '/{id}', $route );
		$shape = preg_replace( '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '/{uuid}', $shape );

		$key = 'yazan_untagged_' . md5( $method . ' ' . $shape );

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
