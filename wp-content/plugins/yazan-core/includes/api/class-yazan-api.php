<?php
/**
 * The store-aware public API — `yazan-api/v1`, served at `/api/`.
 *
 * WHY A NEW NAMESPACE AND NOT `yazan/v1`
 * -------------------------------------
 * `yazan/v1` is the dashboard's private surface: 126 routes behind a same-origin cookie and a
 * default-deny guard. Teaching it to accept bearer tokens would widen the blast radius of every one
 * of those routes at once — including the ones nobody is thinking about today. A separate namespace
 * means the token surface is exactly the routes written for it.
 *
 * It also starts with `/yazan-`, which is what `Yazan_Field_Policy` matches on, so field-level
 * redaction covers this namespace with no change at all. That prefix test was written blunt on
 * purpose; this is the payoff.
 *
 * THE STORE IS AN ADDRESS FACT, NOT A CHOICE
 * ------------------------------------------
 * Every request resolves its store from the host, through the kernel, and nothing in the request
 * can move it: no `?store=`, no `X-Yazan-Store`, no route argument. `StoreAdminContext` exists for
 * the different question of which store an ADMINISTRATOR is acting on; the public API has no such
 * question, and honouring those inputs here would re-create the IDOR the permission phase closed.
 *
 * ⚠️ AND THIS NAMESPACE REFUSES TO SERVE ON AN UNKNOWN HOST. The kernel still falls back to store 1
 * for an unmapped host — its own comment calls that "THE MIGRATION CRUTCH… an unknown host must
 * 404". The API opts out of the crutch first, which is what makes removing it elsewhere an
 * incremental job rather than one frightening switch.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and guards the public store API.
 */
class Yazan_API {

	/** Namespace root. A version is appended: `yazan-api/v1`. */
	const NS_ROOT = 'yazan-api';

	/** The version served at `/api/` when the caller does not name one. */
	const DEFAULT_VERSION = 'v1';

	/** Route arg key carrying the required permission. */
	const KEY_PERM = 'yazan_perm';

	/** Route arg key marking a route as deliberately public. */
	const KEY_PUBLIC = 'yazan_public';

	/**
	 * Version lifecycle.
	 *
	 * A version is a NAMESPACE, not a branch inside a handler. `yazan-api/v2` is a second route
	 * tree registered by second controllers — which is the only shape that lets v1 and v2 genuinely
	 * coexist. Header negotiation was rejected: it pushes the version into every handler as an `if`,
	 * and internal branches are where drift lives.
	 *
	 * @var array<string,array{status:string,sunset:?string,successor:?string}>
	 */
	const VERSIONS = array(
		'v1' => array(
			'status'    => 'stable',
			'sunset'    => null,
			'successor' => null,
		),
	);

	/** Anonymous callers: requests per window. */
	const ANON_MAX = 120;

	/** Authenticated callers: requests per window. */
	const TOKEN_MAX = 600;

	/** Rate-limit window, seconds. */
	const WINDOW = 60;

	/** Whole-store ceiling per window — the brake a distributed client cannot walk around. */
	const STORE_MAX = 3000;

	/**
	 * The token authenticating this request, resolved once.
	 *
	 * @var array|null
	 */
	private static $token = null;

	/**
	 * The parse failure to surface, if any.
	 *
	 * @var WP_Error|null
	 */
	private static $auth_error = null;

	/**
	 * Rate-limit facts for the response headers.
	 *
	 * @var array<string,int>
	 */
	private static $limit = array();

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		/*
		 * `determine_current_user` carries the IDENTITY, because it is the only hook whose effect
		 * reaches every downstream consumer without modifying any of them — the REST guard's
		 * `is_user_logged_in()`, `Yazan_Permissions::for_user()`'s default, and
		 * `Yazan_Field_Policy`'s `get_current_user_id()` all follow it.
		 *
		 * `rest_authentication_errors` carries the ERROR, because `determine_current_user` cannot
		 * return a WP_Error — a bad token there would silently degrade to anonymous, and a request
		 * that quietly succeeds with less data is the hardest failure of all to report.
		 */
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate' ), 20 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'auth_error' ), 10 );

