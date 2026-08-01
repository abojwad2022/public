<?php
/**
 * Base class for an OpenID Connect identity provider.
 *
 * Everything that is identical between Google and Apple lives here — building the authorisation
 * URL, exchanging the authorisation code at the token endpoint, and verifying the returned
 * `id_token`. Subclasses supply only what genuinely differs: endpoints, scopes, how the client
 * secret is produced, and how the provider's claim vocabulary maps onto our normalised identity.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract OIDC provider.
 */
abstract class Yazan_Social_Auth_Provider {

	/**
	 * Provider credentials, as returned by Yazan_Social_Auth::config().
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Non-token data that arrived with the callback itself.
	 *
	 * Apple sends the shopper's name once, and only in the callback body — never in the id_token
	 * and never again on subsequent sign-ins — so the callback handler stashes it here for the
	 * provider to fold into the identity.
	 *
	 * @var array
	 */
	protected $callback_extra = array();

	/**
	 * @param array $config Credentials for this provider.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Hand the provider whatever extra fields the callback carried.
	 *
	 * @param array $extra Raw (already sanitised) callback fields.
	 * @return void
	 */
	public function set_callback_extra( array $extra ) {
		$this->callback_extra = $extra;
	}

	/* --------------------------------------------------------------------- */
	/* Provider description — implemented by subclasses                       */
	/* --------------------------------------------------------------------- */

	/** @return string Machine key used in URLs, user meta and option names. */
	abstract public function key();

	/** @return string Human label for the button ("Google", "Apple"). */
	abstract public function label();

	/** @return string Authorisation endpoint. */
	abstract protected function authorize_endpoint();

	/** @return string Token endpoint. */
	abstract protected function token_endpoint();

	/** @return string JWKS document URL. */
	abstract protected function jwks_uri();

	/** @return string[] Accepted `iss` values in the id_token. */
	abstract protected function issuers();

	/** @return string Space-separated scope string. */
	abstract protected function scope();

	/**
	 * The value to send as `client_secret` at the token endpoint.
	 *
	 * @return string|WP_Error
	 */
	abstract protected function client_secret();

	/**
	 * Normalise this provider's claims into the identity shape the user resolver consumes.
	 *
	 * @param array $claims        Verified id_token claims.
	 * @param array $token_payload Full token-endpoint response (some providers carry extras).
	 * @return array
	 */
	abstract public function identity_from_claims( array $claims, array $token_payload );

	/* --------------------------------------------------------------------- */
	/* Capabilities and configuration                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Is this provider fully configured and therefore safe to advertise?
	 *
	 * The button is only ever rendered when this is true, which is what keeps the module inert —
	 * and the existing email/password login completely untouched — until credentials are added.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== (string) $this->client_id();
	}

	/** @return string OAuth client id. */
	public function client_id() {
		return isset( $this->config['client_id'] ) ? (string) $this->config['client_id'] : '';
	}

	/**
	 * Does this provider support PKCE? Google does; Apple does not document it.
	 *
	 * @return bool
	 */
	protected function supports_pkce() {
		return true;
	}

	/**
	 * Does the provider return the authorisation response as a cross-site form POST?
	 *
	 * Apple does when name/email scopes are requested, and that changes which SameSite value our
	 * state cookie needs in order to survive the round trip.
	 *
	 * @return bool
	 */
	public function uses_form_post() {
		return false;
	}

	/**
	 * Extra authorisation-request parameters.
	 *
	 * @return array<string,string>
	 */
	protected function extra_authorize_args() {
		return array();
	}

	/* --------------------------------------------------------------------- */
	/* Flow                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Build the URL that sends the shopper straight to the provider's account picker.
	 *
	 * @param array $flow Flow state created by Yazan_Social_Auth::start().
	 * @return string
	 */
	public function authorize_url( array $flow ) {
		$args = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id(),
			'redirect_uri'  => Yazan_Social_Auth::redirect_uri( $this->key() ),
			'scope'         => $this->scope(),
			'state'         => $flow['state'],
			'nonce'         => $flow['nonce'],
		);

		if ( $this->supports_pkce() && ! empty( $flow['code_verifier'] ) ) {
			$args['code_challenge']        = Yazan_Social_Auth_JWT::b64url_encode( hash( 'sha256', $flow['code_verifier'], true ) );
			$args['code_challenge_method'] = 'S256';
		}

		$args = array_merge( $args, $this->extra_authorize_args() );

		return add_query_arg( array_map( 'rawurlencode', $args ), $this->authorize_endpoint() );
	}

	/**
	 * Exchange an authorisation code for tokens and return the verified identity.
	 *
	 * @param string $code Authorisation code from the callback.
	 * @param array  $flow Flow state (nonce, code_verifier).
	 * @return array|WP_Error Normalised identity.
	 */
	public function exchange( $code, array $flow ) {
		$secret = $this->client_secret();
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => Yazan_Social_Auth::redirect_uri( $this->key() ),
			'client_id'     => $this->client_id(),
			'client_secret' => $secret,
		);

		if ( $this->supports_pkce() && ! empty( $flow['code_verifier'] ) ) {
			$body['code_verifier'] = $flow['code_verifier'];
		}

		$response = wp_remote_post(
			$this->token_endpoint(),
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'      => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'yazan_sa_token_http', __( 'Could not reach the sign-in provider. Please try again.', 'yazan' ) );
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$status  = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status || ! is_array( $payload ) ) {
			$detail = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : (string) $status;

			return new WP_Error(
				'yazan_sa_token_status',
				__( 'The sign-in provider rejected this request. Please try again.', 'yazan' ),
				array( 'detail' => $detail )
			);
		}

		if ( empty( $payload['id_token'] ) ) {
			return new WP_Error( 'yazan_sa_token_missing', __( 'The sign-in provider returned no identity token.', 'yazan' ) );
		}

		$claims = Yazan_Social_Auth_JWT::verify_id_token(
			(string) $payload['id_token'],
			array(
				'jwks_uri' => $this->jwks_uri(),
				'issuers'  => $this->issuers(),
				'audience' => $this->client_id(),
				'nonce'    => isset( $flow['nonce'] ) ? (string) $flow['nonce'] : '',
			)
		);

		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		return $this->identity_from_claims( $claims, $payload );
	}

	/* --------------------------------------------------------------------- */
	/* Shared helpers                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Split a full name into first/last, tolerating single-word names.
	 *
	 * @param string $name Full display name.
	 * @return array{0:string,1:string}
	 */
	protected function split_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );

		if ( '' === $name ) {
			return array( '', '' );
		}

		$pos = strrpos( $name, ' ' );
		if ( false === $pos ) {
			return array( $name, '' );
		}

		return array( substr( $name, 0, $pos ), substr( $name, $pos + 1 ) );
	}

	/**
	 * Normalise the assorted truthy shapes providers use for `email_verified`.
	 *
	 * Apple sends the string "true"; Google sends a real boolean. Treating the string "false" as
	 * truthy here would silently defeat the verified-email requirement that guards account linking,
	 * so the comparison is explicit rather than a loose cast.
	 *
	 * @param mixed $value Raw claim value.
	 * @return bool
	 */
	protected function is_email_verified( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( 'true', '1' ), true );
	}
}
