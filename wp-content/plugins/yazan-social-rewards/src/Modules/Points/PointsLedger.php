<?php
/**
 * Points ledger service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Points;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The transactional heart of the points currency. Every credit/debit writes one
 * append-only ledger row (with the running balance snapshot), refreshes the
 * cached balance, and announces a domain event so other engines can react.
 */
final class PointsLedger implements PointsLedgerInterface {

	/**
	 * @param PointsRepository $repo  Ledger repository.
	 * @param BalanceCache     $cache Cached balance.
	 * @param EventBus         $bus   Event bus.
	 */
	public function __construct(
		private PointsRepository $repo,
		private BalanceCache $cache,
		private EventBus $bus
	) {}

	/**
	 * @inheritDoc
	 */
	public function balance( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		return $this->cache->get( $user_id );
	}

	/**
	 * @inheritDoc
	 */
	public function credit( int $user_id, int $points, string $source, int $source_id = 0, array $meta = array() ): int {
		$points = (int) abs( $points );
		if ( $user_id <= 0 || 0 === $points ) {
			return 0;
		}

		$status   = isset( $meta['status'] ) ? sanitize_key( (string) $meta['status'] ) : 'approved';
		$approved = 'approved' === $status;

		// Balance snapshot only advances for approved entries; a `pending` entry
		// records the row (with equal before/after) but is not yet spendable.
		$balance_before = $this->repo->balance( $user_id );
		$new_balance    = $balance_before + ( $approved ? $points : 0 );

		$id = $this->repo->insert_entry(
			array(
				'user_id'       => $user_id,
				'points'        => $points,
				'balance_before' => $balance_before,
				'balance_after'  => $new_balance,
				'type'          => isset( $meta['type'] ) ? sanitize_key( (string) $meta['type'] ) : 'earn',
				'reason'        => sanitize_text_field( (string) ( $meta['reason'] ?? ( $meta['note'] ?? '' ) ) ),
				'source'        => sanitize_key( $source ),
				'source_id'     => $source_id,
				'campaign_id'   => (int) ( $meta['campaign_id'] ?? 0 ),
				'status'        => $status,
				'note'          => sanitize_text_field( (string) ( $meta['note'] ?? '' ) ),
				'expires_at'    => $this->normalise_expiry( $meta['expires_at'] ?? null ),
			)
		);

		if ( $id && $approved ) {
			$this->cache->set( $user_id, $new_balance );
			$this->bus->dispatch(
				new GenericEvent(
					Events::POINTS_CREDITED,
					array(
						'user_id'       => $user_id,
						'points'        => $points,
						'balance_after' => $new_balance,
						'source'        => $source,
						'source_id'     => $source_id,
						'entry_id'      => $id,
					)
				)
			);
		}

		return $id;
	}

	/**
	 * @inheritDoc
	 */
	public function debit( int $user_id, int $points, string $source, int $source_id = 0, array $meta = array() ): int {
		$points = (int) abs( $points );
		if ( $user_id <= 0 || 0 === $points ) {
			return 0;
		}

		// Never remove more than the customer holds, so the ledger's running sum
		// stays equal to the balance (clawbacks of already-spent points no-op).
		$current = $this->repo->balance( $user_id );
		$actual  = min( $points, $current );
		if ( $actual <= 0 ) {
			return 0;
		}
		$new_balance = $current - $actual;

		$id = $this->repo->insert_entry(
			array(
				'user_id'       => $user_id,
				'points'        => -$actual,
				'balance_before' => $current,
				'balance_after'  => $new_balance,
				'type'          => isset( $meta['type'] ) ? sanitize_key( (string) $meta['type'] ) : 'redeem',
				'reason'        => sanitize_text_field( (string) ( $meta['reason'] ?? ( $meta['note'] ?? '' ) ) ),
				'source'        => sanitize_key( $source ),
				'source_id'     => $source_id,
				'status'        => 'approved',
				'note'          => sanitize_text_field( (string) ( $meta['note'] ?? '' ) ),
			)
		);

		if ( $id ) {
			$this->cache->set( $user_id, $new_balance );
			$this->bus->dispatch(
				new GenericEvent(
					Events::POINTS_DEBITED,
					array(
						'user_id'       => $user_id,
						'points'        => $actual,
						'balance_after' => $new_balance,
						'source'        => $source,
						'source_id'     => $source_id,
						'entry_id'      => $id,
					)
				)
			);
		}

		return $id;
	}

	/**
	 * @inheritDoc
	 */
	public function net_for_source( int $user_id, string $source, int $source_id ): int {
		return $this->repo->net_for_source( $user_id, $source, $source_id );
	}

	/**
	 * Approve a pending entry: it now counts toward the balance. Re-snapshots the
	 * entry's balance_before/after at approval time and advances the cache.
	 *
	 * @param int $entry_id Ledger entry id.
	 * @return bool True when a transition occurred.
	 */
	public function approve( int $entry_id ): bool {
		$row = $this->repo->get_entry( $entry_id );
		if ( ! $row || 'pending' !== $row->status ) {
			return false;
		}
		$user_id = (int) $row->user_id;
		$points  = (int) $row->points;
		$before  = $this->repo->balance( $user_id );
		$after   = $before + $points;

		$this->repo->update_status( $entry_id, 'approved', $before, $after );
		$this->cache->set( $user_id, $after );

		$this->bus->dispatch( new GenericEvent( Events::POINTS_APPROVED, array( 'user_id' => $user_id, 'points' => $points, 'entry_id' => $entry_id, 'balance_after' => $after ) ) );
		// It now counts as a credit — let earn-driven engines (tiers, analytics) react.
		if ( $points > 0 ) {
			$this->bus->dispatch(
				new GenericEvent(
					Events::POINTS_CREDITED,
					array( 'user_id' => $user_id, 'points' => $points, 'balance_after' => $after, 'source' => (string) $row->source, 'source_id' => (int) $row->source_id, 'entry_id' => $entry_id )
				)
			);
		}
		return true;
	}

	/**
	 * Reject a pending entry: it never counts. Balance is unchanged.
	 *
	 * @param int $entry_id Ledger entry id.
	 * @return bool True when a transition occurred.
	 */
	public function reject( int $entry_id ): bool {
		$row = $this->repo->get_entry( $entry_id );
		if ( ! $row || 'pending' !== $row->status ) {
			return false;
		}
		$this->repo->update_status( $entry_id, 'rejected' );
		$this->bus->dispatch( new GenericEvent( Events::POINTS_REJECTED, array( 'user_id' => (int) $row->user_id, 'points' => (int) $row->points, 'entry_id' => $entry_id ) ) );
		return true;
	}

	/**
	 * Reconcile the cached balance from the ledger (repair/verify helper).
	 *
	 * @param int $user_id User id.
	 * @return int Recomputed balance.
	 */
	public function reconcile( int $user_id ): int {
		$balance = $this->repo->balance( $user_id );
		$this->cache->set( $user_id, $balance );
		return $balance;
	}

	/**
	 * Normalise an expiry timestamp/string to a MySQL datetime or the zero date.
	 *
	 * @param mixed $expiry Raw expiry.
	 * @return string
	 */
	private function normalise_expiry( $expiry ): string {
		if ( empty( $expiry ) ) {
			return '0000-00-00 00:00:00';
		}
		if ( is_numeric( $expiry ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $expiry );
		}
		return sanitize_text_field( (string) $expiry );
	}
}
