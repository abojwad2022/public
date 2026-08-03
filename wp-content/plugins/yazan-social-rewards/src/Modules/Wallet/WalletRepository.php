<?php
/**
 * Wallet ledger repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Wallet;

use Yazan\Rewards\Core\Database\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place `wallet_ledger` SQL is written. Append-only monetary ledger;
 * amounts are DECIMAL and handled as strings.
 */
final class WalletRepository extends AbstractRepository {

	/**
	 * @inheritDoc
	 */
	protected function table_name(): string {
		return 'wallet_ledger';
	}

	/**
	 * Insert a ledger row.
	 *
	 * @param array $row Sanitised values.
	 * @return int Insert id.
	 */
	public function insert_entry( array $row ): int {
		return $this->insert(
			array(
				'user_id'       => (int) $row['user_id'],
				'amount'        => (string) $row['amount'],
				'balance_after' => (string) $row['balance_after'],
				'type'          => (string) $row['type'],
				'source'        => (string) $row['source'],
				'source_id'     => (int) ( $row['source_id'] ?? 0 ),
				'order_id'      => (int) ( $row['order_id'] ?? 0 ),
				'status'        => (string) ( $row['status'] ?? 'cleared' ),
				'note'          => (string) ( $row['note'] ?? '' ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Cleared credit balance for a user (decimal string).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function balance( int $user_id ): string {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$scope = $this->scope();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum = $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$scope} AND user_id = %d AND status = 'cleared'", $user_id )
		);
		return (string) ( null === $sum ? '0' : $sum );
	}

	/**
	 * Grand total of outstanding (cleared) store credit across all users — the
	 * monetary liability, for analytics.
	 *
	 * @return string
	 */
	public function total_outstanding(): string {
		$wpdb  = $this->db->wpdb();
		$table = $this->table();
		$scope = $this->scope();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum = $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$scope} AND status = 'cleared'" );
		return (string) ( null === $sum ? '0' : $sum );
	}

	/**
	 * Paginated history (newest first).
	 *
	 * @param int $user_id  User id.
	 * @param int $per_page Rows.
	 * @param int $page     Page.
	 * @return array{items:object[],total:int,total_pages:int,page:int,per_page:int}
	 */
	public function history( int $user_id, int $per_page = 20, int $page = 1 ): array {
		$wpdb     = $this->db->wpdb();
		$table    = $this->table();
		$scope = $this->scope();
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$scope} AND user_id = %d", $user_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, balance_after, type, source, source_id, order_id, status, note, created_at
				 FROM {$table} WHERE {$scope} AND user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
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
