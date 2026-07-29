<?php
/**
 * Anti-fraud risk engine.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

use Yazan\Rewards\Core\Contracts\DetectorInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Fingerprint;
use Yazan\Rewards\Modules\Points\PointsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The central risk assessor. Two roles:
 *   1. `assess()` — a SYNCHRONOUS pre-commit decision: run every enabled detector,
 *      sum their weighted signals, and return {@see Decision} allow|review. Callers hold
 *      the reward for review when it returns review (a hold, never a hard block).
 *   2. Passive ledger guards — trims a runaway daily earn (via the shared earn filter)
 *      and watches credit velocity; both now DISPATCH {@see Events::FRAUD_FLAGGED} and the
 *      {@see FraudLogger} writes the single case row (no more direct double-writes).
 */
final class RiskEngine implements \Yazan\Rewards\Core\Contracts\Hookable, \Yazan\Rewards\Core\Contracts\SubscriberInterface {

	private const VELOCITY_MAX    = 12;
	private const VELOCITY_WINDOW = 60; // seconds.

	/**
	 * @param DetectorInterface[] $detectors Detectors.
	 * @param PointsRepository     $points    Points repository (velocity/daily).
	 * @param EventBus             $bus       Event bus.
	 * @param Settings             $settings  Settings.
	 * @param Fingerprint          $fp        Fingerprint helper.
	 */
	public function __construct(
		private array $detectors,
		private PointsRepository $points,
		private EventBus $bus,
		private Settings $settings,
		private Fingerprint $fp
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'filter',
				'hook'     => 'yazan_rewards/points/earn_amount',
				'method'   => 'cap_daily',
				'priority' => 99, // Last — after tier (10) and campaign (20) multipliers.
				'args'     => 3,
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::POINTS_CREDITED => array( 'on_points_credited', 99 ),
		);
	}

	/**
	 * Assess an action's fraud risk.
	 *
	 * @param string $event   Event key (e.g. "ugc", "referral").
	 * @param int    $user_id Acting user.
	 * @param array  $context Event facts (url, content, metrics, source, source_id, …).
	 * @return Decision
	 */
	public function assess( string $event, int $user_id, array $context = array() ): Decision {
		if ( $user_id <= 0 || ! $this->settings->feature_enabled( 'antifraud' ) ) {
			return new Decision( Decision::ALLOW, 0, array() );
		}
		if ( empty( $context['ip_hash'] ) ) {
			$context['ip_hash'] = $this->fp->ip_hash();
		}

		$signals = array();
		foreach ( $this->detectors as $detector ) {
			if ( $detector instanceof DetectorInterface && $detector->enabled() ) {
				foreach ( $detector->detect( $event, $user_id, $context ) as $signal ) {
					$signals[] = $signal;
				}
			}
		}

		$score = 0;
		foreach ( $signals as $signal ) {
			$score += (int) ( $signal['weight'] ?? 0 );
		}

		$threshold = max( 1, (int) $this->settings->get( 'antifraud.review_threshold', 50 ) );
		$hold      = (bool) $this->settings->get( 'antifraud.hold_suspicious', true );
		$action    = ( $hold && $score >= $threshold ) ? Decision::REVIEW : Decision::ALLOW;

		return new Decision( $action, $score, $signals );
	}

	/**
	 * Trim an award so it does not exceed the configured daily earn cap.
	 *
	 * @param int    $points  Points about to be awarded.
	 * @param string $event   Event key.
	 * @param array  $context Context (expects user_id).
	 * @return int
	 */
	public function cap_daily( $points, $event, $context ) {
		$points  = (int) $points;
		$user_id = (int) ( $context['user_id'] ?? 0 );
		$max     = (int) $this->settings->get( 'antifraud.max_earn_per_day', 0 );

		if ( $points <= 0 || $user_id <= 0 || $max <= 0 || ! $this->settings->feature_enabled( 'antifraud' ) ) {
			return $points;
		}

		$already = $this->points->earned_since( $user_id, date_i18n( 'Y-m-d 00:00:00' ) );
		$room    = max( 0, $max - $already );
		if ( $points <= $room ) {
			return $points;
		}

		// Over the cap — trim and flag (the logger writes the case).
		$this->dispatch_flag(
			$user_id,
			'daily_earn_cap',
			'medium',
			array( 'requested' => $points, 'granted' => $room, 'cap' => $max, 'event' => $event )
		);
		return $room;
	}

	/**
	 * Velocity check on each credit.
	 *
	 * @param Event $event points_credited.
	 * @return void
	 */
	public function on_points_credited( Event $event ): void {
		if ( ! $this->settings->feature_enabled( 'antifraud' ) ) {
			return;
		}
		$user_id = (int) $event->get( 'user_id' );
		if ( $user_id <= 0 ) {
			return;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - self::VELOCITY_WINDOW );
		$count = $this->points->credit_count_since( $user_id, $since );
		if ( $count > self::VELOCITY_MAX ) {
			$this->dispatch_flag( $user_id, 'velocity', 'high', array( 'credits' => $count, 'window_s' => self::VELOCITY_WINDOW ) );
		}
	}

	/**
	 * Dispatch a normalised FRAUD_FLAGGED event for the central logger to persist.
	 *
	 * @param int    $user_id  User id.
	 * @param string $type     Flag type.
	 * @param string $severity low|medium|high.
	 * @param array  $context  Context.
	 * @return void
	 */
	private function dispatch_flag( int $user_id, string $type, string $severity, array $context ): void {
		$this->bus->dispatch(
			new GenericEvent(
				Events::FRAUD_FLAGGED,
				array(
					'user_id'  => $user_id,
					'type'     => $type,
					'severity' => $severity,
					'context'  => $context,
					'ip_hash'  => $this->fp->ip_hash(),
				)
			)
		);
	}
}
