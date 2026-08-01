<?php
/**
 * OpenID Connect token cryptography — JWKS fetch/cache, RS256 verification, ES256 signing.
 *
 * Deliberately dependency-free. Composer is not part of this install and pulling firebase/php-jwt
 * into the auth path for two algorithms is more supply chain than it is worth, so the two things
 * we actually need are implemented here against ext-openssl:
 *
 *   - RS256 *verification* of the `id_token` returned by Google and Apple. Both providers sign
 *     with RSA and publish their public keys as a JWKS document, so we rebuild a PEM public key
 *     from the JWK's modulus/exponent (openssl has no JWK importer) and verify the signature.
 *   - ES256 *signing*, used only to mint Apple's `client_secret`, which Apple specifies as a
 *     short-lived JWT signed with the P-256 key downloaded from the developer portal.
 *
 * Nothing here knows about WordPress users or providers — it is pure token plumbing, which is why
 * a future native-app endpoint can verify an ID token posted by a mobile SDK using the exact same
 * verify_id_token() call the web callback uses.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JWT/JWS helpers for the social sign-in module.
 */
class Yazan_Social_Auth_JWT {

	/** How long a provider's JWKS document is cached. */
	const JWKS_TTL = 12 * HOUR_IN_SECONDS;

	/** Clock-skew tolerance, in seconds, for exp/iat/nbf checks. */
	const LEEWAY = 60;

