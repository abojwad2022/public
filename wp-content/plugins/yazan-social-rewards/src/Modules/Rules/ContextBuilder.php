<?php
/**
 * Rule context builder.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Modules\Points\PointsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enriches an event's raw context with the user facts that conditions match
 * against (level, lifetime points, purchase count, roles, date parts). Doing this
 * once, here, keeps {@see ConditionMatcher} pure — it only reads the context map.
 *
 * Facts that are expensive (order count, lifetime points) are resolved lazily and
 * only when a rule for this event actually exists, keeping the hot path cheap.
 */
final class ContextBuilder {

	/**
	 * @param Container $container Service container (for optional services).
	 */
	public function __construct( private Container $container ) {}

	/**
	 * Build the full matching context.
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $raw     Event-supplied facts (order_total, product_ids…).
	 * @return array<string,mixed>
	 */
	public function build( int $user_id, array $raw ): array {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$context = array_merge(
			array(
				'user_id'     => $user_id,
				'user_level'  => (string) get_user_meta( $user_id, '_yzrw_tier', true ),
				'roles'       => $this->roles( $user_id ),
				'now_ts'      => $now,
				'month'       => (int) gmdate( 'n', $now ),
				'day'         => (int) gmdate( 'j', $now ),
				'dow'         => (int) gmdate( 'w', $now ),
				'lifetime_points' => $this->lifetime_points( $user_id ),
				'purchase_count'  => $this->purchase_count( $user_id ),
			),
			$raw
		);

		/**
		 * Filter the rule-matching context, so add-ons can inject extra facts.
		 *
		 * @param array $context Context.
		 * @param int   $user_id User id.
		 * @param array $raw     Raw event facts.
		 */
		return (array) apply_filters( 'yazan_rewards/rules/context', $context, $user_id, $raw );
	}

	/**
	 * The user's roles.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	private function roles( int $user_id ): array {
		$user = get_userdata( $user_id );
		return $user ? array_values( (array) $user->roles ) : array();
	}

	/**
	 * Lifetime points earned (0 if the points module is unavailable).
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function lifetime_points( int $user_id ): int {
		if ( ! $this->container->has( PointsRepository::class ) ) {
			return 0;
		}
		return $this->container->get( PointsRepository::class )->lifetime_earned( $user_id );
	}

	/**
	 * Completed order count for the customer.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function purchase_count( int $user_id ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		// Use pagination to get the COUNT without materialising every order id
		// (a heavy customer could otherwise pull thousands of rows per rule evaluation).
		$result = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-completed', 'wc-processing' ),
				'limit'       => 1,
				'return'      => 'ids',
				'paginate'    => true,
			)
		);
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}
}
