<?php
/**
 * SMS provider contract (future-ready seam).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pluggable SMS gateway (Twilio, Vonage, Unifonic, …). No provider ships in
 * core — an add-on registers one via the `yazan_rewards/notification/sms_provider`
 * filter, which flips {@see \Yazan\Rewards\Modules\Notification\Channels\SmsChannel}
 * from dormant to live without any code change here. This is the "SMS providers"
 * abstraction: many gateways, one contract.
 */
interface SmsProviderInterface {

	/**
	 * Provider id (e.g. "twilio").
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether the provider has the credentials/config it needs to send.
	 *
	 * @return bool
	 */
	public function configured(): bool;

	/**
	 * Deliver a text message.
	 *
	 * @param string $to      E.164 phone number.
	 * @param array  $message Rendered message { subject, body, template, payload }.
	 * @return bool Delivered.
	 */
	public function send( string $to, array $message ): bool;
}
