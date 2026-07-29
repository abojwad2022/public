<?php
/**
 * Money & points math.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises decimal/rounding rules so money is handled consistently across the
 * platform. Amounts are stored/computed as strings via wc_format_decimal() to
 * avoid float drift, and rounded to the store's currency precision.
 */
final class Money {

	/**
	 * Format a raw number to a stored decimal string (store currency precision).
	 *
	 * @param float|int|string $amount Raw amount.
	 * @return string
	 */
	public function format( $amount ): string {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( $amount, $decimals );
		}
		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Convert points → store-credit currency using the configured ratio.
	 *
	 * @param int $points          Points to convert.
	 * @param int $points_per_unit Points that equal one currency unit.
	 * @return string Currency amount (decimal string).
	 */
	public function points_to_currency( int $points, int $points_per_unit ): string {
		if ( $points_per_unit <= 0 ) {
			return $this->format( 0 );
		}
		return $this->format( $points / $points_per_unit );
	}

	/**
	 * Convert a currency amount → points using the configured ratio.
	 *
	 * @param float|int|string $amount          Currency amount.
	 * @param int              $points_per_unit Points that equal one currency unit.
	 * @param string           $rounding        floor|round|ceil.
	 * @return int
	 */
	public function currency_to_points( $amount, int $points_per_unit, string $rounding = 'floor' ): int {
		$raw = (float) $amount * $points_per_unit;
		return $this->round( $raw, $rounding );
	}

	/**
	 * Apply a rounding strategy and return an int.
	 *
	 * @param float  $value    Value.
	 * @param string $strategy floor|round|ceil.
	 * @return int
	 */
	public function round( float $value, string $strategy = 'floor' ): int {
		switch ( $strategy ) {
			case 'ceil':
				return (int) ceil( $value );
			case 'round':
				return (int) round( $value );
			case 'floor':
			default:
				return (int) floor( $value );
		}
	}
}
