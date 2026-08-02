<?php
/**
 * Plugin Name: Yazan — Store Context
 * Description: Resolves and freezes the active store for the request. Ships inert: with one store configured it always resolves to store 1 and changes no behaviour.
 * Version:     1.0.0
 * Author:      Yazan
 *
 * WHY THIS IS AN MU-PLUGIN AND NOT PART OF yazan-core
 * ---------------------------------------------------
 * Two reasons, both load-bearing.
 *
 * 1. TIMING. The store has to be known before anything can ask a scoped question. That means
 *    before `plugins_loaded`, before `determine_current_user`, and — critically — before the first
 *    capability check, because Yazan_Cap_Projection hooks `user_has_cap` and would otherwise be
 *    the thing that triggers resolution. An mu-plugin loads ahead of every ordinary plugin.
 *
 * 2. OWNERSHIP. `yazan-social-rewards` has zero PHP-level dependency on `yazan-core` — verified,
 *    and worth keeping, because it is the one subsystem that could be lifted out or promoted to a
 *    service later. Putting the tenancy kernel inside yazan-core would make every consumer depend
 *    on yazan-core to answer "which store am I". Here, all four plugins consume it and none owns it.
 *
 * WHAT IT DOES NOT DO (YET)
 * -------------------------
 * There is no `wp_yazan_stores` table and no schema change of any kind. This phase delivers the
 * CONTRACT — resolution, immutability, scoped switching — with exactly one store, so that the code
 * threading a store through the permission API and the cache keys can be written, reviewed and
 * tested while it provably cannot change behaviour. Store 1 is derived from home_url().
 *
 * @package Yazan_Store_Context
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Load-once guard.
 *
 * NOT `class_exists( 'Yazan_Store_Context' )`. PHP hoists an unconditional top-level class
 * declaration at compile time, so by the time this line executes the class in THIS file already
 * exists — the guard matches on the very first load and returns before register() is ever called.
 * The class and the helper function still exist (both are hoisted), so everything looks fine until
 * you notice no hook was ever added. A constant is evaluated at runtime and does not lie.
 */
if ( defined( 'YAZAN_STORE_CONTEXT_LOADED' ) ) {
	return;
}

define( 'YAZAN_STORE_CONTEXT_LOADED', true );

/**
 * The active store for this request.
 *
 * Resolve once, freeze, then never mutate except through {@see self::run_for()}.
 */
final class Yazan_Store_Context {

	/** The store that exists today. Everything resolves here until a second one is created. */
	const DEFAULT_STORE = 1;

	/** Autoloaded option mapping host => store id. */
	const HOSTMAP_OPTION = 'yazan_store_hostmap';

	/** Resolved store id, or null before resolution. */
	private static $store_id = null;

	/** How the current store was determined — for debugging and the WP_DEBUG assertion. */
	private static $source = 'unresolved';

	/** True once resolution is final. After this there is no setter. */
	private static $frozen = false;

	/** Stack of stores pushed by run_for(). */
	private static $stack = array();

	/**
	 * Monotonic counter, bumped on every push and pop.
	 *
	 * Cache keys that include the store are safe without this, but anything that memoises a
	 * *derived* value can compare epochs to notice it was computed under a different context.
	 *
	 * @var int
	 */
	private static $epoch = 0;

	/** Stores seen during this request — the WP_DEBUG cross-tenant assertion reads this. */
	private static $seen = array();

	/**
	 * Hook registration. Called at the bottom of this file.
	 *
	 * @return void
	 */
	public static function register() {
		// Resolve as early as WordPress lets an mu-plugin act.
		add_action( 'muplugins_loaded', array( __CLASS__, 'resolve' ), 0 );

		/*
		 * Freeze before any ordinary plugin loads. yazan-core's ~25 static boot calls run at
		 * plugin-load time, so by the time any of them can ask a scoped question the answer is
		 * already final and can no longer be changed by a later hook.
		 */
		add_action( 'plugins_loaded', array( __CLASS__, 'freeze' ), 0 );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'shutdown', array( __CLASS__, 'assert_single_store' ), 0 );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Resolution                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Determine the active store. First match wins, and it runs exactly once.
	 *
	 * @return int
	 */
	public static function resolve() {
		if ( null !== self::$store_id ) {
			return self::$store_id;
		}

		list( $store_id, $source ) = self::determine();

		self::$store_id = $store_id;
		self::$source   = $source;
		self::$seen[ $store_id ] = true;

		return self::$store_id;
	}

