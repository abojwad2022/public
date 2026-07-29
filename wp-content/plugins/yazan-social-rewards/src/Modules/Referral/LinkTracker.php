<?php
/**
 * Referral link tracker.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Referral;

use Yazan\Rewards\Core\Contracts\Hookable;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures a `?ref=CODE` visit into a first-party cookie, so the referrer is
 * known when the visitor later registers/checks out. Only stores the cookie for
 * a code that resolves to a real user, and never for the referrer's own visit.
 */
final class LinkTracker implements Hookable {

	/** Cookie name holding the referral code. */
	public const COOKIE = 'yzrw_ref';

	/** Query var carrying the code. */
	public const QUERY_VAR = 'ref';

	/**
	 * @param ReferralCodes $codes    Code resolver.
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		private ReferralCodes $codes,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function hooks(): array {
		return array(
			array( 'type' => 'action', 'hook' => 'init', 'method' => 'capture' ),
		);
	}

	/**
	 * Capture the referral code from the query string.
	 *
	 * @return void
	 */
	public function capture(): void {
		if ( is_admin() ) {
			return;
		}
		// Read-only tracking parameter; no state change, so no nonce applies.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code     = $this->codes->clean( sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$referrer = $this->codes->resolve( $code );
		if ( $referrer <= 0 ) {
			return; // Unknown code — ignore.
		}

		// Don't let a signed-in user self-attribute.
		if ( get_current_user_id() === $referrer ) {
			return;
		}

		$days = max( 1, (int) $this->settings->get( 'referral.cookie_days', 30 ) );

		// Only set if not already carrying a code (first-touch attribution).
		if ( isset( $_COOKIE[ self::COOKIE ] ) && '' !== $_COOKIE[ self::COOKIE ] ) {
			return;
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$code,
				array(
					'expires'  => time() + ( $days * DAY_IN_SECONDS ),
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		// Make it available within this same request too.
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/**
	 * The referral code currently held for this visitor, or ''.
	 *
	 * @return string
	 */
	public function current_code(): string {
		return isset( $_COOKIE[ self::COOKIE ] ) ? $this->codes->clean( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
	}

	/**
	 * Clear the cookie (after attribution).
	 *
	 * @return void
	 */
	public function clear(): void {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '' );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}
}
