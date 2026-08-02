<?php
/**
 * Yazan Homepage Manager — subsystem bootstrap.
 *
 * The one file in this module written in the plugin's original style (a `Yazan_*` class, no
 * namespace). It is the bridge: yazan-core.php gains a single require + init() call, exactly like
 * Yazan_RBAC_Boot and Yazan_Dashboard_Boot, and everything behind it is the namespaced module.
 *
 * ORDERING — the one thing to get right in this file:
 *
 *   Yazan_Permission_Registry::modules() memoises its result in a static, and
 *   Yazan_RBAC_Boot::maybe_install() calls it on `init` priority 1. Our filter therefore has to be
 *   attached at plugin-load time, which is what happens here — init() is called from yazan-core.php
 *   while the plugin file is being included, long before `init` fires. Attaching it any later means
 *   the catalog is built without our permissions and NOTHING reports an error: the Role Editor
 *   simply has no Homepage section. That failure is silent, which is why this comment exists.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the Homepage Manager into the platform.
 */
class Yazan_Homepage_Boot {

	/**
	 * Load the module and register its hooks. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		require_once YAZAN_CORE_DIR . 'includes/homepage/autoload.php';

		// Built-in components register on the public hook, at an early priority.
		add_action( 'yazan_homepage_register_components', array( __CLASS__, 'register_components' ), 5 );

		// Contribute this module's permissions to the central catalog. Must be attached now — see
		// the ordering note above.
		add_filter( 'yazan_permission_modules', array( __CLASS__, 'register_permissions' ) );

		// Install after RBAC (priority 1) so the catalog sync has tables to write to.
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 2 );

		/*
		 * The theme bridge is registered ONLY on the front page. Every other request — product
		 * pages, checkout, wp-admin, REST — never loads a single class of the render layer.
		 */
		add_action( 'template_redirect', array( __CLASS__, 'maybe_register_theme_bridge' ), 5 );

		// Scheduled-section cache purge. Registered always (cron fires on any request), but the
		// render classes only load if the event actually runs.
		add_action( 'yazan_homepage_schedule_boundary', array( __CLASS__, 'on_schedule_boundary' ) );

		// Structured data for the sections that emit it, printed once at the end of the document.
		add_action( 'wp_footer', array( __CLASS__, 'print_structured_data' ), 20 );

		// Scheduled publishing.
		add_action( 'yazan_homepage_publish_due', array( __CLASS__, 'publish_due' ) );

