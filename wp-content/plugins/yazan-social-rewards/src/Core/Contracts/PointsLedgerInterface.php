<?php
/**
 * Points ledger contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The points currency API other engines depend on (by interface, never the
 * concrete class). Rewards reads the balance; observers credit/debit.
 */
interface PointsLedgerInterface {

	/**
	 * Current cleared points balance for a user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function balance( int $user_id ): int;

	/**
	 * Credit points. Writes one append-only ledger row and refreshes the cached
	 * balance. Returns the new ledger row id (0 on no-op/failure).
	 *
	 * @param int    $user_id   User id.
	 * @param int    $points    Positive amount.
	 * @param string $source    Source key (order|signup|review|referral|manual|…).
	 * @param int    $source_id Related object id (0 when none).
	 * @param array  $meta      Optional: note, campaign_id, expires_at, status.
	 * @return int
	 */
	public function credit( int $user_id, int $points, string $source, int $source_id = 0, array $meta = array() ): int;

	/**
	 * Debit points (redeem/expire/clawback). Amount is given positive; the row is
	 * stored negative. Returns the new ledger row id (0 on failure).
	 *
	 * @param int    $user_id   User id.
	 * @param int    $points    Positive amount to remove.
	 * @param string $source    Source key.
	 * @param int    $source_id Related object id.
	 * @param array  $meta      Optional: note, type.
	 * @return int
	 */
	public function debit( int $user_id, int $points, string $source, int $source_id = 0, array $meta = array() ): int;

	/**
	 * Net points already recorded for a specific source object (e.g. an order),
	 * used to make crediting idempotent and to size a clawback.
	 *
	 * @param int    $user_id   User id.
	 * @param string $source    Source key.
	 * @param int    $source_id Object id.
	 * @return int
	 */
	public function net_for_source( int $user_id, string $source, int $source_id ): int;
}
