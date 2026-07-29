<?php
/**
 * Admin notifications controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Rest\V1;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Rest\AbstractController;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Modules\Notification\Channels\WebhookChannel;
use Yazan\Rewards\Modules\Notification\NotificationDispatcher;
use Yazan\Rewards\Modules\Notification\NotificationPreferences;
use Yazan\Rewards\Modules\Notification\NotificationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification management (admin, `manage_yazan_rewards`):
 *   GET/POST /admin/notifications/settings — channels, webhook, digest, category kill-switches.
 *   GET      /admin/notifications/outbox    — recent deliveries (optional ?status=).
 *   POST     /admin/notifications/test      — send a test notification to the current admin.
 * The webhook signing secret is write-only — responses only ever expose its last4.
 */
final class NotificationsAdminController extends AbstractController {

	private Settings $settings;

	private NotificationRepository $repo;

	private NotificationPreferences $prefs;

	private NotificationDispatcher $dispatcher;

	/**
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		parent::__construct( $container );
		$this->settings   = $container->get( Settings::class );
		$this->repo       = $container->get( NotificationRepository::class );
		$this->prefs      = $container->get( NotificationPreferences::class );
		$this->dispatcher = $container->get( NotificationDispatcher::class );
	}

	/**
	 * @inheritDoc
	 */
	protected function base(): string {
		return 'admin/notifications';
	}

