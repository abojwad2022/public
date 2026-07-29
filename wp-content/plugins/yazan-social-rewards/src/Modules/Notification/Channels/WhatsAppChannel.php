<?php
/**
 * WhatsApp notification channel (future-ready).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\ChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Providers\WhatsAppProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer WhatsApp channel. Ships DORMANT — `enabled()` is false until the admin
 * toggle is on AND a provider is registered via
 * `yazan_rewards/notification/whatsapp_provider` and `configured()`. A customer
 * channel, gated by per-category preferences.
 */
final class WhatsAppChannel implements ChannelInterface {

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'whatsapp';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		if ( ! (bool) $this->settings->get( 'notification.whatsapp.enabled', false ) ) {
			return false;
		}
		$provider = $this->provider();
		return $provider instanceof WhatsAppProviderInterface && $provider->configured();
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		$provider = $this->provider();
		if ( ! $provider instanceof WhatsAppProviderInterface || ! $provider->configured() ) {
			return false;
		}
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		$to    = (string) apply_filters( 'yazan_rewards/notification/whatsapp_recipient', $phone, $user_id );
		return '' !== $to && $provider->send( $to, $message );
	}

	/**
	 * The registered WhatsApp provider, if any.
	 *
	 * @return WhatsAppProviderInterface|null
	 */
	private function provider(): ?WhatsAppProviderInterface {
		$provider = apply_filters( 'yazan_rewards/notification/whatsapp_provider', null, $this->settings );
		return $provider instanceof WhatsAppProviderInterface ? $provider : null;
	}
}
