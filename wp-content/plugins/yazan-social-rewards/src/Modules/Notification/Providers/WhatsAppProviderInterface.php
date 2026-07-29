<?php
/**
 * WhatsApp provider contract (future-ready seam).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pluggable WhatsApp gateway (WhatsApp Cloud API, Twilio for WhatsApp, 360dialog,
 * …). No provider ships in core — an add-on registers one via the
 * `yazan_rewards/notification/whatsapp_provider` filter to activate
 * {@see \Yazan\Rewards\Modules\Notification\Channels\WhatsAppChannel}. WhatsApp
 * template-message semantics (approved templates, opt-in) are the provider's concern.
 */
interface WhatsAppProviderInterface {

	/**
	 * Provider id (e.g. "cloud_api").
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
	 * Deliver a WhatsApp message.
	 *
	 * @param string $to      E.164 phone number.
	 * @param array  $message Rendered message { subject, body, template, payload }.
	 * @return bool Delivered.
	 */
	public function send( string $to, array $message ): bool;
}
