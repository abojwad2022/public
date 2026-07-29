<?php
/**
 * Rules admin page (native wp-admin, no build step).
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
 * Registers the "Yazan Rewards → Rules" admin page — a no-code rule builder. The
 * page is a small shell; the vanilla-JS app (assets/js/admin-rules.js) drives it
 * entirely from the `yazan-rewards/v1/rules` REST API (schema-driven dropdowns,
 * add/remove condition & action rows, save/delete), authenticated by the
 * `wp_rest` nonce and gated by the `manage_yazan_rewards` capability.
 */
final class RulesAdminPage implements Hookable {

	/** Admin page slug. */
	public const SLUG = 'yazan-rewards-rules';

	/**
	 * @param Assets $assets Asset URL/version helper.
	 */
	public function __construct( private Assets $assets ) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'admin_menu', 'method' => 'register_menu' ),
			array( 'type' => 'action', 'hook' => 'admin_enqueue_scripts', 'method' => 'enqueue' ),
		);
	}

	/**
	 * Register the top-level menu + Rules page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Yazan Rewards', 'yazan-rewards' ),
			__( 'Yazan Rewards', 'yazan-rewards' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-awards',
			56
		);
		add_submenu_page(
			self::SLUG,
			__( 'Reward Rules', 'yazan-rewards' ),
			__( 'Rules', 'yazan-rewards' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the page shell (the JS app mounts into #yzrw-rules-app).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage rewards.', 'yazan-rewards' ) );
		}
		echo '<div class="wrap yzrw-rules-wrap">';
		echo '<h1>' . esc_html__( 'Reward Rules', 'yazan-rewards' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Create unlimited reward rules: choose an event, add conditions, then add actions. No coding required.', 'yazan-rewards' ) . '</p>';
		echo '<div id="yzrw-rules-app" class="yzrw-rules-app" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue the builder assets, on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'yazan-rules-admin',
			$this->assets->url( 'assets/css/admin-rules.css' ),
			array(),
			$this->assets->version( 'assets/css/admin-rules.css' )
		);
		wp_enqueue_script(
			'yazan-rules-admin',
			$this->assets->url( 'assets/js/admin-rules.js' ),
			array( 'wp-i18n' ),
			$this->assets->version( 'assets/js/admin-rules.js' ),
			true
		);
		wp_localize_script(
			'yazan-rules-admin',
			'YazanRulesAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'yazan-rewards/v1/rules' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
