<?php
/**
 * Plugin Name: Yazan Log
 * Description: One structured, store-aware application log for every Yazan surface.
 *
 * WHY THIS EXISTS
 * ---------------
 * There were three logging stories and none of them worked for a platform.
 *
 *   1. Nine scattered `error_log()` calls, four of them gated on `WP_DEBUG`.
 *   2. `Yazan\Rewards\Core\Support\Logger`, which returns early unless `WP_DEBUG` — so it writes
 *      NOTHING in production. That is precisely when a log matters, and it is the single mistake
 *      this file exists not to repeat.
 *   3. `Yazan\PaymentBridge\Logging\Logger`, which is the one that actually works: it writes through
 *      `wc_get_logger()` and is independent of `WP_DEBUG`. That shape is generalised here.
 *
 * WHY `wc_get_logger()` AND NOT A NEW BACKEND
 * -------------------------------------------
 * It is already present, already proven in this codebase, already has file rotation and retention,
 * already has a wp-admin viewer, already implements the eight PSR-3 levels — and it is already
 * writing files under `wp-content/uploads/wc-logs/`. Building a second one would be a second thing
 * to operate.
 *
 * WHY AN MU-PLUGIN
 * ----------------
 * `yazan-social-rewards` has zero PHP dependency on `yazan-core`, and the roadmap lists preserving
 * that as insurance worth buying. A logger in yazan-core cannot be consumed by rewards without
 * destroying it. Same argument that placed `Yazan_Store_Context` and `Yazan_Rate_Limit` here.
 *
 * @package Yazan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Yazan_Log' ) ) {
	return;
}

/**
 * Structured application logging with automatic store context.
 */
final class Yazan_Log {

