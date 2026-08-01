<?php
/**
 * Yazan Dashboard — roles.
 *
 * CRUD over the role table plus the grant editor's save path. Every mutation runs the
 * {@see Yazan_RBAC_Guard} preconditions first, and the destructive ones hold a named MySQL lock
 * across check-and-write so two concurrent operators cannot both slip past the
 * "another super admin still exists" test.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /roles endpoints.
 */
class Yazan_REST_Roles {

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/roles',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'roles.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'roles.create' ),
			)
		);

		register_rest_route(
			$ns,
			'/roles/(?P<id>\d+)',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'show' ), 'roles.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'roles.edit' ),
				Yazan_REST_Guard::args( WP_REST_Server::DELETABLE, array( __CLASS__, 'destroy' ), 'roles.delete' ),
			)
		);

		register_rest_route(
			$ns,
			'/roles/(?P<id>\d+)/users',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'members' ), 'users.view' )
		);

		register_rest_route(
			$ns,
			'/roles/(?P<id>\d+)/duplicate',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'duplicate' ), 'roles.create' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /roles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$with_counts = ! in_array( $request->get_param( 'with_counts' ), array( '0', 0, false, 'false' ), true );

		return new WP_REST_Response(
			array(
				'items'    => Yazan_Roles::all( $with_counts ),
				'is_super' => Yazan_Permissions::is_super(),
			),
			200
		);
	}

	/**
	 * GET /roles/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$role = Yazan_Roles::get( absint( $request['id'] ) );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		// A viewer may open a role they are not allowed to change, so say so explicitly rather than
		// letting the Save button fail.
		$role['editable'] = ! is_wp_error( Yazan_RBAC_Guard::assert_can_manage_role( $role ) );

		return new WP_REST_Response( $role, 200 );
	}

	/**
	 * GET /roles/{id}/users.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function members( WP_REST_Request $request ) {
		$role = Yazan_Roles::get( absint( $request['id'] ) );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$items = array();
		foreach ( Yazan_Roles::user_ids_in_role( $role['id'] ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$items[] = array(
					'id'     => (int) $user->ID,
					'name'   => $user->display_name,
					'email'  => $user->user_email,
					'avatar' => get_avatar_url( $user->ID, array( 'size' => 40 ) ),
					'status' => Yazan_Users::status( $user->ID ),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => count( $items ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Write                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /roles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$permissions = self::clean_slugs( $request->get_param( 'permissions' ) );
		$wants_super = (bool) $request->get_param( 'is_super' );

		$check = Yazan_RBAC_Guard::assert_can_set_super( $wants_super );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$check = Yazan_RBAC_Guard::assert_can_grant( $permissions );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$role_id = Yazan_Roles::create(
			array(
				'name'        => $request->get_param( 'name' ),
				'slug'        => $request->get_param( 'slug' ),
				'description' => $request->get_param( 'description' ),
				'is_super'    => $wants_super,
				'sort'        => $request->get_param( 'sort' ) ?? 100,
				'permissions' => $permissions,
			)
		);

		if ( is_wp_error( $role_id ) ) {
			return $role_id;
		}

		$role = Yazan_Roles::get( $role_id );

		Yazan_Dashboard_Audit::log(
			'role.create',
			'role',
			$role_id,
			array(
				'name'        => $role['name'],
				'slug'        => $role['slug'],
				'permissions' => count( $permissions ),
			)
		);

		return new WP_REST_Response( $role, 201 );
	}

	/**
	 * PUT /roles/{id} — metadata and, when `permissions` is present, a full grant replace.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$role = Yazan_Roles::get( absint( $request['id'] ) );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$check = Yazan_RBAC_Guard::assert_can_manage_role( $role );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$check = Yazan_RBAC_Guard::assert_fresh( $role, (string) $request->get_param( 'updated_at' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$has_permissions = null !== $request->get_param( 'permissions' );
		$permissions     = $has_permissions ? self::clean_slugs( $request->get_param( 'permissions' ) ) : array();

		if ( $has_permissions ) {
			$check = Yazan_RBAC_Guard::assert_can_grant( $permissions );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$fields = array();
		foreach ( array( 'name', 'description', 'sort' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}

		$demoting_super = false;
		if ( null !== $request->get_param( 'is_super' ) ) {
			$wants_super = (bool) $request->get_param( 'is_super' );

			$check = Yazan_RBAC_Guard::assert_can_set_super( $wants_super );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$demoting_super     = $role['is_super'] && ! $wants_super;
			$fields['is_super'] = $wants_super;
		}

		/*
		 * Removing the super flag, and stripping grants from a super role, are both ways to end up
		 * with nobody able to administer the store. Hold the write lock across the check and the
		 * write so a concurrent request cannot pass the same test.
		 */
		$locked = false;
		if ( $demoting_super ) {
			$locked = Yazan_Roles::lock();

			$check = Yazan_RBAC_Guard::assert_super_remains( 0, $role['id'] );
			if ( is_wp_error( $check ) ) {
				if ( $locked ) {
					Yazan_Roles::unlock();
				}
				return $check;
			}
		}

		if ( $fields ) {
			$result = Yazan_Roles::update( $role['id'], $fields );
			if ( is_wp_error( $result ) ) {
				if ( $locked ) {
					Yazan_Roles::unlock();
				}
				return $result;
			}
		}

		$diff = array(
			'added'   => array(),
			'removed' => array(),
		);
		if ( $has_permissions ) {
			$diff = Yazan_Roles::set_permissions( $role['id'], $permissions );
			if ( is_wp_error( $diff ) ) {
				if ( $locked ) {
					Yazan_Roles::unlock();
				}
				return $diff;
			}
		}

		if ( $locked ) {
			Yazan_Roles::unlock();
		}

		Yazan_Dashboard_Audit::log(
			$has_permissions ? 'role.permissions_update' : 'role.update',
			'role',
			$role['id'],
			array(
				'name'    => $role['name'],
				'added'   => $diff['added'],
				'removed' => $diff['removed'],
				'count'   => count( $permissions ),
			)
		);

		return new WP_REST_Response( Yazan_Roles::get( $role['id'] ), 200 );
	}

	/**
	 * DELETE /roles/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$role = Yazan_Roles::get( absint( $request['id'] ) );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		$check = Yazan_RBAC_Guard::assert_can_manage_role( $role );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$locked = Yazan_Roles::lock();

		if ( $role['is_super'] ) {
			$check = Yazan_RBAC_Guard::assert_super_remains( 0, $role['id'] );
			if ( is_wp_error( $check ) ) {
				if ( $locked ) {
					Yazan_Roles::unlock();
				}
				return $check;
			}
		}

		$result = Yazan_Roles::delete( $role['id'], absint( $request->get_param( 'reassign_to' ) ) );

		if ( $locked ) {
			Yazan_Roles::unlock();
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Yazan_Dashboard_Audit::log(
			'role.delete',
			'role',
			$role['id'],
			array(
				'name'        => $role['name'],
				'slug'        => $role['slug'],
				'reassign_to' => absint( $request->get_param( 'reassign_to' ) ),
			)
		);

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /roles/{id}/duplicate — copy a role's grants into a new, non-system role.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate( WP_REST_Request $request ) {
		$role = Yazan_Roles::get( absint( $request['id'] ) );
		if ( ! $role ) {
			return new WP_Error( 'yazan_role_missing', __( 'Role not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		// Copying is a grant, so the source role's permissions must be within reach too.
		$check = Yazan_RBAC_Guard::assert_can_grant( $role['permissions'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			/* translators: %s: the role being copied. */
			$name = sprintf( __( '%s (copy)', 'yazan' ), $role['name'] );
		}

		$role_id = Yazan_Roles::create(
			array(
				'name'        => $name,
				'description' => $role['description'],
				// A copy is never super and never system — those are deliberate, granted states.
				'is_super'    => false,
				'is_system'   => false,
				'sort'        => $role['sort'] + 1,
				'permissions' => $role['permissions'],
			)
		);

		if ( is_wp_error( $role_id ) ) {
			return $role_id;
		}

		Yazan_Dashboard_Audit::log(
			'role.duplicate',
			'role',
			$role_id,
			array(
				'source' => $role['slug'],
				'name'   => $name,
			)
		);

		return new WP_REST_Response( Yazan_Roles::get( $role_id ), 201 );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Normalise a permissions payload to a unique list of strings.
	 *
	 * @param mixed $raw Request parameter.
	 * @return string[]
	 */
	private static function clean_slugs( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $slug ) {
			if ( is_string( $slug ) && '' !== trim( $slug ) ) {
				$out[ trim( $slug ) ] = true;
			}
		}

		return array_keys( $out );
	}
}
