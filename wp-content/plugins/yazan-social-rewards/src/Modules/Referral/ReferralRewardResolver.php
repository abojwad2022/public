<?php
/**
 * Referral reward resolver.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\WalletServiceInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes and pays the rewards a converting referral generates, applying the
 * configured rules (points|credit, fixed|percent), the tree depth (referred +
 * L1 + L2), and the anti-fraud decision. Approved earnings are credited immediately
 * via the points/wallet ledgers (source `referral`); suspicious ones are written to
 * the earnings ledger as `pending` for admin review and paid only on approval. Every
 * payout is idempotent per (referral, order, beneficiary).
 */
final class ReferralRewardResolver {

	/**
	 * @param ReferralRepository         $repo     Referral repository.
	 * @param ReferralEarningsRepository $earnings Earnings ledger.
	 * @param ReferralTree               $tree     Tree traversal.
	 * @param ReferralRewardRules        $rules    Reward-rule config.
	 * @param ReferralGuard              $guard    Abuse guard.
	 * @param PointsLedgerInterface      $points   Points ledger.
	 * @param Money                      $money    Money helper.
	 * @param EventBus                   $bus      Event bus.
	 * @param Settings                   $settings Settings.
	 * @param WalletServiceInterface|null $wallet  Store-credit wallet (optional).
	 */
	public function __construct(
		private ReferralRepository $repo,
		private ReferralEarningsRepository $earnings,
		private ReferralTree $tree,
		private ReferralRewardRules $rules,
		private ReferralGuard $guard,
		private PointsLedgerInterface $points,
		private Money $money,
		private EventBus $bus,
		private Settings $settings,
		private ?WalletServiceInterface $wallet = null
	) {}

	/**
	 * Pay out every reward a first-order conversion generates.
	 *
	 * @param object $referral    The referred customer's referral row.
	 * @param int    $referred    Referred user id.
	 * @param int    $order_id    Order id.
	 * @param float  $order_total Order total.
	 * @return void
	 */
	public function pay_out( object $referral, int $referred, int $order_id, float $order_total ): void {
		// A zero/negative-total first order (e.g. a 100%-off coupon) neither qualifies
		// for a reward nor should "burn" the one-shot conversion — leave the referral
		// 'signed_up' so a later real order can still convert it, and never pay a fixed
		// reward on a $0 order.
		if ( $order_total <= 0 ) {
			return;
		}
		if ( $order_total < $this->rules->min_order_total() ) {
			return; // Order too small to qualify.
		}

		$referral_id = (int) $referral->id;
		$referrer_id = (int) $referral->referrer_user_id;

		// One abuse decision for the whole conversion.
		$assessment = $this->guard->assess( $referrer_id, $referred, $referral );
		list( $status, $do_credit ) = $this->status_for( (bool) $assessment['suspicious'] );

		foreach ( $this->beneficiaries( $referral, $referred, $order_total ) as $b ) {
			$uid = (int) $b['user'];
			if ( $uid <= 0 || $b['amount'] <= 0 ) {
				continue;
			}
			if ( $this->earnings->exists_for( $referral_id, $order_id, $uid ) ) {
				continue; // Already paid for this order — idempotent.
			}

			$earning_id = $this->earnings->create(
				array(
					'referral_id'         => $referral_id,
					'beneficiary_user_id' => $uid,
					'referred_user_id'    => $referred,
					'level'               => (int) $b['level'],
					'role'                => (string) $b['role'],
					'order_id'            => $order_id,
					'order_total'         => $order_total,
					'reward_kind'         => (string) $b['kind'],
					'reward_amount'       => (float) $b['amount'],
					'status'              => $status,
					'note'                => (string) $b['note'],
				)
			);

			if ( $do_credit && $earning_id > 0 ) {
				$row = $this->earnings->find( $earning_id );
				if ( $row ) {
					$this->settle( $row );
				}
			}
		}

		// The funnel converts once, regardless of whether rewards were held.
		$this->repo->mark_converted( $referral_id, $order_id );

		if ( $assessment['suspicious'] ) {
			$this->bus->dispatch(
				new GenericEvent(
					Events::REFERRAL_REWARD_HELD,
					array(
						'referral_id' => $referral_id,
						'referred_id' => $referred,
						'referrer_id' => $referrer_id,
						'action'      => $this->rules->abuse_action(),
						'reasons'     => $assessment['reasons'],
					)
				)
			);
		}
	}

	/**
	 * Approve a held earning (admin): credit it and settle the rollups.
	 *
	 * @param int $earning_id Earning id.
	 * @return bool
	 */
	public function approve( int $earning_id ): bool {
		// Atomically claim the pending row first so two concurrent approvals (a
		// double-clicked tab or a retried POST) can never both credit it.
		if ( ! $this->earnings->claim_pending( $earning_id ) ) {
			return false;
		}
		$row = $this->earnings->find( $earning_id );
		return $row ? $this->settle( $row ) : false;
	}

