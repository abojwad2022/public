<?php
/**
 * Reconciles a social submission with its fraud-case resolution.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a held UGC submission in step with its fraud case. When an admin resolves a
 * `source = social` fraud case, this flips the underlying `social_actions` row to
 * match — reject → deny the submission (so it can never be paid from the social review
 * queue), approve → mark it approved (crediting is idempotent via net_for_source, so
 * the reward is paid exactly once). Without this the two queues could diverge and a
 * fraud-rejected submission could still be paid.
 */
final class SocialFraudReconciler implements SubscriberInterface {

	/**
	 * @param SocialService $service Social service.
	 */
	public function __construct( private SocialService $service ) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::FRAUD_CASE_RESOLVED => array( 'on_resolved', 10 ),
		);
	}

	/**
	 * @param Event $e fraud_case_resolved.
	 * @return void
	 */
	public function on_resolved( Event $e ): void {
		if ( 'social' !== (string) $e->get( 'source' ) ) {
			return;
		}
		$action_id = (int) $e->get( 'source_id' );
		if ( $action_id <= 0 ) {
			return;
		}
		$status = (string) $e->get( 'status' );
		if ( 'rejected' === $status ) {
			$this->service->reject( $action_id, 0 );
		} elseif ( 'approved' === $status ) {
			$this->service->approve( $action_id, 0 );
		}
	}
}
