<?php
/**
 * Store-credit wallet service.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Wallet;

use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transactional store-credit (money) ledger. Ambassador commissions and points
 * redemptions credit here; checkout spends here. Every write snapshots the
 * running balance, caches it in user meta, and announces an event.
 */
final class WalletService implements WalletServiceInterface {

	/** User-meta key for the cached credit balance. */
	public const META_KEY = '_yzrw_credit_balance';

	/**
	 * @param WalletRepository $repo  Repository.
	 * @param EventBus         $bus   Event bus.
	 * @param Money            $money Money helper.
	 */
	public function __construct(
		private WalletRepository $repo,
		private EventBus $bus,
		private Money $money
	) {}

	/**
	 * @inheritDoc
	 */
	public function balance( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return $this->money->format( 0 );
		}
		$cached = get_user_meta( $user_id, self::META_KEY, true );
		return '' === $cached ? $this->money->format( 0 ) : $this->money->format( $cached );
	}

	/**
	 * @inheritDoc
	 */
	public function credit( int $user_id, $amount, string $source, int $source_id = 0, array $meta = array() ): int {
		$amount = (float) $this->money->format( $amount );
		if ( $user_id <= 0 || $amount <= 0 ) {
			return 0;
		}

		$new_balance = $this->money->format( (float) $this->repo->balance( $user_id ) + $amount );

		$id = $this->repo->insert_entry(
			array(
				'user_id'       => $user_id,
				'amount'        => $this->money->format( $amount ),
				'balance_after' => $new_balance,
				'type'          => isset( $meta['type'] ) ? sanitize_key( (string) $meta['type'] ) : 'adjust',
				'source'        => sanitize_key( $source ),
				'source_id'     => $source_id,
				'order_id'      => (int) ( $meta['order_id'] ?? 0 ),
				'note'          => sanitize_text_field( (string) ( $meta['note'] ?? '' ) ),
			)
		);

		if ( $id ) {
			update_user_meta( $user_id, self::META_KEY, $new_balance );
			$this->bus->dispatch(
				new GenericEvent(
					Events::CREDIT_CREDITED,
					array(
						'user_id'       => $user_id,
						'amount'        => $this->money->format( $amount ),
						'balance_after' => $new_balance,
						'source'        => $source,
						'source_id'     => $source_id,
					)
				)
			);
		}

		return $id;
	}

	/**
	 * @inheritDoc
	 */
	public function debit( int $user_id, $amount, string $source, int $source_id = 0, array $meta = array() ): int {
		$amount = (float) $this->money->format( $amount );
		if ( $user_id <= 0 || $amount <= 0 ) {
			return 0;
		}

		// Never remove more credit than the customer actually holds — this keeps
		// the ledger's running sum equal to the balance (no phantom negatives).
		$current = (float) $this->repo->balance( $user_id );
		$actual  = min( $amount, $current );
		if ( $actual <= 0 ) {
			return 0;
		}
		$new_balance = $this->money->format( $current - $actual );

		$id = $this->repo->insert_entry(
			array(
				'user_id'       => $user_id,
				'amount'        => '-' . $this->money->format( $actual ),
				'balance_after' => $new_balance,
				'type'          => isset( $meta['type'] ) ? sanitize_key( (string) $meta['type'] ) : 'spend',
				'source'        => sanitize_key( $source ),
				'source_id'     => $source_id,
				'order_id'      => (int) ( $meta['order_id'] ?? 0 ),
				'note'          => sanitize_text_field( (string) ( $meta['note'] ?? '' ) ),
			)
		);

		if ( $id ) {
			update_user_meta( $user_id, self::META_KEY, $new_balance );
			$this->bus->dispatch(
				new GenericEvent(
					Events::CREDIT_DEBITED,
					array(
						'user_id'       => $user_id,
						'amount'        => $this->money->format( $actual ),
						'balance_after' => $new_balance,
						'source'        => $source,
						'source_id'     => $source_id,
					)
				)
			);
		}

		return $id;
	}
}
