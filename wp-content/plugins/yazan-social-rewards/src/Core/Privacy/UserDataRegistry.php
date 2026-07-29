<?php
/**
 * Single source of truth for "where does this plugin store user-linked data".
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes every table column in this plugin that points at a WordPress user,
 * and what must happen to those rows when the user goes away.
 *
 * Four consumers share this one map so they can never drift apart:
 *   - UserCleanup   — the `deleted_user` hook.
 *   - PersonalData  — the GDPR exporter/eraser.
 *   - OrphanScanner — the integrity report + opt-in purge.
 *   - Any future module that adds a user-linked table.
 *
 * WHY TWO POLICIES
 * ----------------
 * `DELETE` is the default: behavioural and PII rows should not outlive the
 * account.
 *
 * `ANONYMIZE` is used for the ledgers. Points and store credit are a financial
 * liability — docs/BACKUP.md already treats them that way — so the rows must
 * survive for accounting and reconciliation, but they must stop identifying a
 * person. Anonymising rewrites the user column to 0 and blanks any PII columns
 * on the same row, which preserves every amount and timestamp while severing
 * the link to a human.
 *
 * Deleting a ledger row instead would silently change historical revenue and
 * liability totals, which is why it is never the default here.
 */
final class UserDataRegistry {

	/** Row is removed outright. */
	public const POLICY_DELETE = 'delete';

	/** Row is kept, but the user link and any PII on it are stripped. */
	public const POLICY_ANONYMIZE = 'anonymize';

	/** Value written into a user column when anonymising. */
	public const ANONYMOUS_USER_ID = 0;

	/**
	 * The map.
	 *
	 * Each entry:
	 *   table   — bare table name (no site prefix, no `yazan_rw_` segment).
	 *   columns — user-reference columns on that table. A table may reference a
	 *             user more than once (referrals has both sides of the pair).
	 *   policy  — POLICY_DELETE | POLICY_ANONYMIZE.
	 *   pii     — extra columns blanked when anonymising (hashes, IPs). Ignored
	 *             for POLICY_DELETE, where the whole row goes anyway.
	 *   label   — human-readable group name for the GDPR export UI.
	 *
	 * Verified against the live schema — `per_user_limit` (rewards) and
	 * `per_user_cap` (rules) are configuration columns, NOT user references,
	 * and are deliberately absent.
	 *
	 * @return array<int,array{table:string,columns:array<int,string>,policy:string,pii:array<int,string>,label:string}>
	 */
	public function map(): array {
		$map = array(
			// --- Behavioural / PII: remove with the account. -----------------
			array(
				'table'   => 'activity_logs',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Rewards activity log', 'yazan-rewards' ),
			),
			array(
				'table'   => 'notifications',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Rewards notifications', 'yazan-rewards' ),
			),
			array(
				'table'   => 'user_achievements',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Achievements earned', 'yazan-rewards' ),
			),
			array(
				// Holds encrypted OAuth access/refresh tokens. These must be
				// destroyed, never anonymised — an orphaned token is still a
				// live credential against the social network.
				'table'   => 'social_accounts',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Linked social accounts', 'yazan-rewards' ),
			),
			array(
				'table'   => 'social_actions',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Social actions', 'yazan-rewards' ),
			),
			array(
				'table'   => 'ambassadors',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Ambassador profile', 'yazan-rewards' ),
			),
			array(
				'table'   => 'campaign_participants',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Campaign participation', 'yazan-rewards' ),
			),
			array(
				'table'   => 'campaign_submissions',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_DELETE,
				'pii'     => array(),
				'label'   => __( 'Campaign submissions', 'yazan-rewards' ),
			),

			// --- Financial: keep the row, sever the identity. ----------------
			array(
				'table'   => 'points_ledger',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array(),
				'label'   => __( 'Points ledger', 'yazan-rewards' ),
			),
			array(
				'table'   => 'wallet_ledger',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array(),
				'label'   => __( 'Store credit ledger', 'yazan-rewards' ),
			),
			array(
				'table'   => 'redemptions',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array(),
				'label'   => __( 'Reward redemptions', 'yazan-rewards' ),
			),
			array(
				'table'   => 'referral_earnings',
				'columns' => array( 'beneficiary_user_id', 'referred_user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array(),
				'label'   => __( 'Referral earnings', 'yazan-rewards' ),
			),
			array(
				'table'   => 'service_vouchers',
				'columns' => array( 'user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array(),
				'label'   => __( 'Service vouchers', 'yazan-rewards' ),
			),
			array(
				// Both sides of the pair, plus the hashed identifiers used for
				// self-referral detection.
				'table'   => 'referrals',
				'columns' => array( 'referrer_user_id', 'referred_user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array( 'email_hash', 'ip_hash' ),
				'label'   => __( 'Referrals', 'yazan-rewards' ),
			),
			array(
				// Retained (anonymised) so fraud patterns survive an account
				// deletion — a fraudster deleting their account must not also
				// erase the evidence. The identifying hash is still blanked.
				'table'   => 'fraud_flags',
				'columns' => array( 'user_id', 'reward_user_id' ),
				'policy'  => self::POLICY_ANONYMIZE,
				'pii'     => array( 'ip_hash' ),
				'label'   => __( 'Fraud review flags', 'yazan-rewards' ),
			),
		);

		/**
		 * Filter the user-data map.
		 *
		 * Add-ons that create their own user-linked tables should append an
		 * entry here so they are covered by deletion, GDPR export/erase, and
		 * the orphan scanner automatically.
		 *
		 * @param array $map The registry entries.
		 */
		return (array) apply_filters( 'yazan_rewards/privacy/user_data_map', $map );
	}

	/**
	 * Entries matching a policy.
	 *
	 * @param string $policy POLICY_* constant.
	 * @return array<int,array>
	 */
	public function by_policy( string $policy ): array {
		return array_values(
			array_filter(
				$this->map(),
				static fn( array $e ): bool => $e['policy'] === $policy
			)
		);
	}
}
