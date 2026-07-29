<?php
/**
 * Push-notification provider contract (future-ready seam).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pluggable push-notification service (Firebase Cloud Messaging, OneSignal, Web
 * Push, …). No provider ships in core — an add-on registers one via the
 * `yazan_rewards/notification/push_provider` filter to activate
 * {@see \Yazan\Rewards\Modules\Notification\Channels\PushChannel}.
 */
interface PushProviderInterface {

	/**
	 * Provider id (e.g. "fcm").
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether the provider is configured to send.
	 *
	 * @return bool
	 */
	public function configured(): bool;

	/**
	 * Deliver a push notification.
	 *
	 * @param string $to      Device token / subscription id.
	 * @param array  $message Rendered message { subject, body, template, payload }.
	 * @return bool Delivered.
	 */
	public function send( string $to, array $message ): bool;
}
