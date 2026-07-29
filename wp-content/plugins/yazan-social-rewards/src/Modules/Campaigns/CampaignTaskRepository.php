<?php
/**
 * Campaign task repository.
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
 * Reads/writes the `campaign_tasks` table — the individual activities that make
 * up a campaign (e.g. "share on Instagram", "leave a review", "refer a friend").
 * A task references its campaign and describes how it is completed + rewarded.
 */
final class CampaignTaskRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'campaign_tasks';
	}

	/**
	 * Active tasks for a campaign, in display order.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return object[]
	 */
	public function for_campaign( int $campaign_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND active = 1 ORDER BY sort ASC, id ASC", $campaign_id )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a task.
	 *
	 * @param array $data campaign_id, name, task_type, criteria(array),
	 *                    points_award, sort, active.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'campaign_id'  => (int) ( $data['campaign_id'] ?? 0 ),
				'name'         => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'task_type'    => sanitize_key( (string) ( $data['task_type'] ?? 'custom' ) ),
				'criteria'     => wp_json_encode( (array) ( $data['criteria'] ?? array() ) ),
				'points_award' => max( 0, (int) ( $data['points_award'] ?? 0 ) ),
				'sort'         => (int) ( $data['sort'] ?? 0 ),
				'active'       => ! empty( $data['active'] ) ? 1 : 0,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Delete all tasks for a campaign (campaign teardown).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	public function delete_for_campaign( int $campaign_id ): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE campaign_id = %d", $campaign_id ) );
	}
}
