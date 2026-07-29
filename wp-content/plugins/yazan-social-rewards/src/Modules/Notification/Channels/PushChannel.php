<?php
/**
 * Push notification channel (future-ready).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\ChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Providers\PushProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer push channel. Ships DORMANT — `enabled()` is false until the admin toggle
 * is on AND a provider is registered via `yazan_rewards/notification/push_provider`
 * and `configured()`. Recipient device tokens are supplied by the future provider/
 * add-on (via the `yazan_rewards/notification/push_recipient` filter). A customer
 * channel, gated by per-category preferences.
 */
final class PushChannel implements ChannelInterface {

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'push';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		if ( ! (bool) $this->settings->get( 'notification.push.enabled', false ) ) {
			return false;
		}
		$provider = $this->provider();
		return $provider instanceof PushProviderInterface && $provider->configured();
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		$provider = $this->provider();
		if ( ! $provider instanceof PushProviderInterface || ! $provider->configured() ) {
			return false;
		}
		$to = (string) apply_filters( 'yazan_rewards/notification/push_recipient', '', $user_id );
		return '' !== $to && $provider->send( $to, $message );
	}

	/**
	 * The registered push provider, if any.
	 *
	 * @return PushProviderInterface|null
	 */
	private function provider(): ?PushProviderInterface {
		$provider = apply_filters( 'yazan_rewards/notification/push_provider', null, $this->settings );
		return $provider instanceof PushProviderInterface ? $provider : null;
	}
}
