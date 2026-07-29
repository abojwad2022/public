<?php
/**
 * Activity log repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Activity;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `activity_logs` table — a human-readable, per-user feed of
 * everything the customer has done in the rewards programme. Append-only; indexed
 * for fast per-user reads and time-ordering.
 */
final class ActivityRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'activity_logs';
	}

	/**
	 * Append an activity row.
	 *
	 * @param array $data user_id, activity_type, description, object_type,
	 *                    object_id, points, meta(array).
	 * @return int
	 */
	public function log( array $data ): int {
		return $this->insert(
			array(
				'user_id'       => (int) ( $data['user_id'] ?? 0 ),
				'activity_type' => sanitize_key( (string) ( $data['activity_type'] ?? '' ) ),
				'description'   => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				'object_type'   => sanitize_key( (string) ( $data['object_type'] ?? '' ) ),
				'object_id'     => (int) ( $data['object_id'] ?? 0 ),
				'points'        => (int) ( $data['points'] ?? 0 ),
				'meta'          => wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * A user's activity feed (newest first), paginated.
	 *
	 * @param int $user_id  User id.
	 * @param int $per_page Rows (1–100).
	 * @param int $page     Page.
	 * @return array{items:object[],total:int,total_pages:int,page:int,per_page:int}
	 */
	public function for_user( int $user_id, int $per_page = 20, int $page = 1 ): array {
		$wpdb     = $this->db->wpdb();
		$table    = $this->table();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, activity_type, description, object_type, object_id, points, created_at
				 FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
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

	/**
	 * Purge activity rows older than N days (housekeeping).
	 *
	 * @param int $days Age in days.
	 * @return int Rows removed.
	 */
	public function purge_older_than( int $days ): int {
		$wpdb   = $this->db->wpdb();
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
