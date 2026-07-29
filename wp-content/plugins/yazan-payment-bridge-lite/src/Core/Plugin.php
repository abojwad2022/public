<?php
/**
 * Plugin orchestrator.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Core;

use Yazan\PaymentBridge\Admin\AdminMenu;
use Yazan\PaymentBridge\Admin\DashboardPage;
use Yazan\PaymentBridge\Admin\EventsPage;
use Yazan\PaymentBridge\Admin\RetryController;
use Yazan\PaymentBridge\Admin\SettingsPage;
use Yazan\PaymentBridge\Database\Database;
use Yazan\PaymentBridge\Database\Installer;
use Yazan\PaymentBridge\Events\EventRepository;
use Yazan\PaymentBridge\Integrations\IntegrationDispatcher;
use Yazan\PaymentBridge\Integrations\Ownership\OwnershipConnector;
use Yazan\PaymentBridge\Integrations\Warranty\WarrantyConnector;
use Yazan\PaymentBridge\Logging\Logger;
use Yazan\PaymentBridge\Payments\PaymentEventService;
use Yazan\PaymentBridge\Payments\PaymentListener;
use Yazan\PaymentBridge\Payments\ProductEligibilityService;
use Yazan\PaymentBridge\Payments\RefundClassifier;
use Yazan\PaymentBridge\Security\Capabilities;
use Yazan\PaymentBridge\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the services and registers the hooks.
 *
 * Without WooCommerce — or on a WooCommerce older than the supported minimum —
 * the plugin pauses and shows an admin notice. It never calls deactivate_plugins()
 * and never fatals (H7).
 */
final class Plugin {

	/**
	 * REST namespace reserved for v1.2. Nothing is registered in v1.0.
	 *
	 * Note: `yazan/v1` is already in use by yazan-core and yazan-social-rewards.
	 * WordPress allows a namespace to be shared, but v1.2 must not assume it owns
	 * the namespace — only the `/payment-events` route below.
	 */
	public const REST_NS = 'yazan/v1';

	/** Route reserved for v1.2. Must ship with a real permission_callback. */
	public const REST_ROUTE = '/payment-events';

	/** Minimum supported WooCommerce version. */
	public const MIN_WC_VERSION = '8.0';

	private static ?self $instance = null;

	private Container $container;

	private bool $built = false;

	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The service container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register services. Adds no hooks, so it is safe to call from anywhere
	 * (activation, tests) without side effects.
	 *
	 * @return Container
	 */
	public function build(): Container {
		if ( $this->built ) {
			return $this->container;
		}

		$c = $this->container;

		$c->singleton( Settings::class, static fn () => new Settings() );
		$c->singleton( Database::class, static fn () => new Database() );
		$c->singleton( Logger::class, static fn ( Container $c ) => new Logger( $c->get( Settings::class ) ) );

		$c->singleton(
			Capabilities::class,
			static function () {
				$map = require YAZAN_PB_DIR . 'config/capabilities.php';
				return new Capabilities( is_array( $map ) ? $map : array() );
			}
		);

		$c->singleton(
			EventRepository::class,
			static fn ( Container $c ) => new EventRepository( $c->get( Database::class ), $c->get( Logger::class ) )
		);

		$c->singleton( RefundClassifier::class, static fn () => new RefundClassifier() );

		$c->singleton(
			ProductEligibilityService::class,
			static fn ( Container $c ) => new ProductEligibilityService( $c->get( Settings::class ) )
		);

		$c->singleton(
			OwnershipConnector::class,
			static fn ( Container $c ) => new OwnershipConnector( $c->get( Settings::class ), $c->get( Logger::class ) )
		);

		$c->singleton(
			WarrantyConnector::class,
			static fn ( Container $c ) => new WarrantyConnector( $c->get( Settings::class ), $c->get( Logger::class ) )
		);

		$c->singleton(
			IntegrationDispatcher::class,
			static fn ( Container $c ) => new IntegrationDispatcher(
				$c->get( EventRepository::class ),
				$c->get( OwnershipConnector::class ),
				$c->get( WarrantyConnector::class ),
				$c->get( ProductEligibilityService::class ),
				$c->get( Logger::class )
			)
		);

		$c->singleton(
			PaymentEventService::class,
			static fn ( Container $c ) => new PaymentEventService(
				$c->get( EventRepository::class ),
				$c->get( IntegrationDispatcher::class ),
				$c->get( RefundClassifier::class ),
				$c->get( Logger::class )
			)
		);

		$c->singleton(
			PaymentListener::class,
			static fn ( Container $c ) => new PaymentListener(
				$c->get( PaymentEventService::class ),
				$c->get( RefundClassifier::class ),
				$c->get( Logger::class )
			)
		);

		$c->singleton(
			DashboardPage::class,
			static fn ( Container $c ) => new DashboardPage( $c->get( EventRepository::class ) )
		);

		$c->singleton(
			EventsPage::class,
			static fn ( Container $c ) => new EventsPage( $c->get( EventRepository::class ) )
		);

		$c->singleton(
			SettingsPage::class,
			static fn ( Container $c ) => new SettingsPage( $c->get( Settings::class ) )
		);

		$c->singleton(
			AdminMenu::class,
			static fn ( Container $c ) => new AdminMenu(
				$c->get( DashboardPage::class ),
				$c->get( EventsPage::class ),
				$c->get( SettingsPage::class )
			)
		);

		$c->singleton(
			RetryController::class,
			static fn ( Container $c ) => new RetryController(
				$c->get( PaymentEventService::class ),
				$c->get( Logger::class )
			)
		);

		$c->alias( 'settings', Settings::class );
		$c->alias( 'logger', Logger::class );
		$c->alias( 'db', Database::class );
		$c->alias( 'events', EventRepository::class );

		$this->built = true;

		return $c;
	}

	/**
	 * Register hooks. Called on plugins_loaded priority 20.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->woocommerce_supported() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

		$c = $this->build();

		// Schema upgrades run on init, not admin_init: a gateway webhook or a
		// cron pass can write events long before anyone opens wp-admin.
		add_action( 'init', array( Installer::class, 'maybe_migrate' ), 1 );

		Loader::register( $c->get( PaymentListener::class ) );

		// admin-post.php sets is_admin() true, so the retry handler is reachable
		// from inside this guard.
		if ( is_admin() ) {
			Loader::register( $c->get( AdminMenu::class ) );
			Loader::register( $c->get( SettingsPage::class ) );
			Loader::register( $c->get( RetryController::class ) );
		}

		/**
		 * Fires once the Bridge has registered its hooks.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'yazan_payment_bridge/booted', $this );
	}

	/**
	 * Whether WooCommerce is active and new enough.
	 *
	 * @return bool
	 */
	public function woocommerce_supported(): bool {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return false;
		}

		$version = defined( 'WC_VERSION' ) ? constant( 'WC_VERSION' ) : ( WC()->version ?? '0' );

		return version_compare( (string) $version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Admin notice shown when the WooCommerce requirement is not met.
	 *
	 * @return void
	 */
	public function render_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum WooCommerce version. */
					__( 'YAZAN Payment Bridge Lite is paused: it requires WooCommerce %s or newer. No payment events are being recorded.', 'yazan-payment-bridge' ),
					self::MIN_WC_VERSION
				)
			)
		);
	}
}
