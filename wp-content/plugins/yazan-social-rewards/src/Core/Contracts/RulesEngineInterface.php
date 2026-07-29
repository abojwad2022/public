<?php
/**
 * Rules engine contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of "what earns what". Given an event key and a context map,
 * returns the number of points that should be awarded. Consumers (observers)
 * depend on this interface, never on the concrete evaluator.
 */
interface RulesEngineInterface {

	/**
	 * Evaluate all active rules for an event and return the total points award.
	 *
	 * @param string              $event   Event key (e.g. "order_completed", "signup").
	 * @param array<string,mixed> $context Facts the rules match against
	 *                                     (order_total, user_id, product_cats, …).
	 * @return int Points to award (0 when nothing matches).
	 */
	public function evaluate( string $event, array $context ): int;
}