	/** Levels, lowest first. */
	const LEVELS = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );

	/** Below this, nothing is written unless a store raises its own floor. */
	const DEFAULT_FLOOR = 'info';

	/** Cache group holding the hourly error counters that `/health` reads. */
	const COUNTER_GROUP = 'yazan_log_counts';

	/**
	 * Correlation id for this request, minted once.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Per-request de-duplication of identical lines.
	 *
	 * @var array<string,int>
	 */
	private static $seen = array();

	/** @param string $m Message. @param array $c Context. @return void */
	public static function debug( $m, array $c = array() ) {
		self::write( 'debug', $m, $c );
	}

	/** @param string $m Message. @param array $c Context. @return void */
	public static function info( $m, array $c = array() ) {
		self::write( 'info', $m, $c );
	}

	/** @param string $m Message. @param array $c Context. @return void */
	public static function warning( $m, array $c = array() ) {
		self::write( 'warning', $m, $c );
	}

	/** @param string $m Message. @param array $c Context. @return void */
	public static function error( $m, array $c = array() ) {
		self::write( 'error', $m, $c );
	}

	/** @param string $m Message. @param array $c Context. @return void */
	public static function critical( $m, array $c = array() ) {
		self::write( 'critical', $m, $c );
	}

	/**
	 * Write one line.
	 *
	 * @param string $level   One of self::LEVELS.
	 * @param string $message Short, stable, machine-greppable — e.g. `queue.no_store`.
	 * @param array  $context Structured detail.
	 * @param string $channel Log file to write to.
	 * @return void
	 */
	public static function write( $level, $message, array $context = array(), $channel = 'core' ) {
		$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';

		if ( ! self::passes_floor( $level ) ) {
			return;
		}

		/*
		 * A loop that logs cannot flood the disk. Identical (level, message) pairs collapse after
		 * the third occurrence in one request — the fact that it happened is preserved, the
		 * thousand repetitions are not.
		 */
		$fingerprint = $level . '|' . $message;

		self::$seen[ $fingerprint ] = ( self::$seen[ $fingerprint ] ?? 0 ) + 1;

		if ( self::$seen[ $fingerprint ] > 3 ) {
			return;
		}

		$line = wp_json_encode( self::envelope( $level, $message, $context ) );

		self::count( $level );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, (string) $line, array( 'source' => 'yazan-' . sanitize_key( $channel ) ) );

			self::report( $level, $message, $context );

			return;
		}

		/*
		 * ⚠️ THE FALLBACK IS NOT GATED ON `WP_DEBUG`.
		 *
		 * That gate is exactly why the rewards logger wrote nothing in production. A warning that
		 * only appears when someone has already turned on debugging is a warning nobody sees.
		 */
		if ( in_array( $level, array( 'warning', 'error', 'critical', 'alert', 'emergency' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[yazan] ' . $line );
		}

		self::report( $level, $message, $context );
	}

	/**
	 * The structured line.
	 *
	 * `src` is the store-resolution SOURCE, and it is here on purpose: it is how a cron job running
	 * against the wrong tenant announces itself. A line reading `store=1 src=fallback` from a request
	 * that should have resolved by host is the whole diagnosis.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return array
	 */
	private static function envelope( $level, $message, array $context ) {
		$has_ctx = class_exists( 'Yazan_Store_Context' );

		return array(
			'lvl'   => $level,
			'msg'   => (string) $message,
			'store' => $has_ctx ? Yazan_Store_Context::current() : 1,
			'src'   => $has_ctx ? Yazan_Store_Context::source() : 'unknown',
			'req'   => self::request_id(),
			'ctx'   => self::context_kind(),
			'uid'   => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'data'  => self::scrub( $context ),
		);
	}

	/**
	 * Strip anything that could be personal or secret.
	 *
	 * ⚠️ AN ALLOW-LIST, NOT A DENY-LIST. A deny-list has to anticipate every field name anyone will
	 * ever add; an allow-list is wrong in the direction of losing detail rather than publishing a
	 * customer's address into a log file that ships to a third party.
	 *
	 * @param array $context Raw context.
	 * @param int   $depth   Recursion guard.
	 * @return array
	 */
	public static function scrub( array $context, $depth = 0 ) {
		if ( $depth > 3 ) {
			return array();
		}

		$out = array();

		foreach ( $context as $key => $value ) {
			$key = (string) $key;

			$allowed = 'id' === $key
				|| '_id' === substr( $key, -3 )
				|| in_array( $key, array( 'store', 'status', 'code', 'count', 'ms', 'hook', 'level', 'attempt', 'total', 'group', 'action', 'reason', 'args', 'version', 'key' ), true );

			if ( ! $allowed ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::scrub( $value, $depth + 1 );

				continue;
			}

			$out[ $key ] = is_scalar( $value ) ? self::redact( (string) $value ) : '';
		}

		return $out;
	}

	/**
	 * Redact secrets that made it into an allowed value anyway.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function redact( $value ) {
		$patterns = array(
			'/[\w.+-]+@[\w-]+\.[\w.]+/'          => '<email>',
			'/\byz_live_[A-Za-z0-9_-]{8,}/'      => '<token>',
			'/\bey[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => '<jwt>',
			'/\b\d{13,19}\b/'                    => '<pan>',
		);

		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );
	}

	/**
	 * Is this level worth writing, for this store?
	 *
	 * Per-store, so one misbehaving tenant can be turned up to `debug` without flooding the other 99.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	private static function passes_floor( $level ) {
		$floor = class_exists( 'Yazan_Store_Options' )
			? (string) Yazan_Store_Options::get( 'yazan_log_level', self::DEFAULT_FLOOR )
			: self::DEFAULT_FLOOR;

		$floor_at = array_search( $floor, self::LEVELS, true );
		$level_at = array_search( $level, self::LEVELS, true );

		return false !== $level_at && ( false === $floor_at || $level_at >= $floor_at );
	}

	/**
	 * Count errors into an hourly bucket so `/health` can report a rate.
	 *
	 * The log FILES are not queryable, so the counter is the source of truth for "how bad is it right
	 * now". It lives in the cache, which means a flush loses it — the health payload says so rather
	 * than reporting a confident zero.
	 *
	 * @param string $level Level.
	 * @return void
	 */
	private static function count( $level ) {
		if ( ! in_array( $level, array( 'error', 'critical', 'alert', 'emergency' ), true ) ) {
			return;
		}

		$store = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : 1;
		$key   = 'err:' . $store . ':' . gmdate( 'YmdH' );

		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $key, 1, self::COUNTER_GROUP, 25 * HOUR_IN_SECONDS ) ) {
				wp_cache_incr( $key, 1, self::COUNTER_GROUP );
			}

			return;
		}

		set_transient( self::COUNTER_GROUP . '_' . $key, (int) get_transient( self::COUNTER_GROUP . '_' . $key ) + 1, 25 * HOUR_IN_SECONDS );
	}

	/**
	 * Errors in an hourly bucket.
	 *
	 * @param int|null $store Store, or null for the current one.
	 * @param string   $hour  `YmdH`, or empty for this hour.
	 * @return int
	 */
	public static function error_count( $store = null, $hour = '' ) {
		$store = null === $store && class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::current() : (int) $store;
		$key   = 'err:' . $store . ':' . ( '' !== $hour ? $hour : gmdate( 'YmdH' ) );

		return wp_using_ext_object_cache()
			? (int) wp_cache_get( $key, self::COUNTER_GROUP )
			: (int) get_transient( self::COUNTER_GROUP . '_' . $key );
	}

	/**
	 * Hand an error to whatever error tracker is installed.
	 *
	 * The default reporter does nothing at all — no network call, no dependency, no behaviour change.
	 * An adapter attaches through the filter.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function report( $level, $message, array $context ) {
		if ( ! in_array( $level, array( 'error', 'critical', 'alert', 'emergency' ), true ) ) {
			return;
		}

		/**
		 * Filter the error reporter.
		 *
		 * Receives the already-scrubbed envelope — a crash reporter is the easiest accidental
		 * PII exfiltration path in any platform, so nothing unscrubbed is ever offered to it.
		 *
		 * @param array $envelope Scrubbed structured line.
		 */
		do_action( 'yazan_error_reported', self::envelope( $level, $message, $context ) );
	}

	/**
	 * Correlation id, so a REST 500 can be joined to the job it queued.
	 *
	 * @return string
	 */
	public static function request_id() {
		if ( '' === self::$request_id ) {
			self::$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'yz', true );
		}

		return self::$request_id;
	}

	/**
	 * What kind of request this is.
	 *
	 * @return string
	 */
	private static function context_kind() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		return 'web';
	}
}
