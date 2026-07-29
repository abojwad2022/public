<?php
/**
 * Redemption service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes a redemption safely: rate-limit → validate → reserve stock → debit
 * points → deliver value (wallet credit or coupon) → record → announce. If
 * delivery fails after the points debit, the points are refunded and the stock
 * released, so a customer never loses points for nothing.
 */
final class RedemptionService {

	private const RL_ACTION = 'redeem';
	private const RL_MAX    = 10;
	private const RL_WINDOW = 3600; // 1 hour.

	/**
	 * @param RewardRepository       $rewards     Catalog.
	 * @param RedemptionRepository   $redemptions Records.
	 * @param PointsLedgerInterface  $points      Points ledger.
	 * @param RewardProviderRegistry $providers   Type→issuer registry.
	 * @param EventBus               $bus         Event bus.
	 * @param RateLimiter            $limiter     Rate limiter.
	 * @param Settings               $settings    Settings.
	 */
	public function __construct(
		private RewardRepository $rewards,
		private RedemptionRepository $redemptions,
		private PointsLedgerInterface $points,
		private RewardProviderRegistry $providers,
		private EventBus $bus,
		private RateLimiter $limiter,
		private Settings $settings
	) {}

	/**
	 * Redeem a catalog reward for a user.
	 *
	 * @param int $user_id   User id.
	 * @param int $reward_id Reward id.
	 * @return array|\WP_Error Success payload or an error.
	 */
	public function redeem( int $user_id, int $reward_id ) {
		if ( ! $this->settings->feature_enabled( 'rewards' ) ) {
			return new \WP_Error( 'rewards_disabled', __( 'Rewards are currently unavailable.', 'yazan-rewards' ), array( 'status' => 403 ) );
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be signed in to redeem.', 'yazan-rewards' ), array( 'status' => 401 ) );
		}

		// Rate limit.
		if ( $this->limiter->too_many( self::RL_ACTION, (string) $user_id, self::RL_MAX ) ) {
			return new \WP_Error( 'too_many', __( 'Too many redemptions. Please try again later.', 'yazan-rewards' ), array( 'status' => 429 ) );
		}

		$reward = $this->rewards->get( $reward_id );
		if ( ! $reward || ! $reward->is_available() ) {
			return new \WP_Error( 'reward_unavailable', __( 'That reward is not available.', 'yazan-rewards' ), array( 'status' => 404 ) );
		}

		if ( $reward->cost_points <= 0 ) {
			return new \WP_Error( 'reward_invalid', __( 'That reward cannot be redeemed.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		// Resolve the fulfillment provider for this reward type BEFORE debiting, so an
		// unsupported type is rejected cleanly instead of mis-delivering.
		$issuer = $this->providers->for_type( $reward->type );
		if ( ! $issuer ) {
			return new \WP_Error( 'unsupported_reward', __( 'That reward type cannot be redeemed.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		// Per-user usage limit.
		if ( $reward->per_user_limit > 0 && $this->redemptions->count_for_user_reward( $user_id, $reward_id ) >= $reward->per_user_limit ) {
			return new \WP_Error( 'limit_reached', __( 'You have reached the redemption limit for this reward.', 'yazan-rewards' ), array( 'status' => 409 ) );
		}

		// Balance check.
		if ( $this->points->balance( $user_id ) < $reward->cost_points ) {
			return new \WP_Error( 'insufficient_points', __( 'You do not have enough points for this reward.', 'yazan-rewards' ), array( 'status' => 400 ) );
		}

		// Reserve stock atomically.
		if ( ! $this->rewards->reserve_stock( $reward_id ) ) {
			return new \WP_Error( 'out_of_stock', __( 'That reward just went out of stock.', 'yazan-rewards' ), array( 'status' => 409 ) );
		}

		$this->limiter->hit( self::RL_ACTION, (string) $user_id, self::RL_WINDOW );

		// Debit points first (source keyed to the reward for traceability).
		$debit = $this->points->debit(
			$user_id,
			$reward->cost_points,
			'redeem',
			$reward_id,
			array(
				'type' => 'redeem',
				'note' => sprintf(
					/* translators: %s: reward title. */
					__( 'Redeemed: %s', 'yazan-rewards' ),
					$reward->title
				),
			)
		);
		if ( ! $debit ) {
			$this->rewards->release_stock( $reward_id );
			return new \WP_Error( 'debit_failed', __( 'Could not process the redemption.', 'yazan-rewards' ), array( 'status' => 500 ) );
		}

		// Deliver the value via the registered provider for this reward type.
		$result = $issuer->issue( $user_id, $reward );

		if ( empty( $result['ok'] ) ) {
			// Compensate: give the points back and free the stock.
			$this->points->credit(
				$user_id,
				$reward->cost_points,
				'redeem',
				$reward_id,
				array( 'type' => 'refund', 'note' => __( 'Redemption reversed (delivery failed)', 'yazan-rewards' ) )
			);
			$this->rewards->release_stock( $reward_id );
			return new \WP_Error( 'delivery_failed', __( 'Could not issue the reward. Your points were refunded.', 'yazan-rewards' ), array( 'status' => 500 ) );
		}

		// Record the redemption.
		$redemption_id = $this->redemptions->record(
			array(
				'user_id'      => $user_id,
				'reward_id'    => $reward_id,
				'points_spent' => $reward->cost_points,
				'result_type'  => $result['type'],
				'result_ref'   => $result['ref'],
				'status'       => 'complete',
				'note'         => $reward->title,
			)
		);

		$this->bus->dispatch(
			new GenericEvent(
				Events::REWARD_REDEEMED,
				array(
					'user_id'       => $user_id,
					'reward_id'     => $reward_id,
					'redemption_id' => $redemption_id,
					'points_spent'  => $reward->cost_points,
					'result_type'   => $result['type'],
					'result_ref'    => $result['ref'],
				)
			)
		);

		return array(
			'ok'            => true,
			'redemption_id' => $redemption_id,
			'reward'        => $reward->to_array(),
			'result_type'   => $result['type'],
			'coupon_code'   => $result['code'] ?? '',
			'credit_amount' => $result['amount'] ?? '',
			'balance'       => $this->points->balance( $user_id ),
		);
	}
}
