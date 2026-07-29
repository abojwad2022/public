<?php
/**
 * Read-only verification for the Analytics Dashboard.
 *
 * Run with Local's PHP + the site php.ini so wp-config's localhost resolves to
 * the Local MySQL port:
 *   php.exe -c <run>/conf/php/php.ini -d opcache.enable_cli=0 -d zend.multibyte=0 \
 *           wp-content/plugins/yazan-social-rewards/scratchpad/verify-analytics.php
 *
 * Asserts structure/types (safe on an empty store), the new repo aggregates run
 * without SQL error, the order aggregate is transient-cached, and the CSV export
 * neutralises formula injection. It writes nothing to core tables.
 */

// phpcs:disable

$root = dirname( __DIR__, 4 ); // …/wp-content -> public
// Dev/verification script — must only run under CLI, never web-served.
if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }

require $root . '/wp-load.php';

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Modules\Analytics\AnalyticsMetrics;
use Yazan\Rewards\Modules\Ambassador\AmbassadorRepository;
use Yazan\Rewards\Modules\Referral\ReferralRepository;
use Yazan\Rewards\Modules\Rewards\RedemptionRepository;
use Yazan\Rewards\Rest\V1\AnalyticsController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;

$pass = 0; $fail = 0;
function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}
function is_num_str( $v ) { return is_string( $v ) && is_numeric( $v ); }

echo "=== Yazan Analytics — verification ===\n\n";

$c = Plugin::instance()->container();

echo "[1] Autoload + wiring\n";
ok( 'AnalyticsMetrics class autoloads', class_exists( AnalyticsMetrics::class ) );
ok( 'AnalyticsController class autoloads', class_exists( AnalyticsController::class ) );
ok( 'AnalyticsAdminPage class autoloads', class_exists( \Yazan\Rewards\Admin\AnalyticsAdminPage::class ) );
ok( 'container has AnalyticsMetrics', $c->has( AnalyticsMetrics::class ) );

echo "\n[2] New repo aggregates run + typed\n";
if ( $c->has( AmbassadorRepository::class ) ) {
	$n = $c->get( AmbassadorRepository::class )->count_active();
	ok( 'AmbassadorRepository::count_active() int >= 0', is_int( $n ) && $n >= 0 );
} else { ok( 'AmbassadorRepository present', false ); }

if ( $c->has( ReferralRepository::class ) ) {
	$g = $c->get( ReferralRepository::class )->global_stats();
	ok( 'ReferralRepository::global_stats() has revenue/signups/conversions',
		is_array( $g ) && isset( $g['revenue'], $g['signups'], $g['conversions'] ) );
	ok( 'global_stats revenue is numeric string', is_num_str( $g['revenue'] ) );
	ok( 'global_stats signups/conversions ints', is_int( $g['signups'] ) && is_int( $g['conversions'] ) );
} else { ok( 'ReferralRepository present', false ); }

if ( $c->has( RedemptionRepository::class ) ) {
	$rr = $c->get( RedemptionRepository::class );
	ok( 'RedemptionRepository::total_points_spent() int', is_int( $rr->total_points_spent() ) );
	ok( 'RedemptionRepository::total_redemptions() int', is_int( $rr->total_redemptions() ) );
} else { ok( 'RedemptionRepository present', false ); }

