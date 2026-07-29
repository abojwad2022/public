<?php
/**
 * Notifications REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Modules\Notification\NotificationPreferences;
use Yazan\Rewards\Modules\Notification\NotificationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing notifications (logged-in):
 *   GET  /notifications              — the caller's on-site inbox + unread count.
 *   POST /notifications/read         — mark all read.
 *   POST /notifications/{id}/read    — mark one read.
 *   GET  /notifications/preferences  — categories + the caller's preference matrix.
 *   POST /notifications/preferences  — save the caller's preferences.
 */
final class NotificationsController extends AbstractController {

	private NotificationRepository $repo;

	private NotificationPreferences $prefs;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->repo  = $container->get( NotificationRepository::class );
		$this->prefs = $container->get( NotificationPreferences::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'notifications';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$auth = $this->auth->require_customer();

		register_rest_route( $this->namespace, '/' . $this->base(), array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'index' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/read', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'mark_read' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/(?P<id>\d+)/read', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'mark_read_one' ), 'permission_callback' => $auth, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/preferences', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_preferences' ), 'permission_callback' => $auth ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_preferences' ), 'permission_callback' => $auth ),
		) );
	}

	/**
	 * GET /notifications.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$user_id = get_current_user_id();
		return $this->ok(
			array(
				'unread' => $this->repo->unread_count( $user_id ),
				'items'  => $this->repo->inbox( $user_id, 30 ),
			)
		);
	}

	/**
	 * POST /notifications/read.
	 *
	 * @return \WP_REST_Response
	 */
	public function mark_read(): \WP_REST_Response {
		$this->repo->mark_all_read( get_current_user_id() );
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * POST /notifications/{id}/read.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function mark_read_one( \WP_REST_Request $request ): \WP_REST_Response {
		$this->repo->mark_read( absint( $request['id'] ), get_current_user_id() );
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * GET /notifications/preferences.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_preferences(): \WP_REST_Response {
		return $this->ok( $this->preferences_payload( get_current_user_id() ) );
	}

	/**
	 * POST /notifications/preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_preferences( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$this->prefs->set( $user_id, (array) $request->get_param( 'prefs' ) );
		return $this->ok( $this->preferences_payload( $user_id ) );
	}

	/**
	 * Build the categories + preferences payload.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	private function preferences_payload( int $user_id ): array {
		$prefs = $this->prefs->get( $user_id );
		$out   = array();
		foreach ( $this->prefs->categories() as $key => $meta ) {
			$out[] = array(
				'key'      => $key,
				'label'    => (string) $meta['label'],
				'required' => ! empty( $meta['required'] ),
				'email'    => (string) ( $prefs[ $key ]['email'] ?? 'immediate' ),
				'onsite'   => (bool) ( $prefs[ $key ]['onsite'] ?? true ),
			);
		}
		return array( 'categories' => $out );
	}
}