		// Priority 4: before Yazan_REST_Guard at 5, so an API route is settled by its own guard.
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard' ), 4, 3 );

		// Priority 20: after Yazan_Field_Policy at 10, so redaction happens before headers.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'headers' ), 20, 3 );

		add_action( 'init', array( __CLASS__, 'rewrite' ), 7 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
	}

	/* ------------------------------------------------------------------ ROUTING */

	/**
	 * `/api/…` → `/wp-json/yazan-api/v1/…`.
	 *
	 * A rewrite, NOT the `rest_url_prefix` filter. That filter is global: it would move WordPress's
	 * own routes, WooCommerce's, Elementor's and the dashboard's to `/api/…` and stop `/wp-json/`
	 * working entirely — breaking every integration at once to shorten one URL.
	 *
	 * @return void
	 */
	public static function rewrite() {
		// ⚠️ SINGLE QUOTES. In double quotes `$matches[1]` interpolates to empty, the rule still
		// matches, and every request routes nowhere — a 404 with no clue as to why.
		add_rewrite_rule(
			'^api/(v[0-9]+)/(.*)$',
			'index.php?rest_route=/' . self::NS_ROOT . '/$matches[1]/$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^api/(.*)$',
			'index.php?rest_route=/' . self::NS_ROOT . '/' . self::DEFAULT_VERSION . '/$matches[1]',
			'top'
		);
	}

	/**
	 * Keep `rest_route` available to the rewrite.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'rest_route';

		return $vars;
	}

	/**
	 * Namespace for a version.
	 *
	 * @param string $version Version, e.g. 'v1'.
	 * @return string
	 */
	public static function ns( $version = self::DEFAULT_VERSION ) {
		return self::NS_ROOT . '/' . $version;
	}

