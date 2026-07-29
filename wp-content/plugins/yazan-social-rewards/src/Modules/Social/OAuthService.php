<?php
/**
 * OAuth authorization flow for social account linking.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives the OAuth round-trip: builds the authorize URL with a single-use `state`
 * token (stored in a transient bound to the customer), and on callback validates the
 * state, exchanges the code for tokens, fetches the account identity, and links the
 * account with its tokens ENCRYPTED at rest. Because the network's redirect is a
 * cross-site GET with no `wp_rest` nonce, the `state` transient is the CSRF control
 * and also carries the user id (the user may lack a live cookie in the redirect).
 */
final class OAuthService {

	/** Transient key prefix for OAuth state tokens. */
	private const STATE_PREFIX = 'yzrw_oauth_state_';

	/** Query var marking the front-end callback. */
	public const CALLBACK_VAR = 'yzrw_oauth';

	/**
	 * @param ConnectorRegistry       $registry Connectors.
	 * @param SocialAccountRepository  $accounts Linked accounts.
	 * @param Crypto                   $crypto   Token encryption.
	 * @param EventBus                 $bus      Event bus.
	 */
	public function __construct(
		private ConnectorRegistry $registry,
		private SocialAccountRepository $accounts,
		private Crypto $crypto,
		private EventBus $bus
	) {}

	/**
	 * The redirect URI registered with every network's app (must match on both legs).
	 *
	 * @return string
	 */
	public function redirect_uri(): string {
		return add_query_arg( self::CALLBACK_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Begin linking: returns the authorize URL to send the customer to, or '' when
	 * the network is unknown / not configured.
	 *
	 * @param int    $user_id Customer.
	 * @param string $network Network id.
	 * @return string
	 */
	public function start( int $user_id, string $network ): string {
		$connector = $this->registry->get( $network );
		if ( $user_id <= 0 || ! $connector || ! $connector->is_configured() ) {
			return '';
		}
		$state = wp_generate_password( 40, false, false );
		set_transient(
			self::STATE_PREFIX . $state,
			array( 'user_id' => $user_id, 'network' => $connector->network() ),
			10 * MINUTE_IN_SECONDS
		);
		return $connector->authorize_url( $state, $this->redirect_uri() );
	}

	/**
	 * Handle the OAuth callback. The network + user are taken from the state
	 * transient (not the query string), which is deleted on use (single-use CSRF).
	 *
	 * @param string $code  Authorization code.
	 * @param string $state State token.
	 * @return array{ok:bool,network:string,error:string}
	 */
	public function callback( string $code, string $state ): array {
		$data = '' !== $state ? get_transient( self::STATE_PREFIX . $state ) : false;
		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			return array( 'ok' => false, 'network' => '', 'error' => 'bad_state' );
		}
		delete_transient( self::STATE_PREFIX . $state ); // Single-use.

		$user_id   = (int) $data['user_id'];
		$network   = (string) $data['network'];
		$connector = $this->registry->get( $network );
		if ( ! $connector ) {
			return array( 'ok' => false, 'network' => $network, 'error' => 'unknown_network' );
		}
		if ( '' === $code ) {
			return array( 'ok' => false, 'network' => $network, 'error' => 'no_code' );
		}

		$tokens = $connector->exchange_code( $code, $this->redirect_uri() );
		if ( empty( $tokens['access_token'] ) ) {
			return array( 'ok' => false, 'network' => $network, 'error' => 'exchange_failed' );
		}

		$account = $connector->fetch_account( $tokens );
		$this->store( $user_id, $connector->network(), $tokens, $account );

		$this->bus->dispatch(
			new GenericEvent(
				Events::SOCIAL_ACCOUNT_LINKED,
				array( 'user_id' => $user_id, 'network' => $connector->network(), 'handle' => (string) ( $account['handle'] ?? '' ) )
			)
		);

		return array( 'ok' => true, 'network' => $connector->network(), 'error' => '' );
	}

	/**
	 * Persist the linked account with encrypted tokens (verified — OAuth proves it).
	 *
	 * @param int    $user_id Customer.
	 * @param string $network Network id.
	 * @param array  $tokens  Tokens.
	 * @param array  $account Account identity.
	 * @return void
	 */
	private function store( int $user_id, string $network, array $tokens, array $account ): void {
		$expires = ! empty( $tokens['expires_in'] )
			? gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expires_in'] )
			: '0000-00-00 00:00:00';

		$this->accounts->connect_oauth(
			$user_id,
			$network,
			array(
				'handle'           => (string) ( $account['handle'] ?? '' ),
				'external_id'      => (string) ( $account['external_id'] ?? '' ),
				'profile_url'      => (string) ( $account['profile_url'] ?? '' ),
				'access_token'     => $this->crypto->encrypt( (string) ( $tokens['access_token'] ?? '' ) ),
				'refresh_token'    => $this->crypto->encrypt( (string) ( $tokens['refresh_token'] ?? '' ) ),
				'token_expires_at' => $expires,
				'scope'            => (string) ( $tokens['scope'] ?? '' ),
			)
		);
	}
}
