<?php
/**
 * Payment-event persistence. All SQL lives here.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Events;

use Yazan\PaymentBridge\Database\Database;
use Yazan\PaymentBridge\Exceptions\PaymentBridgeException;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for wp_yazan_payment_events.
 *
 * Two mechanisms in here carry the plugin's correctness guarantees:
 *
 *  - {@see insert_unique()} relies on the UNIQUE (order_id, event_type)
 *    constraint failing, not on a SELECT-then-INSERT sequence. Concurrent
 *    webhooks, cron passes and admin status changes can all race past any PHP
 *    check; they cannot race past the database (H1).
 *  - {@see claim()} is a conditional UPDATE whose affected-row count acts as the
 *    lock, so downstream integrations run at most once even under a retry storm.
 *
 * MySQL error 1062 is ER_DUP_ENTRY.
 */
final class EventRepository {

	/** MySQL duplicate-entry error number. */
	private const ER_DUP_ENTRY = 1062;

	/**
	 * @param Database $db     Database helper.
	 * @param Logger   $logger Logger.
	 */
	public function __construct( private Database $db, private Logger $logger ) {}

	/**
	 * Insert an event, letting the UNIQUE constraint reject duplicates (H1).
	 *
	 * @param Event $event Event to store.
	 * @return int Row id when created, 0 when a duplicate was suppressed.
	 * @throws PaymentBridgeException On a genuine database error.
	 */
	public function insert_unique( Event $event ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();
		$row   = $event->to_row( $this->db->now() );

		$formats = array(
			'event_uuid'         => '%s',
			'order_id'           => '%d',
			'customer_id'        => '%d',
			'event_type'         => '%s',
			'source'             => '%s',
			'gateway'            => '%s',
			'transaction_id'     => '%s',
			'amount'             => '%s',
			'currency'           => '%s',
			'payment_status'     => '%s',
			'integration_status' => '%s',
			'processed'          => '%d',
			'error_message'      => '%s',
			'created_at'         => '%s',
			'updated_at'         => '%s',
		);

		// A duplicate insert is an expected outcome here, not a fault: suppress
		// wpdb's error output around the call and inspect the result ourselves.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result   = $wpdb->insert( $table, $row, array_values( $formats ) );
		$last_err = (string) $wpdb->last_error;
		$errno    = ( $wpdb->dbh instanceof \mysqli ) ? (int) $wpdb->dbh->errno : 0;
		$wpdb->suppress_errors( $suppress );

		if ( false !== $result ) {
			$event->id = (int) $wpdb->insert_id;
			return $event->id;
		}

		if ( self::ER_DUP_ENTRY === $errno || false !== stripos( $last_err, 'duplicate entry' ) ) {
			$this->logger->debug(
				'Duplicate payment event suppressed by the unique constraint.',
				array(
					'order_id'   => $event->order_id,
					'event_type' => $event->event_type,
				)
			);
			return 0;
		}

		throw new PaymentBridgeException( 'Failed to insert payment event: ' . $last_err );
	}

