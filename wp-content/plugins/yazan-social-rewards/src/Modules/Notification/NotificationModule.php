<?php
/**
 * Notification engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Notification;

use Yazan\Rewards\Admin\NotificationsAdminPage;
use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Modules\Notification\Channels\EmailChannel;
use Yazan\Rewards\Modules\Notification\Channels\OnSiteChannel;
use Yazan\Rewards\Modules\Notification\Channels\PushChannel;
use Yazan\Rewards\Modules\Notification\Channels\SmsChannel;
use Yazan\Rewards\Modules\Notification\Channels\WebhookChannel;
use Yazan\Rewards\Modules\Notification\Channels\WhatsAppChannel;
use Yazan\Rewards\Rest\V1\NotificationsAdminController;
use Yazan\Rewards\Rest\V1\NotificationsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `notifications` outbox, the multi-channel dispatcher, and the event
 * subscriber that turns milestones into notifications. No hard module deps — it
 * only listens on the event bus.
 */
final class NotificationModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'notification';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( NotificationRepository::class, static fn( Container $c ) => new NotificationRepository( $c->get( Database::class ) ) );
		$c->singleton( TemplateRenderer::class, static fn() => new TemplateRenderer() );
		$c->singleton( EmailTemplate::class, static fn() => new EmailTemplate() );
		$c->singleton( NotificationPreferences::class, static fn() => new NotificationPreferences() );

		// Channels (shared singletons so the digest reuses the same email channel).
		$c->singleton( OnSiteChannel::class, static fn() => new OnSiteChannel() );
		$c->singleton( EmailChannel::class, static fn( Container $c ) => new EmailChannel( $c->get( Settings::class ), $c->get( EmailTemplate::class ) ) );
		$c->singleton( WebhookChannel::class, static fn( Container $c ) => new WebhookChannel( $c->get( Settings::class ) ) );
		// Future-ready customer channels — dormant until a provider is registered.
		$c->singleton( SmsChannel::class, static fn( Container $c ) => new SmsChannel( $c->get( Settings::class ) ) );
		$c->singleton( PushChannel::class, static fn( Container $c ) => new PushChannel( $c->get( Settings::class ) ) );
		$c->singleton( WhatsAppChannel::class, static fn( Container $c ) => new WhatsAppChannel( $c->get( Settings::class ) ) );

		$c->singleton(
			DigestService::class,
			static fn( Container $c ) => new DigestService(
				$c->get( NotificationRepository::class ),
				$c->get( EmailChannel::class ),
				$c->get( EmailTemplate::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton(
			NotificationDispatcher::class,
			static fn( Container $c ) => new NotificationDispatcher(
				array(
					$c->get( OnSiteChannel::class ),
					$c->get( EmailChannel::class ),
					$c->get( WebhookChannel::class ),
					$c->get( SmsChannel::class ),
					$c->get( PushChannel::class ),
					$c->get( WhatsAppChannel::class ),
				),
				$c->get( TemplateRenderer::class ),
				$c->get( NotificationRepository::class ),
				$c->get( NotificationPreferences::class ),
				$c->get( DigestService::class ),
				$c->get( Settings::class )
			)
		);

		$c->singleton( NotificationSubscriber::class, static fn( Container $c ) => new NotificationSubscriber( $c->get( NotificationDispatcher::class ), $c ) );
		$c->singleton( NotificationScheduler::class, static fn( Container $c ) => new NotificationScheduler( $c->get( Scheduler::class ), $c->get( DigestService::class ) ) );
		$c->singleton( NotificationsAdminPage::class, static fn( Container $c ) => new NotificationsAdminPage( $c->get( Assets::class ) ) );

		// Broadcast fan-out + campaign broadcasts.
		$c->singleton( NotificationBroadcaster::class, static fn( Container $c ) => new NotificationBroadcaster( $c->get( NotificationDispatcher::class ), $c->get( Scheduler::class ) ) );
		$c->singleton( CampaignNotificationSubscriber::class, static fn( Container $c ) => new CampaignNotificationSubscriber( $c, $c->get( NotificationBroadcaster::class ) ) );
		$c->singleton( CampaignEndingScanner::class, static fn( Container $c ) => new CampaignEndingScanner( $c, $c->get( NotificationBroadcaster::class ), $c->get( Scheduler::class ), $c->get( Settings::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'notification' ) ) {
			return;
		}
		$c->get( EventBus::class )->subscribe( $c->get( NotificationSubscriber::class ) );

		$hooks = $c->get( HookLoader::class );
		$hooks->register( $c->get( NotificationScheduler::class ) );
		$hooks->register( $c->get( NotificationsAdminPage::class ) );
		$hooks->register( $c->get( NotificationBroadcaster::class ) ); // Async chunk handler.

		// Campaign-ending scanner: registered unconditionally so its `init` hook can
		// self-schedule (it gates on the marketing feature + lead-time internally).
		$hooks->register( $c->get( CampaignEndingScanner::class ) );

		// New-campaign broadcast — only when the marketing engine is on.
		if ( $c->get( Settings::class )->feature_enabled( 'marketing' ) ) {
			$c->get( EventBus::class )->subscribe( $c->get( CampaignNotificationSubscriber::class ) );
		}

		$rest = $c->get( RestBootstrap::class );
		$rest->add( static fn( Container $c ) => new NotificationsController( $c ) );
		$rest->add( static fn( Container $c ) => new NotificationsAdminController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'notifications';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				channel varchar(20) NOT NULL DEFAULT '',
				template varchar(40) NOT NULL DEFAULT '',
				category varchar(30) NOT NULL DEFAULT '',
				priority varchar(10) NOT NULL DEFAULT 'normal',
				payload longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'sent',
				read_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				scheduled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY user_channel (user_id,channel),
				KEY status (status),
				KEY queue (channel,status,scheduled_at)
			) {$charset_collate};",
		);
	}
}
