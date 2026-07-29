<?php
/**
 * Public OAuth callback front controller.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Security\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the browser redirect back from a social network's OAuth screen at
 * `home_url('/?yzrw_oauth=1&code=…&state=…')`. This is intentionally PUBLIC — a
 * cross-site redirect carries no `wp_rest` nonce — so CSRF protection is the single-use
 * `state` transient validated inside {@see OAuthService::callback()}, plus per-IP rate
 * limiting here. It never trusts the query string for identity; the user + network come
 * from the state record. Ends in a 302 back to the account page.
 */
final class OAuthController implements Hookable {

	/**
	 * @param OAuthService $oauth   OAuth service.
	 * @param RateLimiter  $limiter Rate limiter.
	 */
	public function __construct(
		private OAuthService $oauth,
		private RateLimiter $limiter
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'template_redirect', 'method' => 'maybe_handle' ),
		);
	}

	/**
	 * Detect + handle the OAuth callback.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback: CSRF is the single-use `state` transient, validated in OAuthService.
		if ( empty( $_GET[ OAuthService::CALLBACK_VAR ] ) ) {
			return;
		}

		$ip = $this->limiter->client_ip();
		if ( $this->limiter->too_many( 'oauth_cb', $ip, 30 ) ) {
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'yazan-rewards' ), '', array( 'response' => 429 ) );
		}
		$this->limiter->hit( 'oauth_cb', $ip, 600 );

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->oauth->callback( $code, $state );

		$back = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'dashboard' )
			: home_url( '/my-account/' );
		$back = add_query_arg( 'yzrw_linked', $result['ok'] ? rawurlencode( $result['network'] ) : 'error', $back );

		wp_safe_redirect( $back );
		exit;
	}
}
