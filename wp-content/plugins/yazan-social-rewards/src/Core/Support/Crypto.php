<?php
/**
 * Symmetric encryption for secrets at rest (OAuth tokens).
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated symmetric encryption (libsodium secretbox) for values that must be
 * stored but never exposed — customer OAuth access/refresh tokens. The key is derived
 * from `YZRW_TOKEN_KEY` if defined, else from WordPress's own secret salts, so it
 * lives in wp-config, never in the database. Ciphertext is a versioned base64 string.
 *
 * If libsodium is unavailable the value is returned base64-tagged and clearly marked
 * as NOT encrypted, so a misconfigured host degrades visibly rather than silently
 * storing plaintext that looks encrypted.
 */
final class Crypto {

	private const PREFIX_ENC   = 'yzrw:v1:';   // libsodium secretbox.
	private const PREFIX_PLAIN = 'yzrw:raw:';  // fallback marker (no libsodium).

	/**
	 * Encrypt a value. Returns a self-describing string safe to store.
	 *
	 * @param string $plaintext Value.
	 * @return string
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! $this->available() ) {
			return self::PREFIX_PLAIN . base64_encode( $plaintext );
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );
		return self::PREFIX_ENC . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a value produced by {@see self::encrypt()}. Returns '' on failure.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		if ( str_starts_with( $stored, self::PREFIX_PLAIN ) ) {
			return (string) base64_decode( substr( $stored, strlen( self::PREFIX_PLAIN ) ), true );
		}
		if ( ! str_starts_with( $stored, self::PREFIX_ENC ) || ! $this->available() ) {
			return '';
		}
		$bin = base64_decode( substr( $stored, strlen( self::PREFIX_ENC ) ), true );
		if ( false === $bin || strlen( $bin ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether authenticated encryption is available on this host.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * The 32-byte key, derived from a wp-config constant or the WP salts.
	 *
	 * @return string
	 */
	private function key(): string {
		$material = ( defined( 'YZRW_TOKEN_KEY' ) && '' !== (string) YZRW_TOKEN_KEY )
			? (string) YZRW_TOKEN_KEY
			: wp_salt( 'secure_auth' ) . wp_salt( 'auth' );
		// Fold to exactly KEYBYTES via a hash (raw output).
		return substr( hash( 'sha256', 'yzrw-token|' . $material, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