		/*
		 * A/B attribution. Two hooks, both checkout-only on purpose: an order an administrator
		 * types in by hand did not come from either variant, and counting it would flatter
		 * whichever arm happened to be showing.
		 */
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'count_order' ), 10, 1 );

		// REST surface, in the dashboard's own namespace and behind its guard.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		/*
		 * Cross-cutting listeners: audit and cache REACT to events, they are not called by the
		 * handlers. The hook name is written literally rather than read from
		 * WpEventDispatcher::ANY_HOOK so that touching a constant does not autoload a class on
		 * every single request, including the ones that never reach this module.
		 */
		add_action( 'yazan_homepage_event', array( __CLASS__, 'on_event' ), 10, 1 );
	}

	/**
	 * Register the built-in components.
	 *
	 * Empty in phase 0 — the module is fully wired and its permissions are grantable before a
	 * single component exists, which is exactly the milestone this phase targets.
	 *
	 * @param \Yazan\Homepage\Domain\Component\ComponentRegistry $registry Registry.
	 * @return void
	 */
	public static function register_components( $registry ) {
		$dir = YAZAN_CORE_DIR . 'includes/homepage/Presentation/Components/';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '*Component.php' ) as $file ) {
			$class = '\\Yazan\\Homepage\\Presentation\\Components\\' . basename( $file, '.php' );

			if ( class_exists( $class ) && method_exists( $class, 'definition' ) ) {
				$registry->register( $class::definition() );
			}
		}
	}

	/**
	 * Add the `homepage` module to the permission catalog.
	 *
	 * @param array $modules Existing modules.
	 * @return array
	 */
	public static function register_permissions( $modules ) {
		if ( ! is_array( $modules ) ) {
			return $modules;
		}

		$modules['homepage'] = \Yazan\Homepage\Infrastructure\Permission\PermissionCatalog::module(
			\Yazan\Homepage\Infrastructure\Bootstrap\ComponentBootstrap::registry()
		);

		return $modules;
	}

	/**
	 * Bind the published homepage document onto the theme's content hooks — front page only.
	 *
	 * @return void
	 */
	public static function maybe_register_theme_bridge() {
		if ( is_admin() ) {
			return;
		}

		if ( is_front_page() ) {
			\Yazan\Homepage\Presentation\Render\ThemeBridge::register();
			return;
		}

		// Any other page is untouched unless a layout was deliberately bound to it.
		\Yazan\Homepage\Presentation\Render\PageRenderer::maybe_take_over();
	}

	/**
	 * A scheduled section became due or expired.
	 *
	 * @return void
	 */
	public static function on_schedule_boundary() {
		\Yazan\Homepage\Presentation\Render\ThemeBridge::on_schedule_boundary();
	}

	/**
	 * Mark an order with the experiment arm its basket was built in.
	 *
	 * @param \WC_Order $order Order being created.
	 * @return void
	 */
	public static function stamp_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$stamp = \Yazan\Homepage\Presentation\Render\ExperimentRunner::stamp();

		if ( '' === $stamp ) {
			return;
		}

		// Underscore-prefixed: internal, and not shown on the order screen as a customer-facing
		// line item.
		$order->update_meta_data( '_yazan_ab_arm', $stamp );
	}

	/**
	 * Count a completed checkout against its arm.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function count_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$stamp = (string) $order->get_meta( '_yazan_ab_arm' );

		if ( '' === $stamp ) {
			return;
		}

		\Yazan\Homepage\Presentation\Render\ExperimentRunner::record_order(
			$stamp,
			(float) $order->get_total(),
			(string) wp_date( 'Y-m-d' )
		);
	}

	/**
	 * Install or upgrade.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		\Yazan\Homepage\Infrastructure\Bootstrap\Installer::maybe_install();
	}

	/**
	 * Register the REST controllers.
	 *
	 * Deliberately on `rest_api_init` and not earlier: Yazan_REST_Guard is required by
	 * Yazan_Dashboard_Boot, which loads AFTER this module (this one has to precede RBAC — see the
	 * ordering note at the top). By the time REST initialises, everything is present.
	 *
	 * @return void
	 */
	public static function register_routes() {
		if ( ! class_exists( 'Yazan_REST_Guard' ) ) {
			return;
		}

		\Yazan\Homepage\Presentation\Rest\HomepageController::register_routes();
		\Yazan\Homepage\Presentation\Rest\SectionsController::register_routes();
		\Yazan\Homepage\Presentation\Rest\RevisionsController::register_routes();
		\Yazan\Homepage\Presentation\Rest\TemplatesController::register_routes();
		\Yazan\Homepage\Presentation\Rest\DocumentsController::register_routes();
		\Yazan\Homepage\Presentation\Rest\PortingController::register_routes();
		\Yazan\Homepage\Presentation\Rest\ExperimentController::register_routes();
	}

	/**
	 * Print JSON-LD for whatever rendered on this request.
	 *
	 * @return void
	 */
	public static function print_structured_data() {
		if ( class_exists( '\\Yazan\\Homepage\\Presentation\\Render\\StructuredData', false ) ) {
			\Yazan\Homepage\Presentation\Render\StructuredData::render();
		}
	}

	/**
	 * Publish any document whose scheduled moment has arrived.
	 *
	 * @return void
	 */
	public static function publish_due() {
		\Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::publish()->run_due();
	}

	/**
	 * Fan a domain event out to the module's listeners.
	 *
	 * @param mixed $event Domain event.
	 * @return void
	 */
	public static function on_event( $event ) {
		$audit = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::audit_recorder();
		$cache = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::cache_invalidator();

		$booker = \Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory::publish_scheduler();

		$audit( $event );
		$cache( $event );
		$booker( $event );
	}

	/**
	 * Health summary for GET /status.
	 *
	 * @return array
	 */
	public static function status() {
		return \Yazan\Homepage\Infrastructure\Bootstrap\Installer::status();
	}
}
