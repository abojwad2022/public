<?php
/**
 * Live verification for the Notifications engine (v1.9.0).
 *
 * Run with Local's PHP + the site php.ini:
 *   php.exe -c <run>/conf/php/php.ini -d opcache.enable_cli=0 -d zend.multibyte=0 \
 *           wp-content/plugins/yazan-social-rewards/scratchpad/verify-notifications.php
 *
 * Snapshots and restores the settings option + webhook secret, and deletes the
 * temp user + its notification rows, so the live store is left as it was found.
 */

// phpcs:disable

$root = dirname( __DIR__, 4 );
// Dev/verification script — must only run under CLI, never web-served.
if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Channels\EmailChannel;
use Yazan\Rewards\Modules\Notification\Channels\WebhookChannel;
use Yazan\Rewards\Modules\Notification\DigestService;
use Yazan\Rewards\Modules\Notification\EmailTemplate;
use Yazan\Rewards\Modules\Notification\NotificationDispatcher;
use Yazan\Rewards\Modules\Notification\NotificationPreferences;
use Yazan\Rewards\Modules\Notification\NotificationRepository;
use Yazan\Rewards\Rest\V1\NotificationsAdminController;
use Yazan\Rewards\Rest\V1\NotificationsController;

$pass = 0; $fail = 0;
function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}

echo "=== Yazan Notifications — verification (1.9.0) ===\n\n";

$c        = Plugin::instance()->container();
$settings = $c->get( Settings::class );
global $wpdb;

// Snapshot mutable state for restoration.
$orig_settings = get_option( 'yazan_rewards_settings' );
$orig_secret   = get_option( WebhookChannel::SECRET_OPTION );

echo "[1] Autoload + container wiring\n";
foreach ( array(
	EmailTemplate::class, NotificationPreferences::class, DigestService::class,
	NotificationDispatcher::class, WebhookChannel::class, EmailChannel::class,
	NotificationsAdminController::class, \Yazan\Rewards\Admin\NotificationsAdminPage::class,
	\Yazan\Rewards\Modules\Notification\NotificationScheduler::class,
) as $cls ) {
	ok( "class autoloads: " . $cls, class_exists( $cls ) || interface_exists( $cls ) );
}
ok( 'container has NotificationDispatcher', $c->has( NotificationDispatcher::class ) );
ok( 'WebhookChannel is an IntegrationChannel', $c->get( WebhookChannel::class ) instanceof \Yazan\Rewards\Core\Contracts\IntegrationChannelInterface );

echo "\n[2] Schema migrated to 1.9.0 (category + priority cols)\n";
ok( 'Schema::VERSION is >= 1.9.0', version_compare( \Yazan\Rewards\Core\Install\Schema::VERSION, '1.9.0', '>=' ) );
$table = $wpdb->prefix . 'yazan_rw_notifications';
$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
ok( 'notifications.category column exists', in_array( 'category', (array) $cols, true ) );
ok( 'notifications.priority column exists', in_array( 'priority', (array) $cols, true ) );

echo "\n[3] Preferences model\n";
$prefs = $c->get( NotificationPreferences::class );
$cats  = $prefs->categories();
ok( 'has >= 7 categories', count( $cats ) >= 7 );
ok( 'service is required', ! empty( $cats['service']['required'] ) );
ok( 'reward is required', ! empty( $cats['reward']['required'] ) );
ok( 'campaign is not required', empty( $cats['campaign']['required'] ) );
ok( 'reward_redeemed → reward category', $prefs->category_for_template( 'reward_redeemed' ) === 'reward' );
ok( 'reward_redeemed → urgent', $prefs->priority_for_template( 'reward_redeemed' ) === 'urgent' );
ok( 'campaign_completed → campaign/normal', $prefs->category_for_template( 'campaign_completed' ) === 'campaign' && $prefs->priority_for_template( 'campaign_completed' ) === 'normal' );
ok( 'unknown template → system', $prefs->category_for_template( 'nope_xyz' ) === 'system' );

