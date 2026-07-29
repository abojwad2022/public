<?php
/**
 * Referral earnings ledger repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only ledger of referral payouts — one row per beneficiary per rewarded
 * order (level 1 = direct referrer, level 2 = grand-referrer, plus the referred
 * customer's own welcome reward). Each row records the order value that generated
 * it and moves through pending → approved | rejected (the anti-fraud hold path).
 */
final class ReferralEarningsRepository extends AbstractRepository {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'referral_earnings';
	}

	/**
	 * Record an earning row.
	 *
	 * @param array $data referral_id, beneficiary_user_id, referred_user_id, level,
	 *                    role, order_id, order_total, reward_kind, reward_amount,
	 *                    status, note, ledger_id.
	 * @return int New row id.
	 */
	public function create( array $data ): int {
		$status   = sanitize_key( (string) ( $data['status'] ?? self::STATUS_APPROVED ) );
		$now      = current_time( 'mysql' );
		$approved = self::STATUS_APPROVED === $status ? $now : '0000-00-00 00:00:00';

		return $this->insert(
			array(
				'referral_id'         => (int) ( $data['referral_id'] ?? 0 ),
				'beneficiary_user_id' => (int) ( $data['beneficiary_user_id'] ?? 0 ),
				'referred_user_id'    => (int) ( $data['referred_user_id'] ?? 0 ),
				'level'               => (int) ( $data['level'] ?? 1 ),
				'role'                => sanitize_key( (string) ( $data['role'] ?? 'referrer' ) ),
				'order_id'            => (int) ( $data['order_id'] ?? 0 ),
				'order_total'         => (float) ( $data['order_total'] ?? 0 ),
				'reward_kind'         => sanitize_key( (string) ( $data['reward_kind'] ?? 'points' ) ),
				'reward_amount'       => (float) ( $data['reward_amount'] ?? 0 ),
				'status'              => $status,
				'note'                => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
				'ledger_id'           => (int) ( $data['ledger_id'] ?? 0 ),
				'created_at'          => $now,
				'approved_at'         => $approved,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%f', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Whether an earning already exists for this (referral, order, beneficiary) —
	 * the idempotency guard so a re-fired order never double-pays.
	 *
	 * @param int $referral_id Referral id.
	 * @param int $order_id    Order id.
	 * @param int $beneficiary Beneficiary user id.
	 * @return bool
	 */
	public function exists_for( int $referral_id, int $order_id, int $beneficiary ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE referral_id = %d AND order_id = %d AND beneficiary_user_id = %d LIMIT 1",
				$referral_id,
				$order_id,
				$beneficiary
			)
		);
		return $id > 0;
	}

	/**
	 * Pending earnings awaiting review, newest first.
	 *
	 * @param int $limit Max rows (1..200).
	 * @return object[]
	 */
	public function pending( int $limit = 100 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", self::STATUS_PENDING, $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A beneficiary's earnings (their payout history), newest first.
	 *
	 * @param int $user_id Beneficiary.
	 * @param int $limit   Max rows.
	 * @return object[]
	 */
	public function for_beneficiary( int $user_id, int $limit = 20 ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE beneficiary_user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Approved-earnings totals for a beneficiary.
	 *
	 * @param int $user_id Beneficiary.
	 * @return array{points:int,credit:string,pending:int}
	 */
	public function totals_for( int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$points = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(reward_amount),0) FROM {$table} WHERE beneficiary_user_id = %d AND reward_kind = 'points' AND status = %s", $user_id, self::STATUS_APPROVED ) );
		$credit = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(reward_amount),0) FROM {$table} WHERE beneficiary_user_id = %d AND reward_kind = 'credit' AND status = %s", $user_id, self::STATUS_APPROVED ) );
		$pend   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE beneficiary_user_id = %d AND status = %s", $user_id, self::STATUS_PENDING ) );
		// phpcs:enable
		return array(
			'points'  => $points,
			'credit'  => $credit,
			'pending' => $pend,
		);
	}

	/**
	 * Mark an earning approved and link the ledger/wallet row that paid it.
	 *
	 * @param int $id        Earning id.
	 * @param int $ledger_id Points/wallet ledger row id.
	 * @return void
	 */
	public function mark_approved( int $id, int $ledger_id ): void {
		$this->update(
			array( 'status' => self::STATUS_APPROVED, 'ledger_id' => $ledger_id, 'approved_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Atomically claim a pending earning for payment: flip pending→approved in one
	 * conditional UPDATE and report whether THIS caller won. Prevents two concurrent
	 * admin approvals (double-clicked tab / retried POST) from both crediting.
	 *
	 * @param int $id Earning id.
	 * @return bool True if this call transitioned the row (so it should now be paid).
	 */
	public function claim_pending( int $id ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, approved_at = %s WHERE id = %d AND status = %s",
				self::STATUS_APPROVED,
				current_time( 'mysql' ),
				$id,
				self::STATUS_PENDING
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Link the ledger/wallet row that paid an already-approved earning.
	 *
	 * @param int $id        Earning id.
	 * @param int $ledger_id Points/wallet ledger row id.
	 * @return void
	 */
	public function link_ledger( int $id, int $ledger_id ): void {
		$this->update(
			array( 'ledger_id' => $ledger_id ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark an earning rejected (fraud confirmed / declined).
	 *
	 * @param int $id Earning id.
	 * @return void
	 */
	public function mark_rejected( int $id ): void {
		$this->update(
			array( 'status' => self::STATUS_REJECTED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Open (pending) earnings count — for the admin badge.
	 *
	 * @return int
	 */
	public function pending_count(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING ) );
	}
}
