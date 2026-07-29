<?php
/**
 * Activity feed REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Activity\ActivityRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /activity — the signed-in customer's paginated activity feed.
 */
final class ActivityController extends AbstractController {

	private ActivityRepository $repo;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo = $container->get( ActivityRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'activity';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->base(),
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->auth->require_customer(),
					'args'                => array(
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);
	}

	/**
	 * GET /activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok(
			$this->repo->for_user(
				get_current_user_id(),
				absint( $request->get_param( 'per_page' ) ?: 20 ),
				absint( $request->get_param( 'page' ) ?: 1 )
			)
		);
	}
}
