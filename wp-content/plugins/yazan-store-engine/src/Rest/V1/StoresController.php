<?php
/**
 * REST surface for the store registry.
 *
 * OWN NAMESPACE, NOT `yazan/v1`. That namespace belongs to yazan-core, which polices it with a
 * default-deny guard in `enforce` mode and skips foreign sub-trees through a hand-maintained
 * allow-list. Publishing here would mean either editing that allow-list — a yazan-core change this
 * plugin is specified not to make — or having every route denied at runtime with no
 * registration-time warning. Owning a namespace removes the whole class of failure.
 *
 * Authorization still runs through yazan-core's RBAC, but ALWAYS through {@see StoreAdminContext}
 * so that every route asks "may you do this IN THIS STORE" rather than "may you do this". That
 * distinction is what lets an administrator switch stores from the dashboard without the switch
 * being a way to gain rights they do not have.
 *
 * Route shape mirrors yazan-core's own controllers: actions are POST sub-resources rather than
 * verbs on the item, lists return `{items, total, total_pages, page}`, and items carry an
 * `editable` flag so the UI can disable Save without a second round trip.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Rest\V1;

use Yazan\Stores\Application\StoreAdminContext;
use Yazan\Stores\Application\StoreContext;
use Yazan\Stores\Application\StoreModules;
use Yazan\Stores\Application\StorePermissions;
use Yazan\Stores\Application\StoreService;
use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Plugin;
use Yazan\Stores\Domain\Store\DomainType;
use Yazan\Stores\Domain\Store\StoreStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `yazan-stores/v1`.
 */
final class StoresController {

	public function __construct( private Container $container ) {}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns = Plugin::REST_NS;

