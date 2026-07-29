<?php
/**
 * Fraud-case repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `fraud_flags` table — now a reviewable fraud-CASE store. A case has
 * one of four states (suspicious | manual_review | approved | rejected) and records the
 * reward it is attached to (source/source_id/kind/amount/user) so it can be released or
 * clawed back on resolution.
 */
final class FraudRepository extends AbstractRepository {

	public const STATUS_SUSPICIOUS = 'suspicious';
	public const STATUS_REVIEW     = 'manual_review';
	public const STATUS_APPROVED   = 'approved';
	public const STATUS_REJECTED   = 'rejected';

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'fraud_flags';
	}

	/**
	 * Open a fraud case.
	 *
	 * @param array $data user_id, type, severity, status, score, context(array),
	 *                    signals(array), ip_hash, source, source_id, reward_kind,
	 *                    reward_amount, reward_user_id, note.
	 * @return int New case id.
	 */
	public function open( array $data ): int {
		$status = sanitize_key( (string) ( $data['status'] ?? self::STATUS_SUSPICIOUS ) );
		return $this->insert(
			array(
				'user_id'        => (int) ( $data['user_id'] ?? 0 ),
				'type'           => sanitize_key( (string) ( $data['type'] ?? '' ) ),
				'severity'       => sanitize_key( (string) ( $data['severity'] ?? 'low' ) ),
				'status'         => $status,
				'score'          => max( 0, (int) ( $data['score'] ?? 0 ) ),
				'context'        => wp_json_encode( (array) ( $data['context'] ?? array() ) ),
				'signals'        => wp_json_encode( (array) ( $data['signals'] ?? array() ) ),
				'ip_hash'        => sanitize_text_field( (string) ( $data['ip_hash'] ?? '' ) ),
				'source'         => sanitize_key( (string) ( $data['source'] ?? '' ) ),
				'source_id'      => (int) ( $data['source_id'] ?? 0 ),
				'reward_kind'    => sanitize_key( (string) ( $data['reward_kind'] ?? 'none' ) ),
				'reward_amount'  => (float) ( $data['reward_amount'] ?? 0 ),
				'reward_user_id' => (int) ( $data['reward_user_id'] ?? 0 ),
				'resolved'       => in_array( $status, array( self::STATUS_APPROVED, self::STATUS_REJECTED ), true ) ? 1 : 0,
				'note'           => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Back-compat: record a passive flag (a suspicious case with no held reward).
	 *
	 * @param array $data user_id, type, severity, context(array), ip_hash.
	 * @return int
	 */
	public function flag( array $data ): int {
		$data['status'] = self::STATUS_SUSPICIOUS;
		return $this->open( $data );
	}

	/**
	 * An existing OPEN case for a source object (idempotency / dedup).
	 *
	 * @param string $source    Source key.
	 * @param int    $source_id Source id.
	 * @return object|null
	 */
	public function find_by_source( string $source, int $source_id ): ?object {
		if ( '' === $source || $source_id <= 0 ) {
			return null;
		}
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source = %s AND source_id = %d ORDER BY id DESC LIMIT 1",
				sanitize_key( $source ),
				$source_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Whether the user already has an OPEN case of this type (dedup for source-less
	 * flags like velocity/daily-cap, so a burst can't mint one case per credit).
	 *
	 * @param int    $user_id User id.
	 * @param string $type    Flag type.
	 * @return bool
	 */
	public function has_open( int $user_id, string $type ): bool {
		if ( $user_id <= 0 || '' === $type ) {
			return false;
		}
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND type = %s AND resolved = 0 LIMIT 1", $user_id, sanitize_key( $type ) ) );
		return $id > 0;
	}

	/**
	 * Resolve a case to a terminal (or review) state.
	 *
	 * @param int    $id       Case id.
	 * @param string $status   New status.
	 * @param int    $reviewer Reviewer user id.
	 * @param string $note     Optional note.
	 * @return void
	 */
	public function resolve( int $id, string $status, int $reviewer, string $note = '' ): void {
		$status   = sanitize_key( $status );
		$terminal = in_array( $status, array( self::STATUS_APPROVED, self::STATUS_REJECTED ), true );
		$this->update(
			array(
				'status'      => $status,
				'resolved'    => $terminal ? 1 : 0,
				'resolved_by' => $reviewer,
				'resolved_at' => current_time( 'mysql' ),
				'note'        => sanitize_text_field( $note ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Atomically resolve an OPEN case to a terminal state — only one caller wins, so
	 * two concurrent approve/reject clicks can't both release or claw back.
	 *
	 * @param int    $id       Case id.
	 * @param string $status   Terminal status (approved|rejected).
	 * @param int    $reviewer Reviewer id.
	 * @param string $note     Note.
	 * @return bool True if THIS call transitioned the case.
	 */
	public function claim_resolve( int $id, string $status, int $reviewer, string $note = '' ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, resolved = 1, resolved_by = %d, resolved_at = %s, note = %s WHERE id = %d AND resolved = 0",
				sanitize_key( $status ),
				$reviewer,
				current_time( 'mysql' ),
				sanitize_text_field( $note ),
				$id
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Filtered case list, newest first.
	 *
	 * @param array $filters status, type, severity, user_id.
	 * @param int   $limit   Max rows.
	 * @return object[]
	 */
	public function list_cases( array $filters = array(), int $limit = 100 ): array {
		$wpdb   = $this->db->wpdb();
		$table  = $this->table();
		$limit  = max( 1, min( 500, $limit ) );
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'status', 'type', 'severity' ) as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$where[]  = "{$col} = %s";
				$params[] = sanitize_key( (string) $filters[ $col ] );
			}
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		$params[] = $limit;
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A user's cases (newest first).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows.
	 * @return object[]
	 */
	public function for_user( int $user_id, int $limit = 50 ): array {
		return $this->list_cases( array( 'user_id' => $user_id ), $limit );
	}

	/**
	 * Recent cases (newest first).
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public function recent( int $limit = 50 ): array {
		return $this->list_cases( array(), $limit );
	}

	/**
	 * Count of unresolved cases.
	 *
	 * @return int
	 */
	public function open_count(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 0" );
	}

	/**
	 * Case counts by status (for the admin dashboard).
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array( self::STATUS_SUSPICIOUS => 0, self::STATUS_REVIEW => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}
}
