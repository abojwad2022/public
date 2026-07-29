<?php
/**
 * Email notification channel.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification\Channels;

use Yazan\Rewards\Core\Contracts\ChannelInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\EmailTemplate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends rewards notifications by email as branded, RTL-aware HTML with a plain-text
 * alternative, via wp_mail(). The HTML content-type is set only through this
 * message's own headers (never a global filter) and the plain-text AltBody is
 * attached with a scoped `phpmailer_init` action that is removed straight after
 * the send — so WooCommerce's own transactional emails are completely untouched.
 */
final class EmailChannel implements ChannelInterface {

	/**
	 * @param Settings      $settings Settings.
	 * @param EmailTemplate $template Branded HTML wrapper.
	 */
	public function __construct(
		private Settings $settings,
		private EmailTemplate $template
	) {}

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'email';
	}

	/**
	 * @inheritDoc
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'notification.email', true );
	}

	/**
	 * @inheritDoc
	 */
	public function send( int $user_id, array $message ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$subject = (string) ( $message['subject'] ?? get_bloginfo( 'name' ) );
		$body    = (string) ( $message['body'] ?? '' );

		// Pre-rendered HTML content (e.g. a digest) is passed through untouched;
		// a plain notification body is escaped into a single paragraph.
		$content = isset( $message['html'] ) ? (string) $message['html'] : $this->template->paragraph( $body );
		$html    = $this->template->wrap( $subject, $content, $this->cta( $message ) );

		return $this->deliver( $user->user_email, $subject, $html, wp_strip_all_tags( $body ) );
	}

	/**
	 * Deliver an HTML email with a plain-text alternative, scoped so WooCommerce
	 * emails are unaffected.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $html    HTML body.
	 * @param string $text    Plain-text alternative.
	 * @return bool
	 */
	public function deliver( string $to, string $subject, string $html, string $text ): bool {
		$alt = static function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$ok = (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return $ok;
	}

	/**
	 * The call-to-action for a message (deep-link to the rewards hub).
	 *
	 * @param array $message Rendered message.
	 * @return array<string,string>
	 */
	private function cta( array $message ): array {
		if ( isset( $message['cta_url'] ) ) {
			return array(
				'cta_url'   => (string) $message['cta_url'],
				'cta_label' => (string) ( $message['cta_label'] ?? __( 'View details', 'yazan-rewards' ) ),
			);
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return array(
				'cta_url'   => wc_get_account_endpoint_url( 'rewards' ),
				'cta_label' => __( 'View your rewards', 'yazan-rewards' ),
			);
		}
		return array();
	}
}
