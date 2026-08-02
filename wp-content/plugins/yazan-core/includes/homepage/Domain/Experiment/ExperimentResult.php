<?php
/**
 * The arithmetic of a result, kept away from anything that can query a database.
 *
 * Conversion rate is the only number in this module that people will make a decision with, so it
 * is computed in one place, tested, and reported with the thing it most needs: an honest note when
 * there is not enough data to mean anything.
 *
 * There is deliberately NO statistical-significance verdict here. A p-value produced from two
 * counters and no experiment design would be decoration on a guess; the honest output is the raw
 * numbers, the rate, and a plain warning while the sample is small.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Experiment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-arm totals and the rates derived from them.
 */
final class ExperimentResult {

	/**
	 * Below this many views in an arm, a rate is noise. Chosen as a floor for "worth reading at
	 * all", not as a significance threshold — it does not pretend to be one.
	 */
	const MIN_VIEWS = 100;

	/**
	 * Summarise raw per-arm rows.
	 *
	 * @param array $rows   [ arm => [ 'views' => int, 'orders' => int, 'revenue' => float ] ].
	 * @param array $labels [ arm => human label ].
	 * @return array
	 */
	public static function summarise( array $rows, array $labels = array() ) {
		$out = array();

		foreach ( $rows as $arm => $row ) {
			$views   = max( 0, (int) ( $row['views'] ?? 0 ) );
			$orders  = max( 0, (int) ( $row['orders'] ?? 0 ) );
			$revenue = round( (float) ( $row['revenue'] ?? 0 ), 2 );

			$out[] = array(
				'arm'             => (string) $arm,
				'label'           => isset( $labels[ $arm ] ) ? (string) $labels[ $arm ] : (string) $arm,
				'views'           => $views,
				'orders'          => $orders,
				'revenue'         => $revenue,
				// Percentage, two decimals. Zero views is 0.0 rather than a division by zero
				// dressed up as a result.
				'conversion'      => $views ? round( ( $orders / $views ) * 100, 2 ) : 0.0,
				'revenue_per_view' => $views ? round( $revenue / $views, 2 ) : 0.0,
				'enough_data'     => $views >= self::MIN_VIEWS,
			);
		}

		return $out;
	}

	/**
	 * The run, day by day.
	 *
	 * Deliberately WITHOUT a conversion rate per day. A single day of a normal shop is far below
	 * MIN_VIEWS, so a daily rate would be a column of numbers that swing wildly and mean nothing —
	 * exactly the figure somebody stops a test on. What a day CAN honestly show is how many people
	 * each arm reached and what they bought; the rate stays where the sample is.
	 *
	 * Every arm appears on every day that has any row at all, at zero if it had none, so a gap is
	 * visible as a gap rather than as a missing line.
	 *
	 * @param array    $rows   Rows from the store: date, arm, views, orders, revenue.
	 * @param string[] $arms   Every arm the test has, in display order.
	 * @param array    $labels [ arm => human label ].
	 * @return array<int,array{date:string,arms:array}>
	 */
	public static function by_day( array $rows, array $arms, array $labels = array() ) {
		$days = array();

		foreach ( $rows as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			$arm  = (string) ( $row['arm'] ?? '' );

			if ( '' === $date || '' === $arm ) {
				continue;
			}

			if ( ! isset( $days[ $date ] ) ) {
				$days[ $date ] = array();
			}

			// A day/arm pair is unique in the table, but a row arriving twice must add rather than
			// overwrite — losing a count silently is the one thing a tally must never do.
			$prior = $days[ $date ][ $arm ] ?? array( 'views' => 0, 'orders' => 0, 'revenue' => 0.0 );

			$days[ $date ][ $arm ] = array(
				'views'   => $prior['views'] + max( 0, (int) ( $row['views'] ?? 0 ) ),
				'orders'  => $prior['orders'] + max( 0, (int) ( $row['orders'] ?? 0 ) ),
				'revenue' => round( $prior['revenue'] + (float) ( $row['revenue'] ?? 0 ), 2 ),
			);
		}

		ksort( $days );

		$out = array();

		foreach ( $days as $date => $found ) {
			$line = array();

			foreach ( $arms as $arm ) {
				$arm  = (string) $arm;
				$cell = $found[ $arm ] ?? array( 'views' => 0, 'orders' => 0, 'revenue' => 0.0 );

				$line[ $arm ] = array(
					'label'   => isset( $labels[ $arm ] ) ? (string) $labels[ $arm ] : $arm,
					'views'   => (int) $cell['views'],
					'orders'  => (int) $cell['orders'],
					'revenue' => round( (float) $cell['revenue'], 2 ),
				);
			}

			$out[] = array(
				'date' => (string) $date,
				'arms' => $line,
			);
		}

		return $out;
	}

	/**
	 * How much better the variant converted, as a percentage of the control's rate.
	 *
	 * Returns null rather than a number whenever the comparison would be meaningless — one arm
	 * missing, or a control that has never converted. "No answer yet" is a legitimate answer, and
	 * an infinite uplift is not.
	 *
	 * @param array $summary Output of summarise().
	 * @return float|null
	 */
	public static function uplift( array $summary ) {
		$control = null;
		$variant = null;

		foreach ( $summary as $row ) {
			if ( Experiment::CONTROL === $row['arm'] ) {
				$control = $row;
			} else {
				$variant = $row;
			}
		}

		if ( ! $control || ! $variant ) {
			return null;
		}

		if ( ! $control['enough_data'] || ! $variant['enough_data'] ) {
			return null;
		}

		if ( $control['conversion'] <= 0 ) {
			return null;
		}

		return round( ( ( $variant['conversion'] - $control['conversion'] ) / $control['conversion'] ) * 100, 1 );
	}
}
