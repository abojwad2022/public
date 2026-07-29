<?php
/**
 * Service voucher repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rewards;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `service_vouchers` table — issued when a service reward (VIP
 * service, ring cleaning/polishing, exclusive product) is redeemed. Tracks the
 * fulfillment lifecycle requested → scheduled → fulfilled (or cancelled).
 */
final class ServiceVoucherRepository extends AbstractRepository {

	public const STATUS_REQUESTED = 'requested';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_FULFILLED = 'fulfilled';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'service_vouchers';
	}

	/**
	 * Create a voucher.
	 *
	 * @param array $data user_id, reward_id, redemption_id, type, code, notes.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'user_id'       => (int) $data['user_id'],
				'reward_id'     => (int) ( $data['reward_id'] ?? 0 ),
				'redemption_id' => (int) ( $data['redemption_id'] ?? 0 ),
				'type'          => sanitize_key( (string) ( $data['type'] ?? '' ) ),
				'code'          => sanitize_text_field( (string) ( $data['code'] ?? '' ) ),
				'status'        => self::STATUS_REQUESTED,
				'notes'         => sanitize_text_field( (string) ( $data['notes'] ?? '' ) ),
				'scheduled_at'  => '0000-00-00 00:00:00',
				'fulfilled_at'  => '0000-00-00 00:00:00',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Load a voucher.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		return $this->find( $id );
	}

	/**
	 * Transition a voucher's status (+ stamp scheduled/fulfilled time).
	 *
	 * @param int    $id     Voucher id.
	 * @param string $status New status.
	 * @return void
	 */
	public function set_status( int $id, string $status ): void {
		$status  = sanitize_key( $status );
		$data    = array( 'status' => $status );
		$formats = array( '%s' );
		if ( self::STATUS_SCHEDULED === $status ) {
			$data['scheduled_at'] = current_time( 'mysql' );
			$formats[]            = '%s';
		}
		if ( self::STATUS_FULFILLED === $status ) {
			$data['fulfilled_at'] = current_time( 'mysql' );
			$formats[]            = '%s';
		}
		$this->update( $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Open vouchers (not fulfilled/cancelled) for the admin queue.
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public function open( int $limit = 100 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('requested','scheduled') ORDER BY id ASC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A user's vouchers (newest first).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows.
	 * @return object[]
	 */
	public function for_user( int $user_id, int $limit = 50 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ) );
		return is_array( $rows ) ? $rows : array();
	}
}
