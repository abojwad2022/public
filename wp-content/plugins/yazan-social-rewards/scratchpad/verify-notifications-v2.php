<?php
/**
 * Live verification for the Notification System extension (v1.10.0).
 *
 * Run with Local's PHP + the site php.ini:
 *   php.exe -c <run>/conf/php/php.ini -d opcache.enable_cli=0 -d zend.multibyte=0 \
 *           wp-content/plugins/yazan-social-rewards/scratchpad/verify-notifications-v2.php
 *
 * Uses a temp user + a `users`-targeted temp campaign (so a broadcast reaches ONLY
 * the temp user, never real customers), and snapshots/restores settings + the
 * campaign-notified options. Cleans up everything it creates.
 */

// phpcs:disable

$root = dirname( __DIR__, 4 );
// Dev/verification script — must only run under CLI, never web-served.
if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Achievement\TierRepository;
use Yazan\Rewards\Modules\Campaigns\CampaignEligibility;
use Yazan\Rewards\Modules\Campaigns\MarketingCampaignRepository;
use Yazan\Rewards\Modules\Notification\CampaignEndingScanner;
use Yazan\Rewards\Modules\Notification\CampaignNotificationSubscriber;
use Yazan\Rewards\Modules\Notification\Channels\PushChannel;
use Yazan\Rewards\Modules\Notification\Channels\SmsChannel;
use Yazan\Rewards\Modules\Notification\Channels\WhatsAppChannel;
use Yazan\Rewards\Modules\Notification\NotificationBroadcaster;
use Yazan\Rewards\Modules\Notification\NotificationDispatcher;
use Yazan\Rewards\Modules\Notification\NotificationPreferences;
use Yazan\Rewards\Modules\Notification\NotificationSubscriber;
use Yazan\Rewards\Modules\Notification\Providers\SmsProviderInterface;
use Yazan\Rewards\Modules\Notification\TemplateRenderer;
use Yazan\Rewards\Modules\Points\ExpiryReminderService;
use Yazan\Rewards\Modules\Points\PointsLedger;
use Yazan\Rewards\Rest\V1\NotificationsAdminController;

$pass = 0; $fail = 0;
function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}

echo "=== Yazan Notification System v1.10.0 — verification ===\n\n";

$c        = Plugin::instance()->container();
$settings = $c->get( Settings::class );
global $wpdb;
$notif  = $wpdb->prefix . 'yazan_rw_notifications';
$ledger = $wpdb->prefix . 'yazan_rw_points_ledger';
$camp   = $wpdb->prefix . 'yazan_rw_marketing_campaigns';

$ncount = static function ( $uid, $channel, $template ) use ( $wpdb, $notif ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif} WHERE user_id = %d AND channel = %s AND template = %s", $uid, $channel, $template ) );
};

$orig_settings = get_option( 'yazan_rewards_settings' );
$orig_new      = get_option( 'yazan_rewards_campaign_new_notified' );
$orig_end      = get_option( 'yazan_rewards_campaign_ending_notified' );

$uid = wp_insert_user( array(
	'user_login' => 'yzrw_nv2_' . wp_generate_password( 6, false ),
	'user_email' => 'yzrw_nv2_' . wp_generate_password( 6, false ) . '@example.test',
	'user_pass'  => wp_generate_password(),
	'role'       => 'customer',
) );
$uid = is_wp_error( $uid ) ? 0 : (int) $uid;

echo "[1] Future-ready channels (dormant + provider seam)\n";
$sms = $c->get( SmsChannel::class );
ok( 'SMS disabled by default', ! $sms->enabled() );
ok( 'Push disabled by default', ! $c->get( PushChannel::class )->enabled() );
ok( 'WhatsApp disabled by default', ! $c->get( WhatsAppChannel::class )->enabled() );

$disp = $c->get( NotificationDispatcher::class );
$ref  = new ReflectionProperty( NotificationDispatcher::class, 'channels' );
$ref->setAccessible( true );
$ids  = array_map( static fn( $ch ) => $ch->id(), $ref->getValue( $disp ) );
ok( 'dispatcher wires sms/push/whatsapp', ! array_diff( array( 'sms', 'push', 'whatsapp' ), $ids ) );

$stub = new class() implements SmsProviderInterface {
	public function id(): string { return 'stub'; }
	public function configured(): bool { return true; }
	public function send( string $to, array $message ): bool { return true; }
};
$provider_cb  = static function () use ( $stub ) { return $stub; };
$recipient_cb = static function () { return '+10000000000'; };
add_filter( 'yazan_rewards/notification/sms_provider', $provider_cb );
$all = $settings->all(); $all['notification']['sms']['enabled'] = true; $settings->save( $all );
ok( 'SMS activates once provider + toggle present', $sms->enabled() );
add_filter( 'yazan_rewards/notification/sms_recipient', $recipient_cb );
ok( 'SMS send delegates to the provider', $sms->send( $uid, array( 'subject' => 'x', 'body' => 'y' ) ) );
remove_filter( 'yazan_rewards/notification/sms_provider', $provider_cb );
remove_filter( 'yazan_rewards/notification/sms_recipient', $recipient_cb );
$all = $settings->all(); $all['notification']['sms']['enabled'] = false; $settings->save( $all );