// Temp user for preference + dispatch tests.
$uid = wp_insert_user( array(
	'user_login' => 'yzrw_verify_' . wp_generate_password( 6, false ),
	'user_email' => 'yzrw_verify_' . wp_generate_password( 6, false ) . '@example.test',
	'user_pass'  => wp_generate_password(),
	'role'       => 'customer',
) );
$uid = is_wp_error( $uid ) ? 0 : (int) $uid;
ok( 'temp customer created', $uid > 0 );

$prefs->set( $uid, array(
	'campaign'    => array( 'email' => 'digest', 'onsite' => true ),
	'achievement' => array( 'email' => 'off', 'onsite' => true ),
	'service'     => array( 'email' => 'off', 'onsite' => false ), // required → must be forced on.
) );
ok( 'required service email forced immediate', $prefs->email_mode( $uid, 'service' ) === 'immediate' );
ok( 'required service onsite forced on', $prefs->allows( $uid, 'service', 'onsite' ) === true );
ok( 'campaign email mode = digest', $prefs->email_mode( $uid, 'campaign' ) === 'digest' );
ok( 'achievement email disallowed (off)', $prefs->allows( $uid, 'achievement', 'email' ) === false );

echo "\n[4] Dispatch policy (verified via the outbox log)\n";
$dispatcher = $c->get( NotificationDispatcher::class );
$repo       = $c->get( NotificationRepository::class );

function rows_for( $wpdb, $table, $uid, $channel ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY id DESC", $uid, $channel ) );
}

$dispatcher->notify( $uid, 'campaign_completed', array( 'bonus' => 10 ) );
$dispatcher->notify( $uid, 'achievement_unlocked', array( 'name' => 'First Post', 'points_award' => 5 ) );
$dispatcher->notify( $uid, 'reward_redeemed', array() );

$email_rows  = rows_for( $wpdb, $table, $uid, 'email' );
$onsite_rows = rows_for( $wpdb, $table, $uid, 'onsite' );

$campaign_email = array_values( array_filter( $email_rows, static fn( $r ) => 'campaign_completed' === $r->template ) );
$ach_email      = array_values( array_filter( $email_rows, static fn( $r ) => 'achievement_unlocked' === $r->template ) );
$reward_email   = array_values( array_filter( $email_rows, static fn( $r ) => 'reward_redeemed' === $r->template ) );

ok( 'campaign email QUEUED (digest pref)', ! empty( $campaign_email ) && 'queued' === $campaign_email[0]->status );
ok( 'campaign email scheduled_at set', ! empty( $campaign_email ) && $campaign_email[0]->scheduled_at > '0000-00-00 00:00:00' );
ok( 'achievement email suppressed (off)', empty( $ach_email ) );
ok( 'reward email NOT queued (urgent, required)', ! empty( $reward_email ) && 'queued' !== $reward_email[0]->status );
ok( 'onsite delivered for all 3 (achievement onsite on)', count( $onsite_rows ) >= 3 );
ok( 'onsite rows carry category', ! empty( $onsite_rows ) && '' !== (string) $onsite_rows[0]->category );

echo "\n[5] Digest flush (run_due marks a past-due queued row sent)\n";
$repo->log( array(
	'user_id' => $uid, 'channel' => 'email', 'template' => 'custom', 'category' => 'system',
	'priority' => 'normal', 'status' => 'queued', 'scheduled_at' => '2000-01-01 00:00:00',
	'payload' => array( 'subject' => 'Past due', 'body' => 'body' ),
) );
$digest = $c->get( DigestService::class );
$sent   = $digest->run_due();
ok( 'run_due() sent >= 1 digest', $sent >= 1 );
$still_due = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'queued' AND scheduled_at = '2000-01-01 00:00:00'", $uid ) );
ok( 'past-due queued row no longer queued', 0 === (int) $still_due );

