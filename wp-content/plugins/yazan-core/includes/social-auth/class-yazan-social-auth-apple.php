<?php
/**
 * Apple — Sign in with Apple (OpenID Connect) provider.
 *
 * Apple departs from the common OIDC shape in three ways that drive most of this file:
 *
 *   1. There is no static client secret. Apple requires a short-lived JWT signed with the ES256
 *      key downloaded from the developer portal, so client_secret() mints one per request. Minting
 *      it fresh (1 hour) rather than caching a six-month token means there is never a secret to
 *      rotate and never a silent expiry to debug.
 *   2. Asking for name/email forces `response_mode=form_post`, so the callback arrives as a
 *      cross-site POST. Yazan_Social_Auth compensates by giving Apple's state cookie SameSite=None.
 *   3. The shopper's name is sent exactly once — on the very first authorisation, in the callback
 *      body, not in the id_token — and never again. If it is missed it cannot be re-requested
 *      without the customer revoking the app in their Apple ID settings.
 *
 * Apple also honours "Hide my email", which yields a verified @privaterelay.appleid.com address.
 * Those are stable and unique per (user, app), so they work as an account identity exactly like a
 * real address.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apple provider.
 */
class Yazan_Social_Auth_Apple extends Yazan_Social_Auth_Provider {

	/** How long the generated client-secret JWT is valid. Apple's ceiling is six months. */
	const SECRET_TTL = HOUR_IN_SECONDS;

	/** @inheritDoc */
	public function key() {
		return 'apple';
	}

	/** @inheritDoc */
	public function label() {
		return __( 'Apple', 'yazan' );
	}

	/** @inheritDoc */
	protected function authorize_endpoint() {
		return 'https://appleid.apple.com/auth/authorize';
	}

	/** @inheritDoc */
	protected function token_endpoint() {
		return 'https://appleid.apple.com/auth/token';
	}

	/** @inheritDoc */
	protected function jwks_uri() {
		return 'https://appleid.apple.com/auth/keys';
	}

	/** @inheritDoc */
	protected function issuers() {
		return array( 'https://appleid.apple.com' );
	}

	/** @inheritDoc */
	protected function scope() {
		return 'name email';
	}

	/**
	 * Apple does not document PKCE support for the web flow.
	 *
	 * @inheritDoc
	 */
	protected function supports_pkce() {
		return false;
	}

	/** @inheritDoc */
	public function uses_form_post() {
		return true;
	}

	/** @inheritDoc */
	protected function extra_authorize_args() {
		// Requesting name/email obliges us to declare form_post; Apple rejects the request otherwise.
		return array( 'response_mode' => 'form_post' );
	}

	/** @inheritDoc */
	public function is_configured() {
		return parent::is_configured()
			&& '' !== $this->setting( 'team_id' )
			&& '' !== $this->setting( 'key_id' )
			&& '' !== $this->private_key();
	}

	/**
	 * Mint the ES256 client-secret JWT Apple expects in place of a static secret.
	 *
	 * @inheritDoc
	 */
	protected function client_secret() {
		$team_id     = $this->setting( 'team_id' );
		$key_id      = $this->setting( 'key_id' );
		$private_key = $this->private_key();

		if ( '' === $team_id || '' === $key_id || '' === $private_key ) {
			return new WP_Error( 'yazan_sa_apple_config', __( 'Apple sign-in is not fully configured.', 'yazan' ) );
		}

		$now = time();

		return Yazan_Social_Auth_JWT::sign_es256(
			array(
				'iss' => $team_id,
				'iat' => $now,
				'exp' => $now + self::SECRET_TTL,
				'aud' => 'https://appleid.apple.com',
				'sub' => $this->client_id(), // The Services ID, not the App ID.
			),
			$private_key,
			$key_id
		);
	}

	/** @inheritDoc */
	public function identity_from_claims( array $claims, array $token_payload ) {
		$first = '';
		$last  = '';

		/*
		 * First authorisation only: Apple posts a `user` JSON blob alongside the code. It is absent
		 * on every later sign-in, which is why the name is persisted at account-creation time and
		 * never overwritten with a blank afterwards.
		 */
		if ( ! empty( $this->callback_extra['user'] ) ) {
			$user = json_decode( (string) $this->callback_extra['user'], true );

			if ( is_array( $user ) && ! empty( $user['name'] ) && is_array( $user['name'] ) ) {
				$first = isset( $user['name']['firstName'] ) ? sanitize_text_field( (string) $user['name']['firstName'] ) : '';
				$last  = isset( $user['name']['lastName'] ) ? sanitize_text_field( (string) $user['name']['lastName'] ) : '';
			}
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

	/* --------------------------------------------------------------------- */
	/* Config helpers                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Read one Apple setting.
	 *
	 * @param string $name Setting key.
	 * @return string
	 */
	private function setting( $name ) {
		return isset( $this->config[ $name ] ) ? trim( (string) $this->config[ $name ] ) : '';
	}

	/**
	 * The PEM contents of Apple's .p8 signing key.
	 *
	 * Accepts either the key inline or a path to the .p8 file, because pasting a multi-line PEM
	 * into wp-config.php is awkward and leaving the file on disk (outside the webroot) is the
	 * tidier deployment.
	 *
	 * @return string PEM, or '' when unavailable.
	 */
	private function private_key() {
		$inline = $this->setting( 'private_key' );
		if ( '' !== $inline ) {
			return $inline;
		}

		$path = $this->setting( 'private_key_path' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private key, not a remote request.

		return is_string( $contents ) ? trim( $contents ) : '';
	}
}
