<?php
/**
 * Verify the DashboardEmbed chrome-less renderer (v1.12.0).
 *
 * php.exe -c <ini> -d opcache.enable_cli=0 verify-embed.php
 */

// phpcs:disable

if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }

require dirname( __DIR__, 4 ) . '/wp-load.php';

use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Admin\DashboardEmbed;

$pass = 0; $fail = 0;
function ok( $l, $c ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }
function has( $hay, $needle ) { return false !== strpos( (string) $hay, $needle ); }

echo "=== DashboardEmbed — verification ===\n\n";

$c = Plugin::instance()->container();
ok( 'container has DashboardEmbed', $c->has( DashboardEmbed::class ) );
$embed = $c->get( DashboardEmbed::class );
ok( 'DashboardEmbed is Hookable', $embed instanceof Hookable );
ok( 'QUERY_VAR is yzrw_embed', DashboardEmbed::QUERY_VAR === 'yzrw_embed' );

// An admin context so the integrity screen (which re-checks the cap) renders.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admins ) ) { wp_set_current_user( (int) $admins[0] ); }

echo "\n[analytics] full widget render\n";
$html = @$embed->render_html( 'analytics' );
ok( 'is a full HTML document', has( $html, '<!doctype html' ) && has( $html, '</html>' ) );
ok( 'body carries wp-core-ui (button styles resolve)', has( $html, 'wp-core-ui' ) );
ok( 'mount div #yzrw-analytics-app present', has( $html, 'id="yzrw-analytics-app"' ) );
ok( 'localized global YazanAnalytics present', has( $html, 'YazanAnalytics' ) );
ok( 'restUrl points at admin/analytics', has( $html, 'yazan-rewards/v1/admin/analytics' ) || has( $html, 'yazan-rewards\/v1\/admin\/analytics' ) );
ok( 'admin-analytics.js enqueued', has( $html, 'admin-analytics.js' ) );
ok( 'admin-analytics.css enqueued', has( $html, 'admin-analytics.css' ) );
ok( 'a nonce is localized', has( $html, '"nonce"' ) );

echo "\n[rules] different screen wiring\n";
$html = @$embed->render_html( 'rules' );
ok( 'mount div #yzrw-rules-app', has( $html, 'id="yzrw-rules-app"' ) );
ok( 'admin-rules.js + global', has( $html, 'admin-rules.js' ) && has( $html, 'YazanRulesAdmin' ) );

echo "\n[integrity] server-rendered screen\n";
$html = @$embed->render_html( 'integrity' );
ok( 'integrity renders the Data Integrity page', has( $html, 'Data Integrity' ) );
ok( 'integrity is chrome-less full doc', has( $html, '<!doctype html' ) && has( $html, 'wp-core-ui' ) );

echo "\n[guards]\n";
ok( 'unknown key returns empty string', '' === @$embed->render_html( 'no_such_screen' ) );
$ref = new ReflectionMethod( DashboardEmbed::class, 'screens' );
$ref->setAccessible( true );
$screens = $ref->invoke( $embed );
ok( '11 screens registered', count( $screens ) === 11 );
ok( 'iframe contract: home_url(?yzrw_embed=analytics)', has( home_url( '/?yzrw_embed=analytics' ), 'yzrw_embed=analytics' ) );

echo "\n=== $pass passed, $fail failed ===\n";