echo "\n[2] Points category (+ digest default)\n";
$prefs = $c->get( NotificationPreferences::class );
$cats  = $prefs->categories();
ok( 'points category exists', isset( $cats['points'] ) );
ok( 'points default_email = digest', ( $cats['points']['default_email'] ?? '' ) === 'digest' );
ok( 'fresh user points email mode = digest', $prefs->email_mode( $uid, 'points' ) === 'digest' );

echo "\n[3] Points earned (excludes redemption refunds)\n";
$sub = $c->get( NotificationSubscriber::class );
$sub->on_points_credited( new GenericEvent( Events::POINTS_CREDITED, array( 'user_id' => $uid, 'points' => 100, 'source' => 'order' ) ) );
ok( 'points_earned delivered on-site', $ncount( $uid, 'onsite', 'points_earned' ) >= 1 );
$q = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notif} WHERE user_id = %d AND channel = 'email' AND template = 'points_earned' AND status = 'queued'", $uid ) );
ok( 'points_earned email QUEUED (digest default)', $q >= 1 );
$before = $ncount( $uid, 'onsite', 'points_earned' );
$sub->on_points_credited( new GenericEvent( Events::POINTS_CREDITED, array( 'user_id' => $uid, 'points' => 50, 'source' => 'redeem' ) ) );
ok( 'redemption refund (source=redeem) excluded', $ncount( $uid, 'onsite', 'points_earned' ) === $before );

echo "\n[4] Points expiring (scan) + expired\n";
$c->get( PointsLedger::class )->credit( $uid, 300, 'order', 0 );
$wpdb->query( $wpdb->prepare( "UPDATE {$ledger} SET expires_at = %s WHERE user_id = %d AND status = 'approved'", gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), $uid ) );
$c->get( ExpiryReminderService::class )->run();
ok( 'points_expiring delivered', $ncount( $uid, 'onsite', 'points_expiring' ) >= 1 );
ok( 'reminder dedup meta set', '' !== (string) get_user_meta( $uid, '_yzrw_expiry_reminded', true ) );
$before = $ncount( $uid, 'onsite', 'points_expiring' );
$c->get( ExpiryReminderService::class )->run();
ok( 'reminder deduped on second run', $ncount( $uid, 'onsite', 'points_expiring' ) === $before );
$sub->on_points_expired( new GenericEvent( Events::POINTS_EXPIRED, array( 'user_id' => $uid, 'balance_after' => 0 ) ) );
ok( 'points_expired delivered', $ncount( $uid, 'onsite', 'points_expired' ) >= 1 );

echo "\n[5] Campaign broadcasts (audience + new + ending)\n";
$elig = $c->get( CampaignEligibility::class );
ok( 'audience(users) enumerates the target', in_array( $uid, $elig->audience( array( 'type' => 'users', 'values' => array( (string) $uid ) ) ), true ) );

$cid = $c->get( MarketingCampaignRepository::class )->create( array(
	'title'   => 'Verify Ending',
	'status'  => 'active',
	'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ),
	'target'  => array( 'type' => 'users', 'values' => array( (string) $uid ) ),
) );

$c->get( NotificationBroadcaster::class )->run_chunk( array( 'users' => array( $uid ), 'template' => 'campaign_new', 'payload' => array( 'campaign_id' => $cid, 'title' => 'Verify Ending' ) ) );
ok( 'broadcaster delivers campaign_new', $ncount( $uid, 'onsite', 'campaign_new' ) >= 1 );

$cns = $c->get( CampaignNotificationSubscriber::class );
$cns->on_status_changed( new GenericEvent( Events::CAMPAIGN_STATUS_CHANGED, array( 'campaign_id' => $cid, 'from' => 'scheduled', 'to' => 'active' ) ) );
$new_opt = array_map( 'intval', (array) get_option( 'yazan_rewards_campaign_new_notified' ) );
ok( 'new-campaign broadcast recorded (dedup)', count( array_filter( $new_opt, static fn( $x ) => $x === (int) $cid ) ) === 1 );
$cns->on_status_changed( new GenericEvent( Events::CAMPAIGN_STATUS_CHANGED, array( 'campaign_id' => $cid, 'from' => 'paused', 'to' => 'active' ) ) );
$new_opt = array_map( 'intval', (array) get_option( 'yazan_rewards_campaign_new_notified' ) );
ok( 'new-campaign not re-broadcast on re-activation', count( array_filter( $new_opt, static fn( $x ) => $x === (int) $cid ) ) === 1 );

