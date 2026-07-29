<?php
/**
 * Central registry of domain-event names.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable string names for every domain event. Keeping them here — in Core, not
 * inside any engine — lets one module subscribe to another module's event by a
 * shared constant without ever referencing that module's classes. This is the
 * seam that keeps engines decoupled and independently removable.
 */
final class Events {

	/* Commerce (emitted by the WooCommerce integration). */
	public const ORDER_REWARDED = 'order_rewarded';
	public const ORDER_REFUNDED = 'order_refunded';

	/* Account lifecycle (emitted by the WordPress integration). */
	public const USER_REGISTERED = 'user_registered';
	public const REVIEW_CREATED  = 'review_created';

	/* Points (emitted by the Points engine). */
	public const POINTS_CREDITED = 'points_credited';
	public const POINTS_DEBITED  = 'points_debited';
	public const POINTS_EXPIRED  = 'points_expired';
	public const POINTS_EXPIRING_SOON = 'points_expiring_soon';
	public const POINTS_APPROVED = 'points_approved';
	public const POINTS_REJECTED = 'points_rejected';

	/* Rewards — service fulfillment (emitted by the Rewards engine). */
	public const SERVICE_REQUESTED = 'service_requested';
	public const SERVICE_FULFILLED = 'service_fulfilled';

	/* Marketing campaigns (emitted by the Campaigns engine). */
	public const CAMPAIGN_STATUS_CHANGED      = 'campaign_status_changed';
	public const CAMPAIGN_JOINED              = 'campaign_joined';
	public const CAMPAIGN_SUBMISSION_APPROVED = 'campaign_submission_approved';
	public const CAMPAIGN_SUBMISSION_REJECTED = 'campaign_submission_rejected';
	public const CAMPAIGN_COMPLETED           = 'campaign_completed';

	/* Wallet / store credit (emitted by the Wallet engine). */
	public const CREDIT_CREDITED = 'credit_credited';
	public const CREDIT_DEBITED  = 'credit_debited';

	/* Rewards (emitted by the Rewards engine — later phase). */
	public const REWARD_REDEEMED = 'reward_redeemed';

	/* Ambassador / referral (later phases). */
	public const COMMISSION_EARNED        = 'commission_earned';
	public const REFERRAL_SIGNED_UP       = 'referral_signed_up';
	public const REFERRAL_CONVERTED       = 'referral_converted';
	public const REFERRAL_REWARD_HELD     = 'referral_reward_held';
	public const REFERRAL_REWARD_APPROVED = 'referral_reward_approved';
	public const AMBASSADOR_APPROVED      = 'ambassador_approved';

	/* Achievement / tiers (later phase). */
	public const ACHIEVEMENT_UNLOCKED = 'achievement_unlocked';
	public const TIER_CHANGED         = 'tier_changed';

	/* Social (later phase). */
	public const SOCIAL_ACTION_APPROVED = 'social_action_approved';
	public const SOCIAL_ACCOUNT_LINKED  = 'social_account_linked';
	public const SOCIAL_CONTENT_VERIFIED = 'social_content_verified';

	/* Anti-fraud (later phase). */
	public const FRAUD_FLAGGED       = 'fraud_flagged';
	public const FRAUD_CASE_RESOLVED = 'fraud_case_resolved';
	public const FRAUD_REVERSED      = 'fraud_reversed';
}
