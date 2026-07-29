<?php
/**
 * Base social connector.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social\Connectors;

use Yazan\Rewards\Core\Contracts\ConnectorInterface;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Http;
use Yazan\Rewards\Modules\Social\SocialSecrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared connector behaviour: generic OAuth2 (authorize URL + code→token exchange +
 * account fetch) driven by each network's {@see self::oauth_config()}. Concrete
 * connectors only declare their id/label/hosts/OAuth endpoints; they may override the
 * API content-verification / metrics methods where a network API is implemented.
 * Until app keys are configured, everything degrades to the manual method.
 */
abstract class AbstractConnector implements ConnectorInterface {

	/**
	 * @param SocialSecrets $secrets  App-credentials store.
	 * @param Http          $http     HTTP client.
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		protected SocialSecrets $secrets,
		protected Http $http,
		protected Settings $settings
	) {}

	/**
	 * OAuth endpoint configuration for this network.
	 *
	 * @return array{auth_url:string,token_url:string,scopes:array,account_url?:string,extra_auth_params?:array}
	 */
	abstract protected function oauth_config(): array;

	/**
	 * @inheritDoc
	 */
	public function url_belongs( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		foreach ( $this->hosts() as $known ) {
			$known = strtolower( $known );
			if ( $host === $known || str_ends_with( $host, '.' . $known ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured(): bool {
		return $this->secrets->has( $this->network() );
	}

	/**
	 * @inheritDoc
	 */
	public function authorize_url( string $state, string $redirect_uri ): string {
		$cfg   = $this->oauth_config();
		$creds = $this->secrets->get( $this->network() );

		$params = array_merge(
			array(
				'client_id'     => $creds['client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => implode( ' ', (array) ( $cfg['scopes'] ?? array() ) ),
				'state'         => $state,
			),
			(array) ( $cfg['extra_auth_params'] ?? array() )
		);

		return $cfg['auth_url'] . '?' . http_build_query( $params );
	}

	/**
	 * @inheritDoc
	 */
	public function exchange_code( string $code, string $redirect_uri ): array {
		$cfg   = $this->oauth_config();
		$creds = $this->secrets->get( $this->network() );

		$res = $this->http->post_form(
			$cfg['token_url'],
			array(
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect_uri,
				'code'          => $code,
			)
		);
		if ( ! $res['ok'] ) {
			return array();
		}
		$b = $res['body'];
		return array(
			'access_token'  => (string) ( $b['access_token'] ?? '' ),
			'refresh_token' => (string) ( $b['refresh_token'] ?? '' ),
			'expires_in'    => (int) ( $b['expires_in'] ?? 0 ),
			'scope'         => (string) ( $b['scope'] ?? implode( ' ', (array) ( $cfg['scopes'] ?? array() ) ) ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function fetch_account( array $tokens ): array {
		$cfg = $this->oauth_config();
		if ( empty( $cfg['account_url'] ) || empty( $tokens['access_token'] ) ) {
			return array();
		}
		$res = $this->http->get_json( $cfg['account_url'], array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ) );
		if ( ! $res['ok'] ) {
			return array();
		}
		return $this->map_account( $res['body'] );
	}

	/**
	 * Map a userinfo response to {external_id, handle, profile_url}. Override per
	 * network where the field names differ.
	 *
	 * @param array $body API response.
	 * @return array{external_id:string,handle:string,profile_url:string}
	 */
	protected function map_account( array $body ): array {
		return array(
			'external_id' => (string) ( $body['id'] ?? $body['sub'] ?? '' ),
			'handle'      => (string) ( $body['username'] ?? $body['name'] ?? '' ),
			'profile_url' => (string) ( $body['profile_url'] ?? '' ),
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Default: no API content verification — the caller falls back to manual checks.
	 */
	public function verify_content( array $submission, array $tokens ): ?array {
		return null;
	}

	/**
	 * @inheritDoc
	 *
	 * Default: metrics unavailable without a network-specific implementation.
	 */
	public function fetch_metrics( string $url, array $tokens ): ?array {
		return null;
	}
}
