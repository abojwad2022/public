<?php
/**
 * Analytics dashboard admin page.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Admin;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Support\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Yazan Rewards → Analytics" — a read-only dashboard of customer, campaign and
 * business metrics with self-contained inline-SVG charts (no external library)
 * and per-section CSV export. Driven by admin-analytics.js against the
 * yazan-rewards/v1/admin/analytics API.
 */
final class AnalyticsAdminPage implements Hookable {

	/** Submenu slug. */
	public const SLUG = 'yazan-rewards-analytics';

	/** Parent menu (created by the Rules page). */
	private const PARENT = 'yazan-rewards-rules';

	/**
	 * @param Assets $assets Asset helper.
	 */
	public function __construct( private Assets $assets ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'admin_menu', 'method' => 'register_menu', 'priority' => 30 ),
			array( 'type' => 'action', 'hook' => 'admin_enqueue_scripts', 'method' => 'enqueue' ),
		);
	}

	/**
	 * Register the submenu (or a top-level menu if the parent is absent).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks[ self::PARENT ] ) ) {
			add_submenu_page( self::PARENT, __( 'Analytics', 'yazan-rewards' ), __( 'Analytics', 'yazan-rewards' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ) );
		} else {
			add_menu_page( __( 'Yazan Analytics', 'yazan-rewards' ), __( 'Yazan Analytics', 'yazan-rewards' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ), 'dashicons-chart-area', 57 );
		}
	}

	/**
	 * Render the shell (the JS mounts the dashboard into #yzrw-analytics-app).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'yazan-rewards' ) );
		}
		echo '<div class="wrap yzrw-admin-wrap yzrw-analytics-wrap">';
		echo '<h1>' . esc_html__( 'Analytics', 'yazan-rewards' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Customer, campaign and business performance for your rewards programme.', 'yazan-rewards' ) . '</p>';
		echo '<div id="yzrw-analytics-app" class="yzrw-admin-app" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'yazan-admin-analytics', $this->assets->url( 'assets/css/admin-analytics.css' ), array(), $this->assets->version( 'assets/css/admin-analytics.css' ) );
		wp_enqueue_script( 'yazan-admin-analytics', $this->assets->url( 'assets/js/admin-analytics.js' ), array(), $this->assets->version( 'assets/js/admin-analytics.js' ), true );
		wp_localize_script(
			'yazan-admin-analytics',
			'YazanAnalytics',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'yazan-rewards/v1/admin/analytics' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => function_exists( 'get_woocommerce_currency_symbol' )
					? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
					: '',
			)
		);
	}
}
