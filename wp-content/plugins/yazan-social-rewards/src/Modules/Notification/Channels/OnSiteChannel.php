<?php
/**
 * On-site notification channel.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\ChannelInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The on-site inbox channel. Delivery is simply persisting the notification — the
 * dispatcher logs every channel, and the on-site inbox reads the rows whose
 * channel is "onsite" — so this send() is a no-op that reports success.
 */
final class OnSiteChannel implements ChannelInterface {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'onsite';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		return true; // The dispatcher's log row IS the delivery.
	}
}
