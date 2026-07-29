<?php
/**
 * Social REST controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Modules\Social\ConnectorRegistry;
use Yazan\Rewards\Modules\Social\OAuthService;
use Yazan\Rewards\Modules\Social\SocialAccountRepository;
use Yazan\Rewards\Modules\Social\SocialRepository;
use Yazan\Rewards\Modules\Social\SocialService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer:
 *   GET  /social/networks           — available networks (label, configured, linked).
 *   GET  /social/accounts           — the caller's linked accounts.
 *   GET  /social/connect/{network}  — begin OAuth linking (→ authorize URL).
 *   POST /social/link               — self-declare a handle ({ network, handle, url? }).
 *   DELETE /social/link/{network}   — disconnect.
 *   POST /social/share              — record a share ({ platform, url? }).
 *   POST /social/submit             — submit UGC ({ url, network?, media_type? }).
 *   GET  /social/me                 — the caller's social actions.
 * Admin (manage_yazan_rewards):
 *   GET  /social/pending · POST /social/{id}/approve · POST /social/{id}/reject
 */
final class SocialController extends AbstractController {

	private SocialService $service;

	private SocialRepository $repo;

	private RateLimiter $limiter;

	private ConnectorRegistry $registry;

	private OAuthService $oauth;

	private SocialAccountRepository $accounts;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->service  = $container->get( SocialService::class );
		$this->repo     = $container->get( SocialRepository::class );
		$this->limiter  = $container->get( RateLimiter::class );
		$this->registry = $container->get( ConnectorRegistry::class );
		$this->oauth    = $container->get( OAuthService::class );
		$this->accounts = $container->get( SocialAccountRepository::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'social';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$customer = $this->auth->require_customer();
		$manager  = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/share',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'share' ),
					'permission_callback' => $customer,
					'args'                => array(
						'platform' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'url'      => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/submit',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => $customer,
					'args'                => array(
						'url'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
						'network'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'platform'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ), // Back-compat alias for network.
						'media_type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/networks',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'networks' ), 'permission_callback' => $customer ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/accounts',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'accounts' ), 'permission_callback' => $customer ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/connect/(?P<network>[a-z0-9_-]+)',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'connect' ), 'permission_callback' => $customer, 'args' => array( 'network' => array( 'sanitize_callback' => 'sanitize_key' ) ) ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/link',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'link' ),
					'permission_callback' => $customer,
					'args'                => array(
						'network' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'handle'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'url'     => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/link/(?P<network>[a-z0-9_-]+)',
			array(
				array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'unlink' ), 'permission_callback' => $customer, 'args' => array( 'network' => array( 'sanitize_callback' => 'sanitize_key' ) ) ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/me',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'me' ),
					'permission_callback' => $customer,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/pending',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'pending' ),
					'permission_callback' => $manager,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->base() . '/(?P<id>\d+)/(?P<decision>approve|reject)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'review' ),
					'permission_callback' => $manager,
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);
	}

	/**
	 * POST /social/share.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function share( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		// Light abuse guard on top of the per-day share cap.
		if ( $this->limiter->too_many( 'social_share', (string) $user_id, 20 ) ) {
			return $this->error( 'too_many', __( 'Too many requests.', 'yazan-rewards' ), 429 );
		}
		$this->limiter->hit( 'social_share', (string) $user_id, 3600 );

		$result = $this->service->share( $user_id, (string) $request->get_param( 'platform' ), (string) $request->get_param( 'url' ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST /social/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $this->limiter->too_many( 'social_submit', (string) $user_id, 15 ) ) {
			return $this->error( 'too_many', __( 'Too many submissions. Please slow down.', 'yazan-rewards' ), 429 );
		}
		$this->limiter->hit( 'social_submit', (string) $user_id, 3600 );

		$network = (string) ( $request->get_param( 'network' ) ?: $request->get_param( 'platform' ) );
		$result  = $this->service->submit_ugc(
			$user_id,
			$network,
			(string) $request->get_param( 'url' ),
			(string) $request->get_param( 'media_type' )
		);
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * GET /social/networks — the connector catalogue for this customer.
	 *
	 * @return \WP_REST_Response
	 */
	public function networks(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( $this->registry->all() as $connector ) {
			$linked   = $this->accounts->for_user_platform( $user_id, $connector->network() );
			$items[] = array(
				'network'    => $connector->network(),
				'label'      => $connector->label(),
				'configured' => $connector->is_configured(),
				'can_oauth'  => $connector->is_configured(),
				'linked'     => (bool) ( $linked && 'connected' === $linked->status ),
				'verified'   => (bool) ( $linked && ! empty( $linked->verified ) ),
			);
		}
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /social/accounts — the caller's linked accounts (no tokens exposed).
	 *
	 * @return \WP_REST_Response
	 */
	public function accounts(): \WP_REST_Response {
		$items = array_map(
			static fn( $a ) => array(
				'network'     => (string) $a->platform,
				'handle'      => (string) $a->handle,
				'profile_url' => (string) $a->profile_url,
				'verified'    => (bool) $a->verified,
			),
			$this->accounts->for_user( get_current_user_id() )
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * GET /social/connect/{network} — begin OAuth linking.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect( \WP_REST_Request $request ) {
		$network = sanitize_key( (string) $request['network'] );
		$url     = $this->oauth->start( get_current_user_id(), $network );
		if ( '' === $url ) {
			return $this->error( 'not_available', __( 'This network is not available for connection yet.', 'yazan-rewards' ), 400 );
		}
		return $this->ok( array( 'authorize_url' => $url ) );
	}

	/**
	 * POST /social/link — self-declare a handle (manual link).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link( \WP_REST_Request $request ) {
		$network = sanitize_key( (string) $request->get_param( 'network' ) );
		if ( ! $this->registry->has( $network ) ) {
			return $this->error( 'unknown_network', __( 'Unknown network.', 'yazan-rewards' ), 400 );
		}
		$id = $this->accounts->declare_handle(
			get_current_user_id(),
			$network,
			(string) $request->get_param( 'handle' ),
			(string) $request->get_param( 'url' )
		);
		return $this->ok( array( 'ok' => true, 'account_id' => $id, 'network' => $network, 'verified' => false ) );
	}

	/**
	 * DELETE /social/link/{network} — disconnect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function unlink( \WP_REST_Request $request ): \WP_REST_Response {
		$network = sanitize_key( (string) $request['network'] );
		$this->accounts->disconnect( get_current_user_id(), $network );
		return $this->ok( array( 'ok' => true, 'network' => $network ) );
	}

	/**
	 * GET /social/me.
	 *
	 * @return \WP_REST_Response
	 */
	public function me(): \WP_REST_Response {
		return $this->ok( array( 'items' => $this->repo->for_user( get_current_user_id(), 50 ) ) );
	}

	/**
	 * GET /social/pending.
	 *
	 * @return \WP_REST_Response
	 */
	public function pending(): \WP_REST_Response {
		return $this->ok( array( 'items' => $this->repo->pending( 100 ) ) );
	}

	/**
	 * POST /social/{id}/{approve|reject}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( \WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$decision = (string) $request['decision'];
		$reviewer = get_current_user_id();

		$result = ( 'approve' === $decision )
			? $this->service->approve( $id, $reviewer )
			: $this->service->reject( $id, $reviewer );

		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
