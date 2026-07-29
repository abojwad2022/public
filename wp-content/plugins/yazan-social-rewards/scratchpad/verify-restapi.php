<?php
/**
 * Live verification for the public REST API + developer hooks (v1.11.0).
 *
 * Run with Local's PHP + the site php.ini:
 *   php.exe -c <run>/conf/php/php.ini -d opcache.enable_cli=0 -d zend.multibyte=0 \
 *           wp-content/plugins/yazan-social-rewards/scratchpad/verify-restapi.php
 *
 * Creates a temp customer + temp rewards, exercises the yazan/v1 routes through the
 * REST server, and checks the four yazan_register_*() hooks. Cleans up after itself.
 */

// phpcs:disable

$root = dirname( __DIR__, 4 );
// Dev/verification script — must only run under CLI, never web-served.
if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Points\PointsLedger;
use Yazan\Rewards\Modules\Rewards\CouponIssuer;
use Yazan\Rewards\Modules\Rewards\RedemptionService;
use Yazan\Rewards\Modules\Rewards\Reward;
use Yazan\Rewards\Modules\Rewards\RewardIssuerInterface;
use Yazan\Rewards\Modules\Rewards\RewardProviderRegistry;
use Yazan\Rewards\Modules\Rewards\RewardRepository;
use Yazan\Rewards\Modules\Rewards\ServiceIssuer;
use Yazan\Rewards\Modules\Rewards\WalletIssuer;
use Yazan\Rewards\Modules\Rules\EventCatalog;

$pass = 0; $fail = 0;
function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}

echo "=== Yazan public REST API + dev hooks v1.11.0 — verification ===\n\n";

$c        = Plugin::instance()->container();
$settings = $c->get( Settings::class );
global $wpdb;

echo "[1] Version + module + feature flag\n";
ok( 'Schema::VERSION is 1.11.0', \Yazan\Rewards\Core\Install\Schema::VERSION === '1.11.0' );
ok( 'PUBLIC_REST_NS is yazan/v1', Plugin::PUBLIC_REST_NS === 'yazan/v1' );
$module_ids = array_map( static fn( $m ) => $m->id(), Plugin::instance()->modules() );
ok( 'public_api module registered', in_array( 'public_api', $module_ids, true ) );
ok( 'public_api feature enabled by default', $settings->feature_enabled( 'public_api' ) );

echo "\n[2] Reward provider seam (interface + registry + redemption)\n";
ok( 'WalletIssuer implements RewardIssuerInterface', $c->get( WalletIssuer::class ) instanceof RewardIssuerInterface );
ok( 'CouponIssuer implements RewardIssuerInterface', $c->get( CouponIssuer::class ) instanceof RewardIssuerInterface );
ok( 'ServiceIssuer implements RewardIssuerInterface', $c->get( ServiceIssuer::class ) instanceof RewardIssuerInterface );

$registry = $c->get( RewardProviderRegistry::class );
ok( 'registry: credit → WalletIssuer', $registry->for_type( 'credit' ) instanceof WalletIssuer );
ok( 'registry: coupon → CouponIssuer', $registry->for_type( 'coupon' ) instanceof CouponIssuer );
ok( 'registry: free_shipping → CouponIssuer', $registry->for_type( 'free_shipping' ) instanceof CouponIssuer );
ok( 'registry: vip_service → ServiceIssuer', $registry->for_type( 'vip_service' ) instanceof ServiceIssuer );
ok( 'registry has all 8 built-in types', count( array_intersect( $registry->types(), array( 'credit', 'coupon', 'free_shipping', 'free_product', 'vip_service', 'ring_cleaning', 'ring_polishing', 'exclusive_product' ) ) ) === 8 );
ok( 'registry: unknown type → null', null === $registry->for_type( 'no_such_type' ) );

// Temp customer + rewards for a real redemption.
$uid = wp_insert_user( array(
	'user_login' => 'yzrw_api_' . wp_generate_password( 6, false ),
	'user_email' => 'yzrw_api_' . wp_generate_password( 6, false ) . '@example.test',
	'user_pass'  => wp_generate_password(),
	'role'       => 'customer',
) );
$uid = is_wp_error( $uid ) ? 0 : (int) $uid;

$rewards_repo = $c->get( RewardRepository::class );
$credit_reward = $rewards_repo->create( array( 'type' => 'credit', 'title' => 'Verify Credit', 'cost_points' => 10, 'value' => array( 'amount' => 1 ), 'active' => 1, 'stock' => -1 ) );
$bogus_reward  = $rewards_repo->create( array( 'type' => 'bogus_zzz', 'title' => 'Verify Bogus', 'cost_points' => 10, 'value' => array(), 'active' => 1, 'stock' => -1 ) );

$ledger = $c->get( PointsLedger::class );
$ledger->credit( $uid, 100, 'signup', 0 );
$redeem = $c->get( RedemptionService::class );
echo "      (reward ids: credit #{$credit_reward}, bogus #{$bogus_reward})\n";

$bal_before = (int) $ledger->balance( $uid );
$r1 = $redeem->redeem( $uid, $credit_reward );
ok( 'credit reward redeems OK via registry (no regression)', is_array( $r1 ) && ! empty( $r1['ok'] ) && 'wallet' === ( $r1['result_type'] ?? '' ) );
$bal_after = (int) $ledger->balance( $uid );
echo "      (balance {$bal_before} → {$bal_after})\n";
ok( 'points debited by exactly the cost (−10)', $bal_after === $bal_before - 10 );

