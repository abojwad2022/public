<?php
/**
 * Notification outbox repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `notifications` table — an outbox/log that also backs the
 * on-site notification inbox and the email digest queue (rows with
 * channel=email, status=queued, a future scheduled_at).
 */
final class NotificationRepository extends AbstractRepository {

	private const EMPTY_DATE = '0000-00-00 00:00:00';

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'notifications';
	}

	/**
	 * Log (or queue) a notification.
	 *
	 * @param array $data user_id, channel, template, category, priority, payload(array), status, scheduled_at.
	 * @return int
	 */
	public function log( array $data ): int {
		$status = sanitize_key( (string) ( $data['status'] ?? 'sent' ) );
		$sent   = in_array( $status, array( 'sent', 'failed' ), true ) ? current_time( 'mysql' ) : self::EMPTY_DATE;

		return $this->insert(
			array(
				'user_id'      => (int) $data['user_id'],
				'channel'      => sanitize_key( (string) ( $data['channel'] ?? '' ) ),
				'template'     => sanitize_key( (string) ( $data['template'] ?? '' ) ),
				'category'     => sanitize_key( (string) ( $data['category'] ?? '' ) ),
				'priority'     => sanitize_key( (string) ( $data['priority'] ?? 'normal' ) ),
				'payload'      => wp_json_encode( (array) ( $data['payload'] ?? array() ) ),
				'status'       => $status,
				'read_at'      => self::EMPTY_DATE,
				'scheduled_at' => (string) ( $data['scheduled_at'] ?? self::EMPTY_DATE ),
				'sent_at'      => $sent,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * A user's on-site inbox (newest first).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows.
	 * @return object[]
	 */
	public function inbox( int $user_id, int $limit = 30 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND channel = 'onsite' ORDER BY id DESC LIMIT %d", $user_id, $limit )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count of unread on-site notifications.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function unread_count( int $user_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND channel = 'onsite' AND read_at = %s", $user_id, self::EMPTY_DATE )
		);
	}

	/**
	 * Mark all of a user's on-site notifications read.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function mark_all_read( int $user_id ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET read_at = %s WHERE user_id = %d AND channel = 'onsite' AND read_at = %s", current_time( 'mysql' ), $user_id, self::EMPTY_DATE )
		);
	}

	/**
	 * Mark ONE on-site notification read (ownership-checked).
	 *
	 * @param int $id      Row id.
	 * @param int $user_id Owner.
	 * @return void
	 */
	public function mark_read( int $id, int $user_id ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET read_at = %s WHERE id = %d AND user_id = %d AND channel = 'onsite' AND read_at = %s", current_time( 'mysql' ), $id, $user_id, self::EMPTY_DATE )
		);
	}

	/**
	 * Queued digest emails that are now due, oldest first, grouped-friendly (ordered
	 * by user).
	 *
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public function due_queued( int $limit = 500 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 2000, $limit ) );
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE channel = 'email' AND status = 'queued' AND scheduled_at <= %s ORDER BY user_id ASC, id ASC LIMIT %d", $now, $limit )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Transition a set of rows to a status (with sent_at when delivered).
	 *
	 * @param int[]  $ids    Row ids.
	 * @param string $status New status.
	 * @return void
	 */
	public function mark_status( array $ids, string $status ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$wpdb   = $this->db->wpdb();
		$table  = $this->table();
		$status = sanitize_key( $status );
		$sent   = in_array( $status, array( 'sent', 'failed' ), true ) ? current_time( 'mysql' ) : self::EMPTY_DATE;
		$place  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = %s, sent_at = %s WHERE id IN ({$place})", array_merge( array( $status, $sent ), $ids ) )
		);
	}

	/**
	 * Recent outbox rows for the admin viewer (optionally filtered by status).
	 *
	 * @param string $status Status filter ('' = any).
	 * @param int    $limit  Rows.
	 * @return object[]
	 */
	public function recent( string $status = '', int $limit = 50 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id,user_id,channel,template,category,priority,status,created_at FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", sanitize_key( $status ), $limit )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id,user_id,channel,template,category,priority,status,created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
			);
		}
		return is_array( $rows ) ? $rows : array();
	}
}
