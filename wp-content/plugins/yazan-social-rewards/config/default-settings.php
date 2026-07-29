<?php
/**
 * Default plugin settings.
 *
 * Stored (merged with saved values) under the single option
 * `yazan_rewards_settings`. Grouped by concern; each engine reads its slice via
 * the Settings service. Feature flags let an engine be disabled at runtime
 * independently of whether its module is registered.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Global.
	'currency_name_singular' => 'Point',
	'currency_name_plural'   => 'Points',
	'delete_data_on_uninstall' => false, // Protect the liability ledger by default.

	// Feature flags (per engine).
	'features' => array(
		'rules'        => true,
		'marketing'    => true,
		'points'       => true,
		'wallet'       => true,
		'rewards'      => true,
		'campaigns'    => true,
		'ambassador'   => true,
		'referral'     => true,
		'achievement'  => true,
		'social'       => true,
		'analytics'    => true,
		'antifraud'    => true,
		'notification' => true,
		'activity'     => true,
		'public_api'   => true,
	),

	// Points.
	'points' => array(
		'earn_status'        => 'completed', // Order status that awards purchase points.
		'points_per_currency' => 1,          // Points per 1 unit of order total (pre-tax/ship).
		'signup_bonus'       => 100,
		'review_bonus'       => 50,
		'birthday_bonus'     => 200,
		'expiry_months'      => 0,            // 0 = never expire.
		'expiry_reminder_days' => 14,         // Warn this many days before points expire (0 = off).
		'round'              => 'floor',      // floor|round|ceil.
	),

	// Wallet / redemption.
	'wallet' => array(
		'redeem_mode'        => 'wallet',     // wallet|coupon|wallet_coupon_fallback.
		'points_to_currency' => 100,          // 100 points = 1 currency unit of credit.
		'min_redeem_points'  => 500,
		'max_cart_percent'   => 100,          // Max % of cart payable by wallet credit.
	),

	// Membership tiers / levels.
	'tier' => array(
		'apply_discount' => true, // Auto-apply the member level's discount at checkout.
	),

	// Ambassador.
	'ambassador' => array(
		'auto_approve'    => false,
		'commission_rate' => 5,   // Percent of attributed order total → wallet credit.
		'payout_mode'     => 'store_credit',
	),

	// Referral.
	//
	// Rewards are paid on the referred customer's FIRST order (trigger). Each reward
	// is a { kind: points|credit, mode: fixed|percent, value } spec:
	//   - kind  points = loyalty points, credit = store-credit (money, via wallet)
	//   - mode  fixed  = flat value, percent = value% of the order total
	// The tree pays up to `max_levels` (1 = direct referrer only, 2 = + grand-referrer).
	// Legacy `referrer_reward` / `referred_reward` (flat points) are still honoured as a
	// fallback when the structured specs are absent.
	'referral' => array(
		'cookie_days'     => 30,
		'max_levels'      => 2,            // Reward depth: 1 = direct only, 2 = + grand-referrer.
		'trigger'         => 'first_order', // first_order (reward once, on the first order).
		'min_order_total' => 0,           // Minimum order total to qualify for a reward.
		'abuse_action'    => 'hold',      // hold | block | flag — action on a suspicious referral.
		'referred' => array( 'kind' => 'points', 'mode' => 'fixed', 'value' => 100 ), // New customer.
		'referrer' => array( 'kind' => 'points', 'mode' => 'fixed', 'value' => 200 ), // L1 direct referrer.
		'level2'   => array( 'kind' => 'points', 'mode' => 'fixed', 'value' => 50 ),  // L2 grand-referrer.
		// Legacy (back-compat fallbacks).
		'referrer_reward' => 200,
		'referred_reward' => 100,
		'reward_on'       => 'first_order',
	),

	// Anti-fraud.
	//
	// The risk engine scores each reward-earning action against the enabled detectors;
	// at or above `review_threshold` the reward is HELD as a `manual_review` fraud case
	// (nothing paid) when `hold_suspicious` is on. Admins approve (release) or reject
	// (auto-reverse the reward + flag the account) from Yazan Rewards → Fraud.
	'antifraud' => array(
		'max_earn_per_day'    => 5000,   // Daily points-earn cap (0 = unlimited).
		'hold_suspicious'     => true,   // Hold flagged rewards for review (vs flag-only).
		'review_threshold'    => 50,     // Score at/above which a reward is held.
		'velocity_max'        => 12,     // Max credits within the velocity window.
		'velocity_window'     => 60,     // Velocity window, seconds.
		'multi_account_max'   => 2,      // Shared-fingerprint accounts before flagging.
		'detectors'           => array(
			'duplicate'    => true,
			'multi_account' => true,
			'velocity'     => true,
			'engagement'   => true,
		),
	),

	// Social connector.
	//
	// Verification runs by two methods: (1) the network's API when that connector's
	// app keys are configured AND the customer has linked their account; else (2)
	// manual submission — the 6 checks (url exists, ownership, hashtag, mention/link,
	// date, duplicate). `verify.auto_approve` awards automatically when every REQUIRED
	// manual check passes; otherwise the submission is queued for admin review.
	// Per-network overrides live under `social.networks.{network}` (enabled + points).
	'social' => array(
		'share_points'      => 25,
		'ugc_points'        => 200,
		'share_daily_limit' => 3,     // Rewarded shares per day.
		'auto_approve_ugc'  => false, // Legacy: blanket auto-approve (verification below supersedes).
		'verify'            => array(
			'auto_approve'    => true,  // Auto-award when all required manual checks pass.
			'manual_fetch'    => true,  // Fetch the post URL to run hashtag/mention/date checks.
			'required_hashtag' => '',   // e.g. "#YazanJewelry" — empty disables the hashtag check.
			'required_mention' => '',   // e.g. "@yazan" or a store URL — empty disables it.
			'window_days'      => 30,   // Post must be dated within this many days (0 = no date check).
		),
		// Per-network enable + points overrides (fall back to ugc_points). Networks
		// come from config/connectors.php — nothing here hardcodes the list.
		'networks'          => array(),
	),

	// Notifications.
	//
	// Milestone domain events become notifications, fanned out across channels.
	// `email`/`onsite` are the master channel toggles; `webhook` is an OUTBOUND
	// integration channel (Slack/Zapier/automation) that fires for every notification
	// regardless of a customer's per-category preferences — its shared signing secret
	// is stored write-only (autoload-off option, exposed only as last4). Per-customer
	// control (per category: email immediate|digest|off, on-site on|off) lives in user
	// meta `_yzrw_notif_prefs`; REQUIRED categories (service, reward) are always sent.
	// `digest` batches non-urgent, digest-preferred emails into one daily message.
	'notification' => array(
		'email'   => true,
		'onsite'  => true,
		'webhook' => array(
			'enabled' => false,
			'url'     => '', // https:// endpoint; the signing secret is stored separately.
		),
		// Future-ready customer channels. Each stays dormant until BOTH `enabled` is on
		// AND a provider is registered via the matching filter (…/{sms|push|whatsapp}_provider).
		// No provider ships in core — activate later by config, not by a rebuild.
		'sms'      => array( 'enabled' => false, 'provider' => '' ),
		'push'     => array( 'enabled' => false, 'provider' => '' ),
		'whatsapp' => array( 'enabled' => false, 'provider' => '' ),
		'digest'  => array(
			'enabled' => true,
			'hour'    => 9, // Local hour (0-23) the daily digest is sent.
		),
		'campaign_ending_days' => 3, // Broadcast "campaign ending" this many days before it ends (0 = off).
		// Master per-category enable (an admin kill-switch); customer prefs are separate.
		'categories' => array(
			'service'     => true,
			'reward'      => true,
			'points'      => true,
			'referral'    => true,
			'tier'        => true,
			'achievement' => true,
			'campaign'    => true,
			'system'      => true,
		),
	),
);
