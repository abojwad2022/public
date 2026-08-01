<?php
/**
 * Where social sign-in credentials live when they are not in wp-config.php.
 *
 * The module was built constants-first, and constants are still the better place for a real
 * deployment: they never enter the database, so they never ride along in a DB export, a migration,
 * or one of this plugin's own backup archives. But typing them means editing wp-config.php on the
 * server, which is not a thing a shop owner should have to do — so this adds a second, optional
 * source that the dashboard can write.
 *
 * TWO RULES MAKE THAT SAFE
 * ------------------------
 * 1. **Constants always win.** If YAZAN_GOOGLE_CLIENT_SECRET is defined, whatever is in the database
 *    is ignored and the dashboard shows the field as locked. A compromised dashboard therefore
 *    cannot override a credential that was deliberately pinned in the file system.
 * 2. **Secrets are encrypted at rest and never read back out to a client.** They are sealed with
 *    libsodium's authenticated secretbox under a key derived from the site's wp-config salts, so a
 *    stolen database dump on its own yields nothing usable — the attacker also needs wp-config.php.
 *    That is the realistic threat here (dumps leak far more often than whole file systems); it is
 *    NOT protection against someone who already has code execution on the server, and nothing
 *    stored beside the application could be.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypted option storage for provider credentials.
 */
class Yazan_Social_Auth_Store {

	/** Option holding the credential bundle. Autoload off — only the auth flow reads it. */
	const OPTION = 'yazan_social_auth_credentials';

	/** Prefix marking a value as sealed, so plaintext left by an older write is still readable. */
	const SEALED_PREFIX = 'yzsb1:';

	/**
	 * Fields per provider, and whether each is a secret.
	 *
	 * `client_id` is deliberately NOT secret: it is published in the authorisation URL on every
	 * sign-in, so treating it as confidential would be theatre — and showing it back makes the
	 * dashboard far easier to verify against the provider console.
	 *
	 * @var array<string,array<string,bool>> provider => field => is_secret
	 */
	const FIELDS = array(
		'google' => array(
			'client_id'     => false,
			'client_secret' => true,
		),
		'apple'  => array(
			'client_id'        => false,
			'team_id'          => false,
			'key_id'           => false,
			'private_key'      => true,
			'private_key_path' => false,
		),
	);

	/**
	 * The wp-config constant that pins a given field, if any.
	 *
	 * @param string $provider Provider key.
	 * @param string $field    Field name.
	 * @return string Constant name.
	 */
	public static function constant_name( $provider, $field ) {
		return 'YAZAN_' . strtoupper( $provider ) . '_' . strtoupper( $field );
	}

	/**
	 * Is this field pinned by a wp-config constant?
	 *
	 * @param string $provider Provider key.
	 * @param string $field    Field name.
	 * @return bool
	 */
	public static function is_locked( $provider, $field ) {
		$name = self::constant_name( $provider, $field );

		return defined( $name ) && '' !== (string) constant( $name );
	}

