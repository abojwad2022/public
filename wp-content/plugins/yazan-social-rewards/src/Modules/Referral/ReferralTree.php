<?php
/**
 * Referral tree traversal (upline + downline).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks the referral graph built from `referrals` edges. Each customer has at most
 * one referrer, so the graph is a forest: the upline is a simple chain (used to pay
 * L1/L2 rewards) and the downline is the set of descendants per generation (used for
 * the tree view + revenue rollups). All walks are cycle-guarded and depth-bounded.
 */
final class ReferralTree {

	/** Hard ceiling on downline breadth per generation, to bound cost. */
	private const MAX_PER_LEVEL = 500;

	/**
	 * @param ReferralRepository $repo Referral repository.
	 */
	public function __construct( private ReferralRepository $repo ) {}

	/**
	 * The ancestor chain above a user: [ level1_referrer, level2_referrer, … ] up to
	 * $max_levels. Index 0 is the direct referrer.
	 *
	 * @param int $user_id    The referred user.
	 * @param int $max_levels How many ancestors to return (>=1).
	 * @return int[] Ancestor user ids, nearest first.
	 */
	public function upline( int $user_id, int $max_levels = 2 ): array {
		$max_levels = max( 1, $max_levels );
		$chain      = array();
		$seen       = array( $user_id => true );
		$current    = $user_id;

		for ( $i = 0; $i < $max_levels; $i++ ) {
			$row = $this->repo->find_by_referred( $current );
			if ( ! $row ) {
				break;
			}
			$referrer = (int) $row->referrer_user_id;
			if ( $referrer <= 0 || isset( $seen[ $referrer ] ) ) {
				break; // No referrer, or a cycle — stop.
			}
			$chain[]           = $referrer;
			$seen[ $referrer ] = true;
			$current           = $referrer;
		}
		return $chain;
	}

	/**
	 * The descendants below a user, grouped by generation:
	 * `[ 1 => [ids…], 2 => [ids…], … ]` down to $depth.
	 *
	 * @param int $user_id Root user.
	 * @param int $depth   Generations to descend (>=1).
	 * @return array<int,int[]>
	 */
	public function downline( int $user_id, int $depth = 2 ): array {
		$depth   = max( 1, $depth );
		$levels  = array();
		$seen    = array( $user_id => true );
		$frontier = array( $user_id );

		for ( $level = 1; $level <= $depth; $level++ ) {
			$next = array();
			foreach ( $frontier as $parent ) {
				foreach ( $this->repo->referred_ids( $parent ) as $child ) {
					if ( $child <= 0 || isset( $seen[ $child ] ) ) {
						continue;
					}
					$seen[ $child ] = true;
					$next[]         = $child;
					if ( count( $next ) >= self::MAX_PER_LEVEL ) {
						break 2;
					}
				}
			}
			if ( empty( $next ) ) {
				break;
			}
			$levels[ $level ] = $next;
			$frontier         = $next;
		}
		return $levels;
	}

	/**
	 * Per-generation counts for the downline (for a compact dashboard summary).
	 *
	 * @param int $user_id Root user.
	 * @param int $depth   Generations.
	 * @return array<int,int> level => count.
	 */
	public function downline_counts( int $user_id, int $depth = 2 ): array {
		$counts = array();
		foreach ( $this->downline( $user_id, $depth ) as $level => $ids ) {
			$counts[ $level ] = count( $ids );
		}
		return $counts;
	}
}
