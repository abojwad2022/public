<?php
/**
 * Admin rules REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Modules\Rules\ActionCatalog;
use Yazan\Rewards\Modules\Rules\ConditionCatalog;
use Yazan\Rewards\Modules\Rules\EventCatalog;
use Yazan\Rewards\Modules\Rules\RuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No-code rules management (admin, `manage_yazan_rewards`):
 *   GET    /rules            — list all rules.
 *   POST   /rules            — create a rule (event + conditions + actions).
 *   GET    /rules/{id}       — one rule.
 *   PUT    /rules/{id}       — update.
 *   DELETE /rules/{id}       — delete.
 *   GET    /rules/schema     — event/condition/action catalogs + option lists,
 *                              so the admin UI is fully data-driven.
 */
final class RulesController extends AbstractController {

	private RuleRepository $repo;

	private EventCatalog $events;

	private ConditionCatalog $conditions;

	private ActionCatalog $actions;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo       = $container->get( RuleRepository::class );
		$this->events     = $container->get( EventCatalog::class );
		$this->conditions = $container->get( ConditionCatalog::class );
		$this->actions    = $container->get( ActionCatalog::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'rules';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route(
			$this->namespace,
			'/' . $this->base(),
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $manager ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create' ), 'permission_callback' => $manager ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/schema',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'schema' ), 'permission_callback' => $manager ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/(?P<id>\d+)',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'show' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
				array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
				array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'destroy' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
			)
		);
	}

	/**
	 * GET /rules.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		return $this->ok( array( 'items' => array_map( static fn( $r ) => $r->to_array(), $this->repo->all() ) ) );
	}

	/**
	 * GET /rules/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$rule = $this->repo->get( absint( $request['id'] ) );
		return $rule ? $this->ok( $rule->to_array() ) : $this->error( 'not_found', __( 'Rule not found.', 'yazan-rewards' ), 404 );
	}

	/**
	 * POST /rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$data = $this->validate( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = $this->repo->create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Could not save the rule.', 'yazan-rewards' ), 500 );
		}
		return $this->ok( $this->repo->get( $id )->to_array(), 201 );
	}

	/**
	 * PUT /rules/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! $this->repo->get( $id ) ) {
			return $this->error( 'not_found', __( 'Rule not found.', 'yazan-rewards' ), 404 );
		}
		$data = $this->validate( $request, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$this->repo->update_rule( $id, $data );
		return $this->ok( $this->repo->get( $id )->to_array() );
	}

	/**
	 * DELETE /rules/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request['id'] );
		$this->repo->delete_rule( $id );
		return $this->ok( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * GET /rules/schema — the catalogs + option lists that drive the builder UI.
	 *
	 * @return \WP_REST_Response
	 */
	public function schema(): \WP_REST_Response {
		return $this->ok(
			array(
				'events'     => $this->events->groups(),
				'conditions' => $this->conditions->definitions(),
				'operators'  => $this->conditions->operators(),
				'actions'    => $this->actions->definitions(),
				'options'    => array(
					'tiers'        => $this->tier_options(),
					'roles'        => $this->role_options(),
					'achievements' => $this->achievement_options(),
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Validation                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate + sanitise a rule payload against the catalogs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $partial Update mode (skip required checks for absent keys).
	 * @return array|\WP_Error
	 */
	private function validate( \WP_REST_Request $request, bool $partial = false ) {
		$out = array();

		$event = $request->get_param( 'event' );
		if ( null !== $event ) {
			$event = sanitize_key( (string) $event );
			if ( ! $this->events->has( $event ) ) {
				return $this->error( 'bad_event', __( 'Unknown event.', 'yazan-rewards' ), 400 );
			}
			$out['event'] = $event;
		} elseif ( ! $partial ) {
			return $this->error( 'missing_event', __( 'An event is required.', 'yazan-rewards' ), 400 );
		}

		if ( null !== $request->get_param( 'name' ) ) {
			$out['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}

		// Conditions: keep only catalog keys.
		if ( null !== $request->get_param( 'conditions' ) ) {
			$allowed = $this->conditions->keys();
			$clean   = array();
			foreach ( (array) $request->get_param( 'conditions' ) as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( in_array( $key, $allowed, true ) ) {
					$clean[ $key ] = $this->clean_scalarish( $value );
				}
			}
			$out['conditions'] = $clean;
		}

		// Actions: keep only catalog action types.
		if ( null !== $request->get_param( 'actions' ) ) {
			$allowed = $this->actions->keys();
			$clean   = array();
			foreach ( (array) $request->get_param( 'actions' ) as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				$type = sanitize_key( (string) ( $action['type'] ?? '' ) );
				if ( ! in_array( $type, $allowed, true ) ) {
					continue;
				}
				$params = isset( $action['params'] ) && is_array( $action['params'] ) ? $action['params'] : $action;
				unset( $params['type'] );
				$clean[] = array( 'type' => $type, 'params' => $this->clean_params( $params ) );
			}
			$out['actions'] = $clean;
		}

		foreach ( array( 'priority', 'per_user_cap' ) as $intk ) {
			if ( null !== $request->get_param( $intk ) ) {
				$out[ $intk ] = absint( $request->get_param( $intk ) );
			}
		}
		if ( null !== $request->get_param( 'active' ) ) {
			$out['active'] = (bool) $request->get_param( 'active' );
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $dt ) {
			if ( null !== $request->get_param( $dt ) ) {
				$out[ $dt ] = sanitize_text_field( (string) $request->get_param( $dt ) );
			}
		}

		return $out;
	}

	/**
	 * Recursively clean a scalar/array condition value.
	 *
	 * @param mixed $value Raw.
	 * @return mixed
	 */
	private function clean_scalarish( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ sanitize_key( (string) $k ) ] = is_array( $v ) ? array_map( array( $this, 'clean_leaf' ), $v ) : $this->clean_leaf( $v );
			}
			return $out;
		}
		return $this->clean_leaf( $value );
	}

	/**
	 * Clean action params (one level of key => scalar|array).
	 *
	 * @param array $params Raw params.
	 * @return array
	 */
	private function clean_params( array $params ): array {
		$out = array();
		foreach ( $params as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( 'message' === $key ) {
				$out[ $key ] = sanitize_textarea_field( (string) $v );
			} elseif ( is_array( $v ) ) {
				$out[ $key ] = array_map( array( $this, 'clean_leaf' ), $v );
			} else {
				$out[ $key ] = $this->clean_leaf( $v );
			}
		}
		return $out;
	}

	/**
	 * Clean a single leaf value.
	 *
	 * @param mixed $v Raw.
	 * @return mixed
	 */
	private function clean_leaf( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		return sanitize_text_field( (string) $v );
	}

	/* --------------------------------------------------------------------- */
	/* Option lists                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	private function tier_options(): array {
		$out = array();
		if ( $this->container->has( \Yazan\Rewards\Modules\Achievement\TierRepository::class ) ) {
			foreach ( $this->container->get( \Yazan\Rewards\Modules\Achievement\TierRepository::class )->all() as $t ) {
				$out[] = array( 'value' => (string) $t->slug, 'label' => (string) $t->name );
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	private function role_options(): array {
		$out = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$out[] = array( 'value' => (string) $slug, 'label' => (string) ( $role['name'] ?? $slug ) );
		}
		return $out;
	}

	/**
	 * @return array<int,array{value:string,label:string}>
	 */
	private function achievement_options(): array {
		$out = array();
		if ( $this->container->has( \Yazan\Rewards\Modules\Achievement\AchievementRepository::class ) ) {
			foreach ( $this->container->get( \Yazan\Rewards\Modules\Achievement\AchievementRepository::class )->all() as $a ) {
				$out[] = array( 'value' => (string) $a->akey, 'label' => (string) $a->name );
			}
		}
		return $out;
	}
}
