<?php
/**
 * Module registry source.
 *
 * The ordered list of engine module classes that make up the platform. Each
 * must implement {@see \Yazan\Rewards\Core\Contracts\ModuleInterface}. The
 * registry sorts them by their declared dependencies, so this order is only a
 * hint. Comment a line out to disable an engine; the rest keep working.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Phase 2 — the earning loop (Rules → Points → Wallet + WooCommerce integration).
	\Yazan\Rewards\Modules\Rules\RulesModule::class,
	\Yazan\Rewards\Modules\Points\PointsModule::class,
	\Yazan\Rewards\Modules\Wallet\WalletModule::class,

	// Phase 3 — rewards catalog + redemption + My Account hub.
	\Yazan\Rewards\Modules\Rewards\RewardsModule::class,

	// Phase 4 — referral attribution + ambassador commissions.
	\Yazan\Rewards\Modules\Referral\ReferralModule::class,
	\Yazan\Rewards\Modules\Ambassador\AmbassadorModule::class,

	// Phase 5 — campaigns (earn multipliers) + achievements + loyalty tiers.
	\Yazan\Rewards\Modules\Campaigns\CampaignsModule::class,
	\Yazan\Rewards\Modules\Achievement\AchievementModule::class,

	// Phase 6 — social connector, anti-fraud, analytics, notifications.
	\Yazan\Rewards\Modules\Social\SocialModule::class,
	\Yazan\Rewards\Modules\AntiFraud\AntiFraudModule::class,
	\Yazan\Rewards\Modules\Analytics\AnalyticsModule::class,
	\Yazan\Rewards\Modules\Notification\NotificationModule::class,

	// Cross-cutting — per-user activity feed (listens on the event bus only).
	\Yazan\Rewards\Modules\Activity\ActivityModule::class,

	// Public developer API — the stable `yazan/v1` REST facade.
	\Yazan\Rewards\Modules\PublicApi\PublicApiModule::class,
);
