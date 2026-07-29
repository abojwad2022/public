<?php
/**
 * SMS notification channel (future-ready).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\ChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Providers\SmsProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer SMS channel. Ships DORMANT: `enabled()` is false until BOTH the admin
 * toggle is on AND a provider is registered via the
 * `yazan_rewards/notification/sms_provider` filter and reports `configured()`. Until
 * then the dispatcher simply skips it — the wiring is complete, only the gateway is
 * missing. A customer channel, so it is gated by per-category preferences.
 */
final class SmsChannel implements ChannelInterface {

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'sms';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		if ( ! (bool) $this->settings->get( 'notification.sms.enabled', false ) ) {
			return false;
		}
		$provider = $this->provider();
		return $provider instanceof SmsProviderInterface && $provider->configured();
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		$provider = $this->provider();
		if ( ! $provider instanceof SmsProviderInterface || ! $provider->configured() ) {
			return false;
		}
		$to = $this->recipient( $user_id );
		return '' !== $to && $provider->send( $to, $message );
	}

	/**
	 * The registered SMS provider, if any.
	 *
	 * @return SmsProviderInterface|null
	 */
	private function provider(): ?SmsProviderInterface {
		$provider = apply_filters( 'yazan_rewards/notification/sms_provider', null, $this->settings );
		return $provider instanceof SmsProviderInterface ? $provider : null;
	}

	/**
	 * The customer's phone number (filterable; billing phone by default).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function recipient( int $user_id ): string {
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		return (string) apply_filters( 'yazan_rewards/notification/sms_recipient', $phone, $user_id );
	}
}