	/**
	 * Route args requiring a permission.
	 *
	 * Mirrors `Yazan_REST_Guard::args()` deliberately, including the ordering: `$extra` is merged
	 * FIRST so the three guaranteed keys always win. A call site passing its own
	 * `permission_callback` used to be able to switch off both layers at once.
	 *
	 * @param string|array $methods  HTTP methods.
	 * @param callable     $callback Handler.
	 * @param string       $perm     Permission slug.
	 * @param array        $extra    Extra args.
	 * @return array
	 */
	public static function args( $methods, $callback, $perm, array $extra = array() ) {
		return array_merge(
			$extra,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => '__return_true', // The guard at priority 4 is the decision.
				self::KEY_PERM        => $perm,
			)
		);
	}

	/**
	 * Route args for a deliberately public route.
	 *
	 * `$why` is mandatory and is not decoration: an unexplained public route on a token-bearing
	 * namespace is indistinguishable from a mistake.
	 *
	 * @param string|array $methods  HTTP methods.
	 * @param callable     $callback Handler.
	 * @param string       $why      Justification.
	 * @param array        $extra    Extra args.
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

	/* ------------------------------------------------------------------ AUTH */

	/**
	 * Resolve a bearer token to a user id.
	 *
	 * @param int|false $user_id What WordPress decided already.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		// A cookie already won. Never log an existing session out.
		if ( $user_id ) {
			return $user_id;
		}

		if ( ! self::is_api_request() ) {
			return $user_id;
		}

		$secret = self::bearer();

		if ( '' === $secret ) {
			return $user_id;
		}

		$token = Yazan_API_Tokens::verify( $secret );

		if ( ! $token ) {
			self::$auth_error = new WP_Error(
				'yazan_api_bad_token',
				__( 'That token is not valid, has expired, or was revoked.', 'yazan' ),
				array( 'status' => 401 )
			);

			return $user_id;
		}

		self::$token = $token;

		return $token['user_id'];
	}

	/**
	 * Surface a token failure as a real 401.
	 *
	 * @param mixed $result Existing result.
	 * @return mixed
	 */
	public static function auth_error( $result ) {
		if ( null !== $result && false !== $result ) {
			return $result;
		}

		return self::$auth_error ? self::$auth_error : $result;
	}

	/**
	 * The token authenticating this request, if any.
	 *
	 * @return array|null
	 */
	public static function token() {
		return self::$token;
	}

	/**
	 * Read the bearer value.
	 *
	 * ⚠️ THE SECOND SOURCE IS NOT OPTIONAL. Apache and most CGI setups drop `Authorization` before
	 * PHP sees it, leaving it only in `REDIRECT_HTTP_AUTHORIZATION`. Reading just the first is the
	 * single most common way a bearer deployment "works locally and not in production" — and it
	 * fails as a 200 with less data, not as an error.
	 *
	 * A query parameter is never accepted: it would land the credential in every access log.
	 *
	 * @return string
	 */
	private static function bearer() {
		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
				break;
			}
		}

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$header = trim( (string) $value );
					break;
				}
			}
		}

		if ( '' === $header && ! empty( $_SERVER['HTTP_X_YAZAN_TOKEN'] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_YAZAN_TOKEN'] ) ) );
		}

		return 0 === stripos( $header, 'bearer ' ) ? trim( substr( $header, 7 ) ) : '';
	}

	/**
	 * Is this request aimed at the API?
	 *
	 * ⚠️ TESTED ON `REQUEST_URI`, NOT ON A MATCHED ROUTE. `determine_current_user` fires long before
	 * REST routing — and for EVERY request, including wp-admin. Without this gate, every admin page
	 * load would run the token verifier.
	 *
	 * @return bool
	 */
	private static function is_api_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';

		if ( '' === $uri ) {
			// The test harness dispatches in-process with no REQUEST_URI; fall back to the
			// `rest_route` parameter so the suite exercises the same path a real request takes.
			$route = isset( $_GET['rest_route'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return '' !== $route && false !== strpos( $route, self::NS_ROOT . '/' );
		}

		return false !== strpos( $uri, '/' . self::NS_ROOT . '/' ) || 1 === preg_match( '#/api(/|\?|$)#', $uri );
	}

	/* ------------------------------------------------------------------ GUARD */

	/**
	 * Decide every API request before its handler runs.
	 *
	 * @param mixed           $response Existing response.
	 * @param array           $handler  Matched handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$route = strtolower( (string) $request->get_route() );

		// Lowercased on both sides: WordPress falls back to a case-INSENSITIVE route match when the
		// namespace comparison misses, so `/YAZAN-API/V1/…` reaches the handler. That exact shape
		// was a real guard bypass in the hardening phase.
		if ( 0 !== strpos( $route, '/' . self::NS_ROOT . '/' ) ) {
			return $response;
		}

		if ( 'OPTIONS' === $request->get_method() ) {
			return $response;
		}

		$version = self::version_of( $route );
		$sunset  = self::version_check( $version );

		if ( is_wp_error( $sunset ) ) {
			return $sunset;
		}

		$store = self::store_check();

		if ( is_wp_error( $store ) ) {
			return $store;
		}

		$limited = self::rate_check();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return self::permission_check( $handler, $route );
	}

	/**
	 * Is the store this request resolved to one we may serve?
	 *
	 * @return true|WP_Error
	 */
	private static function store_check() {
		if ( ! class_exists( 'Yazan_Store_Context' ) ) {
			return true;
		}

		/*
		 * ⚠️ THE FALLBACK IS FATAL HERE, AND ONLY HERE.
		 *
		 * The kernel resolves an unmapped host to store 1 so that unconverted code paths keep
		 * working during the migration. Its own comment calls that the crutch and says an unknown
		 * host must 404. Serving an API response for a host nobody registered means answering as
		 * the flagship store to whoever asked — with a 200, and no log line anywhere.
		 *
		 * Refusing per-namespace is what makes the crutch removable one surface at a time instead
		 * of in a single frightening change.
		 */
		if ( 'fallback' === Yazan_Store_Context::source() ) {
			return new WP_Error(
				'yazan_api_unknown_store',
				__( 'No store is published at this address.', 'yazan' ),
				array( 'status' => 404 )
			);
		}

		$token = self::$token;

		if ( ! $token ) {
			return true;
		}

		/*
		 * ⚠️ TWO INDEPENDENT SOURCES MUST AGREE. The credential names a store and the address names
		 * a store; if they differ, a stolen token is being replayed somewhere it does not belong.
		 * The token never WINS this comparison — a token that could switch stores would be exactly
		 * the request-parameter IDOR, wearing a different hat.
		 */
		if ( (int) $token['store_id'] !== Yazan_Store_Context::current() ) {
			return new WP_Error(
				'yazan_api_store_mismatch',
				__( 'That token belongs to a different store.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Enforce the route's permission.
	 *
	 * @param array  $handler Handler.
	 * @param string $route   Route.
	 * @return true|WP_Error
	 */
	private static function permission_check( $handler, $route ) {
		$perm   = isset( $handler[ self::KEY_PERM ] ) ? (string) $handler[ self::KEY_PERM ] : '';
		$public = ! empty( $handler[ self::KEY_PUBLIC ] );

		if ( '' !== $perm && $public ) {
			return new WP_Error(
				'yazan_api_route_contradiction',
				__( 'That route declares itself both public and permission-gated.', 'yazan' ),
				array( 'status' => 500 )
			);
		}

		if ( $public ) {
			return true;
		}

		// Default deny. An untagged route on a credential-bearing namespace is a mistake, and the
		// only safe reading of a mistake is refusal.
		if ( '' === $perm ) {
			return new WP_Error(
				'yazan_api_untagged_route',
				__( 'That route is not available.', 'yazan' ),
				array( 'status' => 403, 'route' => $route )
			);
		}

		$token = self::$token;

		if ( $token ) {
			Yazan_API_Tokens::touch( $token['id'] );

			if ( ! Yazan_API_Tokens::can( $token, $perm ) ) {
				return new WP_Error(
					'yazan_api_forbidden',
					__( 'This token does not carry that permission.', 'yazan' ),
					array( 'status' => 403, 'permission' => $perm )
				);
			}

			return true;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'yazan_api_unauthenticated',
				__( 'This endpoint needs a token.', 'yazan' ),
				array( 'status' => 401 )
			);
		}

		$store = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		if ( ! class_exists( 'Yazan_Permissions' ) || ! Yazan_Permissions::can( $perm, null, $store ) ) {
			return new WP_Error(
				'yazan_api_forbidden',
				__( 'You do not have permission to do that in this store.', 'yazan' ),
				array( 'status' => 403, 'permission' => $perm )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------ VERSIONS */

	/**
	 * The version segment of a route.
	 *
	 * @param string $route Route.
	 * @return string
	 */
	private static function version_of( $route ) {
		return preg_match( '#^/' . self::NS_ROOT . '/(v[0-9]+)/#', $route, $m ) ? $m[1] : self::DEFAULT_VERSION;
	}

	/**
	 * Refuse a version that has passed its sunset.
	 *
	 * A header cannot decline a request — announcing a sunset and then serving forever is how a
	 * version never actually retires.
	 *
	 * @param string $version Version.
	 * @return true|WP_Error
	 */
	private static function version_check( $version ) {
		$spec = self::VERSIONS[ $version ] ?? null;

		if ( ! $spec ) {
			return new WP_Error( 'yazan_api_unknown_version', __( 'Unknown API version.', 'yazan' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $spec['sunset'] ) && strtotime( (string) $spec['sunset'] ) < time() ) {
			return new WP_Error(
				'yazan_api_version_sunset',
				__( 'This API version has been retired.', 'yazan' ),
				array( 'status' => 410, 'successor' => $spec['successor'] )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------ RATE LIMIT */

	/**
	 * Count this request against two ceilings.
	 *
	 * @return true|WP_Error
	 */
	private static function rate_check() {
		if ( ! class_exists( 'Yazan_Rate_Limit' ) ) {
			return true;
		}

		$token = self::$token;

		if ( $token ) {
			// Per TOKEN, not per user: three integrations must not fight over one bucket, and
			// revoking one must not reset the others.
			$actor = 'tok:' . $token['id'];
			$max   = $token['rate_limit'] > 0 ? $token['rate_limit'] : self::TOKEN_MAX;
		} elseif ( is_user_logged_in() ) {
			$actor = 'usr:' . get_current_user_id();
			$max   = self::TOKEN_MAX;
		} else {
			$actor = 'ip:' . Yazan_Rate_Limit::client_ip();
			$max   = self::ANON_MAX;
		}

		$allowed = Yazan_Rate_Limit::consume( 'api', $actor, $max, self::WINDOW );

		self::$limit = array(
			'limit'     => $max,
			'remaining' => Yazan_Rate_Limit::remaining( 'api', $actor, $max ),
			'reset'     => Yazan_Rate_Limit::reset_at( 'api', $actor, self::WINDOW ),
		);

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		/*
		 * ⚠️ AND A WHOLE-STORE CEILING. The per-actor bucket stops one bad client; it does nothing
		 * about a thousand addresses each politely under their own limit. Without this second
		 * bucket the database is protected from individuals and not from a crowd.
		 */
		return Yazan_Rate_Limit::consume( 'api.store', 'all', self::STORE_MAX, self::WINDOW );
	}

	/* ------------------------------------------------------------------ HEADERS */

	/**
	 * Stamp every API response.
	 *
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Server   $server  Server.
	 * @param WP_REST_Request  $request Request.
	 * @return WP_HTTP_Response
	 */
	public static function headers( $result, $server, $request ) {
		unset( $server );

		if ( ! $result instanceof WP_REST_Response ) {
			return $result;
		}

		$route = strtolower( (string) $request->get_route() );

		if ( 0 !== strpos( $route, '/' . self::NS_ROOT . '/' ) ) {
			return $result;
		}

		$version = self::version_of( $route );
		$spec    = self::VERSIONS[ $version ] ?? array();
		$store   = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		$headers = array(
			'X-Yazan-API-Version' => $version,
			'X-Yazan-Store'       => (string) $store,

			/*
			 * ⚠️ THE CACHING HEADERS ARE A TENANCY CONTROL, NOT A PERFORMANCE ONE.
			 *
			 * `/api/*` is a path CDNs and reverse proxies often cache by default. A cached response
			 * keyed on path alone would serve one store's payload to another store's visitors — at
			 * the edge, where no PHP runs, nothing is logged, and no test in this repository could
			 * ever observe it.
			 */
			'Cache-Control'       => 'private, no-store',
			'Vary'                => 'Host, Authorization',
		);

		if ( ! empty( $spec['sunset'] ) ) {
			$headers['Sunset']      = gmdate( 'D, d M Y H:i:s', (int) strtotime( (string) $spec['sunset'] ) ) . ' GMT';
			$headers['Deprecation'] = 'true';
		}

		if ( ! empty( $spec['successor'] ) ) {
			$headers['Link'] = '<' . esc_url_raw( rest_url( self::ns( $spec['successor'] ) ) ) . '>; rel="successor-version"';
		}

		if ( array() !== self::$limit ) {
			$headers['X-RateLimit-Limit']     = (string) self::$limit['limit'];
			$headers['X-RateLimit-Remaining'] = (string) self::$limit['remaining'];
			$headers['X-RateLimit-Reset']     = (string) self::$limit['reset'];
		}

		$data = $result->get_data();

		if ( is_array( $data ) && isset( $data['data']['retry_after'] ) ) {
			$headers['Retry-After'] = (string) (int) $data['data']['retry_after'];
		}

		/*
		 * ⚠️ WITHOUT THIS, A BROWSER CLIENT SEES NONE OF THE ABOVE.
		 *
		 * `Sunset`, `Deprecation`, `Retry-After` and the `X-RateLimit-*` triple are not
		 * CORS-safelisted, so a cross-origin caller gets them stripped by the browser and never
		 * knows. Deprecation notices and backoff signals would vanish for exactly the clients that
		 * most need them — silently, by specification.
		 */
		$headers['Access-Control-Expose-Headers'] = implode( ', ', array_keys( $headers ) );

		foreach ( $headers as $name => $value ) {
			$result->header( $name, $value );
		}

		return $result;
	}

	/* ------------------------------------------------------------------ ROUTES */

	/**
	 * Register the v1 surface.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = self::ns();

		register_rest_route(
			$ns,
			'/store',
			self::public_args(
				WP_REST_Server::READABLE,
				array( __CLASS__, 'route_store' ),
				'Identifies the store an address resolves to. Carries no customer or commercial data.'
			)
		);

		register_rest_route(
			$ns,
			'/products',
			self::args( WP_REST_Server::READABLE, array( __CLASS__, 'route_products' ), 'products.view' )
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)',
			self::args( WP_REST_Server::READABLE, array( __CLASS__, 'route_product' ), 'products.view' )
		);

		register_rest_route(
			$ns,
			'/orders',
			self::args( WP_REST_Server::READABLE, array( __CLASS__, 'route_orders' ), 'orders.view' )
		);

		register_rest_route(
			$ns,
			'/whoami',
			self::public_args(
				WP_REST_Server::READABLE,
				array( __CLASS__, 'route_whoami' ),
				'Diagnostic. Reports whether a credential arrived at PHP at all — the answer to the '
				. 'commonest bearer-token failure, which is a web server stripping the header.'
			)
		);
	}

	/**
	 * GET /store.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_store() {
		$store = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		return new WP_REST_Response(
			array(
				'id'      => $store,
				'name'    => get_bloginfo( 'name' ),
				'version' => self::DEFAULT_VERSION,
			),
			200
		);
	}

	/**
	 * GET /whoami.
	 *
	 * @return WP_REST_Response
	 */
	public static function route_whoami() {
		$token = self::$token;

		return new WP_REST_Response(
			array(
				'authenticated'      => (bool) $token,
				'store'              => class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1,
				'user_id'            => get_current_user_id(),
				'scopes'             => $token ? $token['scopes'] : array(),
				// The diagnostic that turns "it works locally" into one request: did the header
				// survive the web server at all?
				'authorization_seen' => '' !== self::bearer(),
			),
			200
		);
	}

	/**
	 * GET /products.
	 *
	 * The store predicate is NOT applied here. It lives in `Yazan_Store_Catalogue`, on the query
	 * filters WooCommerce itself runs — so this handler, the dashboard's handler, Elementor's
	 * widgets and a shortcode all get the same partition without any of them asking for it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_products( $request ) {
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $per_page,
				'page'    => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		$items = array();

		foreach ( (array) $products as $product ) {
			$items[] = self::present_product( $product );
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * GET /products/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_product( $request ) {
		$product = wc_get_product( (int) $request['id'] );

		/*
		 * A direct id bypasses every query filter, so membership is asserted at the READ BOUNDARY.
		 * This is the entity-level check the architecture document pairs with each query filter,
		 * and it is what stops a permalink from being a cross-tenant read.
		 */
		$stores = $product ? Yazan_Store_Catalogue::store_of_product( $product->get_id() ) : array();
		$store  = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;

		if ( ! $product || ! in_array( $store, $stores, true ) ) {
			// 404 rather than 403: whether the id exists in another store is not this caller's
			// business either way.
			return new WP_Error( 'yazan_api_not_found', __( 'No such product.', 'yazan' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( self::present_product( $product ), 200 );
	}

	/**
	 * GET /orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_orders( $request ) {
		$orders = wc_get_orders(
			array(
				'limit' => max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) ),
				'page'  => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
			)
		);

		$items = array();

		foreach ( (array) $orders as $order ) {
			$items[] = array(
				'id'       => $order->get_id(),
				'number'   => $order->get_order_number(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'created'  => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Shape a product for the API.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function present_product( $product ) {
		return array(
			'id'     => $product->get_id(),
			'name'   => $product->get_name(),
			'slug'   => $product->get_slug(),
			'price'  => $product->get_price(),
			'status' => $product->get_status(),
			'sku'    => $product->get_sku(),
			'image'  => wp_get_attachment_url( (int) $product->get_image_id() ) ?: null,
		);
	}
}
