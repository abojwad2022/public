<?php
/**
 * Where an experiment and its tally live.
 *
 * The experiment itself is ONE option row: there is at most a handful of them, they are tiny, and
 * a table would be three files of ceremony around six fields.
 *
 * The tally is not. A counter that every participating page view increments is the one hot write
 * in this module, and an option row would mean read-modify-write on a serialised blob under
 * concurrency — the classic way to lose counts. It gets a table and a single atomic upsert per
 * view: `INSERT … ON DUPLICATE KEY UPDATE views = views + 1`, which MySQL settles inside the row
 * lock without the reader ever seeing a torn value.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Experiment\Experiment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Experiment configuration and counters.
 */
final class ExperimentStore {

	/** Option holding every experiment, keyed by control document. */
	const OPTION = 'yazan_homepage_experiments';

	/**
	 * The experiment whose CONTROL is this document, if any.
	 *
	 * @param DocumentKey $key Control document.
	 * @return Experiment|null
	 */
	public function get( DocumentKey $key ) {
		$all = $this->all();

		return isset( $all[ $key->value() ] ) ? $all[ $key->value() ] : null;
	}

	/**
	 * Every stored experiment.
	 *
	 * @return array<string,Experiment>
	 */
	public function all() {
		$raw = get_option( self::OPTION, array() );
		$out = array();

		foreach ( (array) $raw as $control => $row ) {
			$experiment = Experiment::from_array( is_array( $row ) ? $row : array() );

			if ( $experiment ) {
				$out[ (string) $control ] = $experiment;
			}
		}

		return $out;
	}

	/**
	 * @param Experiment $experiment Experiment.
	 * @return void
	 */
	public function save( Experiment $experiment ) {
		$raw = (array) get_option( self::OPTION, array() );

		$raw[ $experiment->control()->value() ] = $experiment->to_array();

		// Not autoloaded: the storefront reads this on the front page only, and autoloading it
		// would put it in every single request's option cache including wp-admin and REST.
		update_option( self::OPTION, $raw, false );
	}

	/**
	 * @param DocumentKey $key Control document.
	 * @return void
	 */
	public function remove( DocumentKey $key ) {
		$raw = (array) get_option( self::OPTION, array() );

		unset( $raw[ $key->value() ] );

		update_option( self::OPTION, $raw, false );
	}

	/**
	 * Count one view for an arm.
	 *
	 * @param string $control Control document key.
	 * @param string $arm     CONTROL or the variant key.
	 * @param string $date    Y-m-d in site time.
	 * @return void
	 */
	public function record_view( $control, $arm, $date ) {
		$this->upsert( $control, $arm, $date, 'views', 1, 0 );
	}

	/**
	 * Count one order, and its money.
	 *
	 * @param string $control Control document key.
	 * @param string $arm     Arm.
	 * @param string $date    Y-m-d.
	 * @param float  $revenue Order total.
	 * @return void
	 */
	public function record_order( $control, $arm, $date, $revenue ) {
		$this->upsert( $control, $arm, $date, 'orders', 1, (float) $revenue );
	}

	/**
	 * Totals per arm since a date.
	 *
	 * @param string   $control Control document key.
	 * @param int|null $since   UTC timestamp, or null for everything.
	 * @return array<string,array{views:int,orders:int,revenue:float}>
	 */
	public function totals( $control, $since = null ) {
		global $wpdb;

		$table = Schema::table( 'ab_stats' );

		if ( $since ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT arm, SUM(views) AS views, SUM(orders) AS orders, SUM(revenue) AS revenue
					 FROM {$table} WHERE doc_key = %s AND stat_date >= %s GROUP BY arm",
					$control,
					// wp_date, not gmdate: rows are written with the SITE's date, and a UTC
					// boundary would drop or add a day's worth of a running test for any shop
					// that is not on UTC.
					(string) wp_date( 'Y-m-d', (int) $since )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT arm, SUM(views) AS views, SUM(orders) AS orders, SUM(revenue) AS revenue
					 FROM {$table} WHERE doc_key = %s GROUP BY arm",
					$control
				),
				ARRAY_A
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['arm'] ] = array(
				'views'   => (int) $row['views'],
				'orders'  => (int) $row['orders'],
				'revenue' => (float) $row['revenue'],
			);
		}

		return $out;
	}

	/**
	 * Forget an experiment's numbers. Used when a test is restarted, so the second run is not
	 * read on top of the first one's figures.
	 *
	 * @param string $control Control document key.
	 * @return void
	 */
	public function clear( $control ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'ab_stats' ), array( 'doc_key' => (string) $control ), array( '%s' ) );
	}

	/**
	 * One atomic counter bump.
	 *
	 * @param string $control Control key.
	 * @param string $arm     Arm.
	 * @param string $date    Y-m-d.
	 * @param string $column  views|orders.
	 * @param int    $by      Increment.
	 * @param float  $revenue Money to add.
	 * @return void
	 */
	private function upsert( $control, $arm, $date, $column, $by, $revenue ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'views', 'orders' ), true ) ) {
			return;
		}

		$table = Schema::table( 'ab_stats' );
		$views = 'views' === $column ? (int) $by : 0;
		$count = 'orders' === $column ? (int) $by : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (doc_key, arm, stat_date, views, orders, revenue)
				 VALUES (%s, %s, %s, %d, %d, %f)
				 ON DUPLICATE KEY UPDATE views = views + %d, orders = orders + %d, revenue = revenue + %f",
				(string) $control,
				(string) $arm,
				(string) $date,
				$views,
				$count,
				(float) $revenue,
				$views,
				$count,
				(float) $revenue
			)
		);
	}
}
