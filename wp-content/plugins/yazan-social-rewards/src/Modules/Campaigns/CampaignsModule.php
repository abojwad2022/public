<?php
/**
 * Campaign engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Campaigns;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Admin\CampaignsAdminPage;
use Yazan\Rewards\Rest\V1\CampaignsController;
use Yazan\Rewards\Rest\V1\CampaignsAdminController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `campaigns` table and the earn multiplier. Depends on Points (it
 * multiplies points earning).
 */
final class CampaignsModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'campaigns';
	}

	/**
	 * @inheritDoc
	 */
	public function dependencies(): array {
		return array( 'points' );
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( CampaignRepository::class, static fn( Container $c ) => new CampaignRepository( $c->get( Database::class ) ) );
		$c->singleton( CampaignTaskRepository::class, static fn( Container $c ) => new CampaignTaskRepository( $c->get( Database::class ) ) );
		$c->singleton(
			MultiplierResolver::class,
			static fn( Container $c ) => new MultiplierResolver(
				$c->get( CampaignRepository::class ),
				$c->get( Settings::class ),
				$c->get( Money::class )
			)
		);

		/* --- Marketing campaign engine ---------------------------------- */
		$c->singleton( MarketingCampaignRepository::class, static fn( Container $c ) => new MarketingCampaignRepository( $c->get( Database::class ) ) );
		$c->singleton( ParticipantRepository::class, static fn( Container $c ) => new ParticipantRepository( $c->get( Database::class ) ) );
		$c->singleton( SubmissionRepository::class, static fn( Container $c ) => new SubmissionRepository( $c->get( Database::class ) ) );
		$c->singleton( CampaignEligibility::class, static fn() => new CampaignEligibility() );
		$c->singleton( CampaignWorkflow::class, static fn( Container $c ) => new CampaignWorkflow( $c->get( MarketingCampaignRepository::class ), $c->get( EventBus::class ), $c->get( Scheduler::class ) ) );
		$c->singleton(
			ParticipationService::class,
			static fn( Container $c ) => new ParticipationService(
				$c->get( MarketingCampaignRepository::class ),
				$c->get( CampaignTaskRepository::class ),
				$c->get( ParticipantRepository::class ),
				$c->get( SubmissionRepository::class ),
				$c->get( CampaignEligibility::class ),
				$c->get( EventBus::class ),
				$c->get( RateLimiter::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton(
			SubmissionReviewService::class,
			static fn( Container $c ) => new SubmissionReviewService(
				$c->get( SubmissionRepository::class ),
				$c->get( CampaignTaskRepository::class ),
				$c->get( MarketingCampaignRepository::class ),
				$c->get( ParticipantRepository::class ),
				$c->get( PointsLedgerInterface::class ),
				$c->get( EventBus::class )
			)
		);
		$c->singleton( CampaignAnalytics::class, static fn( Container $c ) => new CampaignAnalytics( $c->get( ParticipantRepository::class ), $c->get( SubmissionRepository::class ), $c->get( MarketingCampaignRepository::class ) ) );
		$c->singleton( CampaignsAdminPage::class, static fn( Container $c ) => new CampaignsAdminPage( $c->get( Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		$settings = $c->get( Settings::class );
		$hooks    = $c->get( HookLoader::class );

		// Earn multiplier campaigns.
		if ( $settings->feature_enabled( 'campaigns' ) ) {
			$hooks->register( $c->get( MultiplierResolver::class ) );
		}

		// Marketing campaign engine (workflow tick self-schedules on `init`).
		if ( $settings->feature_enabled( 'marketing' ) ) {
			$hooks->register( $c->get( CampaignWorkflow::class ) );

			$hooks->register( $c->get( CampaignsAdminPage::class ) );

			$rest = $c->get( RestBootstrap::class );
			$rest->add( static fn( Container $c ) => new CampaignsController( $c ) );
			$rest->add( static fn( Container $c ) => new CampaignsAdminController( $c ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$campaigns     = $wpdb->prefix . Database::TABLE_PREFIX . 'campaigns';
		$tasks         = $wpdb->prefix . Database::TABLE_PREFIX . 'campaign_tasks';
		$marketing     = $wpdb->prefix . Database::TABLE_PREFIX . 'marketing_campaigns';
		$participants  = $wpdb->prefix . Database::TABLE_PREFIX . 'campaign_participants';
		$submissions   = $wpdb->prefix . Database::TABLE_PREFIX . 'campaign_submissions';

		return array(
			"CREATE TABLE {$campaigns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				type varchar(20) NOT NULL DEFAULT 'multiplier',
				rules longtext NULL,
				multiplier decimal(6,2) NOT NULL DEFAULT 1,
				priority int(11) NOT NULL DEFAULT 10,
				active tinyint(1) NOT NULL DEFAULT 1,
				starts_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				ends_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY active (active),
				KEY window_start (starts_at),
				KEY window_end (ends_at)
			) {$charset_collate};",

			// Campaign activities: the tasks that make up a marketing campaign
			// (campaign_id references marketing_campaigns).
			"CREATE TABLE {$tasks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(190) NOT NULL DEFAULT '',
				task_type varchar(30) NOT NULL DEFAULT 'custom',
				criteria longtext NULL,
				points_award bigint(20) unsigned NOT NULL DEFAULT 0,
				sort int(11) NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY campaign_id (campaign_id),
				KEY active (active)
			) {$charset_collate};",

			// Marketing campaigns — workflow-based UGC campaigns.
			"CREATE TABLE {$marketing} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(190) NOT NULL DEFAULT '',
				description text NULL,
				media longtext NULL,
				target longtext NULL,
				reward_amount bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'draft',
				starts_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				ends_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY status (status),
				KEY window_start (starts_at),
				KEY window_end (ends_at)
			) {$charset_collate};",

			// Per-user participation in a marketing campaign.
			"CREATE TABLE {$participants} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'joined',
				points_earned bigint(20) unsigned NOT NULL DEFAULT 0,
				joined_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				completed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_user (campaign_id,user_id),
				KEY campaign_id (campaign_id)
			) {$charset_collate};",

			// Task submissions (content) awaiting review.
			"CREATE TABLE {$submissions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				task_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT '',
				content_url varchar(255) NOT NULL DEFAULT '',
				metric_value bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				points_award bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				reviewed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY campaign_id (campaign_id),
				KEY task_id (task_id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};",
		);
	}
}
