<?php
/**
 * Yazan Dashboard — permission catalog (read-only).
 *
 * Serves the code-defined catalog to the Role Editor, grouped by module. The response also carries
 * `grantable`: the slugs THIS caller is allowed to hand out, so the editor can disable the rest
 * without a second round trip. That list is a UI affordance only — {@see Yazan_RBAC_Guard} enforces
 * the same subset rule on every write.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /permissions endpoints.
 */
class Yazan_REST_Permissions {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Yazan_Dashboard_Auth::NS,
			'/permissions',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'permissions.view' )
		);
	}

	/**
	 * GET /permissions — the catalog, plus what the caller may grant.
	 *
	 * @return WP_REST_Response
	 */
	public static function index() {
		$grouped   = Yazan_Permission_Registry::grouped();
		$grantable = Yazan_Permissions::grantable_by();

		// Which roles grant each permission, so the catalog browser can show "used by N roles"
		// without the client fetching every role.
		$usage = array();
		foreach ( Yazan_Roles::all() as $role ) {
			foreach ( $role['permissions'] as $slug ) {
				$usage[ $slug ] = ( $usage[ $slug ] ?? 0 ) + 1;
			}
		}

		foreach ( $grouped as &$module ) {
			foreach ( $module['permissions'] as &$permission ) {
				$permission['roles'] = (int) ( $usage[ $permission['slug'] ] ?? 0 );
			}
			unset( $permission );
		}
		unset( $module );

		return new WP_REST_Response(
			array(
				'modules'   => $grouped,
				'grantable' => array_values( $grantable ),
				'is_super'  => Yazan_Permissions::is_super(),
				'total'     => count( Yazan_Permission_Registry::all() ),
				'version'   => Yazan_Permission_Registry::REGISTRY_VERSION,
			),
			200
		);
	}
}
