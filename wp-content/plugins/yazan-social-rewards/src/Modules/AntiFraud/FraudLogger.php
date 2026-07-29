<?php
/**
 * Central fraud-flag logger.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single subscriber to {@see Events::FRAUD_FLAGGED}. Every engine now DISPATCHES a
 * normalised flag instead of writing the `fraud_flags` table itself; this logger opens
 * one `suspicious` case per flag, deduping by (source, source_id) so a re-fired signal
 * for the same object never creates duplicate cases. It normalises the two historic
 * payload shapes (risk-engine + referral-guard).
 */
final class FraudLogger implements SubscriberInterface {

	/**
	 * @param FraudRepository $cases Case store.
	 */
	public function __construct( private FraudRepository $cases ) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::FRAUD_FLAGGED          => array( 'on_flagged', 10 ),
			Events::SOCIAL_ACTION_APPROVED => array( 'on_social_approved', 10 ),
		);
	}

	/**
	 * Auto-clear a linked social fraud case when its submission is approved in the
	 * social review queue — so the two queues never disagree.
	 *
	 * @param Event $e social_action_approved.
	 * @return void
	 */
	public function on_social_approved( Event $e ): void {
		$action_id = (int) $e->get( 'action_id' );
		if ( $action_id <= 0 ) {
			return;
		}
		$case = $this->cases->find_by_source( 'social', $action_id );
		if ( $case && 0 === (int) $case->resolved ) {
			$this->cases->resolve( (int) $case->id, FraudRepository::STATUS_APPROVED, 0, __( 'Auto-cleared: submission approved', 'yazan-rewards' ) );
		}
	}

	/**
	 * Persist a flag as a fraud case.
	 *
	 * @param Event $e fraud_flagged.
	 * @return void
	 */
	public function on_flagged( Event $e ): void {
		$user_id   = (int) $e->get( 'user_id' );
		$type      = (string) ( $e->get( 'type' ) ?: 'unknown' );
		$source    = (string) ( $e->get( 'source' ) ?: '' );
		$source_id = (int) ( $e->get( 'source_id' ) ?: 0 );

		// Dedup: one open case per flagged object; for source-less passive flags
		// (velocity/daily-cap), one open case per (user, type) so a rapid-credit burst
		// can't mint an unbounded number of cases.
		if ( '' !== $source && $source_id > 0 ) {
			if ( $this->cases->find_by_source( $source, $source_id ) ) {
				return;
			}
		} elseif ( $this->cases->has_open( $user_id, $type ) ) {
			return;
		}

		$context = (array) ( $e->get( 'context' ) ?: array() );
		$reasons = (array) ( $e->get( 'reasons' ) ?: array() );
		if ( ! empty( $reasons ) ) {
			$context['reasons'] = $reasons;
		}
		if ( null !== $e->get( 'referrer_id' ) ) {
			$context['referrer_id'] = (int) $e->get( 'referrer_id' );
		}

		$severity = (string) ( $e->get( 'severity' ) ?: ( in_array( 'same_email', $reasons, true ) ? 'high' : 'medium' ) );

		$this->cases->open(
			array(
				'user_id'   => $user_id,
				'type'      => $type,
				'severity'  => $severity,
				'status'    => FraudRepository::STATUS_SUSPICIOUS,
				'context'   => $context,
				'signals'   => $reasons,
				'ip_hash'   => (string) ( $e->get( 'ip_hash' ) ?: '' ),
				'source'    => $source,
				'source_id' => $source_id,
			)
		);
	}
}