	/* --------------------------------------------------------------------- */
	/* Read                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * The effective credentials: constants where present, decrypted database values otherwise.
	 *
	 * This is what Yazan_Social_Auth::config() consumes, so it returns real plaintext. Nothing here
	 * is safe to send to a browser — use status() for that.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( self::FIELDS as $provider => $fields ) {
			$out[ $provider ] = array();

			foreach ( $fields as $field => $is_secret ) {
				$constant = self::constant_name( $provider, $field );

				if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
					$out[ $provider ][ $field ] = (string) constant( $constant );
					continue;
				}

				$raw                        = isset( $stored[ $provider ][ $field ] ) ? (string) $stored[ $provider ][ $field ] : '';
				$out[ $provider ][ $field ] = $is_secret ? self::unseal( $raw ) : $raw;
			}
		}

		return $out;
	}

	/**
	 * A browser-safe description of what is configured — never the secret values themselves.
	 *
	 * @return array
	 */
	public static function status() {
		$effective = self::all();
		$out       = array();

		foreach ( self::FIELDS as $provider => $fields ) {
			$entry = array(
				'fields'       => array(),
				'redirect_uri' => Yazan_Social_Auth::redirect_uri( $provider ),
			);

			foreach ( $fields as $field => $is_secret ) {
				$value = isset( $effective[ $provider ][ $field ] ) ? (string) $effective[ $provider ][ $field ] : '';

				$entry['fields'][ $field ] = array(
					'set'      => '' !== $value,
					'secret'   => $is_secret,
					'locked'   => self::is_locked( $provider, $field ),
					'constant' => self::constant_name( $provider, $field ),
					// Non-secrets echo back so the value can be checked against the provider console;
					// secrets report only their length, which is enough to spot a truncated paste.
					'value'    => $is_secret ? '' : $value,
					'length'   => $is_secret ? strlen( $value ) : null,
				);
			}

			$provider_object      = Yazan_Social_Auth::provider( $provider );
			$entry['active']      = null !== $provider_object;
			$out[ $provider ]     = $entry;
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Write                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Merge a partial set of credentials into storage.
	 *
	 * Semantics chosen for a write-only form: a field that is ABSENT is left untouched, so the UI
	 * never has to round-trip a secret it was not allowed to read. An empty string clears the field.
	 * Fields pinned by a constant are refused rather than silently dropped, so the caller learns why
	 * nothing changed.
	 *
	 * @param array $input provider => field => value.
	 * @return array{saved:string[],skipped:string[]}
	 */
	public static function save( array $input ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$saved   = array();
		$skipped = array();

		foreach ( self::FIELDS as $provider => $fields ) {
			if ( ! isset( $input[ $provider ] ) || ! is_array( $input[ $provider ] ) ) {
				continue;
			}

			foreach ( $fields as $field => $is_secret ) {
				if ( ! array_key_exists( $field, $input[ $provider ] ) ) {
					continue; // Absent = unchanged.
				}

				if ( self::is_locked( $provider, $field ) ) {
					$skipped[] = "{$provider}.{$field}";
					continue;
				}

				$value = self::clean( (string) $input[ $provider ][ $field ], $field );

				if ( '' === $value ) {
					unset( $stored[ $provider ][ $field ] );
				} else {
					$stored[ $provider ][ $field ] = $is_secret ? self::seal( $value ) : $value;
				}

				$saved[] = "{$provider}.{$field}";
			}
		}

		// Autoload off: this is read by the auth flow, not by every page load.
		update_option( self::OPTION, $stored, false );

		return array(
			'saved'   => $saved,
			'skipped' => $skipped,
		);
	}

	/**
	 * Normalise one submitted value.
	 *
	 * @param string $value Raw value.
	 * @param string $field Field name.
	 * @return string
	 */
	private static function clean( $value, $field ) {
		// The Apple .p8 is a multi-line PEM; trimming each line is fine but stripping newlines is
		// not — openssl_pkey_get_private() rejects a single-line key.
		if ( 'private_key' === $field ) {
			$value = str_replace( "\r\n", "\n", $value );
			return trim( $value );
		}

		return trim( sanitize_text_field( $value ) );
	}

	/* --------------------------------------------------------------------- */
	/* Sealing                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Derive the encryption key from the site's wp-config salts.
	 *
	 * Rotating the salts intentionally invalidates stored secrets — they must be re-entered, which
	 * is the correct outcome, not a bug.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		if ( '' === $material ) {
			// A site with no salts at all is already badly misconfigured; still, never key off ''.
			$material = ABSPATH . get_option( 'siteurl' );
		}

		return hash( 'sha256', 'yazan-social-auth|' . $material, true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plaintext Secret.
	 * @return string Sealed, prefixed, base64 blob.
	 */
	private static function seal( $plaintext ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plaintext; // Better stored than refused; unseal() handles plaintext.
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext into a text option.
		return self::SEALED_PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Value as held in the option.
	 * @return string Plaintext, or '' when it cannot be opened.
	 */
	private static function unseal( $stored ) {
		if ( '' === $stored || 0 !== strpos( $stored, self::SEALED_PREFIX ) ) {
			return $stored; // Never sealed (no sodium at write time) — return as-is.
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverse of seal().
		$raw = base64_decode( substr( $stored, strlen( self::SEALED_PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		// false = wrong key (salts rotated) or tampering. Empty string disables the provider
		// cleanly instead of feeding garbage to the OAuth exchange.
		return false === $plain ? '' : $plain;
	}
}