	/**
	 * The resolution ladder.
	 *
	 * Ordered most-explicit to least. The last rung is a migration crutch and is the one that has
	 * to be removed before a second store goes live — see the comment on it.
	 *
	 * @return array{0:int,1:string} Store id and the name of the rung that matched.
	 */
	private static function determine() {
		// 1. An explicit constant in wp-config or on the CLI. Absolute.
		if ( defined( 'YAZAN_STORE_ID' ) && YAZAN_STORE_ID ) {
			return array( (int) YAZAN_STORE_ID, 'constant' );
		}

		// 2. WP-CLI: `wp --store=2 …`.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = self::cli_store_argument();

			if ( $cli ) {
				return array( $cli, 'cli' );
			}
		}

		/*
		 * 3. Cron and Action Scheduler.
		 *
		 * A scheduled job has no host and no session, so it must carry its store in its arguments.
		 * Today every yazan job is scheduled with empty args — which is precisely what makes adding
		 * a store argument a mechanical change rather than a semantic one — so nothing reaches this
		 * rung yet and jobs fall through to the default. When jobs start carrying `store_id`, this
		 * is where it is read, and a job WITHOUT one should become an error rather than a guess.
		 */
		if ( self::is_scheduled_context() ) {
			return array( self::DEFAULT_STORE, 'cron-default' );
		}

		// 4/6. The request host. REST is served on the store's own subdomain, so this covers both.
		$host = self::request_host();

		if ( '' !== $host ) {
			$map = self::hostmap();

			if ( isset( $map[ $host ] ) ) {
				return array( (int) $map[ $host ], 'host' );
			}
		}