$scanner = $c->get( CampaignEndingScanner::class );
$scanner->run();
$end_opt = array_map( 'intval', (array) get_option( 'yazan_rewards_campaign_ending_notified' ) );
ok( 'ending scan picks the campaign ending in 2 days', in_array( (int) $cid, $end_opt, true ) );
$scanner->run();
$end_opt = array_map( 'intval', (array) get_option( 'yazan_rewards_campaign_ending_notified' ) );
ok( 'ending scan deduped on second run', count( array_filter( $end_opt, static fn( $x ) => $x === (int) $cid ) ) === 1 );

echo "\n[6] Level upgrade only (not downgrade)\n";
$tiers = $c->has( TierRepository::class ) ? $c->get( TierRepository::class )->all() : array();
if ( count( $tiers ) >= 2 ) {
	$low  = (string) $tiers[0]->slug;
	$high = (string) $tiers[ count( $tiers ) - 1 ]->slug;
	$sub->on_tier( new GenericEvent( Events::TIER_CHANGED, array( 'user_id' => $uid, 'from' => $low, 'to' => $high, 'tier' => array( 'name' => 'High' ) ) ) );
	ok( 'upgrade notifies', $ncount( $uid, 'onsite', 'tier_changed' ) >= 1 );
	$before = $ncount( $uid, 'onsite', 'tier_changed' );
	$sub->on_tier( new GenericEvent( Events::TIER_CHANGED, array( 'user_id' => $uid, 'from' => $high, 'to' => $low, 'tier' => array( 'name' => 'Low' ) ) ) );
	ok( 'downgrade suppressed', $ncount( $uid, 'onsite', 'tier_changed' ) === $before );
} else {
	ok( 'tiers available for upgrade test', false );
	ok( 'tiers available for downgrade test', false );
}

echo "\n[7] REST admin — future block, lead time, persistence, cap-gating\n";
$admin = new NotificationsAdminController( $c );
$get   = $admin->get_settings()->get_data();
ok( 'future block present', isset( $get['future']['sms'], $get['future']['push'], $get['future']['whatsapp'] ) );
ok( 'campaign_ending_days present', isset( $get['campaign_ending_days'] ) );
$cat_keys = array_map( static fn( $r ) => $r['key'], (array) $get['categories'] );
ok( 'points category surfaced to admin', in_array( 'points', $cat_keys, true ) );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'campaign_ending_days', 5 );
$req->set_param( 'future', array( 'sms' => array( 'enabled' => true ) ) );
$admin->save_settings( $req );
$after = $admin->get_settings()->get_data();
ok( 'save persists campaign_ending_days', 5 === (int) $after['campaign_ending_days'] );
ok( 'save persists sms enable toggle', true === (bool) $after['future']['sms']['enabled'] );
ok( 'guest cannot manage', ! current_user_can( Capabilities::MANAGE ) );

echo "\n[8] New templates render\n";
$tr = $c->get( TemplateRenderer::class );
foreach ( array(
	'points_earned'   => array( 'points' => 50 ),
	'points_expiring' => array( 'points' => 50, 'days' => 7 ),
	'points_expired'  => array(),
	'campaign_new'    => array( 'title' => 'Ramadan' ),
	'campaign_ending' => array( 'title' => 'Ramadan' ),
) as $t => $p ) {
	$m = $tr->render( $t, $p );
	ok( "template {$t} renders subject + body", '' !== (string) ( $m['subject'] ?? '' ) && '' !== (string) ( $m['body'] ?? '' ) );
}

/* ---- cleanup ---- */
$wpdb->delete( $notif, array( 'user_id' => $uid ), array( '%d' ) );
$wpdb->delete( $ledger, array( 'user_id' => $uid ), array( '%d' ) );
if ( $cid ) { $wpdb->delete( $camp, array( 'id' => $cid ), array( '%d' ) ); }
if ( $uid > 0 ) { wp_delete_user( $uid ); }
if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'yzrw_notification_broadcast_chunk' ); }
foreach ( array( 'yazan_rewards_campaign_new_notified' => $orig_new, 'yazan_rewards_campaign_ending_notified' => $orig_end ) as $opt => $val ) {
	if ( false === $val ) { delete_option( $opt ); } else { update_option( $opt, $val, false ); }
}
if ( false === $orig_settings ) { delete_option( 'yazan_rewards_settings' ); } else { update_option( 'yazan_rewards_settings', $orig_settings, false ); }
echo "\n(cleaned up temp user #{$uid}, campaign #{$cid}; restored settings + options)\n";

echo "\n=== $pass passed, $fail failed ===\n";
