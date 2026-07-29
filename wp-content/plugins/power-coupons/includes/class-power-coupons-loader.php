<?php
/**
 * Plugin Loader.
 *
 * @package Power_Coupons
 * @since 1.0.0
 */

namespace Power_Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Power_Coupons_Loader
 *
 * @since 1.0.0
 */
class Power_Coupons_Loader {

	/**
	 * Instance
	 *
	 * @access private
	 * @var object Class Instance.
	 * @since 1.0.0
	 */
	private static $instance;

	/**
	 * Initiator
	 *
	 * @since 1.0.0
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Autoload classes.
	 *
	 * @param string $class class name.
	 * @since 1.0.0
	 * @return void
	 */
	public function autoload( $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ ) ) {
			return;
		}

		$class_to_load = $class;

		$filename = strtolower(
			(string) preg_replace(
				array( '/^' . __NAMESPACE__ . '\\\/', '/([a-z])([A-Z])/', '/_/', '/\\\/' ),
				array( '', '$1-$2', '-', DIRECTORY_SEPARATOR ),
				$class_to_load
			)
		);

		$file = POWER_COUPONS_DIR . $filename . '.php';

		// If the file is readable, include it.
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		spl_autoload_register( array( $this, 'autoload' ) );

		// Declare WooCommerce compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );

		add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 20 );
	}

	/**
	 * Declare WooCommerce compatibility
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function declare_woocommerce_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			// Declare compatibility with custom order tables (HPOS).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', POWER_COUPONS_FILE, true );
			// Declare compatibility with cart/checkout blocks.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', POWER_COUPONS_FILE, true );
		}
	}

	/**
	 * Check if WooCommerce is active
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Initialize plugin
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init_plugin() {
		// Check WooCommerce is active before initializing.
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		// Load dependencies first.
		$this->load_dependencies();

		// Run pending migrations on admin page loads.
		if ( is_admin() ) {
			add_action( 'admin_init', array( '\Power_Coupons\Includes\Power_Coupons_Migration', 'run' ) );
		}

		// Initialize main plugin class.
		\Power_Coupons\Includes\Power_Coupons_Core::get_instance();
	}

	/**
	 * Load plugin dependencies
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// Load traits first.
		require_once POWER_COUPONS_DIR . 'includes/traits/trait-power-coupons-singleton.php';

		// Load activator class.
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-activator.php';

		// Load main plugin class.
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-core.php';

		// Load utility classes.
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-utilities.php';
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-settings-helper.php';
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-migration.php';

		// Load analytics tracking.
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-analytics.php';

		// Load controllers.
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-wc-blocks-integration.php';

		// Load rules registry (conditional rules metadata schema).
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-rules-registry.php';

		// Load models.
		require_once POWER_COUPONS_DIR . 'models/class-power-coupons-rule-model.php';
		require_once POWER_COUPONS_DIR . 'models/class-power-coupons-cart-model.php';

		// Load controllers.
		require_once POWER_COUPONS_DIR . 'controllers/class-power-coupons-rule-controller.php';
		require_once POWER_COUPONS_DIR . 'controllers/class-power-coupons-auto-apply-controller.php';
		require_once POWER_COUPONS_DIR . 'controllers/class-power-coupons-cart-controller.php';
		require_once POWER_COUPONS_DIR . 'controllers/class-power-coupons-display-controller.php';
		require_once POWER_COUPONS_DIR . 'controllers/class-checkout-drawer-controller.php';
		require_once POWER_COUPONS_DIR . 'controllers/class-power-coupons-coupon-usage-controller.php';

		// Load admin and public classes.
		if ( is_admin() ) {
			require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-admin.php';
			require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-admin-rules.php';
			require_once POWER_COUPONS_DIR . 'admin/class-power-coupons-analytics.php';
		}

		require_once POWER_COUPONS_DIR . 'public/class-power-coupons-frontend.php';
		require_once POWER_COUPONS_DIR . 'public/class-power-coupons-frontend-rules.php';

		// Load Abilities API (WordPress 6.9+).
		$this->load_abilities_api();
	}

	/**
	 * Load WordPress Abilities API integration.
	 *
	 * Gracefully degrades: if WordPress < 6.9 or class WP_Ability
	 * does not exist, the plugin functions normally without abilities.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function load_abilities_api() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( ! defined( 'POWER_COUPONS_ABILITY_API' ) ) {
			define( 'POWER_COUPONS_ABILITY_API', true );
		}

		if ( ! defined( 'POWER_COUPONS_ABILITY_API_NAMESPACE' ) ) {
			define( 'POWER_COUPONS_ABILITY_API_NAMESPACE', 'power-coupons/' );
		}

		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-config-ability.php';
		require_once POWER_COUPONS_DIR . 'includes/class-power-coupons-ability.php';

		$pc_ability = new \Power_Coupons\Includes\Power_Coupons_Ability();
		add_action( 'wp_abilities_api_categories_init', array( $pc_ability, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $pc_ability, 'register' ) );
	}
}