	/**
	 * Claim an event for integration work.
	 *
	 * The conditional UPDATE is the lock: only the caller whose UPDATE affected a
	 * row may run downstream integrations. Because the status always changes
	 * (pending|failed → processing), MySQL's rows-changed-vs-rows-matched
	 * behaviour cannot produce a false negative.
	 *
	 * @param int $event_id Event row id.
	 * @return bool True when this caller owns the claim.
	 */
	public function claim( int $event_id ): bool {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
					SET integration_status = %s, updated_at = %s
					WHERE id = %d
					AND integration_status IN ( %s, %s )",
				IntegrationStatus::PROCESSING,
				$this->db->now(),
				$event_id,
				IntegrationStatus::PENDING,
				IntegrationStatus::FAILED
			)
		);

		return is_numeric( $rows ) && (int) $rows > 0;
	}

	/**
	 * Write the terminal state of an integration run.
	 *
	 * @param string|null $error Raw error message; scrubbed before storage (H6).
	 * @param int         $event_id Event row id.
	 * @param string      $status   One of IntegrationStatus::*.
	 * @return void
	 */
	public function finish( int $event_id, string $status, ?string $error = null ): void {
		if ( ! IntegrationStatus::is_valid( $status ) ) {
			$status = IntegrationStatus::FAILED;
		}

		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		$processed = in_array( $status, array( IntegrationStatus::PENDING, IntegrationStatus::PROCESSING ), true ) ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'integration_status' => $status,
				'processed'          => $processed,
				'error_message'      => null === $error ? null : Sanitizer::scrub_error( $error ),
				'updated_at'         => $this->db->now(),
			),
			array( 'id' => $event_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update the recorded amount and status of an existing event.
	 *
	 * Used when a repeat partial refund is rejected by the unique constraint: the
	 * stored row is refreshed to the cumulative refunded total rather than the
	 * additional refund being dropped (H3).
	 *
	 * @param int    $event_id Event row id.
	 * @param string $amount   New decimal amount.
	 * @param string $status   New integration status.
	 * @return void
	 */
	public function update_amount( int $event_id, string $amount, string $status ): void {
		if ( ! IntegrationStatus::is_valid( $status ) ) {
			return;
		}

		$wpdb = $this->db->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->db->events_table(),
			array(
				'amount'             => $amount,
				'integration_status' => $status,
				'updated_at'         => $this->db->now(),
			),
			array( 'id' => $event_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fetch one event row.
	 *
	 * @param int $event_id Row id.
	 * @return object|null
	 */
	public function find( int $event_id ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) );

		return $row ?: null;
	}

	/**
	 * Fetch the canonical event of a given type for an order.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $event_type Event type.
	 * @return object|null
	 */
	public function find_by_order_and_type( int $order_id, string $event_type ): ?object {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_id = %d AND event_type = %s",
				$order_id,
				$event_type
			)
		);

		return $row ?: null;
	}

	/**
	 * Paginated, searchable, filterable event list for the admin.
	 *
	 * Filter values are whitelist-validated against the known vocabularies and
	 * never interpolated; the ORDER BY clause is a fixed literal; LIMIT/OFFSET
	 * are bound placeholders (H5).
	 *
	 * @param array<string,mixed> $args search, event_type, integration_status, per_page, page.
	 * @return object[]
	 */
	public function query( array $args = array() ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		[ $where_sql, $params ] = $this->build_where( $args );

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$params[] = $per_page;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total rows matching the same filters as {@see query()}.
	 *
	 * @param array<string,mixed> $args Same shape as query().
	 * @return int
	 */
	public function count_matching( array $args = array() ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		[ $where_sql, $params ] = $this->build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Dashboard counters.
	 *
	 * @return array<string,int> total plus one entry per integration status.
	 */
	public function dashboard_counts(): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->events_table();

		$counts = array( 'total' => 0, 'successful_payments' => 0 );
		foreach ( IntegrationStatus::all() as $status ) {
			$counts[ $status ] = 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT integration_status, COUNT(*) AS total FROM {$table} GROUP BY integration_status" );

		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row->integration_status ?? '' );
			$total  = (int) ( $row->total ?? 0 );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $total;
			}
			$counts['total'] += $total;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['successful_payments'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", EventTypes::PAYMENT_COMPLETED )
		);

		return $counts;
	}

	/**
	 * Delete an event row (used by the test harness teardown).
	 *
	 * @param int $event_id Row id.
	 * @return void
	 */
	public function delete( int $event_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DeleteQuery
		$this->db->wpdb()->delete( $this->db->events_table(), array( 'id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Build the shared WHERE clause and its bound parameters.
	 *
	 * @param array<string,mixed> $args Filter args.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $args ): array {
		$wpdb   = $this->db->wpdb();
		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses = array( 'transaction_id LIKE %s', 'event_uuid LIKE %s' );
			$values  = array( $like, $like );

			if ( ctype_digit( $search ) ) {
				array_unshift( $clauses, 'order_id = %d' );
				array_unshift( $values, absint( $search ) );
			}

			$where[] = '( ' . implode( ' OR ', $clauses ) . ' )';
			$params  = array_merge( $params, $values );
		}

		$event_type = (string) ( $args['event_type'] ?? '' );
		if ( EventTypes::is_valid( $event_type ) ) {
			$where[]  = 'event_type = %s';
			$params[] = $event_type;
		}

		$integration_status = (string) ( $args['integration_status'] ?? '' );
		if ( IntegrationStatus::is_valid( $integration_status ) ) {
			$where[]  = 'integration_status = %s';
			$params[] = $integration_status;
		}

		return array( implode( ' AND ', $where ), $params );
	}
}
