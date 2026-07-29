<?php
/**
 * Email digest service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Channels\EmailChannel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batches non-urgent, digest-preferred emails into a single daily message per
 * customer. The dispatcher QUEUES such emails (a `notifications` row, channel
 * email, status queued, scheduled_at = the next digest hour); an hourly cron
 * ({@see NotificationScheduler}) flushes everything now due, grouping by user and
 * sending one branded summary — then marks the rows sent. On-site copies of the
 * same notifications are delivered immediately, so nothing is hidden until digest.
 */
final class DigestService {

	/**
	 * @param NotificationRepository $repo     Outbox.
	 * @param EmailChannel           $email    Email channel.
	 * @param EmailTemplate          $template Branded HTML wrapper.
	 * @param Settings               $settings Settings.
	 */
	public function __construct(
		private NotificationRepository $repo,
		private EmailChannel $email,
		private EmailTemplate $template,
		private Settings $settings
	) {}

	/**
	 * Whether digesting is enabled (else the dispatcher sends digest-preferred email
	 * immediately instead of deferring).
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'notification.digest.enabled', true );
	}

	/**
	 * The next digest send time as a local MySQL datetime (used as scheduled_at).
	 *
	 * @return string
	 */
	public function next_run_at(): string {
		$hour  = (int) $this->settings->get( 'notification.digest.hour', 9 );
		$hour  = max( 0, min( 23, $hour ) );
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );
		$slot  = $today . sprintf( ' %02d:00:00', $hour );

		if ( $slot > $now ) {
			return $slot;
		}
		return gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) . sprintf( ' %02d:00:00', $hour );
	}

	/**
	 * Queue an email into the next digest.
	 *
	 * @param int    $user_id  User id.
	 * @param array  $message  Rendered message { subject, body, template }.
	 * @param string $category Category.
	 * @return int Row id.
	 */
	public function queue( int $user_id, array $message, string $category ): int {
		return $this->repo->log(
			array(
				'user_id'      => $user_id,
				'channel'      => 'email',
				'template'     => (string) ( $message['template'] ?? 'custom' ),
				'category'     => $category,
				'priority'     => 'normal',
				'status'       => 'queued',
				'scheduled_at' => $this->next_run_at(),
				'payload'      => array(
					'subject' => (string) ( $message['subject'] ?? '' ),
					'body'    => (string) ( $message['body'] ?? '' ),
				),
			)
		);
	}

	/**
	 * Flush all due queued digest emails (cron). Groups by user, sends one summary
	 * each, marks the rows sent/failed.
	 *
	 * @return int Number of digest emails sent.
	 */
	public function run_due(): int {
		$rows = $this->repo->due_queued( 1000 );
		if ( empty( $rows ) ) {
			return 0;
		}

		// Group queued rows by recipient.
		$by_user = array();
		foreach ( $rows as $row ) {
			$by_user[ (int) $row->user_id ][] = $row;
		}

		$sent = 0;
		foreach ( $by_user as $user_id => $user_rows ) {
			$items = array();
			$ids   = array();
			$lines = array();
			foreach ( $user_rows as $row ) {
				$payload = json_decode( (string) $row->payload, true );
				$subject = is_array( $payload ) ? (string) ( $payload['subject'] ?? '' ) : '';
				$body    = is_array( $payload ) ? (string) ( $payload['body'] ?? '' ) : '';
				$items[] = array( 'subject' => $subject, 'body' => $body );
				$lines[] = $subject . ( '' !== $body ? ' — ' . $body : '' );
				$ids[]   = (int) $row->id;
			}

			$subject = sprintf(
				/* translators: %s: store name. */
				__( 'Your %s rewards summary', 'yazan-rewards' ),
				get_bloginfo( 'name' )
			);
			$intro = $this->template->paragraph(
				sprintf(
					/* translators: %d: number of updates. */
					_n( 'Here is your latest rewards update.', 'Here are your %d latest rewards updates.', count( $items ), 'yazan-rewards' ),
					count( $items )
				)
			);
			$html = $intro . $this->template->items_list( $items );

			$ok = $this->email->send(
				(int) $user_id,
				array(
					'subject'  => $subject,
					'html'     => $html,
					'body'     => implode( "\n", $lines ),
					'template' => 'digest',
					'category' => 'system',
					'priority' => 'normal',
				)
			);

			$this->repo->mark_status( $ids, $ok ? 'sent' : 'failed' );
			if ( $ok ) {
				$sent++;
			}
		}

		return $sent;
	}
}
