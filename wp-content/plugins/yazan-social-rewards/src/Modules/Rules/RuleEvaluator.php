<?php
/**
 * Rule evaluator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Contracts\RulesEngineInterface;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an event + context into a points award by running the active rules. This
 * is the single authority for "what earns what". Campaign multipliers are layered
 * on via the `yazan_rewards/points/earn_amount` filter (the Campaign engine hooks
 * it in a later phase), so this class needs no knowledge of campaigns.
 */
final class RuleEvaluator implements RulesEngineInterface {

	/**
	 * @param RuleRepository   $rules   Rule repository.
	 * @param ConditionMatcher $matcher Condition matcher.
	 * @param Money            $money   Rounding helper.
	 */
	public function __construct(
		private RuleRepository $rules,
		private ConditionMatcher $matcher,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function evaluate( string $event, array $context ): int {
		$total = 0;

		foreach ( $this->rules->active_for_event( $event ) as $rule ) {
			// Action-rules are executed by the RuleEngine (EVENT→CONDITIONS→ACTIONS);
			// the pure points evaluator only handles legacy actionless award rules,
			// so the two paths never double-count.
			if ( $rule->has_actions() ) {
				continue;
			}
			if ( ! $rule->is_live() ) {
				continue;
			}
			if ( ! $this->matcher->matches( $rule->conditions, $context ) ) {
				continue;
			}
			$total += $this->award_for( $rule, $context );
		}

		/**
		 * Filter the final points award for an event before it is credited.
		 * Campaigns multiply here; anti-fraud may cap here.
		 *
		 * @param int    $total   Computed points.
		 * @param string $event   Event key.
		 * @param array  $context Runtime facts.
		 */
		$total = (int) apply_filters( 'yazan_rewards/points/earn_amount', $total, $event, $context );

		return max( 0, $total );
	}

	/**
	 * Points a single rule awards for this context.
	 *
	 * @param Rule  $rule    Rule.
	 * @param array $context Facts.
	 * @return int
	 */
	private function award_for( Rule $rule, array $context ): int {
		switch ( $rule->award_type ) {
			case 'per_currency':
				$base = (float) ( $context['order_total'] ?? 0 );
				return $this->money->round( $base * $rule->award_value, (string) ( $context['rounding'] ?? 'floor' ) );

			case 'fixed':
			default:
				return (int) round( $rule->award_value );
		}
	}
}
