<?php
/**
 * Redemption records repository.
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
 * Reads/writes the `redemptions` table (an audit trail of every redemption).
 */
final class RedemptionRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'redemptions';
	}

	/**
	 * Record a redemption.
	 *
	 * @param array $data user_id, reward_id, points_spent, result_type,
	 *                    result_ref, status, note.
	 * @return int
	 */
	public function record( array $data ): int {
		return $this->insert(
			array(
				'user_id'      => (int) $data['user_id'],
				'reward_id'    => (int) $data['reward_id'],
				'points_spent' => (int) $data['points_spent'],
				'result_type'  => sanitize_key( (string) ( $data['result_type'] ?? '' ) ),
				'result_ref'   => sanitize_text_field( (string) ( $data['result_ref'] ?? '' ) ),
				'status'       => sanitize_key( (string) ( $data['status'] ?? 'complete' ) ),
				'note'         => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * How many times a user has redeemed a specific reward (enforces per_user_limit).
	 *
	 * @param int $user_id   User id.
	 * @param int $reward_id Reward id.
	 * @return int
	 */
	public function count_for_user_reward( int $user_id, int $reward_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND reward_id = %d AND status <> 'reversed'", $user_id, $reward_id )
		);
	}

	/**
	 * Total points ever spent on redemptions (excludes reversed) — the points side of
	 * "reward cost". Value it with the redemption ratio for a currency figure.
	 *
	 * @return int
	 */
	public function total_points_spent(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(points_spent),0) FROM {$table} WHERE status <> 'reversed'" );
	}

	/**
	 * Total number of non-reversed redemptions.
	 *
	 * @return int
	 */
	public function total_redemptions(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status <> 'reversed'" );
	}

	/**
	 * A user's redemption history (newest first).
	 *
	 * @param int $user_id  User id.
	 * @param int $per_page Rows.
	 * @param int $page     Page.
	 * @return array{items:object[],total:int,total_pages:int,page:int,per_page:int}
	 */
	public function history( int $user_id, int $per_page = 20, int $page = 1 ): array {
		$wpdb     = $this->db->wpdb();
		$table    = $this->table();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable

		return array(
			'items'       => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