	/**
	 * @inheritDoc
	 */
	public function register_routes(): void {
		$manager = $this->auth->require_cap( Capabilities::MANAGE );

		register_rest_route( $this->namespace, '/' . $this->base() . '/settings', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_settings' ), 'permission_callback' => $manager ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_settings' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/outbox', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'outbox' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( $this->namespace, '/' . $this->base() . '/test', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'test' ), 'permission_callback' => $manager ),
		) );
	}

	/**
	 * GET /admin/notifications/settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings(): \WP_REST_Response {
		$secret     = (string) get_option( WebhookChannel::SECRET_OPTION, '' );
		$categories = array();
		foreach ( $this->prefs->categories() as $key => $meta ) {
			$categories[] = array(
				'key'      => $key,
				'label'    => (string) $meta['label'],
				'required' => ! empty( $meta['required'] ),
				'enabled'  => ! empty( $meta['required'] ) || (bool) $this->settings->get( 'notification.categories.' . $key, true ),
			);
		}

		return $this->ok(
			array(
				'channels'   => array(
					'email'   => (bool) $this->settings->get( 'notification.email', true ),
					'onsite'  => (bool) $this->settings->get( 'notification.onsite', true ),
					'webhook' => array(
						'enabled'      => (bool) $this->settings->get( 'notification.webhook.enabled', false ),
						'url'          => (string) $this->settings->get( 'notification.webhook.url', '' ),
						'secret_last4' => '' !== $secret ? substr( $secret, -4 ) : '',
					),
				),
				// Future-ready channels: `configured` reflects whether a provider add-on
				// has been registered for that channel (none ship in core).
				'future'     => array(
					'sms'      => array( 'enabled' => (bool) $this->settings->get( 'notification.sms.enabled', false ), 'configured' => $this->provider_configured( 'sms' ) ),
					'push'     => array( 'enabled' => (bool) $this->settings->get( 'notification.push.enabled', false ), 'configured' => $this->provider_configured( 'push' ) ),
					'whatsapp' => array( 'enabled' => (bool) $this->settings->get( 'notification.whatsapp.enabled', false ), 'configured' => $this->provider_configured( 'whatsapp' ) ),
				),
				'digest'     => array(
					'enabled' => (bool) $this->settings->get( 'notification.digest.enabled', true ),
					'hour'    => (int) $this->settings->get( 'notification.digest.hour', 9 ),
				),
				'campaign_ending_days' => (int) $this->settings->get( 'notification.campaign_ending_days', 3 ),
				'categories'           => $categories,
			)
		);
	}

	/**
	 * Whether a provider add-on is registered + configured for a future channel.
	 *
	 * @param string $channel sms|push|whatsapp.
	 * @return bool
	 */
	private function provider_configured( string $channel ): bool {
		$provider = apply_filters( 'yazan_rewards/notification/' . $channel . '_provider', null, $this->settings );
		return is_object( $provider ) && method_exists( $provider, 'configured' ) && (bool) $provider->configured();
	}

	/**
	 * POST /admin/notifications/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$all                     = $this->settings->all();
		$all['notification']     = (array) ( $all['notification'] ?? array() );
		$notif                   = &$all['notification'];
		$notif['webhook']        = (array) ( $notif['webhook'] ?? array() );
		$notif['digest']         = (array) ( $notif['digest'] ?? array() );
		$notif['categories']     = (array) ( $notif['categories'] ?? array() );

		$channels = (array) $request->get_param( 'channels' );
		if ( isset( $channels['email'] ) ) {
			$notif['email'] = (bool) $channels['email'];
		}
		if ( isset( $channels['onsite'] ) ) {
			$notif['onsite'] = (bool) $channels['onsite'];
		}
		if ( isset( $channels['webhook'] ) && is_array( $channels['webhook'] ) ) {
			$wh = $channels['webhook'];
			if ( isset( $wh['enabled'] ) ) {
				$notif['webhook']['enabled'] = (bool) $wh['enabled'];
			}
			if ( isset( $wh['url'] ) ) {
				$url = trim( (string) $wh['url'] );
				if ( '' !== $url && ! preg_match( '#^https://#i', $url ) ) {
					return $this->error( 'invalid_url', __( 'The webhook URL must start with https://.', 'yazan-rewards' ), 400 );
				}
				$notif['webhook']['url'] = '' === $url ? '' : esc_url_raw( $url );
			}
			// Secret is write-only: update only when a non-empty value is supplied.
			if ( isset( $wh['secret'] ) && '' !== (string) $wh['secret'] ) {
				update_option( WebhookChannel::SECRET_OPTION, sanitize_text_field( (string) $wh['secret'] ), false );
			}
		}

		$digest = (array) $request->get_param( 'digest' );
		if ( isset( $digest['enabled'] ) ) {
			$notif['digest']['enabled'] = (bool) $digest['enabled'];
		}
		if ( isset( $digest['hour'] ) ) {
			$notif['digest']['hour'] = max( 0, min( 23, (int) $digest['hour'] ) );
		}

		// Future channels — only the enable toggle is admin-editable here (a provider
		// add-on supplies its own credentials). Toggling on without a provider is inert.
		$future = (array) $request->get_param( 'future' );
		foreach ( array( 'sms', 'push', 'whatsapp' ) as $ch ) {
			if ( isset( $future[ $ch ]['enabled'] ) ) {
				$notif[ $ch ]            = (array) ( $notif[ $ch ] ?? array() );
				$notif[ $ch ]['enabled'] = (bool) $future[ $ch ]['enabled'];
			}
		}

		if ( null !== $request->get_param( 'campaign_ending_days' ) ) {
			$notif['campaign_ending_days'] = max( 0, min( 60, (int) $request->get_param( 'campaign_ending_days' ) ) );
		}

		$cats = (array) $request->get_param( 'categories' );
		foreach ( $this->prefs->categories() as $key => $meta ) {
			if ( ! empty( $meta['required'] ) ) {
				$notif['categories'][ $key ] = true; // Required categories can't be disabled.
				continue;
			}
			if ( array_key_exists( $key, $cats ) ) {
				$notif['categories'][ $key ] = (bool) $cats[ $key ];
			}
		}

		$this->settings->save( $all );
		return $this->get_settings();
	}

	/**
	 * GET /admin/notifications/outbox.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function outbox( \WP_REST_Request $request ): \WP_REST_Response {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$items  = array_map(
			static function ( $r ) {
				$user = get_userdata( (int) $r->user_id );
				return array(
					'id'         => (int) $r->id,
					'user'       => $user ? $user->display_name : ( '#' . (int) $r->user_id ),
					'channel'    => (string) $r->channel,
					'template'   => (string) $r->template,
					'category'   => (string) $r->category,
					'priority'   => (string) $r->priority,
					'status'     => (string) $r->status,
					'created_at' => (string) $r->created_at,
				);
			},
			$this->repo->recent( $status, 100 )
		);
		return $this->ok( array( 'items' => $items ) );
	}

	/**
	 * POST /admin/notifications/test — send a system test to the current admin.
	 *
	 * @return \WP_REST_Response
	 */
	public function test(): \WP_REST_Response {
		$user_id = get_current_user_id();
		$this->dispatcher->notify(
			$user_id,
			'custom',
			array(
				'subject' => __( 'Test notification', 'yazan-rewards' ),
				'message' => __( 'This is a test from your Yazan rewards notification settings. If you can read this, delivery is working.', 'yazan-rewards' ),
			),
			array( 'category' => 'system', 'priority' => 'urgent' )
		);
		return $this->ok( array( 'sent' => true ) );
	}
}