echo "\n[6] Webhook channel guard (https-only, enabled logic)\n";
$webhook = $c->get( WebhookChannel::class );
ok( 'disabled by default (no url)', ! $webhook->enabled() );
$all = $settings->all();
$all['notification']['webhook'] = array( 'enabled' => true, 'url' => 'http://insecure.example/hook' );
$settings->save( $all );
ok( 'http url rejected → still disabled', ! $webhook->enabled() );
$all['notification']['webhook']['url'] = 'https://secure.example/hook';
$settings->save( $all );
ok( 'https url → enabled', $webhook->enabled() );
// Restore so no dispatch below hits an external URL.
$all['notification']['webhook'] = array( 'enabled' => false, 'url' => '' );
$settings->save( $all );

echo "\n[7] Email template (branded HTML + escaping)\n";
$tpl  = $c->get( EmailTemplate::class );
$html = $tpl->wrap( 'Hello <b>World</b>', $tpl->paragraph( 'Body text' ), array( 'cta_url' => 'https://x.test/', 'cta_label' => 'Go' ) );
ok( 'wrap() returns a full HTML document', str_contains( $html, '<html' ) && str_contains( $html, '</html>' ) );
ok( 'subject is escaped in output', str_contains( $html, 'Hello &lt;b&gt;World&lt;/b&gt;' ) );
ok( 'CTA rendered', str_contains( $html, 'https://x.test/' ) && str_contains( $html, '>Go</a>' ) );
$list = $tpl->items_list( array( array( 'subject' => '<x>', 'body' => 'b' ) ) );
ok( 'items_list escapes subject', str_contains( $list, '&lt;x&gt;' ) );

echo "\n[8] REST: admin cap-gating + settings shape + https validation\n";
$admin = new NotificationsAdminController( $c );
ok( 'guest cannot manage', ! current_user_can( Capabilities::MANAGE ) );
$get = $admin->get_settings()->get_data();
ok( 'get_settings shape', isset( $get['channels'], $get['digest'], $get['categories'] ) );
ok( 'webhook secret exposed only as last4', array_key_exists( 'secret_last4', (array) $get['channels']['webhook'] ) && ! isset( $get['channels']['webhook']['secret'] ) );

$req = new WP_REST_Request( 'POST' );
$req->set_param( 'channels', array( 'webhook' => array( 'url' => 'http://nope' ) ) );
$bad = $admin->save_settings( $req );
ok( 'save rejects non-https webhook url (WP_Error 400)', is_wp_error( $bad ) && 400 === (int) $bad->get_error_data()['status'] );

$req2 = new WP_REST_Request( 'POST' );
$req2->set_param( 'channels', array( 'webhook' => array( 'secret' => 'supersecret123' ) ) );
$admin->save_settings( $req2 );
$after = $admin->get_settings()->get_data();
ok( 'secret saved, exposed as last4 only', 't123' === $after['channels']['webhook']['secret_last4'] );

echo "\n[9] REST: customer preferences payload\n";
wp_set_current_user( $uid );
$cust = new NotificationsController( $c );
$pl   = $cust->get_preferences()->get_data();
ok( 'preferences payload has categories', isset( $pl['categories'] ) && count( $pl['categories'] ) >= 7 );
$first = $pl['categories'][0];
ok( 'category row shape', isset( $first['key'], $first['label'], $first['required'], $first['email'], $first['onsite'] ) );
wp_set_current_user( 0 );

/* ---- cleanup ---- */
$wpdb->delete( $table, array( 'user_id' => $uid ), array( '%d' ) );
if ( $uid > 0 ) { wp_delete_user( $uid ); }
if ( false === $orig_settings ) { delete_option( 'yazan_rewards_settings' ); } else { update_option( 'yazan_rewards_settings', $orig_settings, false ); }
if ( false === $orig_secret ) { delete_option( WebhookChannel::SECRET_OPTION ); } else { update_option( WebhookChannel::SECRET_OPTION, $orig_secret, false ); }
echo "\n(cleaned up temp user #{$uid} + its rows; restored settings + secret)\n";

echo "\n=== $pass passed, $fail failed ===\n";
