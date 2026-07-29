<?php
/**
 * Analytics rollup repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Analytics;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `analytics_daily` rollup table. Metrics are accumulated with
 * an idempotent upsert keyed on (date, metric, dimension), so event-driven
 * increments are cheap and safe.
 */
final class ReportRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'analytics_daily';
	}

	/**
	 * Add to a daily metric (upsert).
	 *
	 * @param string $date      Y-m-d.
	 * @param string $metric    Metric key.
	 * @param float  $value     Amount to add.
	 * @param string $dimension Optional dimension.
	 * @return void
	 */
	public function increment( string $date, string $metric, float $value, string $dimension = '' ): void {
		$wpdb   = $this->db->wpdb();
		$table  = $this->table();
		$metric = sanitize_key( $metric );
		$dim    = sanitize_key( $dimension );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (stat_date, metric, dimension, value)
				 VALUES (%s, %s, %s, %f)
				 ON DUPLICATE KEY UPDATE value = value + VALUES(value)",
				$date,
				$metric,
				$dim,
				$value
			)
		);
	}

	/**
	 * Sum a metric over a date range.
	 *
	 * @param string $metric Metric key.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @return float
	 */
	public function sum( string $metric, string $from, string $to ): float {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(value),0) FROM {$table} WHERE metric = %s AND stat_date BETWEEN %s AND %s", sanitize_key( $metric ), $from, $to )
		);
	}

	/**
	 * A metric's daily series over a range.
	 *
	 * @param string $metric Metric.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @return array<int,array{date:string,value:float}>
	 */
	public function series( string $metric, string $from, string $to ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, SUM(value) AS value FROM {$table}
				 WHERE metric = %s AND stat_date BETWEEN %s AND %s
				 GROUP BY stat_date ORDER BY stat_date ASC",
				sanitize_key( $metric ),
				$from,
				$to
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'date' => (string) $r->stat_date, 'value' => (float) $r->value );
		}
		return $out;
	}
}
