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
