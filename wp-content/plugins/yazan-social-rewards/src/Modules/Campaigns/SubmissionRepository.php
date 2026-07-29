<?php
/**
 * Campaign submission repository.
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
 * Reads/writes the `campaign_submissions` table (task content awaiting review).
 */
final class SubmissionRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'campaign_submissions';
	}

	/**
	 * Create a submission.
	 *
	 * @param array $data campaign_id, task_id, user_id, type, content_url,
	 *                    metric_value, points_award.
	 * @return int
	 */
	public function create( array $data ): int {
		return $this->insert(
			array(
				'campaign_id'  => (int) $data['campaign_id'],
				'task_id'      => (int) $data['task_id'],
				'user_id'      => (int) $data['user_id'],
				'type'         => sanitize_key( (string) ( $data['type'] ?? '' ) ),
				'content_url'  => esc_url_raw( (string) ( $data['content_url'] ?? '' ) ),
				'metric_value' => max( 0, (int) ( $data['metric_value'] ?? 0 ) ),
				'status'       => 'pending',
				'points_award' => max( 0, (int) ( $data['points_award'] ?? 0 ) ),
				'reviewed_by'  => 0,
				'note'         => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
				'created_at'   => current_time( 'mysql' ),
				'reviewed_at'  => '0000-00-00 00:00:00',
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Load a submission.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		return $this->find( $id );
	}

	/**
	 * Transition status + reviewer.
	 *
	 * @param int    $id          Id.
	 * @param string $status      approved|rejected.
	 * @param int    $reviewed_by Reviewer.
	 * @return void
	 */
	public function set_status( int $id, string $status, int $reviewed_by ): void {
		$this->update(
			array( 'status' => sanitize_key( $status ), 'reviewed_by' => $reviewed_by, 'reviewed_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether a user already has an approved submission for a task.
	 *
	 * @param int $user_id User id.
	 * @param int $task_id Task id.
	 * @return bool
	 */
	public function has_approved( int $user_id, int $task_id ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND task_id = %d AND status = 'approved'", $user_id, $task_id ) ) > 0;
	}

	/**
	 * A user's submissions for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $user_id     User id.
	 * @return object[]
	 */
	public function for_user_campaign( int $campaign_id, int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND user_id = %d ORDER BY id DESC", $campaign_id, $user_id ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The review queue (pending first), optionally filtered by campaign.
	 *
	 * @param int $campaign_id 0 for all.
	 * @param int $limit       Rows.
	 * @return object[]
	 */
	public function queue( int $campaign_id = 0, int $limit = 100 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $campaign_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $campaign_id, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit ) );
		}
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Status counts for a campaign (analytics).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array{total:int,approved:int,rejected:int,pending:int,points:int}
	 */
	public function stats( int $campaign_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// One grouped scan instead of five COUNT(*) queries (called per-campaign in the
		// analytics loop — collapsing avoids an N+1 storm on the dashboard).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS n, COALESCE(SUM(points_award),0) AS pts FROM {$table} WHERE campaign_id = %d GROUP BY status", $campaign_id )
		);

		$out = array( 'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'points' => 0 );
		foreach ( (array) $rows as $r ) {
			$n = (int) $r->n;
			$out['total'] += $n;
			$status = (string) $r->status;
			if ( array_key_exists( $status, $out ) && 'total' !== $status && 'points' !== $status ) {
				$out[ $status ] = $n;
			}
			if ( 'approved' === $status ) {
				$out['points'] = (int) $r->pts;
			}
		}
		return $out;
	}

	/**
	 * Distinct participant count with ≥1 submission (engagement).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function engaged_users( int $campaign_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE campaign_id = %d", $campaign_id ) );
	}
}
