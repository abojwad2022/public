<?php
/**
 * Yazan Dashboard — system status and maintenance tools.
 *
 * Reports the environment (WordPress, WooCommerce, PHP, MySQL, server, theme, active plugins,
 * template overrides) and exposes WooCommerce's own maintenance tools.
 *
 * Tools are run through `WC_Admin_Status::get_tools()`, so the dashboard never re-implements them —
 * it invokes the exact routine wp-admin does. The genuinely destructive ones (resetting roles,
 * deleting all products/orders/taxonomies) are hidden behind an explicit deny-list, because a
 * mis-click there is unrecoverable and the dashboard is not the right place for it.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /status endpoints.
 */
class Yazan_REST_Status {

	/** Reading the report is store-owner level. */
	const CAP = 'manage_woocommerce';

	/** Running a maintenance tool is full-administrator level. */
	const CAP_TOOLS = 'manage_options';

	/**
	 * Tools that will never be offered here. These delete catalogue/customer data outright or
	 * rewrite capabilities — use wp-admin, deliberately, with a backup.
	 */
	const BLOCKED_TOOLS = array(
		'reset_roles',
		'delete_taxes',
		'clear_sessions',
		'db_update_routine',
		'regenerate_thumbnails',
		'delete_orphaned_variations',
	);

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/status',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'status.view' )
		);

		register_rest_route(
			$ns,
			'/status/tools/(?P<tool>[a-z0-9_\-]+)',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'run_tool' ), 'status.tools' )
		);
	}

	/**
	 * GET /status.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		global $wpdb, $wp_version;

		$theme       = wp_get_theme();
		$parent      = $theme->parent();
		$upload_dir  = wp_upload_dir();
		$memory      = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) : 0;

		$environment = array(
			array( 'label' => __( 'Site URL', 'yazan' ), 'value' => home_url() ),
			array( 'label' => __( 'WordPress', 'yazan' ), 'value' => $wp_version ),
			array( 'label' => __( 'WooCommerce', 'yazan' ), 'value' => defined( 'WC_VERSION' ) ? WC_VERSION : '—' ),
			array( 'label' => __( 'Yazan Core', 'yazan' ), 'value' => defined( 'YAZAN_CORE_VERSION' ) ? YAZAN_CORE_VERSION : '—' ),
			array( 'label' => __( 'PHP', 'yazan' ), 'value' => PHP_VERSION ),
			array( 'label' => __( 'MySQL', 'yazan' ), 'value' => $wpdb->db_version() ),
			array( 'label' => __( 'Web server', 'yazan' ), 'value' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '—' ),
			array( 'label' => __( 'PHP memory limit', 'yazan' ), 'value' => size_format( $memory ) ),
			array( 'label' => __( 'Max upload size', 'yazan' ), 'value' => size_format( wp_max_upload_size() ) ),
			array( 'label' => __( 'Max execution time', 'yazan' ), 'value' => ini_get( 'max_execution_time' ) . 's' ),
			array( 'label' => __( 'Uploads writable', 'yazan' ), 'value' => wp_is_writable( $upload_dir['basedir'] ) ? __( 'Yes', 'yazan' ) : __( 'No', 'yazan' ), 'warn' => ! wp_is_writable( $upload_dir['basedir'] ) ),
			array( 'label' => __( 'WP_DEBUG', 'yazan' ), 'value' => defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'On', 'yazan' ) : __( 'Off', 'yazan' ) ),
			array( 'label' => __( 'HPOS (order tables)', 'yazan' ), 'value' => 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ? __( 'Enabled', 'yazan' ) : __( 'Disabled', 'yazan' ) ),
			array( 'label' => __( 'SSL', 'yazan' ), 'value' => is_ssl() ? __( 'Yes', 'yazan' ) : __( 'No', 'yazan' ), 'warn' => ! is_ssl() ),
			array( 'label' => __( 'Theme', 'yazan' ), 'value' => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $parent ? sprintf( ' (child of %s)', $parent->get( 'Name' ) ) : '' ) ),
		);

		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$data      = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
			$plugins[] = array( 'name' => $data['Name'], 'version' => $data['Version'] );
		}

		$counts = array(
			'products'  => count( wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'status' => array( 'publish', 'draft' ) ) ) ),
			'orders'    => count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any', 'type' => 'shop_order' ) ) ),
			'customers' => (int) count_users()['total_users'],
			'coupons'   => count( get_posts( array( 'post_type' => 'shop_coupon', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) ),
			'audit'     => Yazan_Dashboard_Audit::query( array( 'per_page' => 1 ) )['total'],
		);

		return new WP_REST_Response(
			array(
				'environment' => $environment,
				'plugins'     => $plugins,
				'counts'      => $counts,
				'tools'       => self::tool_list(),
				'can_run_tools' => Yazan_Permissions::can( 'status.tools' ),
				// Security self-check: any yazan/v1 handler shipped without a permission
				// declaration, plus the state of the RBAC tables. Surfaced here so an unprotected
				// endpoint is visible from the dashboard instead of needing a grep over SSH.
				'rbac'          => Yazan_RBAC_Boot::status(),
				'route_guard'   => Yazan_REST_Guard::status(),
			),
			200
		);
	}

	/**
	 * POST /status/tools/{tool} — run one WooCommerce maintenance tool.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run_tool( WP_REST_Request $request ) {
		$tool = sanitize_key( (string) $request['tool'] );

		if ( in_array( $tool, self::BLOCKED_TOOLS, true ) ) {
			return new WP_Error(
				'yazan_blocked_tool',
				__( 'That tool is destructive and is intentionally not available from the dashboard. Run it from WooCommerce → Status → Tools, with a backup.', 'yazan' ),
				array( 'status' => 403 )
			);
		}

		$tools = self::raw_tools();
		if ( ! isset( $tools[ $tool ] ) ) {
			return new WP_Error( 'yazan_not_found', __( 'Unknown tool.', 'yazan' ), array( 'status' => 404 ) );
		}

		/*
		 * WC_Admin_Status::get_tools() only describes the tools (name/button/desc) — the actual
		 * routines live in WooCommerce's own REST tools controller. Delegating to it means we run
		 * the identical code wp-admin runs, instead of re-implementing any of it here.
		 */
		if ( ! class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
			return new WP_Error( 'yazan_unavailable', __( 'The WooCommerce tools runner is unavailable.', 'yazan' ), array( 'status' => 500 ) );
		}

		$runner = new WC_REST_System_Status_Tools_Controller();
		$result = $runner->execute_tool( $tool );

		$success = is_array( $result ) ? ! empty( $result['success'] ) : (bool) $result;
		$message = is_array( $result ) && ! empty( $result['message'] )
			? wp_strip_all_tags( (string) $result['message'] )
			: ( $tools[ $tool ]['name'] ?? __( 'Done.', 'yazan' ) );

		Yazan_Dashboard_Audit::log( 'status.tool', 'settings', 0, array( 'tool' => $tool, 'success' => $success ? 1 : 0 ) );

		if ( ! $success ) {
			return new WP_Error( 'yazan_tool_failed', $message, array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'tool' => $tool, 'message' => $message ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * WooCommerce's own tool definitions.
	 *
	 * @return array
	 */
	private static function raw_tools() {
		if ( ! class_exists( 'WC_Admin_Status' ) ) {
			$path = WC_ABSPATH . 'includes/admin/class-wc-admin-status.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
		if ( ! class_exists( 'WC_Admin_Status' ) || ! method_exists( 'WC_Admin_Status', 'get_tools' ) ) {
			return array();
		}
		return (array) WC_Admin_Status::get_tools();
	}

	/**
	 * The tools the dashboard is willing to offer, shaped for the UI.
	 *
	 * @return array
	 */
	private static function tool_list() {
		$out = array();
		foreach ( self::raw_tools() as $key => $tool ) {
			if ( in_array( $key, self::BLOCKED_TOOLS, true ) ) {
				continue;
			}
			$out[] = array(
				'key'         => $key,
				'name'        => wp_strip_all_tags( (string) ( $tool['name'] ?? $key ) ),
				'button'      => wp_strip_all_tags( (string) ( $tool['button'] ?? __( 'Run', 'yazan' ) ) ),
				'description' => wp_strip_all_tags( (string) ( $tool['desc'] ?? '' ) ),
			);
		}
		return $out;
	}
}