	/* --------------------------------------------------------------------- */
	/* Verification                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Verify an OpenID Connect ID token and return its claims.
	 *
	 * Performs the full set of checks the OIDC core spec requires of a relying party: signature
	 * against the issuer's published key, `iss`, `aud` (plus `azp` when present), `exp`/`iat`, and
	 * the replay-defeating `nonce` we generated at the start of the flow.
	 *
	 * @param string $jwt  Raw compact-serialised JWT.
	 * @param array  $args {
	 *     Verification parameters.
	 *
	 *     @type string   $jwks_uri  URL of the issuer's JWKS document. Required.
	 *     @type string[] $issuers   Accepted `iss` values. Required.
	 *     @type string   $audience  Expected `aud` (our client id). Required.
	 *     @type string   $nonce     Expected `nonce`. Required — pass '' only when the flow had none.
	 * }
	 * @return array|WP_Error Claim set on success.
	 */
	public static function verify_id_token( $jwt, array $args ) {
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'yazan_sa_jwt_malformed', __( 'Malformed identity token.', 'yazan' ) );
		}

		list( $b64_header, $b64_payload, $b64_signature ) = $parts;

		$header = json_decode( (string) self::b64url_decode( $b64_header ), true );
		$claims = json_decode( (string) self::b64url_decode( $b64_payload ), true );

		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new WP_Error( 'yazan_sa_jwt_decode', __( 'Identity token could not be decoded.', 'yazan' ) );
		}

		// Only RS256 is accepted. Pinning the algorithm is what stops the classic "alg: none" and
		// HMAC-confusion attacks, where an attacker re-signs a forged token using the public key.
		if ( ! isset( $header['alg'] ) || 'RS256' !== $header['alg'] ) {
			return new WP_Error( 'yazan_sa_jwt_alg', __( 'Unsupported identity token algorithm.', 'yazan' ) );
		}

		$kid = isset( $header['kid'] ) ? (string) $header['kid'] : '';
		$pem = self::public_key_for_kid( $args['jwks_uri'], $kid );
		if ( is_wp_error( $pem ) ) {
			return $pem;
		}

		$signature = self::b64url_decode( $b64_signature );
		if ( false === $signature ) {
			return new WP_Error( 'yazan_sa_jwt_sig', __( 'Identity token signature could not be decoded.', 'yazan' ) );
		}

		$ok = openssl_verify( $b64_header . '.' . $b64_payload, $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return new WP_Error( 'yazan_sa_jwt_signature', __( 'Identity token signature is invalid.', 'yazan' ) );
		}

		return self::verify_claims( $claims, $args );
	}

	/**
	 * Validate the claim set of an already signature-verified token.
	 *
	 * Split out from verify_id_token() so the rules live in one readable place.
	 *
	 * @param array $claims Decoded claims.
	 * @param array $args   Same shape as verify_id_token()'s $args.
	 * @return array|WP_Error
	 */
	private static function verify_claims( array $claims, array $args ) {
		$now = time();

		$issuers = isset( $args['issuers'] ) ? (array) $args['issuers'] : array();
		$iss     = isset( $claims['iss'] ) ? (string) $claims['iss'] : '';
		if ( ! in_array( $iss, $issuers, true ) ) {
			return new WP_Error( 'yazan_sa_jwt_iss', __( 'Identity token came from an unexpected issuer.', 'yazan' ) );
		}

		// `aud` is a string or an array of strings; ours must be in it.
		$audience = isset( $args['audience'] ) ? (string) $args['audience'] : '';
		$aud      = isset( $claims['aud'] ) ? (array) $claims['aud'] : array();
		if ( '' === $audience || ! in_array( $audience, array_map( 'strval', $aud ), true ) ) {
			return new WP_Error( 'yazan_sa_jwt_aud', __( 'Identity token was not issued for this site.', 'yazan' ) );
		}

		// When the token is addressed to more than one audience, `azp` names the party it was
		// actually issued to and must be us — otherwise a token minted for a different app that
		// happens to list our client id would be accepted.
		if ( isset( $claims['azp'] ) && (string) $claims['azp'] !== $audience ) {
			return new WP_Error( 'yazan_sa_jwt_azp', __( 'Identity token was issued to a different application.', 'yazan' ) );
		}

		if ( ! isset( $claims['exp'] ) || ( (int) $claims['exp'] + self::LEEWAY ) < $now ) {
			return new WP_Error( 'yazan_sa_jwt_expired', __( 'Identity token has expired. Please try again.', 'yazan' ) );
		}

		if ( isset( $claims['nbf'] ) && ( (int) $claims['nbf'] - self::LEEWAY ) > $now ) {
			return new WP_Error( 'yazan_sa_jwt_nbf', __( 'Identity token is not valid yet.', 'yazan' ) );
		}

		if ( isset( $claims['iat'] ) && ( (int) $claims['iat'] - self::LEEWAY ) > $now ) {
			return new WP_Error( 'yazan_sa_jwt_iat', __( 'Identity token was issued in the future.', 'yazan' ) );
		}

		// The nonce ties this token to the authorisation request this browser started. Without it a
		// captured token could be replayed into someone else's session.
		$expected_nonce = isset( $args['nonce'] ) ? (string) $args['nonce'] : '';
		if ( '' !== $expected_nonce ) {
			$got = isset( $claims['nonce'] ) ? (string) $claims['nonce'] : '';
			if ( ! hash_equals( $expected_nonce, $got ) ) {
				return new WP_Error( 'yazan_sa_jwt_nonce', __( 'Identity token did not match this sign-in attempt.', 'yazan' ) );
			}
		}

		if ( empty( $claims['sub'] ) ) {
			return new WP_Error( 'yazan_sa_jwt_sub', __( 'Identity token carried no account identifier.', 'yazan' ) );
		}

		return $claims;
	}

	/* --------------------------------------------------------------------- */
	/* JWKS                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve a `kid` to a PEM public key, refreshing the cached JWKS once on a miss.
	 *
	 * Providers rotate signing keys without warning, so a cache miss is a normal event, not an
	 * error — one forced refetch is the difference between a seamless rotation and a morning of
	 * failed logins.
	 *
	 * @param string $jwks_uri JWKS document URL.
	 * @param string $kid      Key id from the token header.
	 * @return string|WP_Error PEM public key.
	 */
	private static function public_key_for_kid( $jwks_uri, $kid ) {
		$jwks = self::fetch_jwks( $jwks_uri, false );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}

		$jwk = self::find_jwk( $jwks, $kid );

		if ( null === $jwk ) {
			$jwks = self::fetch_jwks( $jwks_uri, true ); // Force refresh — likely a key rotation.
			if ( is_wp_error( $jwks ) ) {
				return $jwks;
			}
			$jwk = self::find_jwk( $jwks, $kid );
		}

		if ( null === $jwk ) {
			return new WP_Error( 'yazan_sa_jwks_kid', __( 'The identity provider signed with an unknown key.', 'yazan' ) );
		}

		return self::jwk_to_pem( $jwk );
	}

	/**
	 * Pick the matching key out of a JWKS `keys` array.
	 *
	 * @param array  $jwks Decoded JWKS document.
	 * @param string $kid  Key id.
	 * @return array|null
	 */
	private static function find_jwk( array $jwks, $kid ) {
		$keys = isset( $jwks['keys'] ) && is_array( $jwks['keys'] ) ? $jwks['keys'] : array();

		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['kty'] ) || 'RSA' !== $key['kty'] ) {
				continue;
			}
			// A JWKS with a single key and no kid in the token is still usable.
			if ( '' === $kid || ( isset( $key['kid'] ) && hash_equals( (string) $key['kid'], $kid ) ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Fetch (and cache) a JWKS document.
	 *
	 * @param string $jwks_uri JWKS URL.
	 * @param bool   $force    Bypass and rewrite the cache.
	 * @return array|WP_Error
	 */
	private static function fetch_jwks( $jwks_uri, $force = false ) {
		$cache_key = 'yazan_sa_jwks_' . md5( $jwks_uri );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$jwks_uri,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'yazan_sa_jwks_http', __( 'Could not reach the identity provider. Please try again.', 'yazan' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'yazan_sa_jwks_status', __( 'The identity provider returned an unexpected response.', 'yazan' ) );
		}

		$jwks = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $jwks ) || empty( $jwks['keys'] ) ) {
			return new WP_Error( 'yazan_sa_jwks_body', __( 'The identity provider returned no signing keys.', 'yazan' ) );
		}

		set_transient( $cache_key, $jwks, self::JWKS_TTL );

		return $jwks;
	}

	/* --------------------------------------------------------------------- */
	/* JWK (RSA) -> PEM                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Convert an RSA JWK into a PEM public key.
	 *
	 * openssl cannot import a JWK, so the modulus and exponent are re-encoded into a DER
	 * SubjectPublicKeyInfo structure and base64-wrapped:
	 *
	 *   SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { SEQUENCE { INT n, INT e } } }
	 *
	 * @param array $jwk JWK with `n` and `e` (base64url big-endian integers).
	 * @return string|WP_Error PEM.
	 */
	private static function jwk_to_pem( array $jwk ) {
		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return new WP_Error( 'yazan_sa_jwk_fields', __( 'The identity provider key is incomplete.', 'yazan' ) );
		}

		$modulus  = self::b64url_decode( (string) $jwk['n'] );
		$exponent = self::b64url_decode( (string) $jwk['e'] );

		if ( false === $modulus || false === $exponent ) {
			return new WP_Error( 'yazan_sa_jwk_decode', __( 'The identity provider key could not be decoded.', 'yazan' ) );
		}

		$rsa_public_key = self::der_sequence( self::der_integer( $modulus ) . self::der_integer( $exponent ) );

		$algorithm = self::der_sequence(
			// OID 1.2.840.113549.1.1.1 (rsaEncryption) followed by an explicit NULL parameter.
			"\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
		);

		$der = self::der_sequence( $algorithm . self::der_bit_string( $rsa_public_key ) );

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $der ), 64, "\n" ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- DER to PEM, not obfuscation.
			. "-----END PUBLIC KEY-----\n";

		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			return new WP_Error( 'yazan_sa_jwk_pem', __( 'The identity provider key was rejected by OpenSSL.', 'yazan' ) );
		}

		return $pem;
	}

	/* --------------------------------------------------------------------- */
	/* ES256 signing (Apple client_secret)                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Mint a compact JWS signed with ES256.
	 *
	 * Used only for Apple's `client_secret`. openssl_sign() emits an ASN.1 DER ECDSA-Sig-Value,
	 * but JWS requires the raw fixed-width R||S concatenation, so the signature is transcoded.
	 *
	 * @param array  $claims      Claim set.
	 * @param string $private_key PEM-encoded EC P-256 private key (Apple's .p8 contents).
	 * @param string $kid         Apple key id, carried in the JWS header.
	 * @return string|WP_Error Compact JWS.
	 */
	public static function sign_es256( array $claims, $private_key, $kid ) {
		$key = openssl_pkey_get_private( $private_key );
		if ( false === $key ) {
			return new WP_Error( 'yazan_sa_es256_key', __( 'The Apple signing key could not be read.', 'yazan' ) );
		}

		$header = array(
			'alg' => 'ES256',
			'kid' => (string) $kid,
			'typ' => 'JWT',
		);

		$signing_input = self::b64url_encode( (string) wp_json_encode( $header ) )
			. '.' . self::b64url_encode( (string) wp_json_encode( $claims ) );

		$der = '';
		if ( ! openssl_sign( $signing_input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'yazan_sa_es256_sign', __( 'The Apple client secret could not be signed.', 'yazan' ) );
		}

		$raw = self::der_ecdsa_to_raw( $der, 32 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return $signing_input . '.' . self::b64url_encode( $raw );
	}

	/**
	 * Transcode a DER ECDSA-Sig-Value into the raw R||S form JWS expects.
	 *
	 * @param string $der       DER SEQUENCE { INTEGER r, INTEGER s }.
	 * @param int    $part_size Fixed width of each half (32 for P-256).
	 * @return string|WP_Error
	 */
	private static function der_ecdsa_to_raw( $der, $part_size ) {
		$offset = 0;
		$length = strlen( $der );

		// Outer SEQUENCE.
		if ( $length < 2 || "\x30" !== $der[0] ) {
			return new WP_Error( 'yazan_sa_es256_der', __( 'The signature had an unexpected format.', 'yazan' ) );
		}
		$offset = 1;
		$offset += self::der_skip_length( $der, $offset );

		$parts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( ! isset( $der[ $offset ] ) || "\x02" !== $der[ $offset ] ) {
				return new WP_Error( 'yazan_sa_es256_der', __( 'The signature had an unexpected format.', 'yazan' ) );
			}
			$offset++;

			$len_bytes = self::der_skip_length( $der, $offset, $int_length );
			$offset   += $len_bytes;

			$value  = substr( $der, $offset, $int_length );
			$offset += $int_length;

			// DER integers are signed, so a leading 0x00 pad may have been added; JWS wants the
			// unsigned fixed-width value.
			$value = ltrim( $value, "\x00" );
			if ( strlen( $value ) > $part_size ) {
				return new WP_Error( 'yazan_sa_es256_der', __( 'The signature had an unexpected format.', 'yazan' ) );
			}

			$parts[] = str_pad( $value, $part_size, "\x00", STR_PAD_LEFT );
		}

		return $parts[0] . $parts[1];
	}

	/**
	 * Read a DER length field, returning how many bytes it occupied.
	 *
	 * @param string   $der    DER blob.
	 * @param int      $offset Offset of the length field.
	 * @param int|null $length Receives the decoded length.
	 * @return int Bytes consumed by the length field.
	 */
	private static function der_skip_length( $der, $offset, &$length = null ) {
		$first = isset( $der[ $offset ] ) ? ord( $der[ $offset ] ) : 0;

		if ( $first < 0x80 ) {
			$length = $first;
			return 1;
		}

		$count  = $first & 0x7F;
		$length = 0;
		for ( $i = 1; $i <= $count; $i++ ) {
			$length = ( $length << 8 ) | ( isset( $der[ $offset + $i ] ) ? ord( $der[ $offset + $i ] ) : 0 );
		}

		return $count + 1;
	}

	/* --------------------------------------------------------------------- */
	/* Primitive DER + base64url helpers                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Encode a DER length prefix.
	 *
	 * @param int $length Content length.
	 * @return string
	 */
	private static function der_length( $length ) {
		if ( $length < 0x80 ) {
			return chr( $length );
		}

		$bytes = ltrim( pack( 'N', $length ), "\x00" );

		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Wrap content in a DER SEQUENCE.
	 *
	 * @param string $contents Encoded members.
	 * @return string
	 */
	private static function der_sequence( $contents ) {
		return "\x30" . self::der_length( strlen( $contents ) ) . $contents;
	}

	/**
	 * Encode a big-endian byte string as a DER INTEGER.
	 *
	 * @param string $bytes Unsigned big-endian integer.
	 * @return string
	 */
	private static function der_integer( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );

		if ( '' === $bytes ) {
			$bytes = "\x00";
		} elseif ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes; // Keep it positive — DER integers are two's complement.
		}

		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Wrap content in a DER BIT STRING with zero unused bits.
	 *
	 * @param string $contents Encoded value.
	 * @return string
	 */
	private static function der_bit_string( $contents ) {
		return "\x03" . self::der_length( strlen( $contents ) + 1 ) . "\x00" . $contents;
	}

	/**
	 * Base64url encode (RFC 7515 — no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function b64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWS encoding.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Encoded string.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWS decoding.
		return base64_decode( strtr( $data, '-_', '+/' ), true );
	}
}
