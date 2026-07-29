<?php
/**
 * Notification dispatcher.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Contracts\ChannelInterface;
use Yazan\Rewards\Core\Contracts\IntegrationChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a notification and fans it out across channels, applying the delivery
 * policy: an admin master kill-switch per category; per-customer preferences on
 * customer channels (email/on-site); email deferral into the daily digest when the
 * customer prefers it and the notification is not urgent. INTEGRATION channels
 * (webhook) bypass customer preferences — they are the store's own event stream.
 * Every actual delivery is logged to the outbox.
 */
final class NotificationDispatcher {

	/**
	 * @param ChannelInterface[]      $channels    Delivery channels.
	 * @param TemplateRenderer        $renderer    Template renderer.
	 * @param NotificationRepository  $repo        Outbox.
	 * @param NotificationPreferences $preferences Customer preferences.
	 * @param DigestService           $digest      Digest queue.
	 * @param Settings                $settings    Settings.
	 */
	public function __construct(
		private array $channels,
		private TemplateRenderer $renderer,
		private NotificationRepository $repo,
		private NotificationPreferences $preferences,
		private DigestService $digest,
		private Settings $settings
	) {}

	/**
	 * Send a notification to a user.
	 *
	 * @param int    $user_id  User id.
	 * @param string $template Template key.
	 * @param array  $payload  Payload.
	 * @param array  $opts     { category?, priority? } — inferred from the template when omitted.
	 * @return void
	 */
	public function notify( int $user_id, string $template, array $payload = array(), array $opts = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$category = isset( $opts['category'] ) ? sanitize_key( (string) $opts['category'] ) : $this->preferences->category_for_template( $template );
		$priority = isset( $opts['priority'] ) ? sanitize_key( (string) $opts['priority'] ) : $this->preferences->priority_for_template( $template );

		$categories = $this->preferences->categories();
		$required   = ! empty( $categories[ $category ]['required'] );

		// Admin master kill-switch for a whole category (required categories bypass).
		if ( ! $required && ! (bool) $this->settings->get( 'notification.categories.' . $category, true ) ) {
			return;
		}

		$message             = $this->renderer->render( $template, $payload );
		$message['category'] = $category;
		$message['priority'] = $priority;

		foreach ( $this->channels as $channel ) {
			if ( ! $channel->enabled() ) {
				continue;
			}

			// Integration channels (webhook) always fire — they are not a customer inbox.
			if ( $channel instanceof IntegrationChannelInterface ) {
				$ok = $channel->send( $user_id, $message );
				$this->record( $user_id, $channel->id(), $template, $message, $ok ? 'sent' : 'failed', $category, $priority );
				continue;
			}

			$cid = $channel->id();

			// Customer channels respect the customer's opt-out.
			if ( ! $this->preferences->allows( $user_id, $category, $cid ) ) {
				continue;
			}

			// Defer non-urgent email into the daily digest when the customer prefers it.
			if ( 'email' === $cid && 'urgent' !== $priority && $this->digest->enabled()
				&& 'digest' === $this->preferences->email_mode( $user_id, $category ) ) {
				$this->digest->queue( $user_id, $message, $category );
				continue;
			}

			$ok = $channel->send( $user_id, $message );
			$this->record( $user_id, $cid, $template, $message, $ok ? 'sent' : 'failed', $category, $priority );
		}
	}

	/**
	 * Log a delivery to the outbox.
	 *
	 * @param int    $user_id  User id.
	 * @param string $channel  Channel id.
	 * @param string $template Template.
	 * @param array  $message  Rendered message.
	 * @param string $status   sent|failed.
	 * @param string $category Category.
	 * @param string $priority Priority.
	 * @return void
	 */
	private function record( int $user_id, string $channel, string $template, array $message, string $status, string $category, string $priority ): void {
		$this->repo->log(
			array(
				'user_id'  => $user_id,
				'channel'  => $channel,
				'template' => $template,
				'category' => $category,
				'priority' => $priority,
				'status'   => $status,
				'payload'  => array(
					'subject' => $message['subject'] ?? '',
					'body'    => $message['body'] ?? '',
				),
			)
		);
	}
}
