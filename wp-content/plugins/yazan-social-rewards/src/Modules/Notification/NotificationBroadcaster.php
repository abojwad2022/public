<?php
/**
 * Notification broadcaster (fan-out).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one notification to MANY users. The dispatcher is single-user by design, so
 * a broadcast is chunked and each chunk is handed to Action Scheduler as an async
 * job — keeping a "new campaign" fan-out off the request that triggered it. Falls
 * back to inline delivery when Action Scheduler is unavailable. Each chunk still runs
 * through the full per-user policy (preferences, digest, channels).
 */
final class NotificationBroadcaster implements Hookable {

	/** Async per-chunk delivery hook. */
	public const HOOK = 'yzrw_notification_broadcast_chunk';

	private const CHUNK = 100;

	/**
	 * @param NotificationDispatcher $dispatcher Dispatcher.
	 * @param Scheduler              $scheduler  Scheduler.
	 */
	public function __construct(
		private NotificationDispatcher $dispatcher,
		private Scheduler $scheduler
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => self::HOOK, 'method' => 'run_chunk', 'args' => 1 ),
		);
	}

	/**
	 * Broadcast a template to a list of users (chunked + async).
	 *
	 * @param int[]  $user_ids Recipients.
	 * @param string $template Template key.
	 * @param array  $payload  Payload.
	 * @param array  $opts     { category?, priority? }.
	 * @return void
	 */
	public function broadcast( array $user_ids, string $template, array $payload = array(), array $opts = array() ): void {
		$user_ids = array_values( array_filter( array_unique( array_map( 'intval', $user_ids ) ), static fn( $id ) => $id > 0 ) );
		if ( empty( $user_ids ) || '' === $template ) {
			return;
		}

		foreach ( array_chunk( $user_ids, self::CHUNK ) as $chunk ) {
			$job = array( 'users' => $chunk, 'template' => $template, 'payload' => $payload, 'opts' => $opts );
			if ( $this->scheduler->available() ) {
				$this->scheduler->async( self::HOOK, array( $job ) );
			} else {
				$this->run_chunk( $job ); // Inline fallback.
			}
		}
	}

	/**
	 * Deliver one chunk (the async callback).
	 *
	 * @param mixed $job { users, template, payload, opts }.
	 * @return void
	 */
	public function run_chunk( $job ): void {
		if ( ! is_array( $job ) ) {
			return;
		}
		$template = (string) ( $job['template'] ?? '' );
		if ( '' === $template ) {
			return;
		}
		$payload = (array) ( $job['payload'] ?? array() );
		$opts    = (array) ( $job['opts'] ?? array() );
		foreach ( (array) ( $job['users'] ?? array() ) as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id > 0 ) {
				$this->dispatcher->notify( $user_id, $template, $payload, $opts );
			}
		}
	}
}
