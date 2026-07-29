<?php
/**
 * User registration observer.
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
 * Fires `user_registered` when a new account is created (the referral engine also
 * subscribes to attribute a referred signup in a later phase).
 */
final class UserObserver implements Hookable {

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
				'hook'   => 'user_register',
				'method' => 'on_register',
				'args'   => 1,
			),
		);
	}

	/**
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function on_register( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$this->bus->dispatch(
			new GenericEvent(
				Events::USER_REGISTERED,
				array( 'user_id' => $user_id )
			)
		);
	}
}
