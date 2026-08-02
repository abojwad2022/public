<?php
/**
 * REST surface for the store registry.
 *
 * OWN NAMESPACE, NOT `yazan/v1`. That namespace belongs to yazan-core, which polices it with a
 * default-deny guard in `enforce` mode and skips foreign sub-trees through a hand-maintained
 * allow-list. Publishing here would mean either editing that allow-list — a yazan-core change this
 * plugin is specified not to make — or having every route denied at runtime with no
 * registration-time warning. Owning a namespace removes the whole class of failure, and is the
 * same move yazan-payment-bridge-lite and the rewards public API already made.
 *
 * Authorization still runs through yazan-core's RBAC: the permission slugs are registered into its
 * catalogue via the `yazan_permission_modules` filter, so a Yazan role grants them exactly as it
 * grants any other. Own namespace, shared authority.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Rest\V1;

use Yazan\Stores\Application\StoreContext;
use Yazan\Stores\Application\StorePermissions;
use Yazan\Stores\Application\StoreService;
use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Plugin;

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
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require( StorePermissions::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->require( StorePermissions::CREATE ),
					'args'                => array(
						'slug' => array( 'required' => true, 'type' => 'string' ),
						'name' => array( 'required' => true, 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/stores/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->require( StorePermissions::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->require( StorePermissions::EDIT ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/context',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'context' ),
					'permission_callback' => $this->require( StorePermissions::VIEW ),
				),
			)
		);
	}

	/**
	 * A permission_callback that consults yazan-core's RBAC for the ACTIVE store.
	 *
	 * Fails CLOSED when RBAC is unavailable. An engine that granted access because the authority
	 * was missing would be a fail-open, and a fail-open is invisible with one tenant and a breach
	 * with two.
	 *
	 * @param string $permission Slug.
	 * @return callable
	 */
	private function require( string $permission ): callable {
		return static function () use ( $permission ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'yazan_stores_unauthenticated',
					__( 'You must be signed in.', 'yazan-stores' ),
					array( 'status' => 401 )
				);
			}

			if ( ! class_exists( 'Yazan_Permissions' ) ) {
				return new \WP_Error(
					'yazan_stores_no_rbac',
					__( 'Permissions are unavailable, so this request was refused.', 'yazan-stores' ),
					array( 'status' => 503 )
				);
			}

			if ( ! \Yazan_Permissions::can( $permission ) ) {
				return new \WP_Error(
					'yazan_stores_forbidden',
					__( 'You do not have permission to do that.', 'yazan-stores' ),
					array( 'status' => 403, 'permission' => $permission )
				);
			}

			return true;
		};
	}

	private function service(): StoreService {
		return $this->container->get( StoreService::class );
	}

	/** GET /stores */
	public function index(): \WP_REST_Response {
		$items = array_map(
			static fn( $store ): array => $store->to_array(),
			$this->service()->all()
		);

		return new \WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/** POST /stores */
	public function create( \WP_REST_Request $request ) {
		$result = $this->service()->create(
			array(
				'slug'     => (string) $request->get_param( 'slug' ),
				'name'     => (string) $request->get_param( 'name' ),
				'currency' => (string) ( $request->get_param( 'currency' ) ?? '' ),
				'theme'    => (string) ( $request->get_param( 'theme' ) ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result->to_array(), 201 );
	}

	/** GET /stores/{id} */
	public function show( \WP_REST_Request $request ) {
		$store = $this->service()->find( (int) $request['id'] );

		if ( null === $store ) {
			return new \WP_Error( 'yazan_store_not_found', __( 'That store does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'store'    => $store->to_array(),
				'domains'  => $this->service()->domains( $store->id() ),
				'settings' => $this->service()->settings( $store->id() ),
			),
			200
		);
	}

	/** PUT /stores/{id} */
	public function update( \WP_REST_Request $request ) {
		$result = $this->service()->update( (int) $request['id'], (array) $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result->to_array(), 200 );
	}

	/**
	 * GET /context — what the engine believes about the current request.
	 *
	 * Exists for operators and for the migration itself: the fastest way to diagnose "why did this
	 * request resolve to that store" is to ask the engine directly, including which rung of the
	 * kernel's ladder decided it.
	 */
	public function context(): \WP_REST_Response {
		/** @var StoreContext $context */
		$context = $this->container->get( StoreContext::class );
		$store   = $context->store();

		return new \WP_REST_Response(
			array(
				'store_id' => $context->id(),
				'source'   => class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::source() : 'kernel-absent',
				'frozen'   => class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::is_frozen() : false,
				'store'    => $store ? $store->to_array() : null,
				'theme'    => $context->theme(),
				'modules'  => $context->modules(),
				'settings' => $context->settings(),
			),
			200
		);
	}
}
