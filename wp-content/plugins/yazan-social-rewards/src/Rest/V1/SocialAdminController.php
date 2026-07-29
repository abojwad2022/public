<?php
/**
 * Admin social controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Social\ConnectorRegistry;
use Yazan\Rewards\Modules\Social\SocialRepository;
use Yazan\Rewards\Modules\Social\SocialSecrets;
use Yazan\Rewards\Modules\Social\SocialService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social connector management (admin, `manage_yazan_rewards`):
 *   GET  /admin/social/connectors            — networks + credential status (last4) + enabled + points.
 *   POST /admin/social/connectors/{network}  — save app keys / enabled / points.
 *   GET/POST /admin/social/settings          — verification defaults.
 *   GET  /admin/social/pending               — submission review queue (with check results).
 *   POST /admin/social/submissions/{id}/{approve|reject}.
 * Client secrets are write-only — responses ever return only a `last4`.
 */
final class SocialAdminController extends AbstractController {

	private ConnectorRegistry $registry;

	private SocialSecrets $secrets;

	private SocialService $service;

	private SocialRepository $repo;

	private Settings $settings;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->registry = $container->get( ConnectorRegistry::class );
		$this->secrets  = $container->get( SocialSecrets::class );
		$this->service  = $container->get( SocialService::class );
		$this->repo     = $container->get( SocialRepository::class );
		$this->settings = $container->get( Settings::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/social';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base() . '/connectors', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'connectors' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/connectors/(?P<network>[a-z0-9_-]+)', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_connector' ), 'permission_callback' => $manager, 'args' => array( 'network' => array( 'sanitize_callback' => 'sanitize_key' ) ) ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/settings', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_settings' ), 'permission_callback' => $manager ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_settings' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/pending', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'pending' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/submissions/(?P<id>\d+)/(?P<decision>approve|reject)', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'review' ), 'permission_callback' => $manager, 'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ) ),
		) );
	}

	/**
	 * GET /admin/social/connectors.
	 *
	 * @return \WP_REST_Response
	 */
	public function connectors(): \WP_REST_Response {
		$networks = (array) $this->settings->get( 'social.networks', array() );
		$items    = array();
		foreach ( $this->registry->all() as $connector ) {
			$net    = $connector->network();
			$status = $this->secrets->status( $net );
			$items[] = array(
				'network'      => $net,
				'label'        => $connector->label(),
				'configured'   => $status['configured'],
				'id_last4'     => $status['id_last4'],
				'secret_last4' => $status['secret_last4'],
				'source'       => $status['source'],
				'enabled'      => ! isset( $networks[ $net ]['enabled'] ) || (bool) $networks[ $net ]['enabled'],
				'points'       => isset( $networks[ $net ]['points'] ) ? (int) $networks[ $net ]['points'] : null,
			);
		}
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * POST /admin/social/connectors/{network}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_connector( \WP_REST_Request $request ) {
		$network = sanitize_key( (string) $request['network'] );
		if ( ! $this->registry->has( $network ) ) {
			return $this->error( 'unknown_network', __( 'Unknown network.', 'yazan-rewards' ), 400 );
		}

		// Credentials — keep the existing value when a field is omitted/blank (so the
		// secret isn't wiped just because it isn't re-typed).
		$current = $this->secrets->get( $network );
		$id      = null !== $request->get_param( 'client_id' ) ? sanitize_text_field( (string) $request->get_param( 'client_id' ) ) : $current['client_id'];
		$secret  = ( null !== $request->get_param( 'client_secret' ) && '' !== (string) $request->get_param( 'client_secret' ) )
			? sanitize_text_field( (string) $request->get_param( 'client_secret' ) )
			: $current['client_secret'];
		$this->secrets->set( $network, $id, $secret );

		// Enabled + per-network points override.
		$all                      = $this->settings->all();
		$all['social']            = (array) ( $all['social'] ?? array() );
		$all['social']['networks'] = (array) ( $all['social']['networks'] ?? array() );
		$entry                    = (array) ( $all['social']['networks'][ $network ] ?? array() );
		if ( null !== $request->get_param( 'enabled' ) ) {
			$entry['enabled'] = (bool) $request->get_param( 'enabled' );
		}
		if ( null !== $request->get_param( 'points' ) ) {
			$entry['points'] = max( 0, (int) $request->get_param( 'points' ) );
		}
		$all['social']['networks'][ $network ] = $entry;
		$this->settings->save( $all );

		return $this->ok( array( 'saved' => true, 'network' => $network, 'status' => $this->secrets->status( $network ) ) );
	}

	/**
	 * GET /admin/social/settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		return $this->ok( array( 'verify' => (array) $this->settings->get( 'social.verify', array() ) ) );
	}

	/**
	 * POST /admin/social/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$in  = (array) $request->get_param( 'verify' );
		$all = $this->settings->all();
		$cur = (array) ( $all['social']['verify'] ?? array() );

		foreach ( array( 'auto_approve', 'manual_fetch' ) as $bool ) {
			if ( isset( $in[ $bool ] ) ) {
				$cur[ $bool ] = (bool) $in[ $bool ];
			}
		}
		foreach ( array( 'required_hashtag', 'required_mention' ) as $text ) {
			if ( isset( $in[ $text ] ) ) {
				$cur[ $text ] = sanitize_text_field( (string) $in[ $text ] );
			}
		}
		if ( isset( $in['window_days'] ) ) {
			$cur['window_days'] = max( 0, (int) $in['window_days'] );
		}

		$all['social']           = (array) ( $all['social'] ?? array() );
		$all['social']['verify'] = $cur;
		$this->settings->save( $all );

		return $this->ok( array( 'verify' => $cur ) );
	}

	/**
	 * GET /admin/social/pending.
	 *
	 * @return \WP_REST_Response
	 */
	public function pending(): \WP_REST_Response {
		$items = array_map(
			function ( $r ) {
				$user   = get_userdata( (int) $r->user_id );
				$checks = json_decode( (string) ( $r->checks ?? '' ), true );
				return array(
					'id'         => (int) $r->id,
					'user'       => $user ? $user->display_name : ( '#' . (int) $r->user_id ),
					'network'    => (string) $r->platform,
					'media_type' => (string) ( $r->media_type ?? '' ),
					'url'        => (string) $r->submission_url,
					'method'     => (string) ( $r->verify_method ?? '' ),
					'points'     => (int) $r->points_award,
					'checks'     => is_array( $checks ) ? $checks : array(),
					'created_at' => (string) $r->created_at,
				);
			},
			$this->repo->pending( 100 )
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * POST /admin/social/submissions/{id}/{approve|reject}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( \WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$reviewer = get_current_user_id();
		$result   = ( 'approve' === (string) $request['decision'] )
			? $this->service->approve( $id, $reviewer )
			: $this->service->reject( $id, $reviewer );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
