<?php
/**
 * Rule condition matcher.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates a rule's condition map against a runtime context. All conditions in
 * the map must pass (logical AND). Supported keys are intentionally small and
 * extensible via the `yazan_rewards/rules/match_condition` filter.
 */
final class ConditionMatcher {

	/**
	 * Whether every condition matches the context.
	 *
	 * @param array<string,mixed> $conditions Condition map.
	 * @param array<string,mixed> $context    Runtime facts.
	 * @return bool
	 */
	public function matches( array $conditions, array $context ): bool {
		foreach ( $conditions as $key => $expected ) {
			if ( ! $this->match_one( (string) $key, $expected, $context ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Evaluate a single condition.
	 *
	 * @param string $key      Condition key.
	 * @param mixed  $expected Expected value.
	 * @param array  $context  Runtime facts.
	 * @return bool
	 */
	private function match_one( string $key, $expected, array $context ): bool {
		switch ( $key ) {
			/* --- Legacy / order facts ------------------------------------- */
			case 'min_total':
				return isset( $context['order_total'] ) && (float) $context['order_total'] >= (float) $expected;

			case 'max_total':
				return isset( $context['order_total'] ) && (float) $context['order_total'] <= (float) $expected;

			case 'order_total': // Rich: { op, value } — e.g. order value > 100.
				return $this->compare_spec( (float) ( $context['order_total'] ?? 0 ), $expected );

			case 'payment_method':
				return isset( $context['payment_method'] )
					&& in_array( (string) $context['payment_method'], array_map( 'strval', (array) $expected ), true );

			case 'product_cats':
			case 'category':
				$cats = array_map( 'intval', (array) ( $context['product_cats'] ?? array() ) );
				$want = array_map( 'intval', (array) $this->values_of( $expected ) );
				return (bool) array_intersect( $cats, $want );

			case 'product':
				$prods = array_map( 'intval', (array) ( $context['product_ids'] ?? array() ) );
				$want  = array_map( 'intval', (array) $this->values_of( $expected ) );
				return (bool) array_intersect( $prods, $want );

			case 'is_first_order':
				return (bool) ( $context['is_first_order'] ?? false ) === (bool) $expected;

			/* --- User facts (enriched by ContextBuilder) ------------------ */
			case 'user_level':
				$want = array_map( 'strval', (array) $this->values_of( $expected ) );
				return in_array( (string) ( $context['user_level'] ?? '' ), $want, true );

			case 'lifetime_points':
				return $this->compare_spec( (int) ( $context['lifetime_points'] ?? 0 ), $expected );

			case 'purchase_history':
				return $this->compare_spec( (int) ( $context['purchase_count'] ?? 0 ), $expected );

			case 'customer_role':
				$want  = array_map( 'strval', (array) $this->values_of( $expected ) );
				$roles = array_map( 'strval', (array) ( $context['roles'] ?? array() ) );
				return (bool) array_intersect( $roles, $want );

			case 'date':
				return $this->match_date( (array) $expected, $context );

			default:
				/**
				 * Filter the result of an unknown condition, so add-ons can add
				 * new condition types without editing this matcher.
				 *
				 * @param bool   $matched  Default false.
				 * @param string $key      Condition key.
				 * @param mixed  $expected Expected value.
				 * @param array  $context  Runtime facts.
				 */
				return (bool) apply_filters( 'yazan_rewards/rules/match_condition', false, $key, $expected, $context );
		}
	}

	/**
	 * Compare a numeric fact against a { op, value } spec (or a bare number, which
	 * is treated as ">=").
	 *
	 * @param float $actual Fact.
	 * @param mixed $spec   { op: '='|'!='|'>'|'>='|'<'|'<=', value: number } or number.
	 * @return bool
	 */
	private function compare_spec( float $actual, $spec ): bool {
		if ( is_array( $spec ) ) {
			$op    = (string) ( $spec['op'] ?? '>=' );
			$value = (float) ( $spec['value'] ?? 0 );
		} else {
			$op    = '>=';
			$value = (float) $spec;
		}
		switch ( $op ) {
			case '=':
			case '==':
				return $actual === $value;
			case '!=':
				return $actual !== $value;
			case '>':
				return $actual > $value;
			case '>=':
				return $actual >= $value;
			case '<':
				return $actual < $value;
			case '<=':
				return $actual <= $value;
			default:
				return false;
		}
	}

	/**
	 * Extract the value list from either a bare array or a { value: [...] } spec.
	 *
	 * @param mixed $expected Raw.
	 * @return array
	 */
	private function values_of( $expected ): array {
		if ( is_array( $expected ) && array_key_exists( 'value', $expected ) ) {
			return (array) $expected['value'];
		}
		return (array) $expected;
	}

	/**
	 * Match a date condition. Supports { from, to } (Y-m-d range), { month, day }
	 * (anniversary/seasonal), and { days_of_week: [0..6] } (0 = Sunday).
	 *
	 * @param array $spec    Date spec.
	 * @param array $context Runtime facts (carries `now_ts`, `month`, `day`, `dow`).
	 * @return bool
	 */
	private function match_date( array $spec, array $context ): bool {
		$ts    = (int) ( $context['now_ts'] ?? 0 );
		$month = (int) ( $context['month'] ?? 0 );
		$day   = (int) ( $context['day'] ?? 0 );
		$dow   = (int) ( $context['dow'] ?? -1 );

		if ( isset( $spec['from'] ) && $ts && strtotime( (string) $spec['from'] . ' 00:00:00' ) > $ts ) {
			return false;
		}
		if ( isset( $spec['to'] ) && $ts && strtotime( (string) $spec['to'] . ' 23:59:59' ) < $ts ) {
			return false;
		}
		if ( isset( $spec['month'] ) && (int) $spec['month'] !== $month ) {
			return false;
		}
		if ( isset( $spec['day'] ) && (int) $spec['day'] !== $day ) {
			return false;
		}
		if ( isset( $spec['days_of_week'] ) && ! in_array( $dow, array_map( 'intval', (array) $spec['days_of_week'] ), true ) ) {
			return false;
		}
		return true;
	}
}
