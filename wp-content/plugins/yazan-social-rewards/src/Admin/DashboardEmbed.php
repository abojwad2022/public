<?php
/**
 * Chrome-less embed renderer for the rewards admin screens.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Admin;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Support\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders any single rewards admin screen as a standalone, chrome-less HTML page
 * at `?yzrw_embed={screen}` — no wp-admin menu/bar, just the screen. This lets the
 * standalone `/dashboard` (yazan-core) show each rewards screen inside an iframe,
 * "inheriting" the real WordPress-rendered, WordPress-authenticated screen.
 *
 * Each JS screen is the same self-contained widget wp-admin uses: a mount div + its
 * `admin-*.js` + a `{restUrl,nonce}` global. We enqueue the WP-admin base styles the
 * widgets rely on (buttons/forms/tables/icons) so they look identical to wp-admin.
 * Access requires `manage_yazan_rewards`, exactly like the wp-admin pages + the REST
 * routes those widgets call.
 */
final class DashboardEmbed implements Hookable {

	/** Query var carrying the screen key. */
	public const QUERY_VAR = 'yzrw_embed';

	/**
	 * @param Container $container Service container (for the integrity screen).
	 * @param Assets    $assets    Core asset URL/version helper.
	 */
	public function __construct(
		private Container $container,
		private Assets $assets
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'template_redirect', 'method' => 'maybe_render' ),
		);
	}

	/**
	 * Map of embeddable screens → their widget wiring. `integrity` is the one
	 * server-rendered screen (no JS widget); the rest are div + JS + global.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function screens(): array {
		return array(
			'rules'         => array( 'div' => 'yzrw-rules-app',         'js' => 'admin-rules.js',         'css' => 'admin-rules.css',     'global' => 'YazanRulesAdmin',         'base' => 'rules' ),
			'points'        => array( 'div' => 'yzrw-points-app',        'js' => 'admin-points.js',        'css' => 'admin-rewards.css',   'global' => 'YazanPointsAdmin',        'base' => 'admin/points' ),
			'rewards'       => array( 'div' => 'yzrw-rewards-app',       'js' => 'admin-rewards.js',       'css' => 'admin-rewards.css',   'global' => 'YazanRewardsAdmin',       'base' => 'admin/rewards' ),
			'services'      => array( 'div' => 'yzrw-service-app',       'js' => 'admin-service.js',       'css' => 'admin-rewards.css',   'global' => 'YazanServiceAdmin',       'base' => 'service-vouchers' ),
			'campaigns'     => array( 'div' => 'yzrw-campaigns-app',     'js' => 'admin-campaigns.js',     'css' => 'admin-rewards.css',   'global' => 'YazanCampaignsAdmin',     'base' => 'admin/campaigns' ),
			'referrals'     => array( 'div' => 'yzrw-referrals-app',     'js' => 'admin-referrals.js',     'css' => 'admin-rewards.css',   'global' => 'YazanReferralAdmin',      'base' => 'admin/referrals' ),
			'social'        => array( 'div' => 'yzrw-social-app',        'js' => 'admin-social.js',        'css' => 'admin-rewards.css',   'global' => 'YazanSocialAdmin',        'base' => 'admin/social' ),
			'fraud'         => array( 'div' => 'yzrw-fraud-app',         'js' => 'admin-fraud.js',         'css' => 'admin-rewards.css',   'global' => 'YazanFraudAdmin',         'base' => 'admin/fraud' ),
			'analytics'     => array( 'div' => 'yzrw-analytics-app',     'js' => 'admin-analytics.js',     'css' => 'admin-analytics.css', 'global' => 'YazanAnalytics',          'base' => 'admin/analytics', 'currency' => true ),
			'notifications' => array( 'div' => 'yzrw-notifications-app', 'js' => 'admin-notifications.js', 'css' => 'admin-rewards.css',   'global' => 'YazanNotificationsAdmin', 'base' => 'admin/notifications' ),
			'integrity'     => array( 'integrity' => true ),
		);
	}

	/**
	 * Intercept `?yzrw_embed={screen}` and render the chrome-less screen.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		// Read-only render selected by a query flag (not a state change); the screen
		// and every REST route it calls enforce the manage capability. No nonce needed
		// to *view*, exactly like a wp-admin page load.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $key ) {
			return;
		}

		if ( ! isset( $this->screens()[ $key ] ) ) {
			$this->die_message( 404, __( 'Unknown screen.', 'yazan-rewards' ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			$this->die_message( 403, __( 'You do not have permission to view this.', 'yazan-rewards' ) );
		}

		// Theme mirrors the parent dashboard — the iframe passes ?theme=dark|light.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$theme = ( isset( $_GET['theme'] ) && 'light' === sanitize_key( (string) wp_unslash( $_GET['theme'] ) ) ) ? 'light' : 'dark';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html builds trusted, escaped markup.
		echo $this->render_html( $key, $theme );
		exit;
	}

	/**
	 * Build the full chrome-less HTML document for a screen (pure — no exit, so it
	 * is testable).
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public function render_html( string $key, string $theme = 'dark' ): string {
		$screen = $this->screens()[ $key ] ?? null;
		if ( null === $screen ) {
			return '';
		}

		$theme = ( 'light' === $theme ) ? 'light' : 'dark';
		// Analytics is pinned light: its charts draw a JS palette validated for a
		// light surface only, so the whole screen renders in the light token context.
		if ( 'analytics' === $key ) {
			$theme = 'light';
		}

		nocache_headers();

		// WP-admin base styles the REST widgets style themselves against.
		$base_styles = array( 'common', 'forms', 'buttons', 'list-tables', 'dashicons' );
		foreach ( $base_styles as $base_style ) {
			wp_enqueue_style( $base_style );
		}

		$is_integrity = ! empty( $screen['integrity'] );
		$skin_deps    = $base_styles;

		if ( ! $is_integrity ) {
			$handle = 'yzrw-embed-' . $key;
			wp_enqueue_style( $handle, $this->assets->url( 'assets/css/' . $screen['css'] ), array(), $this->assets->version( 'assets/css/' . $screen['css'] ) );
			wp_enqueue_script( $handle, $this->assets->url( 'assets/js/' . $screen['js'] ), array(), $this->assets->version( 'assets/js/' . $screen['js'] ), true );

			$data = array(
				'restUrl' => esc_url_raw( rest_url( 'yazan-rewards/v1/' . $screen['base'] ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			);
			if ( ! empty( $screen['currency'] ) ) {
				$data['currency'] = function_exists( 'get_woocommerce_currency_symbol' )
					? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
					: '';
			}
			wp_localize_script( $handle, (string) $screen['global'], $data );
			$skin_deps[] = $handle;
		}

		// Shared design tokens + self-hosted fonts (yazan-core) — the SAME single
		// source of truth the dashboard SPA consumes, so the embed resolves the
		// identical --yz-* values, radii, shadows and typefaces (Inter/Cardo) and
		// can never drift from the dashboard. Enqueued as skin dependencies so they
		// load first; the skin then only mirrors the native component styles.
		if ( defined( 'YAZAN_CORE_URL' ) && defined( 'YAZAN_CORE_DIR' ) ) {
			$core_css   = YAZAN_CORE_URL . 'assets/css/';
			$fonts_path = YAZAN_CORE_DIR . 'assets/css/yz-fonts.css';
			$tokens_path = YAZAN_CORE_DIR . 'assets/css/yz-tokens.css';
			if ( file_exists( $fonts_path ) ) {
				wp_enqueue_style( 'yz-fonts', $core_css . 'yz-fonts.css', array(), (string) filemtime( $fonts_path ) );
				$skin_deps[] = 'yz-fonts';
			}
			if ( file_exists( $tokens_path ) ) {
				wp_enqueue_style( 'yz-tokens', $core_css . 'yz-tokens.css', array( 'yz-fonts' ), (string) filemtime( $tokens_path ) );
				$skin_deps[] = 'yz-tokens';
			}
		}

		// The Yazan skin loads LAST (it depends on the base + screen CSS + shared
		// tokens/fonts) so it wins, re-theming the raw wp-admin screen to match the
		// dashboard's native components.
		wp_enqueue_style( 'yzrw-embed-skin', $this->assets->url( 'assets/css/embed-skin.css' ), $skin_deps, $this->assets->version( 'assets/css/embed-skin.css' ) );

		// Shared embed enhancer for every screen (incl. the server-rendered integrity
		// page): wraps wide tables in a horizontal-scroll container so they stay usable
		// on a phone instead of overflowing the chrome-less frame.
		wp_enqueue_script( 'yzrw-embed-enhance', $this->assets->url( 'assets/js/embed-enhance.js' ), array(), $this->assets->version( 'assets/js/embed-enhance.js' ), true );

		// Analytics always renders on a light surface (its charts use a JS palette
		// validated for light only), so paint its pre-skin ground light too.
		$prepaint = ( 'analytics' === $key || 'light' === $theme ) ? '#f4f1ea' : '#1e2128';

		ob_start();
		?>
<!doctype html>
<html <?php language_attributes(); ?> data-theme="<?php echo esc_attr( $theme ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html__( 'Yazan Rewards', 'yazan-rewards' ); ?></title>
<style>html,body{margin:0;background:<?php echo esc_attr( $prepaint ); ?>;}</style>
<?php wp_print_styles(); ?>
</head>
<body class="wp-admin wp-core-ui yzrw-embed yzrw-embed--<?php echo esc_attr( $key ); ?>">
		<?php
		if ( $is_integrity ) {
			if ( $this->container->has( DataIntegrityAdminPage::class ) ) {
				$this->container->get( DataIntegrityAdminPage::class )->render();
			}
		} else {
			echo '<div class="wrap"><div id="' . esc_attr( (string) $screen['div'] ) . '" class="yzrw-admin-app" aria-live="polite"></div></div>';
		}

		wp_print_footer_scripts();
		?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Emit a minimal status page and exit.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 * @return void
	 */
	private function die_message( int $code, string $message ): void {
		status_header( $code );
		nocache_headers();
		printf(
			'<!doctype html><meta charset="utf-8"><body style="font:14px -apple-system,sans-serif;padding:24px;color:#1d2327"><p>%s</p></body>',
			esc_html( $message )
		);
		exit;
	}
}
