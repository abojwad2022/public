<?php
/**
 * Privacy-preserving client fingerprints (IP + email) for fraud signals.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, stateless helpers that turn identifying data into stable, non-reversible
 * hashes used only to compare two accounts for duplicate/self-referral signals —
 * never to store or expose the raw value. Centralised here so the IP hash isn't
 * copy-pasted across the Referral and Anti-Fraud engines.
 */
final class Fingerprint {

	/**
	 * A salted, non-reversible hash of the client IP (32 chars). Uses an HMAC keyed
	 * with a site salt so the small IPv4 space can't be brute-forced back to the
	 * address. Returns '' when no IP is available (e.g. CLI).
	 *
	 * @return string 32-char hash, or ''.
	 */
	public function ip_hash(): string {
		$ip = $this->client_ip();
		return '' !== $ip ? substr( $this->hmac( $ip ), 0, 32 ) : '';
	}

	/**
	 * The raw client IP (validated). Uses REMOTE_ADDR — the only address the web
	 * server can vouch for. `X-Forwarded-For` is client-spoofable, so it is honoured
	 * ONLY when `YZRW_TRUST_PROXY` is defined truthy (i.e. the site sits behind a
	 * trusted reverse proxy), in which case the left-most forwarded entry is taken.
	 * This keeps the anti-fraud IP signal from being trivially rotated per request.
	 *
	 * @return string
	 */
	public function client_ip(): string {
		if ( defined( 'YZRW_TRUST_PROXY' ) && YZRW_TRUST_PROXY && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			foreach ( array_map( 'trim', explode( ',', $forwarded ) ) as $ip ) {
				if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		$remote = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return ( '' !== $remote && false !== filter_var( $remote, FILTER_VALIDATE_IP ) ) ? $remote : '';
	}

	/**
	 * Keyed HMAC used for both the IP and email hashes, so neither stored value is
	 * reversible without the site's secret salts.
	 *
	 * @param string $value Value to hash.
	 * @return string 64-char hex.
	 */
	private function hmac( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) );
	}

	/**
	 * Canonicalise an email so aliases of the same inbox compare equal:
	 * lowercase, and for Gmail-style hosts strip dots and any `+tag` from the
	 * local part. Used ONLY for duplicate-account matching, never for delivery.
	 *
	 * @param string $email Raw email.
	 * @return string Normalised email, or '' if not a valid address.
	 */
	public function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		list( $local, $domain ) = explode( '@', $email, 2 );

		// Strip everything from a `+tag` onward (common alias mechanism).
		$plus = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}

		// Dot-insensitive local parts on Google-hosted domains.
		$dotless_hosts = array( 'gmail.com', 'googlemail.com' );
		if ( in_array( $domain, $dotless_hosts, true ) ) {
			$local = str_replace( '.', '', $local );
		}

		return '' !== $local ? $local . '@' . $domain : '';
	}

	/**
	 * A stable hash of the normalised email (for cross-account comparison).
	 *
	 * @param string $email Raw email.
	 * @return string 64-char hash, or ''.
	 */
	public function email_hash( string $email ): string {
		$normal = $this->normalize_email( $email );
		return '' !== $normal ? $this->hmac( $normal ) : '';
	}
}
