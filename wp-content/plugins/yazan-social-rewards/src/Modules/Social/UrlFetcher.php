<?php
/**
 * SSRF-guarded outbound URL fetcher for manual content verification.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Social;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a customer-supplied post URL to check it exists and to read its HTML for
 * the manual checks. Because the URL is untrusted, every fetch is SSRF-guarded:
 * http/https only, no credentials, and the resolved host must NOT be a private,
 * reserved, or loopback address — so a submission can't be used to probe the
 * server's internal network. wp_safe_remote_get adds a second layer.
 */
class UrlFetcher {

	private const MAX_BYTES = 524288; // 512 KB.
	private const TIMEOUT   = 10;

	/**
	 * Whether a URL is safe to fetch (scheme, credentials, and non-internal host).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_safe( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false; // No embedded credentials.
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}

		$ips = $this->resolve( $host );
		if ( empty( $ips ) ) {
			return false; // Unresolvable → refuse.
		}
		foreach ( $ips as $ip ) {
			// Reject anything that is NOT a public, routable address.
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the URL resolves to a reachable page (2xx/3xx).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function exists( string $url ): bool {
		$result = $this->fetch( $url );
		return $result['ok'];
	}

	/**
	 * Fetch a URL's HTML (SSRF-guarded, size-capped).
	 *
	 * @param string $url URL.
	 * @return array{ok:bool,status:int,body:string}
	 */
	public function fetch( string $url ): array {
		if ( ! $this->is_safe( $url ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => '' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'reject_unsafe_urls'  => true,
				'user-agent'          => 'YazanRewards/1.0 (+verification)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => '' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		return array(
			'ok'     => $status >= 200 && $status < 400,
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Resolve a host to its IP addresses (or return the host itself if already an IP).
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips     = array();
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		if ( empty( $ips ) ) {
			$v4 = gethostbyname( $host );
			if ( $v4 && $v4 !== $host ) {
				$ips[] = $v4;
			}
		}
		return $ips;
	}
}
