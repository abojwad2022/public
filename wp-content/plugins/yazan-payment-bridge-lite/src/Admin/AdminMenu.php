<?php
/**
 * Admin menu registration.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Admin;

use Yazan\PaymentBridge\Contracts\Hookable;
use Yazan\PaymentBridge\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the top-level YAZAN menu and its pages.
 *
 * The parent is claimed defensively: if another YAZAN plugin has already
 * registered the same top-level slug, this one attaches to it instead of
 * creating a duplicate menu.
 */
final class AdminMenu implements Hookable {

	/** Top-level menu slug — shared by any future YAZAN plugin. */
	public const PARENT_SLUG = 'yazan-payment-bridge';

	/**
	 * @param DashboardPage $dashboard Dashboard renderer.
	 * @param EventsPage    $events    Events list renderer.
	 * @param SettingsPage  $settings  Settings renderer.
	 */
	public function __construct(
		private DashboardPage $dashboard,
		private EventsPage $events,
		private SettingsPage $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array(
				'type'     => 'action',
				'hook'     => 'admin_menu',
				'method'   => 'register_menu',
				'priority' => 20,
				'args'     => 0,
			),
			array(
				'type'   => 'action',
				'hook'   => 'admin_enqueue_scripts',
				'method' => 'enqueue',
				'args'   => 1,
			),
		);
	}

	/**
	 * Register the menu and its pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		global $admin_page_hooks;

		if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			add_menu_page(
				__( 'YAZAN', 'yazan-payment-bridge' ),
				__( 'YAZAN', 'yazan-payment-bridge' ),
				Capabilities::VIEW,
				self::PARENT_SLUG,
				array( $this->dashboard, 'render' ),
				'dashicons-shield-alt',
				55
			);
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Payment Bridge', 'yazan-payment-bridge' ),
			__( 'Payment Bridge', 'yazan-payment-bridge' ),
			Capabilities::VIEW,
			DashboardPage::SLUG,
			array( $this->dashboard, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Payment Events', 'yazan-payment-bridge' ),
			__( 'Payment Events', 'yazan-payment-bridge' ),
			Capabilities::VIEW,
			EventsPage::SLUG,
			array( $this->events, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Payment Bridge Settings', 'yazan-payment-bridge' ),
			__( 'Settings', 'yazan-payment-bridge' ),
			Capabilities::MANAGE,
			SettingsPage::SLUG,
			array( $this->settings, 'render' )
		);
	}

	/**
	 * Load the admin stylesheet only on this plugin's screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || false === strpos( $hook_suffix, 'yazan-payment-bridge' ) ) {
			return;
		}

		$relative = 'assets/css/admin.css';
		$path     = YAZAN_PB_DIR . $relative;
		$version  = is_readable( $path ) ? (string) filemtime( $path ) : YAZAN_PB_VERSION;

		wp_enqueue_style( 'yazan-payment-bridge-admin', YAZAN_PB_URL . $relative, array(), $version );
	}
}
