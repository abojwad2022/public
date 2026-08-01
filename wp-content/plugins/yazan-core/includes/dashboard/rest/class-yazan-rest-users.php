<?php
/**
 * Yazan Dashboard — staff users.
 *
 * "Users" here means STAFF: accounts that hold a Yazan role and can sign into /dashboard. Shoppers
 * stay on the separate Customers screen. The distinction is enforced by scoping every query to an
 * explicit id list built from the `yazan_user_roles` pivot, so a customer can never appear in a
 * staff listing by accident — a whitelist rather than a "not a customer" blacklist, because a
 * blacklist would also hide a real staff member who happens to shop here.
 *
 * Real WordPress administrators are always included, read-only for anyone who is not a super
 * admin, so the screen never lies about who can actually do things.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /users endpoints.
 */
class Yazan_REST_Users {

	/** Minimum length for an administrator-set password. */
	const MIN_PASSWORD = 12;

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Yazan_Dashboard_Auth::NS;

		register_rest_route(
			$ns,
			'/users',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'index' ), 'users.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'create' ), 'users.create' ),
			)
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)',
			array(
				Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'show' ), 'users.view' ),
				Yazan_REST_Guard::args( WP_REST_Server::EDITABLE, array( __CLASS__, 'update' ), 'users.edit' ),
				Yazan_REST_Guard::args( WP_REST_Server::DELETABLE, array( __CLASS__, 'destroy' ), 'users.delete' ),
			)
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/suspend',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'suspend' ), 'users.suspend' )
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/activate',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'activate' ), 'users.suspend' )
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/reset-password',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'reset_password' ), 'users.reset_password' )
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/force-logout',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'force_logout' ), 'users.force_logout' )
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/activity',
			Yazan_REST_Guard::args( WP_REST_Server::READABLE, array( __CLASS__, 'activity' ), 'users.view_activity' )
		);

		register_rest_route(
			$ns,
			'/users/(?P<id>\d+)/photo',
			Yazan_REST_Guard::args( WP_REST_Server::CREATABLE, array( __CLASS__, 'photo' ), 'users.edit' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /users — the staff list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ) {
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$role     = absint( $request->get_param( 'role' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );

		$ids = self::staff_scope();

		if ( $role ) {
			$ids = array_values( array_intersect( $ids, Yazan_Roles::user_ids_in_role( $role ) ) );
		}

		if ( in_array( $status, array( Yazan_Users::STATUS_ACTIVE, Yazan_Users::STATUS_SUSPENDED ), true ) ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $status ) {
						return Yazan_Users::status( $id ) === $status;
					}
				)
			);
		}

		if ( ! $ids ) {
			return new WP_REST_Response(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
					'page'        => $page,
				),
				200
			);
		}

		$orderby_map = array(
			'name'       => 'display_name',
			'email'      => 'user_email',
			'registered' => 'user_registered',
		);
		$orderby = $orderby_map[ (string) $request->get_param( 'orderby' ) ] ?? 'display_name';
		$order   = 'desc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'DESC' : 'ASC';

		$args = array(
			'include' => $ids,
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => $orderby,
			'order'   => $order,
			'fields'  => 'all',
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$query = new WP_User_Query( $args );
		$items = array();

		foreach ( $query->get_results() as $user ) {
			$items[] = self::row( $user );
		}

		$total = (int) $query->get_total();

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
				'page'        => $page,
				'roles'       => Yazan_Roles::all( true ),
			),
			200
		);
	}

	/**
	 * GET /users/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$data                       = Yazan_Users::payload( $user );
		$data['editable']           = ! is_wp_error( Yazan_RBAC_Guard::assert_can_edit_user( $user->ID ) );
		$data['is_self']            = get_current_user_id() === (int) $user->ID;
		$data['can_set_wp_role']    = self::may_set_wp_role( $user );
		$data['wp_roles_available'] = self::assignable_wp_roles();

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /users/{id}/activity — this account's slice of the audit log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activity( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$result = Yazan_Dashboard_Audit::query(
			array(
				'user_id'  => $user->ID,
				'page'     => absint( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => absint( $request->get_param( 'per_page' ) ?: 25 ),
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Write                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /users — create a staff account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $name ) {
			return new WP_Error( 'yazan_invalid', __( 'A name is required.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'yazan_invalid', __( 'A valid email address is required.', 'yazan' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'yazan_email_taken', __( 'An account with that email already exists.', 'yazan' ), array( 'status' => 409 ) );
		}

		$role_ids = self::clean_ids( $request->get_param( 'roles' ) );
		if ( ! $role_ids ) {
			return new WP_Error( 'yazan_invalid', __( 'Choose at least one role.', 'yazan' ), array( 'status' => 400 ) );
		}

		$check = Yazan_RBAC_Guard::assert_can_assign_roles( $role_ids );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$password = (string) $request->get_param( 'password' );
		if ( '' === $password ) {
			// No password supplied: mint a strong one and send the reset link instead, so nobody
			// ever has to transmit a credential by hand.
			$password = wp_generate_password( 24, true, true );
		} elseif ( strlen( $password ) < self::MIN_PASSWORD ) {
			return new WP_Error(
				'yazan_weak_password',
				/* translators: %d: minimum password length. */
				sprintf( __( 'Passwords must be at least %d characters.', 'yazan' ), self::MIN_PASSWORD ),
				array( 'status' => 400 )
			);
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
				'last_name'    => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
				/*
				 * The WordPress role stays `subscriber`. Everything this account may do comes from
				 * its Yazan roles, projected onto capabilities at runtime — creating staff here must
				 * never be a way to mint a WordPress administrator.
				 */
				'role'         => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'yazan_user_create', $user_id->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Users::set_phone( $user_id, (string) $request->get_param( 'phone' ) );
		Yazan_Users::set_status( $user_id, (string) ( $request->get_param( 'status' ) ?: Yazan_Users::STATUS_ACTIVE ) );
		Yazan_Users::set_avatar_id( $user_id, absint( $request->get_param( 'avatar_id' ) ) );
		Yazan_Roles::set_user_roles( $user_id, $role_ids );

		if ( $request->get_param( 'send_invite' ) ) {
			// Core's own email, with core's own rate limiting and expiry handling.
			retrieve_password( get_userdata( $user_id )->user_login );
		}

		Yazan_Dashboard_Audit::log(
			'user.create',
			'user',
			$user_id,
			array(
				'email' => $email,
				'roles' => $role_ids,
			)
		);

		return new WP_REST_Response( Yazan_Users::payload( get_userdata( $user_id ) ), 201 );
	}

	/**
	 * PUT /users/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$fields = array( 'ID' => $user->ID );

		if ( null !== $request->get_param( 'name' ) ) {
			$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error( 'yazan_invalid', __( 'A name is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			$fields['display_name'] = $name;
		}
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$fields[ $field ] = sanitize_text_field( (string) $request->get_param( $field ) );
			}
		}

		if ( null !== $request->get_param( 'email' ) ) {
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'yazan_invalid', __( 'A valid email address is required.', 'yazan' ), array( 'status' => 400 ) );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== (int) $user->ID ) {
				return new WP_Error( 'yazan_email_taken', __( 'An account with that email already exists.', 'yazan' ), array( 'status' => 409 ) );
			}
			$fields['user_email'] = $email;
		}

		if ( count( $fields ) > 1 ) {
			$result = wp_update_user( $fields );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'yazan_user_update', $result->get_error_message(), array( 'status' => 400 ) );
			}
		}

		/*
		 * WordPress role. Separate from Yazan roles and far more consequential: an `administrator`
		 * bypasses every Yazan permission by design (the lockout backstop), so demoting one is the
		 * switch that makes Yazan roles actually take effect on that account.
		 */
		$wp_role_change = null;
		if ( null !== $request->get_param( 'wp_role' ) ) {
			$wp_role = sanitize_key( (string) $request->get_param( 'wp_role' ) );
			$current = self::primary_wp_role( $user );

			if ( $wp_role !== $current ) {
				$check = self::guard_wp_role_change( $user, $wp_role );
				if ( is_wp_error( $check ) ) {
					return $check;
				}

				// set_role() with '' strips every role; it also fires `set_user_role`, which
				// Yazan_RBAC_Boot listens to in order to drop the cached permission set.
				$target = get_userdata( $user->ID );
				$target->set_role( $wp_role );

				$wp_role_change = array(
					'from' => $current,
					'to'   => $wp_role ? $wp_role : 'none',
				);
			}
		}

		if ( null !== $request->get_param( 'phone' ) ) {
			Yazan_Users::set_phone( $user->ID, (string) $request->get_param( 'phone' ) );
		}
		if ( null !== $request->get_param( 'avatar_id' ) ) {
			Yazan_Users::set_avatar_id( $user->ID, absint( $request->get_param( 'avatar_id' ) ) );
		}

		$role_diff = null;
		if ( null !== $request->get_param( 'roles' ) ) {
			$role_ids = self::clean_ids( $request->get_param( 'roles' ) );

			/*
			 * Changing your own roles is the single easiest way to lock yourself out of the store,
			 * and it is never necessary — someone else can always do it.
			 */
			$check = Yazan_RBAC_Guard::assert_not_self( $user->ID );
			if ( is_wp_error( $check ) && $role_ids !== Yazan_Roles::for_user( $user->ID ) ) {
				return $check;
			}

			$check = Yazan_RBAC_Guard::assert_can_assign_roles( $role_ids );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$locked = Yazan_Roles::lock();

			// If this user is currently the only super admin, the new role set must keep one.
			if ( Yazan_Permissions::is_super( $user->ID ) && ! self::roles_include_super( $role_ids ) ) {
				$check = Yazan_RBAC_Guard::assert_super_remains( $user->ID );
				if ( is_wp_error( $check ) ) {
					if ( $locked ) {
						Yazan_Roles::unlock();
					}
					return $check;
				}
			}

			$role_diff = Yazan_Roles::set_user_roles( $user->ID, $role_ids );

			if ( $locked ) {
				Yazan_Roles::unlock();
			}
		}

		Yazan_Permissions::flush_user( $user->ID );

		Yazan_Dashboard_Audit::log(
			'user.update',
			'user',
			$user->ID,
			array(
				'fields'        => array_values( array_diff( array_keys( $fields ), array( 'ID' ) ) ),
				'roles_added'   => $role_diff['added'] ?? array(),
				'roles_removed' => $role_diff['removed'] ?? array(),
			)
		);

		// A site-level privilege change gets its own audit entry — it should never be buried inside
		// a generic "user.update" alongside a phone number edit.
		if ( $wp_role_change ) {
			Yazan_Dashboard_Audit::log(
				'user.wp_role_change',
				'user',
				$user->ID,
				array(
					'email' => $user->user_email,
					'from'  => $wp_role_change['from'],
					'to'    => $wp_role_change['to'],
				)
			);
		}

		$data                       = Yazan_Users::payload( get_userdata( $user->ID ) );
		$data['editable']           = ! is_wp_error( Yazan_RBAC_Guard::assert_can_edit_user( $user->ID ) );
		$data['is_self']            = get_current_user_id() === (int) $user->ID;
		$data['can_set_wp_role']    = self::may_set_wp_role( get_userdata( $user->ID ) );
		$data['wp_roles_available'] = self::assignable_wp_roles();

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * All preconditions for moving an account onto a different WordPress role.
	 *
	 * @param WP_User $user    Target.
	 * @param string  $wp_role Target role slug ('' strips every role).
	 * @return true|WP_Error
	 */
	private static function guard_wp_role_change( WP_User $user, $wp_role ) {
		if ( ! Yazan_Permissions::can( 'users.wp_role' ) ) {
			return new WP_Error(
				'yazan_forbidden',
				__( 'You do not have permission to change WordPress roles.', 'yazan' ),
				array(
					'status'     => rest_authorization_required_code(),
					'permission' => 'users.wp_role',
				)
			);
		}

		// Changing your own WordPress role is the single fastest way to lock yourself out of
		// wp-admin, and someone else can always do it for you.
		$check = Yazan_RBAC_Guard::assert_not_self(
			$user->ID,
			__( 'You cannot change your own WordPress role. Ask another administrator.', 'yazan' )
		);
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( '' !== $wp_role && ! wp_roles()->is_role( $wp_role ) ) {
			return new WP_Error( 'yazan_invalid', __( 'That WordPress role does not exist.', 'yazan' ), array( 'status' => 400 ) );
		}

		$check = Yazan_RBAC_Guard::assert_can_set_wp_role( $wp_role );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Only demotions need the "someone must remain" check.
		if ( Yazan_Users::is_wp_admin( $user ) && 'administrator' !== $wp_role ) {
			$check = Yazan_RBAC_Guard::assert_wp_admin_remains( $user->ID );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return true;
	}

	/**
	 * The account's primary WordPress role.
	 *
	 * @param WP_User $user User.
	 * @return string '' when the account holds no role.
	 */
	private static function primary_wp_role( WP_User $user ) {
		$roles = array_values( (array) $user->roles );
		return $roles ? (string) $roles[0] : '';
	}

	/**
	 * May the current caller change this account's WordPress role?
	 *
	 * @param WP_User $user Target.
	 * @return bool
	 */
	private static function may_set_wp_role( WP_User $user ) {
		if ( get_current_user_id() === (int) $user->ID ) {
			return false;
		}
		if ( ! Yazan_Permissions::can( 'users.wp_role' ) ) {
			return false;
		}
		return ! is_wp_error( Yazan_RBAC_Guard::assert_can_set_wp_role( '' ) );
	}

	/**
	 * WordPress roles the caller may assign, labelled for a select.
	 *
	 * `administrator` is omitted entirely unless the caller is one themselves, so the UI never
	 * offers an option the server is guaranteed to refuse.
	 *
	 * @return array<int,array{value:string,label:string,warn:bool}>
	 */
	private static function assignable_wp_roles() {
		$can_grant_admin = Yazan_Users::is_wp_admin( get_current_user_id() );
		$out             = array();

		foreach ( wp_roles()->get_names() as $slug => $label ) {
			if ( 'administrator' === $slug && ! $can_grant_admin ) {
				continue;
			}
			$out[] = array(
				'value' => $slug,
				'label' => translate_user_role( $label ),
				// Flags the roles that bypass Yazan permissions, so the UI can say so.
				'warn'  => in_array( $slug, array( 'administrator' ), true ),
			);
		}

		return $out;
	}

	/**
	 * DELETE /users/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_not_self( $user->ID, __( 'You cannot delete your own account.', 'yazan' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$locked = Yazan_Roles::lock();

		$check = Yazan_RBAC_Guard::assert_super_remains( $user->ID );
		if ( is_wp_error( $check ) ) {
			if ( $locked ) {
				Yazan_Roles::unlock();
			}
			return $check;
		}

		$email    = $user->user_email;
		$reassign = absint( $request->get_param( 'reassign' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wp_delete_user( $user->ID, $reassign ?: null );

		if ( $locked ) {
			Yazan_Roles::unlock();
		}

		if ( ! $deleted ) {
			return new WP_Error( 'yazan_user_delete', __( 'Could not delete that account.', 'yazan' ), array( 'status' => 500 ) );
		}

		Yazan_Dashboard_Audit::log(
			'user.delete',
			'user',
			$user->ID,
			array(
				'email'    => $email,
				'reassign' => $reassign,
			)
		);

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Account actions                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /users/{id}/suspend.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function suspend( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_not_self( $user->ID, __( 'You cannot suspend your own account.', 'yazan' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$locked = Yazan_Roles::lock();

		// A suspended super admin cannot sign in to undo anything, so suspension counts as removal
		// for the purposes of "is there still somebody in charge".
		$check = Yazan_RBAC_Guard::assert_super_remains( $user->ID );
		if ( is_wp_error( $check ) ) {
			if ( $locked ) {
				Yazan_Roles::unlock();
			}
			return $check;
		}

		Yazan_Users::set_status( $user->ID, Yazan_Users::STATUS_SUSPENDED );

		if ( $locked ) {
			Yazan_Roles::unlock();
		}

		Yazan_Dashboard_Audit::log( 'user.suspend', 'user', $user->ID, array( 'email' => $user->user_email ) );

		return new WP_REST_Response( Yazan_Users::payload( get_userdata( $user->ID ) ), 200 );
	}

	/**
	 * POST /users/{id}/activate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activate( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		Yazan_Users::set_status( $user->ID, Yazan_Users::STATUS_ACTIVE );

		Yazan_Dashboard_Audit::log( 'user.activate', 'user', $user->ID, array( 'email' => $user->user_email ) );

		return new WP_REST_Response( Yazan_Users::payload( get_userdata( $user->ID ) ), 200 );
	}

	/**
	 * POST /users/{id}/reset-password.
	 *
	 * `link` is the default and the recommended path: core sends its standard email, handles its own
	 * rate limiting and expiry, and nobody ever sees or transmits the password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reset_password( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$mode = 'set' === $request->get_param( 'mode' ) ? 'set' : 'link';

		if ( 'link' === $mode ) {
			$sent = retrieve_password( $user->user_login );
			if ( is_wp_error( $sent ) ) {
				return new WP_Error( 'yazan_reset_failed', $sent->get_error_message(), array( 'status' => 500 ) );
			}

			// Field NAMES only — Yazan_Dashboard_Audit::sanitize_changes() has no notion of secrets,
			// so a value must never reach it.
			Yazan_Dashboard_Audit::log( 'user.password_reset_link', 'user', $user->ID, array( 'email' => $user->user_email ) );

			return new WP_REST_Response( array( 'sent' => true ), 200 );
		}

		/*
		 * wp_set_password() destroys every session the target has. Doing that to yourself would
		 * 401 the very response carrying the result, so self-service goes through the link flow.
		 */
		$check = Yazan_RBAC_Guard::assert_not_self(
			$user->ID,
			__( 'Use the reset link for your own account — setting a password here would sign you out immediately.', 'yazan' )
		);
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$password = (string) $request->get_param( 'password' );
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			return new WP_Error(
				'yazan_weak_password',
				/* translators: %d: minimum password length. */
				sprintf( __( 'Passwords must be at least %d characters.', 'yazan' ), self::MIN_PASSWORD ),
				array( 'status' => 400 )
			);
		}

		wp_set_password( $password, $user->ID );

		Yazan_Dashboard_Audit::log( 'user.password_set', 'user', $user->ID, array( 'fields' => array( 'password' ) ) );

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * POST /users/{id}/force-logout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function force_logout( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// Allowed on yourself — it is how you clear a forgotten session on another device — but it
		// keeps the current one so the response still reaches you.
		Yazan_Users::destroy_sessions( $user->ID, true );

		Yazan_Dashboard_Audit::log( 'user.force_logout', 'user', $user->ID, array( 'email' => $user->user_email ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /users/{id}/photo — upload and attach a profile photo.
	 *
	 * Handled here rather than through /media so a role that administers staff does not also need
	 * a media-library grant just to set someone's picture.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function photo( WP_REST_Request $request ) {
		$user = self::staff_user( $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$check = Yazan_RBAC_Guard::assert_can_edit_user( $user->ID );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'yazan_no_file', __( 'No file was uploaded.', 'yazan' ), array( 'status' => 400 ) );
		}

		$check = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			return new WP_Error( 'yazan_bad_file', __( 'Profile photos must be an image.', 'yazan' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'yazan_upload_failed', $attachment_id->get_error_message(), array( 'status' => 400 ) );
		}

		Yazan_Users::set_avatar_id( $user->ID, $attachment_id );

		Yazan_Dashboard_Audit::log( 'user.photo_update', 'user', $user->ID, array( 'attachment' => $attachment_id ) );

		return new WP_REST_Response(
			array(
				'avatar_id' => (int) $attachment_id,
				'avatar'    => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			),
			200
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * The complete set of user ids this screen may show.
	 *
	 * @return int[]
	 */
	private static function staff_scope() {
		$ids = Yazan_Roles::staff_ids();

		// Always surface real WordPress administrators, even without a Yazan role — they can do
		// everything regardless, and hiding them would make the screen misleading.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 100,
			)
		);

		return array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $admins ) ) ) );
	}

	/**
	 * Load a user, refusing anyone outside the staff scope.
	 *
	 * @param mixed $id Route id.
	 * @return WP_User|WP_Error
	 */
	private static function staff_user( $id ) {
		$id   = absint( $id );
		$user = $id ? get_userdata( $id ) : null;

		if ( ! $user ) {
			return new WP_Error( 'yazan_user_missing', __( 'User not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		// 404 rather than 403 — a staff screen should not confirm the existence of customer ids.
		if ( ! in_array( (int) $user->ID, self::staff_scope(), true ) ) {
			return new WP_Error( 'yazan_user_missing', __( 'User not found.', 'yazan' ), array( 'status' => 404 ) );
		}

		return $user;
	}

	/**
	 * Public row shape for the staff table.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	private static function row( WP_User $user ) {
		$data             = Yazan_Users::payload( $user );
		$data['editable'] = ! is_wp_error( Yazan_RBAC_Guard::assert_can_edit_user( $user->ID ) );
		$data['is_self']  = get_current_user_id() === (int) $user->ID;

		return $data;
	}

	/**
	 * Would this role set keep the user a super admin?
	 *
	 * @param int[] $role_ids Role ids.
	 * @return bool
	 */
	private static function roles_include_super( array $role_ids ) {
		foreach ( $role_ids as $role_id ) {
			$role = Yazan_Roles::get( $role_id );
			if ( $role && $role['is_super'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Derive a free `user_login` from an email address.
	 *
	 * Staff never type it: core's wp_authenticate_email_password accepts the email in the login
	 * field, so the generated username is an internal detail.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private static function unique_login( $email ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) {
			$base = 'staff';
		}

		$candidate = $base;
		$suffix    = 2;

		while ( username_exists( $candidate ) ) {
			$candidate = $base . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Normalise an id-array parameter.
	 *
	 * @param mixed $raw Request parameter.
	 * @return int[]
	 */
	private static function clean_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( $id ) {
				$out[ $id ] = true;
			}
		}

		return array_keys( $out );
	}
}