$bal_pre_bogus = (int) $ledger->balance( $uid );
$r2 = $redeem->redeem( $uid, $bogus_reward );
$r2_code = is_wp_error( $r2 ) ? $r2->get_error_code() : ( 'array:' . wp_json_encode( $r2 ) );
echo "      (bogus redeem result: {$r2_code})\n";
ok( 'unknown reward type → unsupported_reward WP_Error', is_wp_error( $r2 ) && 'unsupported_reward' === $r2->get_error_code() );
ok( 'no points debited for unsupported type', (int) $ledger->balance( $uid ) === $bal_pre_bogus );

echo "\n[3] yazan/v1 routes registered\n";
$server = rest_get_server(); // triggers rest_api_init → RestBootstrap::register.
$routes = array_keys( $server->get_routes() );
foreach ( array(
	'/yazan/v1/customer/points', '/yazan/v1/customer/profile',
	'/yazan/v1/campaign/submit', '/yazan/v1/reward/redeem', '/yazan/v1/statistics',
) as $route ) {
	ok( "route exists: {$route}", in_array( $route, $routes, true ) );
}

echo "\n[4] Endpoint dispatch + auth\n";
wp_set_current_user( $uid );
$resp = $server->dispatch( new WP_REST_Request( 'GET', '/yazan/v1/customer/points' ) );
$data = $resp->get_data();
ok( 'GET /customer/points 200 + shape', 200 === $resp->get_status() && isset( $data['balance'], $data['label'], $data['wallet'] ) );

$resp = $server->dispatch( new WP_REST_Request( 'GET', '/yazan/v1/customer/profile' ) );
$data = $resp->get_data();
ok( 'GET /customer/profile 200 + shape', 200 === $resp->get_status() && isset( $data['points'], $data['tier'], $data['ranking'] ) );

$resp = $server->dispatch( new WP_REST_Request( 'GET', '/yazan/v1/statistics' ) );
ok( 'GET /statistics as customer → 403 (needs VIEW cap)', 403 === $resp->get_status() );

$req = new WP_REST_Request( 'POST', '/yazan/v1/reward/redeem' );
$req->set_param( 'reward_id', 99999999 );
$resp = $server->dispatch( $req );
ok( 'POST /reward/redeem wires to service (unavailable → 404)', 404 === $resp->get_status() );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admins ) ) {
	wp_set_current_user( (int) $admins[0] );
	$resp = $server->dispatch( new WP_REST_Request( 'GET', '/yazan/v1/statistics' ) );
	$data = $resp->get_data();
	ok( 'GET /statistics as admin → 200 + analytics shape', 200 === $resp->get_status() && isset( $data['customers'], $data['campaigns'], $data['business'] ) );
} else {
	ok( 'GET /statistics as admin (no admin user found — skipped)', true );
}
wp_set_current_user( 0 );

echo "\n[5] Developer registration hooks\n";
foreach ( array( 'yazan_register_module', 'yazan_register_connector', 'yazan_register_reward_provider', 'yazan_register_event' ) as $fn ) {
	ok( "function exists: {$fn}", function_exists( $fn ) );
}
yazan_register_module( 'Acme\\Demo\\DemoModule' );
ok( 'yazan_register_module adds to the modules filter', in_array( 'Acme\\Demo\\DemoModule', (array) apply_filters( 'yazan_rewards/modules', array() ), true ) );

yazan_register_connector( 'Acme\\Demo\\DemoConnector' );
ok( 'yazan_register_connector adds to the connectors filter', in_array( 'Acme\\Demo\\DemoConnector', (array) apply_filters( 'yazan_rewards/social/connectors', array() ), true ) );

yazan_register_event( 'unit_test_event', array( 'label' => 'Unit Test Event' ) );
ok( 'yazan_register_event adds the event to the rules catalog', ( new EventCatalog() )->has( 'unit_test_event' ) );

$stub = new class() implements RewardIssuerInterface {
	public function issue( int $user_id, Reward $reward ): array { return array( 'ok' => true, 'type' => 'gift', 'ref' => 'x' ); }
};
yazan_register_reward_provider( 'unit_gift', $stub );
$fresh = apply_filters( 'yazan_rewards/rewards/providers', new RewardProviderRegistry() );
ok( 'yazan_register_reward_provider registers a custom type', $fresh instanceof RewardProviderRegistry && $fresh->for_type( 'unit_gift' ) === $stub );

/* ---- cleanup ---- */
$wpdb->delete( $wpdb->prefix . 'yazan_rw_notifications', array( 'user_id' => $uid ), array( '%d' ) );
$wpdb->delete( $wpdb->prefix . 'yazan_rw_points_ledger', array( 'user_id' => $uid ), array( '%d' ) );
$wpdb->delete( $wpdb->prefix . 'yazan_rw_redemptions', array( 'user_id' => $uid ), array( '%d' ) );
foreach ( array( $credit_reward, $bogus_reward ) as $rid ) {
	if ( $rid ) { $wpdb->delete( $wpdb->prefix . 'yazan_rw_rewards', array( 'id' => $rid ), array( '%d' ) ); }
}
if ( $uid > 0 ) { wp_delete_user( $uid ); }
echo "\n(cleaned up temp user #{$uid} + temp rewards #{$credit_reward}/#{$bogus_reward})\n";

echo "\n=== $pass passed, $fail failed ===\n";
