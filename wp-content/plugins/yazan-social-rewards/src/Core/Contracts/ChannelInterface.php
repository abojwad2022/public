<?php
/**
 * Notification channel contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A delivery channel for a notification (email, on-site, SMS, push). New channels
 * implement this and are added to the dispatcher — no change to the events that
 * trigger notifications.
 */
interface ChannelInterface {

	/**
	 * Channel id (e.g. "email", "onsite").
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Whether this channel is currently able to deliver.
	 *
	 * @return bool
	 */
	public function enabled(): bool;

	/**
	 * Deliver a rendered notification to a user.
	 *
	 * @param int   $user_id User id.
	 * @param array $message Rendered message: { subject, body, template, payload }.
	 * @return bool Delivered.
	 */
	public function send( int $user_id, array $message ): bool;
}
