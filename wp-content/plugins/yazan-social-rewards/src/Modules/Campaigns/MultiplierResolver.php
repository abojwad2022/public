<?php
/**
 * Campaign multiplier.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the active campaign multiplier to earned points. Hooks the shared
 * `yazan_rewards/points/earn_amount` filter, so the Rules engine stays unaware of
 * campaigns — they just multiply the result. The highest-priority active campaign
 * wins (multipliers are not stacked, to keep liability predictable).
 */
final class MultiplierResolver implements Hookable {

	/**
	 * @param CampaignRepository $repo     Campaign repository.
	 * @param Settings           $settings Settings.
	 * @param Money              $money    Rounding helper.
	 */
	public function __construct(
		private CampaignRepository $repo,
		private Settings $settings,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'filter',
				'hook'     => 'yazan_rewards/points/earn_amount',
				'method'   => 'apply',
				'priority' => 20, // After tier multiplier (10), before anti-fraud caps (99).
				'args'     => 3,
			),
		);
	}

	/**
	 * Multiply the points by the winning active campaign.
	 *
	 * @param int    $points  Current points.
	 * @param string $event   Event key.
	 * @param array  $context Context.
	 * @return int
	 */
	public function apply( $points, $event, $context ) {
		$points = (int) $points;
		if ( $points <= 0 || ! $this->settings->feature_enabled( 'campaigns' ) ) {
			return $points;
		}

		$multiplier = $this->winning_multiplier();
		if ( $multiplier <= 1.0 ) {
			return $points;
		}

		return $this->money->round( $points * $multiplier, 'floor' );
	}

	/**
	 * The multiplier of the highest-priority active campaign (1.0 if none).
	 *
	 * @return float
	 */
	public function winning_multiplier(): float {
		$campaigns = $this->repo->active_now();
		foreach ( $campaigns as $campaign ) {
			$m = (float) $campaign->multiplier;
			if ( $m > 0 ) {
				return $m; // First = highest priority.
			}
		}
		return 1.0;
	}
}