		register_rest_route(
			$ns,
			'/stores',
			array(
				$this->route( \WP_REST_Server::READABLE, 'index', StorePermissions::VIEW ),
				$this->route(
					\WP_REST_Server::CREATABLE,
					'create',
					StorePermissions::CREATE,
					array(
						'slug' => array( 'required' => true, 'type' => 'string' ),
						'name' => array( 'required' => true, 'type' => 'string' ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)',
			array(
				$this->route( \WP_REST_Server::READABLE, 'show', StorePermissions::VIEW ),
				$this->route( \WP_REST_Server::EDITABLE, 'update', StorePermissions::EDIT ),
			)
		);

		// Actions are POST sub-resources, matching yazan-core's controllers.
		foreach ( array( 'activate', 'suspend' ) as $action ) {
			register_rest_route(
				$ns,
				'/stores/(?P<id>\d+)/' . $action,
				array( $this->route( \WP_REST_Server::CREATABLE, 'status_' . $action, StorePermissions::EDIT ) )
			);
		}

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/archive',
			array( $this->route( \WP_REST_Server::CREATABLE, 'archive', StorePermissions::DELETE ) )
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/clone',
			array(
				$this->route(
					\WP_REST_Server::CREATABLE,
					'clone_store',
					StorePermissions::CREATE,
					array(
						'slug' => array( 'required' => true, 'type' => 'string' ),
						'name' => array( 'required' => true, 'type' => 'string' ),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/settings',
			array(
				$this->route( \WP_REST_Server::READABLE, 'settings_index', StorePermissions::SETTINGS ),
				$this->route( \WP_REST_Server::EDITABLE, 'settings_update', StorePermissions::SETTINGS ),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/domains',
			array(
				$this->route( \WP_REST_Server::READABLE, 'domains_index', StorePermissions::DOMAINS ),
				$this->route( \WP_REST_Server::CREATABLE, 'domains_create', StorePermissions::DOMAINS ),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/domains/(?P<domain>\d+)',
			array( $this->route( \WP_REST_Server::DELETABLE, 'domains_delete', StorePermissions::DOMAINS ) )
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/modules',
			array(
				$this->route( \WP_REST_Server::READABLE, 'modules_index', StorePermissions::MODULES ),
				$this->route( \WP_REST_Server::EDITABLE, 'modules_update', StorePermissions::MODULES ),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)/users',
			array(
				$this->route( \WP_REST_Server::READABLE, 'users_index', StorePermissions::EDIT ),
				$this->route( \WP_REST_Server::EDITABLE, 'users_update', StorePermissions::EDIT ),
			)
		);

		register_rest_route( $ns, '/context', array( $this->route( \WP_REST_Server::READABLE, 'context', StorePermissions::VIEW ) ) );

		// The switcher's list — every store this user may actually act on.
		register_rest_route( $ns, '/switchable', array( $this->route( \WP_REST_Server::READABLE, 'switchable', StorePermissions::VIEW ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Build one handler, wrapped in the authorise → switch → run triad.
	 *
	 * Emitting the callback and the permission from a single declaration is what removes the usual
	 * "two places to keep in sync" objection: a route cannot be registered without stating what it
	 * requires, and the permission is checked against the TARGET store rather than the request's.
	 *
	 * @param string $methods    HTTP methods.
	 * @param string $method     Method name on this class.
	 * @param string $permission Required permission slug.
	 * @param array  $args       Argument schema.
	 * @return array
	 */
	private function route( string $methods, string $method, string $permission, array $args = array() ): array {
		$admin = $this->admin();

		return array(
			'methods'  => $methods,
			'args'     => $args,
			'callback' => function ( \WP_REST_Request $request ) use ( $admin, $method, $permission ) {
				return $admin->run( $request, $permission, fn( int $store_id ) => $this->$method( $request, $store_id ) );
			},
			/*
			 * The real check lives in `run()`, which needs the resolved store id. This callback is
			 * the coarse gate WordPress requires: logged in, RBAC present. It must never be
			 * `__return_true` — a route whose only guard runs inside its own callback is a route
			 * that has already executed by the time it refuses.
			 */
			'permission_callback' => static function () {
				if ( ! is_user_logged_in() ) {
					return new \WP_Error( 'yazan_stores_unauthenticated', __( 'You must be signed in.', 'yazan-stores' ), array( 'status' => 401 ) );
				}

				if ( ! class_exists( 'Yazan_Permissions' ) ) {
					return new \WP_Error( 'yazan_stores_no_rbac', __( 'Permissions are unavailable.', 'yazan-stores' ), array( 'status' => 503 ) );
				}

				return true;
			},
		);
	}

	private function admin(): StoreAdminContext {
		return $this->container->get( StoreAdminContext::class );
	}

	private function service(): StoreService {
		return $this->container->get( StoreService::class );
	}

	/**
	 * Decorate a store for the client.
	 *
	 * `editable` mirrors yazan-core's convention: the UI disables Save from the payload rather than
	 * guessing, and an archived store is finished — reopening it is a status change, not an edit.
	 *
	 * @param \Yazan\Stores\Domain\Store\Store $store Store.
	 * @return array<string,mixed>
	 */
	private function present( $store ): array {
		$data = $store->to_array();

		$data['editable']    = StoreStatus::ARCHIVED !== $store->status();
		$data['is_active']   = $store->is_active();
		$data['is_platform'] = 1 === $store->id();

		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Stores                                                              */
	/* ------------------------------------------------------------------ */

	/** GET /stores */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) ( $request->get_param( 'status' ) ?? '' );
		$search = strtolower( trim( (string) ( $request->get_param( 'search' ) ?? '' ) ) );

		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		$all = array_filter(
			$this->service()->all(),
			static function ( $store ) use ( $status, $search ) {
				if ( '' !== $status && $store->status() !== $status ) {
					return false;
				}

				if ( '' !== $search
					&& false === strpos( strtolower( $store->name() ), $search )
					&& false === strpos( $store->slug(), $search ) ) {
					return false;
				}

				return true;
			}
		);

		$all   = array_values( $all );
		$total = count( $all );

		return new \WP_REST_Response(
			array(
				'items'       => array_map( array( $this, 'present' ), array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) ),
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
				'page'        => $page,
				'statuses'    => StoreStatus::all(),
			),
			200
		);
	}

	/** POST /stores */
	public function create( \WP_REST_Request $request ) {
		$result = $this->service()->create( $this->payload( $request ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 201 );
	}

	/** GET /stores/{id} */
	public function show( \WP_REST_Request $request ) {
		$store = $this->service()->find( (int) $request['id'] );

		if ( null === $store ) {
			return new \WP_Error( 'yazan_store_not_found', __( 'That store does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'store'    => $this->present( $store ),
				'domains'  => $this->service()->domains( $store->id() ),
				'settings' => $this->service()->settings( $store->id() ),
				'modules'  => $this->container->get( StoreModules::class )->for_store( $store->id(), $this->service()->settings( $store->id() ) ),
			),
			200
		);
	}

	/** PUT|PATCH /stores/{id} */
	public function update( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		/*
		 * Optimistic concurrency, matching the roles controller. The client echoes the `updated_at`
		 * it loaded; a mismatch means somebody else saved in between and a blind overwrite would
		 * silently discard their work.
		 */
		$expected = (string) ( $request->get_param( 'updated_at' ) ?? '' );

		if ( '' !== $expected ) {
			$current = $this->service()->find( $id );

			if ( $current && $current->updated_at() !== $expected ) {
				return new \WP_Error(
					'yazan_stale',
					__( 'This store changed while you were editing it. Reload and try again.', 'yazan-stores' ),
					array( 'status' => 409 )
				);
			}
		}

		$result = $this->service()->update( $id, $this->payload( $request ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 200 );
	}

	/** POST /stores/{id}/activate */
	public function status_activate( \WP_REST_Request $request ) {
		$result = $this->service()->update( (int) $request['id'], array( 'status' => StoreStatus::ACTIVE ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 200 );
	}

	/** POST /stores/{id}/suspend */
	public function status_suspend( \WP_REST_Request $request ) {
		$result = $this->guard_last_active( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->service()->update( (int) $request['id'], array( 'status' => StoreStatus::SUSPENDED ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 200 );
	}

	/** POST /stores/{id}/archive */
	public function archive( \WP_REST_Request $request ) {
		$result = $this->guard_last_active( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->service()->archive( (int) $request['id'] );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 200 );
	}

	/**
	 * Refuse to take the last reachable store off the web.
	 *
	 * Mirrors `Yazan_RBAC_Guard::assert_super_remains()`. Without it, one click can leave the
	 * platform with no active store — the hostmap empties, the kernel falls back, and the symptom
	 * is a site that looks broken for a reason nobody can see.
	 *
	 * @param int $id Store about to be taken down.
	 * @return true|\WP_Error
	 */
	private function guard_last_active( int $id ) {
		$others = array_filter(
			$this->service()->all( true ),
			static fn( $store ) => $store->id() !== $id
		);

		if ( array() !== $others ) {
			return true;
		}

		return new \WP_Error(
			'yazan_store_last_active',
			__( 'This is the only active store. Activate another one first, or the platform will have no reachable storefront.', 'yazan-stores' ),
			array( 'status' => 409 )
		);
	}

	/** POST /stores/{id}/clone */
	public function clone_store( \WP_REST_Request $request ) {
		$result = $this->service()->clone_store(
			(int) $request['id'],
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'name' )
		);

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $this->present( $result ), 201 );
	}

	/* ------------------------------------------------------------------ */
	/* Settings · domains · modules · users                                */
	/* ------------------------------------------------------------------ */

	/** GET /stores/{id}/settings */
	public function settings_index( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'settings' => $this->service()->settings( (int) $request['id'] ) ), 200 );
	}

	/** PUT /stores/{id}/settings */
	public function settings_update( \WP_REST_Request $request ): \WP_REST_Response {
		$incoming = (array) ( $request->get_param( 'settings' ) ?? array() );
		$id       = (int) $request['id'];

		$clean = array();

		foreach ( $incoming as $key => $value ) {
			$key = sanitize_text_field( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			$clean[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}

		return new \WP_REST_Response(
			array(
				'written'  => $this->service()->set_settings( $id, $clean ),
				'settings' => $this->service()->settings( $id ),
			),
			200
		);
	}

	/** GET /stores/{id}/domains */
	public function domains_index( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'items' => $this->service()->domains( (int) $request['id'] ),
				'types' => DomainType::all(),
			),
			200
		);
	}

	/** POST /stores/{id}/domains */
	public function domains_create( \WP_REST_Request $request ) {
		$result = $this->service()->add_domain(
			(int) $request['id'],
			(string) ( $request->get_param( 'host' ) ?? '' ),
			(string) ( $request->get_param( 'path' ) ?? '' ),
			(string) ( $request->get_param( 'type' ) ?? DomainType::SUBDOMAIN ),
			(bool) $request->get_param( 'is_primary' )
		);

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'id' => $result ), 201 );
	}

	/** DELETE /stores/{id}/domains/{domain} */
	public function domains_delete( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array( 'deleted' => $this->service()->remove_domain( (int) $request['domain'] ) ),
			200
		);
	}

	/** GET /stores/{id}/modules */
	public function modules_index( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request['id'];
		$modules = $this->container->get( StoreModules::class );

		return new \WP_REST_Response(
			array(
				'catalogue' => $modules->catalogue(),
				'enabled'   => $modules->for_store( $id, $this->service()->settings( $id ) ),
			),
			200
		);
	}

	/** PUT /stores/{id}/modules */
	public function modules_update( \WP_REST_Request $request ): \WP_REST_Response {
		$id        = (int) $request['id'];
		$incoming  = (array) ( $request->get_param( 'modules' ) ?? array() );
		$catalogue = $this->container->get( StoreModules::class )->catalogue();

		foreach ( $incoming as $module => $enabled ) {
			// Only known modules. An unknown key is stale configuration, not a new feature.
			if ( ! array_key_exists( (string) $module, $catalogue ) ) {
				continue;
			}

			$this->service()->set_module( $id, (string) $module, (bool) $enabled );
		}

		return $this->modules_index( $request );
	}

	/** GET /stores/{id}/users */
	public function users_index( \WP_REST_Request $request, int $store_id ): \WP_REST_Response {
		/*
		 * Only offer roles the caller could actually assign here. Handing the raw catalogue to the
		 * UI meant the staff screen listed super-admin to anyone who could edit a store — a button
		 * that either escalates or always fails, and both are worse than its absence.
		 */
		$roles = array();

		if ( class_exists( 'Yazan_Roles' ) && class_exists( 'Yazan_RBAC_Guard' ) ) {
			foreach ( \Yazan_Roles::all() as $role ) {
				if ( ! is_wp_error( \Yazan_RBAC_Guard::assert_can_assign_roles( array( (int) $role['id'] ), null, $store_id ) ) ) {
					$roles[] = $role;
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'items' => $this->service()->users( (int) $request['id'] ),
				'roles' => $roles,
			),
			200
		);
	}

	/** PUT /stores/{id}/users */
	public function users_update( \WP_REST_Request $request ) {
		$result = $this->service()->set_user_roles(
			(int) $request['id'],
			absint( $request->get_param( 'user_id' ) ),
			array_map( 'absint', (array) ( $request->get_param( 'role_ids' ) ?? array() ) )
		);

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'ok' => true ) + (array) $result, 200 );
	}

	/* ------------------------------------------------------------------ */
	/* Context                                                             */
	/* ------------------------------------------------------------------ */

	/** GET /context */
	public function context( \WP_REST_Request $request, int $store_id ): \WP_REST_Response {
		/** @var StoreContext $context */
		$context = $this->container->get( StoreContext::class );
		$store   = $context->store();

		return new \WP_REST_Response(
			array(
				'store_id'  => $context->id(),
				'requested' => $store_id,
				'source'    => class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::source() : 'kernel-absent',
				'frozen'    => class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::is_frozen() : false,
				'store'     => $store ? $this->present( $store ) : null,
				'theme'     => $context->theme(),
				'modules'   => $context->modules(),
				'settings'  => $context->settings(),
			),
			200
		);
	}

	/** GET /switchable */
	public function switchable(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'items' => $this->admin()->switchable_stores() ), 200 );
	}

	/* ------------------------------------------------------------------ */
	/* Input                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Whitelist and sanitise the writable store fields.
	 *
	 * The previous PUT handler passed `get_json_params()` straight through. The service has its own
	 * allow-list so nothing unexpected could be written, but sanitising here means the values that
	 * DO get through are the right shape — and it documents the writable surface in one place.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function payload( \WP_REST_Request $request ): array {
		$out = array();

		$text = array( 'slug', 'name', 'status', 'type', 'currency', 'locale', 'timezone', 'theme', 'languages' );

		foreach ( $text as $field ) {
			$value = $request->get_param( $field );

			if ( null !== $value ) {
				$out[ $field ] = sanitize_text_field( (string) $value );
			}
		}

		foreach ( array( 'owner_user_id', 'parent_store_id', 'logo_attachment_id' ) as $field ) {
			$value = $request->get_param( $field );

			if ( null !== $value ) {
				$out[ $field ] = absint( $value );
			}
		}

		return $out;
	}
}
