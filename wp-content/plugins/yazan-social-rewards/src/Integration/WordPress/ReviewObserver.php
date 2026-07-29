<?php
/**
 * Product-review observer.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Integration\WordPress;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires `review_created` when a logged-in customer's product review is approved.
 * Handles both instant approval and later moderation. The points engine dedupes
 * per product, so firing on both paths is safe.
 */
final class ReviewObserver implements Hookable {

	/**
	 * @param EventBus $bus Event bus.
	 */
	public function __construct( private EventBus $bus ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'   => 'action',
				'hook'   => 'comment_post',
				'method' => 'on_comment_post',
				'args'   => 2,
			),
			array(
				'type'   => 'action',
				'hook'   => 'transition_comment_status',
				'method' => 'on_transition',
				'args'   => 3,
			),
		);
	}

	/**
	 * A freshly posted comment that is already approved.
	 *
	 * @param int        $comment_id       Comment id.
	 * @param int|string $comment_approved 1 when approved.
	 * @return void
	 */
	public function on_comment_post( $comment_id, $comment_approved ): void {
		if ( 1 === (int) $comment_approved ) {
			$this->maybe_reward( (int) $comment_id );
		}
	}

	/**
	 * A comment moderated into the approved state.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $comment    Comment object.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $comment ): void {
		if ( 'approved' === $new_status && 'approved' !== $old_status && isset( $comment->comment_ID ) ) {
			$this->maybe_reward( (int) $comment->comment_ID );
		}
	}

	/**
	 * Fire the event if the comment is a product review by a logged-in customer.
	 *
	 * @param int $comment_id Comment id.
	 * @return void
	 */
	private function maybe_reward( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$user_id    = (int) $comment->user_id;
		$product_id = (int) $comment->comment_post_ID;

		if ( $user_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return; // Guests earn nothing; only product reviews count.
		}

		$this->bus->dispatch(
			new GenericEvent(
				Events::REVIEW_CREATED,
				array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
					'comment_id' => $comment_id,
				)
			)
		);
	}
}
