<?php
/**
 * Outbound webhook notification channel.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\IntegrationChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Social\UrlFetcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POSTs each notification as signed JSON to an admin-configured HTTPS endpoint
 * (Slack workflow, Zapier/Make, a custom automation). An INTEGRATION channel —
 * it fires for every notification irrespective of the recipient's preferences,
 * because it is the store's own event stream, not a customer inbox.
 *
 * The body is signed with an HMAC-SHA256 of the raw payload using a shared secret
 * (stored write-only, autoload-off) so the receiver can verify authenticity via
 * the `X-Yazan-Signature: sha256=…` header.
 */
final class WebhookChannel implements IntegrationChannelInterface {

	/** Autoload-off option holding the shared signing secret (write-only). */
	public const SECRET_OPTION = 'yazan_rewards_notification_webhook_secret';

	private const TIMEOUT = 10;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'webhook';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'notification.webhook.enabled', false )
			&& '' !== $this->url();
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		$url = $this->url();
		if ( '' === $url ) {
			return false;
		}

		$body = (string) wp_json_encode(
			array(
				'event'     => (string) ( $message['template'] ?? '' ),
				'category'  => (string) ( $message['category'] ?? '' ),
				'priority'  => (string) ( $message['priority'] ?? 'normal' ),
				'user_id'   => $user_id,
				'subject'   => (string) ( $message['subject'] ?? '' ),
				'body'      => (string) ( $message['body'] ?? '' ),
				'data'      => (array) ( $message['payload'] ?? array() ),
				'site'      => home_url(),
				'timestamp' => time(),
			)
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'X-Yazan-Event' => (string) ( $message['template'] ?? '' ),
		);
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' !== $secret ) {
			$headers['X-Yazan-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		/*
		 * SSRF guard.
		 *
		 * The destination is admin-configured, so this is defence in depth
		 * rather than a hole a visitor can reach directly — but "admin-only"
		 * stops being reassuring the moment an admin session is hijacked or a
		 * stored XSS lands in wp-admin. Without this check the webhook field is
		 * a clean pivot into the private network: point it at
		 * http://169.254.169.254/ and every notification exfiltrates cloud
		 * instance credentials to whatever the receiver logs.
		 *
		 * Reuses the resolve-and-validate check already written for
		 * customer-submitted social URLs instead of duplicating the logic.
		 */
		$allow_internal = (bool) apply_filters( 'yazan_rewards/webhook/allow_internal_url', false, $url );

		if ( ! $allow_internal && ! ( new UrlFetcher() )->is_safe( $url ) ) {
			return false;
		}

		// wp_safe_remote_post (not wp_remote_post) so WordPress re-validates the
		// host itself — this is what catches a DNS answer that changes between
		// our check above and the actual connection.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'body'        => $body,
				'blocking'    => true,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * The configured webhook URL, only when it is a valid https:// endpoint.
	 *
	 * @return string
	 */
	private function url(): string {
		$url = trim( (string) $this->settings->get( 'notification.webhook.url', '' ) );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		return ( isset( $parts['scheme'] ) && 'https' === strtolower( (string) $parts['scheme'] ) ) ? $url : '';
	}
}