echo "\n[3] overview() payload shape (range=30 and all-time)\n";
$m = $c->get( AnalyticsMetrics::class );
foreach ( array( 30, 0 ) as $days ) {
	$o = $m->overview( $days, true );
	$tag = $days ? 'range=30' : 'all-time';
	ok( "[$tag] has range/customers/campaigns/business/series",
		isset( $o['range'], $o['customers'], $o['campaigns'], $o['business'], $o['series'] ) );
	$cu = $o['customers'];
	ok( "[$tag] customers.total int + vip int + active_ambassadors int + tiers array",
		is_int( $cu['total'] ) && is_int( $cu['vip'] ) && is_int( $cu['active_ambassadors'] ) && is_array( $cu['tiers'] ) );
	$ca = $o['campaigns'];
	ok( "[$tag] campaigns keys + best array",
		isset( $ca['total_campaigns'], $ca['participation'], $ca['content_generated'], $ca['engagement_rate'] ) && is_array( $ca['best'] ) );
	// best[] sorted by points_generated desc
	$sorted = true; $prev = PHP_INT_MAX;
	foreach ( $ca['best'] as $b ) { if ( $b['points_generated'] > $prev ) { $sorted = false; break; } $prev = $b['points_generated']; }
	ok( "[$tag] campaigns.best sorted desc by points_generated", $sorted );
	$bu = $o['business'];
	ok( "[$tag] business has all metrics",
		isset( $bu['referral_revenue'], $bu['reward_cost'], $bu['point_liability'], $bu['retention_rate'], $bu['clv'], $bu['revenue_total'], $bu['purchasing_customers'] ) );
	ok( "[$tag] reward_cost has points/value/redemptions",
		isset( $bu['reward_cost']['points'], $bu['reward_cost']['value'], $bu['reward_cost']['redemptions'] ) );
	ok( "[$tag] retention_rate in 0..1", $bu['retention_rate'] >= 0 && $bu['retention_rate'] <= 1 );
	ok( "[$tag] series has 4 headline metrics",
		isset( $o['series']['points_issued'], $o['series']['points_spent'], $o['series']['redemptions'], $o['series']['orders'] ) );
}

echo "\n[4] Order aggregate is transient-cached\n";
delete_transient( 'yzrw_analytics_orders' );
$m->business( true ); // fresh -> writes cache
ok( 'transient set after business()', is_array( get_transient( 'yzrw_analytics_orders' ) ) );
$t0 = microtime( true ); $m->business( false ); $cached_ms = ( microtime( true ) - $t0 ) * 1000;
ok( 'cached business() call fast (< 50ms)', $cached_ms < 50 );
echo "      (cached call: " . round( $cached_ms, 2 ) . " ms)\n";

echo "\n[5] CSV export — formula-injection neutralised\n";
$ctrl = new AnalyticsController( $c );
$ref  = new ReflectionMethod( AnalyticsController::class, 'to_csv' );
$ref->setAccessible( true );
$csv = $ref->invoke( $ctrl, array( array( '=cmd()', '+2', '-3', '@x', 'safe' ) ) );
ok( 'leading = escaped', strpos( $csv, '"\'=cmd()"' ) !== false );
ok( 'leading + escaped', strpos( $csv, '"\'+2"' ) !== false );
ok( 'leading - escaped', strpos( $csv, '"\'-3"' ) !== false );
ok( 'leading @ escaped', strpos( $csv, '"\'@x"' ) !== false );
ok( 'safe cell not prefixed', strpos( $csv, '"safe"' ) !== false );
// quotes doubled
$csv2 = $ref->invoke( $ctrl, array( array( 'a"b' ) ) );
ok( 'embedded quote doubled', strpos( $csv2, '"a""b"' ) !== false );

// export() returns filename + csv for each report
$req = new WP_REST_Request( 'GET' );
$req->set_param( 'report', 'business' );
$req->set_param( 'range', '30' );
$resp = $ctrl->export( $req );
$d = $resp->get_data();
ok( 'export(business) returns filename + csv',
	is_array( $d ) && ! empty( $d['filename'] ) && isset( $d['csv'] ) );

echo "\n[6] Cap-gating + feature flag\n";
ok( 'MANAGE cap constant present', defined( Capabilities::class . '::MANAGE' ) || Capabilities::MANAGE !== '' );
ok( 'guest cannot manage rewards', ! current_user_can( Capabilities::MANAGE ) );
$analytics_on = $c->get( Settings::class )->feature_enabled( 'analytics' );
echo "      analytics feature enabled: " . ( $analytics_on ? 'yes' : 'no' ) . "\n";

echo "\n=== $pass passed, $fail failed ===\n";