	/**
	 * Reject a held earning (admin): never paid.
	 *
	 * @param int $earning_id Earning id.
	 * @return bool
	 */
	public function reject( int $earning_id ): bool {
		$row = $this->earnings->find( $earning_id );
		if ( ! $row || ReferralEarningsRepository::STATUS_PENDING !== $row->status ) {
			return false;
		}
		$this->earnings->mark_rejected( $earning_id );
		return true;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The (status, credit-now) pair for a conversion given the abuse verdict.
	 *
	 * @param bool $suspicious Whether the guard flagged it.
	 * @return array{0:string,1:bool}
	 */
	private function status_for( bool $suspicious ): array {
		if ( ! $suspicious ) {
			return array( ReferralEarningsRepository::STATUS_APPROVED, true );
		}
		switch ( $this->rules->abuse_action() ) {
			case 'block':
				return array( ReferralEarningsRepository::STATUS_REJECTED, false );
			case 'flag':
				return array( ReferralEarningsRepository::STATUS_APPROVED, true );
			case 'hold':
			default:
				return array( ReferralEarningsRepository::STATUS_PENDING, false );
		}
	}

	/**
	 * Build the list of beneficiaries with resolved reward amounts: the referred
	 * customer (welcome) + each upline referrer up to the configured depth.
	 *
	 * @param object $referral    Referral row.
	 * @param int    $referred    Referred user id.
	 * @param float  $order_total Order total.
	 * @return array<int,array{user:int,role:string,level:int,kind:string,amount:float,note:string}>
	 */
	private function beneficiaries( object $referral, int $referred, float $order_total ): array {
		$out = array();

		$welcome = $this->amount( $this->rules->referred_spec(), $order_total );
		$out[]   = array(
			'user'   => $referred,
			'role'   => 'referred',
			'level'  => 0,
			'kind'   => $welcome['kind'],
			'amount' => $welcome['amount'],
			'note'   => __( 'Welcome referral bonus', 'yazan-rewards' ),
		);

		$upline = $this->tree->upline( $referred, $this->rules->max_levels() );
		foreach ( $upline as $idx => $ancestor ) {
			$level = $idx + 1;
			$calc  = $this->amount( $this->rules->spec_for_level( $level ), $order_total );
			$out[] = array(
				'user'   => (int) $ancestor,
				'role'   => 'referrer',
				'level'  => $level,
				'kind'   => $calc['kind'],
				'amount' => $calc['amount'],
				'note'   => 1 === $level
					? __( 'Referral reward', 'yazan-rewards' )
					: __( 'Level-2 referral reward', 'yazan-rewards' ),
			);
		}
		return $out;
	}

	/**
	 * Resolve a spec + order value into a concrete reward amount.
	 *
	 * @param array $spec        { kind, mode, value }.
	 * @param float $order_total Order total.
	 * @return array{kind:string,amount:float}
	 */
	private function amount( array $spec, float $order_total ): array {
		$kind  = (string) $spec['kind'];
		$value = (float) $spec['value'];
		if ( $value <= 0 ) {
			return array( 'kind' => $kind, 'amount' => 0.0 );
		}

		$raw = 'percent' === $spec['mode'] ? ( $order_total * $value / 100 ) : $value;

		if ( 'points' === $kind ) {
			// Fixed points are whole; percent-of-order points are floored.
			$amount = 'percent' === $spec['mode'] ? (float) $this->money->round( $raw, 'floor' ) : floor( $raw );
		} else {
			$amount = (float) $this->money->format( $raw );
		}

		return array( 'kind' => $kind, 'amount' => max( 0.0, $amount ) );
	}

	/**
	 * Credit an earning, link the ledger row, roll up the referral totals, and
	 * announce it. Shared by the auto-approve and admin-approve paths.
	 *
	 * @param object $earning Earning row.
	 * @return void
	 */
	private function settle( object $earning ): bool {
		$uid         = (int) $earning->beneficiary_user_id;
		$referral_id = (int) $earning->referral_id;
		$kind        = (string) $earning->reward_kind;
		$amount      = (float) $earning->reward_amount;
		$note        = (string) $earning->note;

		// Store credit requires the Wallet engine. If it isn't bound, we cannot pay —
		// reject honestly rather than mark an unpayable reward "approved" (which would
		// show the customer credit they can never spend).
		if ( 'credit' === $kind && ! $this->wallet ) {
			$this->earnings->mark_rejected( (int) $earning->id );
			return false;
		}

		$ledger_id = 0;
		if ( 'points' === $kind ) {
			$pts = (int) round( $amount );
			if ( $pts > 0 ) {
				$ledger_id = $this->points->credit( $uid, $pts, 'referral', $referral_id, array( 'type' => 'earn', 'note' => $note ) );
				$this->repo->add_points_generated( $referral_id, $pts );
				if ( 'referrer' === (string) $earning->role && 1 === (int) $earning->level ) {
					$this->repo->add_reward_points( $referral_id, $pts );
				}
			}
		} elseif ( 'credit' === $kind ) {
			$ledger_id = $this->wallet->credit( $uid, $this->money->format( $amount ), 'referral', $referral_id, array( 'type' => 'referral', 'note' => $note ) );
		}

		// The row is already 'approved' (created so, or claimed atomically) — just
		// record which ledger row paid it.
		$this->earnings->link_ledger( (int) $earning->id, $ledger_id );

		$this->bus->dispatch(
			new GenericEvent(
				Events::REFERRAL_REWARD_APPROVED,
				array(
					'earning_id' => (int) $earning->id,
					'user_id'    => $uid,
					'kind'       => $kind,
					'amount'     => $amount,
				)
			)
		);
		return true;
	}
}
