<?php
/**
 * Analytics rollup subscriber.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Analytics;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accumulates daily metrics from domain events. Subscribing here (rather than
 * querying ledgers on demand) keeps dashboards fast and lets the plugin report on
 * issuance, redemption, and commission over time. Runs late (priority 90) so it
 * records the final, post-fraud-cap amounts.
 */
final class RollupService implements SubscriberInterface {

	/**
	 * @param ReportRepository $reports Rollup repository.
	 */
	public function __construct( private ReportRepository $reports ) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::POINTS_CREDITED  => array( 'on_points_credited', 90 ),
			Events::POINTS_DEBITED   => array( 'on_points_debited', 90 ),
			Events::CREDIT_CREDITED  => array( 'on_credit_credited', 90 ),
			Events::CREDIT_DEBITED   => array( 'on_credit_debited', 90 ),
			Events::REWARD_REDEEMED  => array( 'on_reward_redeemed', 90 ),
			Events::COMMISSION_EARNED => array( 'on_commission', 90 ),
			Events::ORDER_REWARDED   => array( 'on_order', 90 ),
		);
	}

	/**
	 * @param Event $e points_credited.
	 * @return void
	 */
	public function on_points_credited( Event $e ): void {
		$this->reports->increment( $this->today(), 'points_issued', (float) $e->get( 'points', 0 ) );
	}

	/**
	 * @param Event $e points_debited.
	 * @return void
	 */
	public function on_points_debited( Event $e ): void {
		$this->reports->increment( $this->today(), 'points_spent', (float) $e->get( 'points', 0 ) );
	}

	/**
	 * @param Event $e credit_credited.
	 * @return void
	 */
	public function on_credit_credited( Event $e ): void {
		$this->reports->increment( $this->today(), 'credit_issued', (float) $e->get( 'amount', 0 ) );
	}

	/**
	 * @param Event $e credit_debited.
	 * @return void
	 */
	public function on_credit_debited( Event $e ): void {
		$this->reports->increment( $this->today(), 'credit_spent', (float) $e->get( 'amount', 0 ) );
	}

	/**
	 * @param Event $e reward_redeemed.
	 * @return void
	 */
	public function on_reward_redeemed( Event $e ): void {
		$this->reports->increment( $this->today(), 'redemptions', 1 );
	}

	/**
	 * @param Event $e commission_earned.
	 * @return void
	 */
	public function on_commission( Event $e ): void {
		$this->reports->increment( $this->today(), 'commission_paid', (float) $e->get( 'amount', 0 ) );
	}

	/**
	 * @param Event $e order_rewarded.
	 * @return void
	 */
	public function on_order( Event $e ): void {
		$this->reports->increment( $this->today(), 'orders_rewarded', 1 );
	}

	/**
	 * Today's date, store timezone.
	 *
	 * @return string
	 */
	private function today(): string {
		return date_i18n( 'Y-m-d' );
	}
}
