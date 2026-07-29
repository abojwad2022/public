<?php
/**
 * Social network connector contract.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One social network's integration. Connectors are interchangeable and discovered
 * from config (config/connectors.php) — nothing in the engine hardcodes a network.
 * Each connector provides the same uniform capability surface:
 *   - OAuth authentication  (authorize_url + exchange_code),
 *   - account linking        (exchange_code stores tokens),
 *   - account verification   (fetch_account → the linked handle/id),
 *   - content verification   (verify_content via the API; null → fall back to manual),
 *   - metrics                (fetch_metrics; null → unavailable).
 *
 * The API paths activate only when the network's app keys are configured
 * ({@see self::is_configured()}); otherwise the manual submission method is used.
 */
interface ConnectorInterface {

	/**
	 * Stable network id, e.g. "instagram".
	 *
	 * @return string
	 */
	public function network(): string;

	/**
	 * Human label, e.g. "Instagram".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Hostnames a post/profile URL for this network uses, e.g. ["instagram.com"].
	 *
	 * @return string[]
	 */
	public function hosts(): array;

	/**
	 * Whether a given URL belongs to this network (host match).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function url_belongs( string $url ): bool;

	/**
	 * Whether this network's OAuth app credentials are configured (API path available).
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * The OAuth authorize URL to redirect the customer to.
	 *
	 * @param string $state        CSRF state token.
	 * @param string $redirect_uri Callback URL.
	 * @return string
	 */
	public function authorize_url( string $state, string $redirect_uri ): string;

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Callback URL (must match the authorize step).
	 * @return array{access_token?:string,refresh_token?:string,expires_in?:int,scope?:string}
	 */
	public function exchange_code( string $code, string $redirect_uri ): array;

	/**
	 * Fetch the linked account's identity (verification), given tokens.
	 *
	 * @param array $tokens Tokens from {@see self::exchange_code()}.
	 * @return array{external_id?:string,handle?:string,profile_url?:string}
	 */
	public function fetch_account( array $tokens ): array;

	/**
	 * Verify a piece of content via the API. Returns per-check results, or null when
	 * the API path is unavailable (caller falls back to manual verification).
	 *
	 * @param array $submission { url, required_hashtag, required_mention, window_start, handle, external_id }.
	 * @param array $tokens     The submitter's linked tokens.
	 * @return array<string,mixed>|null
	 */
	public function verify_content( array $submission, array $tokens ): ?array;

	/**
	 * Fetch engagement metrics for a post, or null when unavailable.
	 *
	 * @param string $url    Post URL.
	 * @param array  $tokens Tokens.
	 * @return array<string,int>|null
	 */
	public function fetch_metrics( string $url, array $tokens ): ?array;
}
