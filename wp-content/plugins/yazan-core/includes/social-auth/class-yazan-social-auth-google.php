<?php
/**
 * Google — OpenID Connect provider.
 *
 * Uses the plain server-side authorisation-code flow with PKCE rather than the Google Identity
 * Services JavaScript SDK. The UX is the same single tap (the account chooser is Google's own
 * page), but nothing from accounts.google.com is loaded into the storefront, which preserves the
 * site's zero-external-requests position. See the module README for the rationale.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google provider.
 */
class Yazan_Social_Auth_Google extends Yazan_Social_Auth_Provider {

	/** @inheritDoc */
	public function key() {
		return 'google';
	}

	/** @inheritDoc */
	public function label() {
		return __( 'Google', 'yazan' );
	}

	/** @inheritDoc */
	protected function authorize_endpoint() {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	/** @inheritDoc */
	protected function token_endpoint() {
		return 'https://oauth2.googleapis.com/token';
	}

	/** @inheritDoc */
	protected function jwks_uri() {
		return 'https://www.googleapis.com/oauth2/v3/certs';
	}

	/** @inheritDoc */
	protected function issuers() {
		// Google has issued tokens under both spellings over the years; both are legitimate.
		return array( 'https://accounts.google.com', 'accounts.google.com' );
	}

	/** @inheritDoc */
	protected function scope() {
		// The minimum that yields a verified email and a display name. No Gmail, Drive or contacts
		// scope is requested, so the consent screen stays on Google's "basic profile" tier and most
		// returning shoppers never see it again after the first sign-in.
		return 'openid email profile';
	}

	/** @inheritDoc */
	protected function client_secret() {
		$secret = isset( $this->config['client_secret'] ) ? (string) $this->config['client_secret'] : '';

		if ( '' === $secret ) {
			return new WP_Error( 'yazan_sa_google_secret', __( 'Google sign-in is not fully configured.', 'yazan' ) );
		}

		return $secret;
	}

	/** @inheritDoc */
	public function is_configured() {
		return parent::is_configured() && ! is_wp_error( $this->client_secret() );
	}

	/** @inheritDoc */
	protected function extra_authorize_args() {
		return array(
			/*
			 * `select_account` is what makes this feel like one tap: Google shows the account
			 * chooser immediately instead of silently reusing the last session, and a shopper with
			 * several Google accounts can pick the right one. Consent is not re-prompted, so a
			 * returning customer goes picker -> straight back, signed in.
			 */
			'prompt' => 'select_account',
			/*
			 * Nothing here calls a Google API on the customer's behalf, so no refresh token is
			 * requested. Not holding a long-lived credential we have no use for is the whole point.
			 */
			'access_type' => 'online',
		);
	}

	/** @inheritDoc */
	public function identity_from_claims( array $claims, array $token_payload ) {
		$first = isset( $claims['given_name'] ) ? (string) $claims['given_name'] : '';
		$last  = isset( $claims['family_name'] ) ? (string) $claims['family_name'] : '';

		if ( '' === $first && '' === $last && ! empty( $claims['name'] ) ) {
			list( $first, $last ) = $this->split_name( (string) $claims['name'] );
		}

		return array(
			'provider'       => $this->key(),
			'sub'            => (string) $claims['sub'],
			'email'          => isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '',
			'email_verified' => isset( $claims['email_verified'] ) && $this->is_email_verified( $claims['email_verified'] ),
			'first_name'     => $first,
			'last_name'      => $last,
		);
	}
}