		/*
		 * 7. Fall back to the only store there is.
		 *
		 * THIS RUNG IS THE MIGRATION CRUTCH AND IT MUST BE REMOVED BEFORE STORE 2 IS REACHABLE.
		 * A silent default is the single largest generator of cross-tenant leaks in a store_id
		 * design: an unconverted query path keeps working, reads store 1's rows, and nothing
		 * anywhere reports a problem. Once a real registry exists, an unknown host must 404.
		 *
		 * It is logged under WP_DEBUG so its use is visible rather than assumed.
		 */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $host ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[yazan-store] host "%s" is not mapped; falling back to store %d', $host, self::DEFAULT_STORE ) );
		}

		return array( self::DEFAULT_STORE, 'fallback' );
	}

	/**
	 * host => store id.
	 *
	 * MUST stay a plain array lookup: zero queries, and above all zero calls into the capability
	 * API. Yazan_Permissions::resolve() documents why — it runs inside the `user_has_cap` filter,
	 * and any capability call there re-enters the filter and recurses until the process dies.
	 * Context resolution sits on the same path and inherits the same rule.
	 *
	 * The option is autoloaded, so reading it costs nothing beyond the alloptions array WordPress
	 * already loaded.
	 *
	 * @return array<string,int>
	 */
	public static function hostmap() {
		$map = get_option( self::HOSTMAP_OPTION, null );

		if ( ! is_array( $map ) || array() === $map ) {
			// Seed from the site's own host so the map is never empty and never needs a migration.
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			$map  = $home ? array( strtolower( (string) $home ) => self::DEFAULT_STORE ) : array();
		}

		return $map;
	}

	/**
	 * The requested host, lower-cased and without its port.
	 *
	 * @return string
	 */
	private static function request_host() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$host = preg_replace( '/:\d+$/', '', $host );

		// Host headers are attacker-controlled; keep only what can legally be a hostname.
		return (string) preg_replace( '/[^a-z0-9.\-]/', '', (string) $host );
	}

	/**
	 * `--store=N` from the WP-CLI argv.
	 *
	 * Read from argv directly rather than through WP_CLI's parser, because this runs before
	 * WP-CLI has finished bootstrapping.
	 *
	 * @return int 0 when absent.
	 */
	private static function cli_store_argument() {
		$argv = isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array();

		foreach ( $argv as $arg ) {
			if ( is_string( $arg ) && 0 === strpos( $arg, '--store=' ) ) {
				return (int) substr( $arg, 8 );
			}
		}

		return 0;
	}

	/**
	 * Is this request a cron run or an Action Scheduler pass?
	 *
	 * @return bool
	 */
	private static function is_scheduled_context() {
		return ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( isset( $_GET['action'] ) && 'as_async_request_queue_runner' === $_GET['action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* --------------------------------------------------------------------- */
	/* Reading                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * The active store id.
	 *
	 * Safe to call at any time: if something asks before `muplugins_loaded` it resolves on demand
	 * rather than returning a wrong answer.
	 *
	 * @return int
	 */
	public static function current() {
		if ( array() !== self::$stack ) {
			return (int) end( self::$stack );
		}

		return null === self::$store_id ? self::resolve() : (int) self::$store_id;
	}

	/**
	 * Which rung of the ladder decided the current store.
	 *
	 * @return string
	 */
	public static function source() {
		return self::$source;
	}

	/**
	 * Push/pop counter. Changes whenever the active store changes.
	 *
	 * @return int
	 */
	public static function epoch() {
		return self::$epoch;
	}

	/**
	 * Is resolution final?
	 *
	 * @return bool
	 */
	public static function is_frozen() {
		return self::$frozen;
	}

	/**
	 * Mark resolution final. There is deliberately no setter and no unfreeze.
	 *
	 * @return void
	 */
	public static function freeze() {
		self::resolve();
		self::$frozen = true;
	}

	/* --------------------------------------------------------------------- */
	/* Scoped switching                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Run a callback with a different active store, then restore.
	 *
	 * THE SHAPE OF THIS FUNCTION IS DELIBERATE. It mirrors switch_to_blog()/restore_current_blog()
	 * — a push, a body, a guaranteed pop — but expressed as a callback so the restore cannot be
	 * forgotten and cannot be skipped by an early return or a thrown exception.
	 *
	 * That similarity is free insurance. If this platform ever moves from `store_id`-on-one-install
	 * to WordPress multisite, every call site written against run_for() maps onto switch_to_blog()
	 * without changing its shape. Designing the switch as a bare setter would have foreclosed that.
	 *
	 * @param int      $store_id Store to run as.
	 * @param callable $callback Receives the store id.
	 * @return mixed Whatever the callback returns.
	 */
	public static function run_for( $store_id, callable $callback ) {
		$store_id = (int) $store_id;

		self::$stack[] = $store_id;
		self::$seen[ $store_id ] = true;
		++self::$epoch;

		try {
			return $callback( $store_id );
		} finally {
			array_pop( self::$stack );
			++self::$epoch;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Development-only invariant                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Warn when one request touched more than one store without saying so.
	 *
	 * This is aimed squarely at the failure mode that a store_id design produces and that no test
	 * naturally catches: a request-scoped static cache — Yazan_Permissions::$memo, ServiceFactory's
	 * instances, ThemeBridge's plan — populated under store A and then read under store B. The
	 * symptom is a customer seeing another brand's data, and by then it is a support ticket rather
	 * than a stack trace.
	 *
	 * Today it can never fire: everything resolves to store 1 and nothing calls run_for(). It is
	 * here so that the day something does, it is loud in development instead of silent in
	 * production.
	 *
	 * @return void
	 */
	public static function assert_single_store() {
		if ( count( self::$seen ) <= 1 ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[yazan-store] request touched %d stores (%s) via source "%s" — check that every request-scoped cache is keyed by store.',
				count( self::$seen ),
				implode( ', ', array_keys( self::$seen ) ),
				self::$source
			)
		);
	}
}

Yazan_Store_Context::register();

/**
 * Shorthand for the active store id.
 *
 * A function so call sites in themes and plugins do not each have to guard on class_exists().
 *
 * @return int
 */
function yazan_store_id() {
	// The literal, not the class constant: reaching for the constant in the "class is missing"
	// branch would itself be the fatal error the guard exists to avoid.
	return class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
}
