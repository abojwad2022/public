<?php
/**
 * Fraud-case review + enforcement.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\AntiFraud;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens held (manual_review) cases and enforces the admin's decision:
 *   - approve → clear the case + RELEASE a held reward (credit it, once);
 *   - reject  → confirm fraud + REVERSE an already-paid reward (a reversing ledger debit,
 *     idempotent via net_for_source) + flag the account high-risk.
 * A held reward that was never paid simply stays unpaid on reject (net_for_source = 0).
 */
final class FraudReviewService {

	/** User-meta keys for account risk. */
	public const META_RISK_FLAGGED = '_yzrw_risk_flagged';
	public const META_RISK_SCORE   = '_yzrw_risk';

	/**
	 * @param FraudRepository            $cases   Case store.
	 * @param PointsLedgerInterface       $points  Points ledger.
	 * @param Money                       $money   Money helper.
	 * @param EventBus                    $bus     Event bus.
	 * @param WalletServiceInterface|null $wallet  Wallet (optional).
	 */
	public function __construct(
		private FraudRepository $cases,
		private PointsLedgerInterface $points,
		private Money $money,
		private EventBus $bus,
		private ?WalletServiceInterface $wallet = null
	) {}

	/**
	 * Open a manual-review case that HOLDS a reward for admin decision.
	 *
	 * @param array $case user_id, type, severity, score, signals, ip_hash, source,
	 *                    source_id, reward_kind, reward_amount, reward_user_id, context.
	 * @return int Case id.
	 */
	public function hold( array $case ): int {
		$case['status'] = FraudRepository::STATUS_REVIEW;
		return $this->cases->open( $case );
	}

	/**
	 * Approve (clear) a case: release a held reward once.
	 *
	 * @param int $case_id  Case id.
	 * @param int $reviewer Reviewer id.
	 * @return bool
	 */
	public function approve( int $case_id, int $reviewer ): bool {
		$case = $this->cases->find( $case_id );
		if ( ! $case || (int) $case->resolved === 1 ) {
			return false;
		}
		if ( ! $this->cases->claim_resolve( $case_id, FraudRepository::STATUS_APPROVED, $reviewer, __( 'Cleared — legitimate', 'yazan-rewards' ) ) ) {
			return false;
		}
		$this->release( $case );
		$this->bus->dispatch(
			new GenericEvent(
				Events::FRAUD_CASE_RESOLVED,
				array( 'case_id' => $case_id, 'status' => 'approved', 'user_id' => (int) $case->user_id, 'source' => (string) $case->source, 'source_id' => (int) $case->source_id )
			)
		);
		return true;
	}

	/**
	 * Reject (confirm fraud): reverse a paid reward + flag the account.
	 *
	 * @param int $case_id  Case id.
	 * @param int $reviewer Reviewer id.
	 * @return bool
	 */
	public function reject( int $case_id, int $reviewer ): bool {
		$case = $this->cases->find( $case_id );
		if ( ! $case || (int) $case->resolved === 1 ) {
			return false;
		}
		if ( ! $this->cases->claim_resolve( $case_id, FraudRepository::STATUS_REJECTED, $reviewer, __( 'Confirmed fraud', 'yazan-rewards' ) ) ) {
			return false;
		}
		$this->reverse( $case );
		$this->flag_account( $case );
		$this->bus->dispatch(
			new GenericEvent(
				Events::FRAUD_CASE_RESOLVED,
				array( 'case_id' => $case_id, 'status' => 'rejected', 'user_id' => (int) $case->user_id, 'source' => (string) $case->source, 'source_id' => (int) $case->source_id )
			)
		);
		return true;
	}

	/**
	 * Send a case to manual review (from suspicious).
	 *
	 * @param int $case_id  Case id.
	 * @param int $reviewer Reviewer id.
	 * @return void
	 */
	public function send_to_review( int $case_id, int $reviewer ): void {
		$this->cases->resolve( $case_id, FraudRepository::STATUS_REVIEW, $reviewer );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Credit a held reward that was never paid (idempotent).
	 *
	 * @param object $case Case row.
	 * @return void
	 */
	private function release( object $case ): void {
		$uid    = (int) $case->reward_user_id;
		$kind   = (string) $case->reward_kind;
		$amount = (float) $case->reward_amount;
		$source = (string) $case->source;
		$srcid  = (int) $case->source_id;
		if ( $uid <= 0 || $amount <= 0 || 'none' === $kind || '' === $source ) {
			return;
		}
		if ( 'points' === $kind ) {
			// Only if not already paid.
			if ( $this->points->net_for_source( $uid, $source, $srcid ) <= 0 ) {
				$this->points->credit( $uid, (int) round( $amount ), $source, $srcid, array( 'type' => 'earn', 'note' => __( 'Reward released after review', 'yazan-rewards' ) ) );
			}
		} elseif ( 'credit' === $kind && $this->wallet ) {
			$this->wallet->credit( $uid, $this->money->format( $amount ), $source, $srcid, array( 'type' => 'review_release', 'note' => __( 'Reward released after review', 'yazan-rewards' ) ) );
		}
	}

	/**
	 * Reverse an already-paid reward (clawback), idempotent.
	 *
	 * @param object $case Case row.
	 * @return void
	 */
	private function reverse( object $case ): void {
		$uid    = (int) $case->reward_user_id;
		$kind   = (string) $case->reward_kind;
		$source = (string) $case->source;
		$srcid  = (int) $case->source_id;
		if ( $uid <= 0 || 'none' === $kind || '' === $source ) {
			return;
		}
		if ( 'points' === $kind ) {
			// net_for_source returns 0 for a held-but-unpaid reward, so a hold reversal
			// is a safe no-op; a genuinely paid reward is clawed back exactly once.
			$net = $this->points->net_for_source( $uid, $source, $srcid );
			if ( $net > 0 ) {
				$this->points->debit( $uid, $net, $source, $srcid, array( 'type' => 'fraud_reversal', 'note' => __( 'Reward reversed — confirmed fraud', 'yazan-rewards' ) ) );
				$this->announce_reversal( $uid, 'points', (float) $net, $source, $srcid );
			}
		}
		// Store-credit clawback is intentionally NOT auto-reversed: the wallet has no
		// per-source netting, so we cannot tell a held-unpaid credit reward apart from a
		// paid one and must not debit unrelated balance. No credit-kind reward is held
		// today (only points); enable this once the wallet exposes net_for_source.
	}

	/**
	 * Raise the account's risk score + high-risk flag.
	 *
	 * @param object $case Case row.
	 * @return void
	 */
	private function flag_account( object $case ): void {
		$uid = (int) ( $case->reward_user_id ?: $case->user_id );
		if ( $uid <= 0 ) {
			return;
		}
		update_user_meta( $uid, self::META_RISK_FLAGGED, 1 );
		$score = (int) get_user_meta( $uid, self::META_RISK_SCORE, true ) + max( 1, (int) $case->score );
		update_user_meta( $uid, self::META_RISK_SCORE, $score );
	}

	/**
	 * @param int    $uid    User.
	 * @param string $kind   points|credit.
	 * @param float  $amount Amount reversed.
	 * @param string $source Source.
	 * @param int    $srcid  Source id.
	 * @return void
	 */
	private function announce_reversal( int $uid, string $kind, float $amount, string $source, int $srcid ): void {
		$this->bus->dispatch(
			new GenericEvent(
				Events::FRAUD_REVERSED,
				array( 'user_id' => $uid, 'kind' => $kind, 'amount' => $amount, 'source' => $source, 'source_id' => $srcid )
			)
		);
	}
}
