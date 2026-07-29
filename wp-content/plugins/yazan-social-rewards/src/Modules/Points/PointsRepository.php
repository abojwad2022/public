<?php
/**
 * Points ledger repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place `points_ledger` SQL is written. Append-only: rows are inserted,
 * never updated in place (except a status transition for held/expired entries).
 */
final class PointsRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'points_ledger';
	}

	/**
	 * Insert a ledger row.
	 *
	 * @param array $row Prepared, sanitised column values.
	 * @return int Insert id.
	 */
	public function insert_entry( array $row ): int {
		return $this->insert(
			array(
				'user_id'       => (int) $row['user_id'],
				'points'        => (int) $row['points'],
				'balance_before' => (int) ( $row['balance_before'] ?? 0 ),
				'balance_after'  => (int) $row['balance_after'],
				'type'          => (string) $row['type'],
				'reason'        => (string) ( $row['reason'] ?? '' ),
				'source'        => (string) $row['source'],
				'source_id'     => (int) ( $row['source_id'] ?? 0 ),
				'campaign_id'   => (int) ( $row['campaign_id'] ?? 0 ),
				'status'        => (string) ( $row['status'] ?? 'approved' ),
				'note'          => (string) ( $row['note'] ?? '' ),
				'expires_at'    => (string) ( $row['expires_at'] ?? '0000-00-00 00:00:00' ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Approved points balance for a user (the spendable balance from the ledger).
	 * Only APPROVED entries count; pending/rejected/expired do not.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function balance( int $user_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d AND status = 'approved'", $user_id )
		);
	}

	/**
	 * Lifetime points earned (sum of positive approved entries) — the basis for
	 * loyalty-tier calculation, which counts what a customer has ever earned, not
	 * their spendable balance.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function lifetime_earned( int $user_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d AND points > 0 AND status = 'approved'", $user_id )
		);
	}

	/**
	 * Net points recorded for a specific source object (idempotency + clawback).
	 *
	 * @param int    $user_id   User id.
	 * @param string $source    Source key.
	 * @param int    $source_id Object id.
	 * @return int
	 */
	public function net_for_source( int $user_id, string $source, int $source_id ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d AND source = %s AND source_id = %d",
				$user_id,
				$source,
				$source_id
			)
		);
	}

	/**
	 * Total positive points a user has earned since a datetime (fraud daily-cap).
	 *
	 * @param int    $user_id User id.
	 * @param string $since   MySQL datetime.
	 * @return int
	 */
	public function earned_since( int $user_id, string $since ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE user_id = %d AND points > 0 AND created_at >= %s", $user_id, $since )
		);
	}

	/**
	 * Count of credit entries for a user since a datetime (velocity signal).
	 *
	 * @param int    $user_id User id.
	 * @param string $since   MySQL datetime.
	 * @return int
	 */
	public function credit_count_since( int $user_id, string $since ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND points > 0 AND created_at >= %s", $user_id, $since )
		);
	}

	/**
	 * A user's ranking among all members by lifetime earned points (approved).
	 * Rank 1 = the highest lifetime. `total` is the number of members with any
	 * lifetime points. This scans grouped ledger data, so callers should cache it.
	 *
	 * @param int $user_id User id.
	 * @return array{rank:int,total:int,lifetime:int}
	 */
	public function rank_by_lifetime( int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();

		$mine = $this->lifetime_earned( $user_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT user_id FROM {$table} WHERE points > 0 AND status = 'approved'
				GROUP BY user_id HAVING SUM(points) > 0
			) t"
		);
		$higher = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT user_id, SUM(points) AS lt FROM {$table} WHERE points > 0 AND status = 'approved'
					GROUP BY user_id HAVING lt > %d
				) t",
				$mine
			)
		);
		// phpcs:enable

		return array(
			'rank'     => $mine > 0 ? $higher + 1 : 0,
			'total'    => $total,
			'lifetime' => $mine,
		);
	}

	/**
	 * Grand total of outstanding (cleared) points across all users — the points
	 * liability, for analytics.
	 *
	 * @return int
	 */
	public function total_outstanding(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(points),0) FROM {$table} WHERE status = 'approved'" );
	}

	/**
	 * Cleared earn entries expiring within a window, aggregated per user — for the
	 * pre-expiry "expiring soon" reminder. Returns the summed points and the EARLIEST
	 * upcoming expiry date per user (used to de-duplicate reminders per batch).
	 *
	 * @param string $from  Window start (Y-m-d H:i:s, usually now).
	 * @param string $to    Window end.
	 * @param int    $limit Max users per run.
	 * @return array<int,array{user_id:int,points:int,earliest:string}>
	 */
	public function expiring_between( string $from, string $to, int $limit = 1000 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 5000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COALESCE(SUM(points),0) AS points, MIN(expires_at) AS earliest FROM {$table}
				 WHERE status = 'approved' AND points > 0
				   AND expires_at <> '0000-00-00 00:00:00' AND expires_at BETWEEN %s AND %s
				 GROUP BY user_id
				 LIMIT %d",
				$from,
				$to,
				$limit
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'user_id' => (int) $r->user_id, 'points' => (int) $r->points, 'earliest' => (string) $r->earliest );
		}
		return $out;
	}

	/**
	 * Expire cleared earn entries whose expiry has passed. Flips their status to
	 * `expired` (which removes them from the cleared-balance sum) and returns the
	 * affected user ids so the caller can reconcile + notify. Idempotent.
	 *
	 * @param int $limit Max rows per run (batch safety).
	 * @return int[] Affected user ids.
	 */
	public function expire_due( int $limit = 500 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$now   = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$table}
				 WHERE status = 'approved' AND points > 0
				   AND expires_at <> '0000-00-00 00:00:00' AND expires_at < %s
				 LIMIT %d",
				$now,
				$limit
			)
		);

		if ( empty( $users ) ) {
			return array();
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired'
				 WHERE status = 'approved' AND points > 0
				   AND expires_at <> '0000-00-00 00:00:00' AND expires_at < %s
				 LIMIT %d",
				$now,
				$limit
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $users );
	}

	/**
	 * Paginated history for a user (newest first).
	 *
	 * @param int $user_id  User id.
	 * @param int $per_page Rows (1–100).
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
				"SELECT id, points, balance_before, balance_after, type, reason, source, source_id, campaign_id, status, note, created_at
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
	 * A single ledger entry by id.
	 *
	 * @param int $id Entry id.
	 * @return object|null
	 */
	public function get_entry( int $id ): ?object {
		return $this->find( $id );
	}

	/**
	 * Update an entry's status (+ optional balance snapshots on a transition).
	 *
	 * @param int      $id            Entry id.
	 * @param string   $status        New status.
	 * @param int|null $balance_before Optional new balance_before.
	 * @param int|null $balance_after  Optional new balance_after.
	 * @return void
	 */
	public function update_status( int $id, string $status, ?int $balance_before = null, ?int $balance_after = null ): void {
		$data    = array( 'status' => sanitize_key( $status ) );
		$formats = array( '%s' );
		if ( null !== $balance_before ) {
			$data['balance_before'] = $balance_before;
			$formats[]              = '%d';
		}
		if ( null !== $balance_after ) {
			$data['balance_after'] = $balance_after;
			$formats[]             = '%d';
		}
		$this->update( $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Pending entries awaiting review (admin queue), newest first.
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public function pending( int $limit = 100 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id DESC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One-time status-vocabulary migration: cleared→approved, void→rejected.
	 * Idempotent — safe to run on every activation / version bump.
	 *
	 * @return void
	 */
	public function migrate_statuses(): void {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET status = 'approved' WHERE status = 'cleared'" );
		$wpdb->query( "UPDATE {$table} SET status = 'rejected' WHERE status = 'void'" );
		// phpcs:enable
	}
}
