<?php
/**
 * Campaign participant repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `campaign_participants` table (one row per user+campaign).
 */
final class ParticipantRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'campaign_participants';
	}

	/**
	 * The participant row for a user in a campaign, or null.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @return object|null
	 */
	public function get( int $campaign_id, int $user_id ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND user_id = %d", $campaign_id, $user_id ) );
		return $row ?: null;
	}

	/**
	 * Ensure a participant row exists (join). Returns its id.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @return int
	 */
	public function ensure( int $campaign_id, int $user_id ): int {
		$existing = $this->get( $campaign_id, $user_id );
		if ( $existing ) {
			return (int) $existing->id;
		}
		return $this->insert(
			array(
				'campaign_id'   => $campaign_id,
				'user_id'       => $user_id,
				'status'        => 'joined',
				'points_earned' => 0,
				'joined_at'     => current_time( 'mysql' ),
				'completed_at'  => '0000-00-00 00:00:00',
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Add points to a participant's running total.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @param int $points      Points to add.
	 * @return void
	 */
	public function add_points( int $campaign_id, int $user_id, int $points ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET points_earned = points_earned + %d WHERE campaign_id = %d AND user_id = %d", $points, $campaign_id, $user_id ) );
	}

	/**
	 * Mark a participant completed.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @return void
	 */
	public function mark_completed( int $campaign_id, int $user_id ): void {
		$this->update(
			array( 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ),
			array( 'campaign_id' => $campaign_id, 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Participant count for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function count( int $campaign_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d", $campaign_id ) );
	}

	/**
	 * Completed-participant count for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function completed_count( int $campaign_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = 'completed'", $campaign_id ) );
	}

	/**
	 * Campaign ids a user has joined.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public function campaigns_for_user( int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT campaign_id FROM {$table} WHERE user_id = %d", $user_id ) );
		return array_map( 'intval', (array) $ids );
	}
}
