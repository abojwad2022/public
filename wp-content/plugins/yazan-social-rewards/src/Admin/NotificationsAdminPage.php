<?php
/**
 * Notifications admin page.
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
 * "Yazan Rewards → Notifications" — configure channels (email/on-site/webhook),
 * the daily digest, and per-category kill-switches, plus a live outbox viewer and
 * a test-send. Driven by admin-notifications.js against the
 * yazan-rewards/v1/admin/notifications API.
 */
final class NotificationsAdminPage implements Hookable {

	/** Submenu slug. */
	public const SLUG = 'yazan-rewards-notifications';

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
			array( 'type' => 'action', 'hook' => 'admin_menu', 'method' => 'register_menu', 'priority' => 40 ),
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
			add_submenu_page( self::PARENT, __( 'Notifications', 'yazan-rewards' ), __( 'Notifications', 'yazan-rewards' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ) );
		} else {
			add_menu_page( __( 'Yazan Notifications', 'yazan-rewards' ), __( 'Yazan Notifications', 'yazan-rewards' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ), 'dashicons-bell', 58 );
		}
	}

	/**
	 * Render the shell.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage notifications.', 'yazan-rewards' ) );
		}
		echo '<div class="wrap yzrw-admin-wrap">';
		echo '<h1>' . esc_html__( 'Notifications', 'yazan-rewards' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Choose how members are notified, connect a webhook, and review what has been sent.', 'yazan-rewards' ) . '</p>';
		echo '<div id="yzrw-notifications-app" class="yzrw-admin-app" aria-live="polite"></div>';
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
		wp_enqueue_style( 'yazan-admin-rewards', $this->assets->url( 'assets/css/admin-rewards.css' ), array(), $this->assets->version( 'assets/css/admin-rewards.css' ) );
		wp_enqueue_script( 'yazan-admin-notifications', $this->assets->url( 'assets/js/admin-notifications.js' ), array(), $this->assets->version( 'assets/js/admin-notifications.js' ), true );
		wp_localize_script(
			'yazan-admin-notifications',
			'YazanNotificationsAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'yazan-rewards/v1/admin/notifications' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
