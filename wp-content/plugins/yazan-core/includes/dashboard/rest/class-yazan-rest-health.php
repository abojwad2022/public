<?php
/**
 * Operational health — what is happening right now, per store.
 *
 * DISTINCT FROM `/status`, WHICH IS AN INVENTORY.
 * `/status` answers "what is installed": versions, plugins, row counts, WooCommerce tools. It is
 * for a human deciding whether an environment is set up correctly. This answers "is it working",
 * which is a different question with a different audience and a much shorter acceptable latency.
 *
 * ⚠️ EVERY FIELD HERE MUST BE CHEAP. A health endpoint that is expensive to serve becomes the thing
 * that finishes off an already-struggling site — and it is polled, so it is the one request
 * guaranteed to arrive during an incident. Nothing below loads a row it does not need: counts come
 * from indexed aggregates, queue depth from Action Scheduler's own indexed lookups, error rates from
 * a counter rather than by reading log files.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health and metrics endpoints.
 */
class Yazan_REST_Health {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/health',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'health' ), 'status.view' )
		);

		register_rest_route(
			$ns,
			'/metrics',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'metrics' ), 'status.view' )
		);
	}

	/**
	 * GET /health — shallow, no numbers, for a probe.
	 *
	 * Deliberately carries no counts. A probe endpoint that leaks how many orders a tenant has is a
	 * probe endpoint that becomes a reconnaissance tool.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		$checks = array(
			'db'      => self::check_db(),
			'cache'   => self::check_cache(),
			'queue'   => self::check_queue(),
			'hostmap' => self::check_hostmap(),
		);

		$status = 'ok';

		foreach ( $checks as $check ) {
			if ( 'down' === $check['status'] ) {
				$status = 'down';
				break;
			}

			if ( 'degraded' === $check['status'] ) {
				$status = 'degraded';
			}
		}

		return new WP_REST_Response(
			array(
				'status' => $status,
				'store'  => Yazan_DB::store_id(),
				'checks' => $checks,
			),
			'down' === $status ? 503 : 200
		);
	}

	/**
	 * GET /metrics — the numbers, scoped to this store.
	 *
	 * @return WP_REST_Response
	 */
	public static function metrics() {
		return new WP_REST_Response(
			array(
				'store'   => Yazan_DB::store_id(),
				'queue'   => self::queue_metrics(),
				'cache'   => self::cache_metrics(),
				'errors'  => self::error_metrics(),
				'cron'    => self::cron_metrics(),
				'hostmap' => self::check_hostmap(),
			),
			200
		);
	}

	/**
	 * Can we reach the database, and is the schema current?
	 *
	 * @return array
	 */
	private static function check_db() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = (bool) $wpdb->get_var( 'SELECT 1' );

		return array(
			'status'  => $ok ? 'ok' : 'down',
			'schema'  => (string) get_option( 'yazan_store_schema_version', '' ),
			'rbac'    => (string) get_option( 'yazan_rbac_registry_version', '' ),
		);
	}

	/**
	 * Which cache backend is actually in use.
	 *
	 * Reported because the answer changes behaviour across the whole platform and is otherwise
	 * invisible: with no drop-in every rate limiter silently takes its non-atomic path.
	 *
	 * @return array
	 */
	private static function check_cache() {
		$external = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

		return array(
			'status'   => 'ok',
			'backend'  => $external ? 'object' : 'transient',
			'dropin'   => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
			// Without an external cache the rate limiters use read-modify-write, which can overrun
			// a ceiling under concurrency. Not a fault — a property worth being able to see.
			'atomic'   => $external,
		);
	}

	/**
	 * Is the queue moving, and does THIS store still have its jobs?
	 *
	 * ⚠️ PRESENCE, NOT DEPTH, PER STORE. A per-store depth would mean matching `args LIKE '[N,%'`,
	 * which is a full scan of the actions table — the exact kind of query a health check must never
	 * make. `as_has_scheduled_action()` is an indexed lookup and answers the question that actually
	 * matters during an incident: "is store 47's expiry job still scheduled, or did it fail three
	 * times and get dropped?"
	 *
	 * @return array
	 */
	private static function check_queue() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! class_exists( '\Yazan\Rewards\Core\Support\Scheduler' ) ) {
			return array( 'status' => 'ok', 'available' => false );
		}

		$store   = Yazan_DB::store_id();
		$missing = array();

		foreach ( \Yazan\Rewards\Core\Support\Scheduler::HOOKS as $hook ) {
			if ( ! as_has_scheduled_action( $hook, array( $store ), 'yazan_rewards' ) ) {
				$missing[] = $hook;
			}
		}

		/*
		 * A missing recurring job is DEGRADED, not down. Action Scheduler stops rescheduling a job
		 * that has failed repeatedly — silently — and the symptom is that nothing happens. Points
		 * stop expiring, digests stop sending, and every request still returns 200. This field is
		 * the only place that failure becomes visible.
		 */
		return array(
			'status'    => array() === $missing ? 'ok' : 'degraded',
			'available' => true,
			'missing'   => $missing,
		);
	}

	/**
	 * Is the host map complete?
	 *
	 * @return array
	 */
	private static function check_hostmap() {
		$map = class_exists( 'Yazan_Store_Context' ) ? Yazan_Store_Context::hostmap() : array();
		$raw = (string) wp_json_encode( $map );

		/*
		 * ⚠️ THE FAILURE THIS WATCHES FOR TAKES A LIVE STORE OFFLINE WITH NO ERROR. The map is built
		 * from a bounded query; if it were ever truncated, the stores past the cut resolve to
		 * nothing and answer 404 to their own customers. Size is reported so the slope is visible
		 * long before the ceiling is.
		 */
		return array(
			'status'  => array() === $map ? 'degraded' : 'ok',
			'hosts'   => count( $map ),
			'bytes'   => strlen( $raw ),
			'stores'  => count( array_unique( array_values( $map ) ) ),
		);
	}

	/**
	 * Queue depth by status — global, because that is what is indexed.
	 *
	 * @return array
	 */
	private static function queue_metrics() {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array( 'available' => false );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );

		$by_status = array();

		foreach ( (array) $rows as $row ) {
			$by_status[ (string) $row['status'] ] = (int) $row['n'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oldest = $wpdb->get_var( "SELECT MIN(scheduled_date_gmt) FROM {$table} WHERE status = 'pending'" );

		return array(
			'available'          => true,
			'by_status'          => $by_status,
			// The number that says "the queue has stopped". Depth alone does not: a deep queue that
			// is draining is healthy, and a shallow one that has not moved in a day is not.
			'oldest_pending_age' => $oldest ? max( 0, time() - strtotime( (string) $oldest . ' UTC' ) ) : 0,
		);
	}

	/**
	 * Cache facts.
	 *
	 * @return array
	 */
	private static function cache_metrics() {
		return self::check_cache();
	}

	/**
	 * Error rate for this store.
	 *
	 * @return array
	 */
	private static function error_metrics() {
		if ( ! class_exists( 'Yazan_Log' ) ) {
			return array( 'available' => false );
		}

		$last_24 = 0;

		for ( $i = 0; $i < 24; $i++ ) {
			$last_24 += Yazan_Log::error_count( null, gmdate( 'YmdH', time() - ( $i * HOUR_IN_SECONDS ) ) );
		}

		return array(
			'available' => true,
			'hour'      => Yazan_Log::error_count(),
			'day'       => $last_24,
			// Said plainly rather than reported as a confident zero: the counters live in the cache,
			// so a flush loses them. A health field that overstates its own certainty is worse than
			// one that admits the gap.
			'source'    => 'cache',
			'lossy'     => true,
		);
	}

	/**
	 * WP-Cron facts.
	 *
	 * @return array
	 */
	private static function cron_metrics() {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next     = wp_next_scheduled( 'yazan_homepage_publish_due', array( Yazan_DB::store_id() ) );

		return array(
			'wp_cron_disabled' => $disabled,
			'doing_cron'       => (bool) get_transient( 'doing_cron' ),
			'next_publish_due' => $next ? (int) $next : null,
		);
	}
}
