<?php
/**
 * Minimal outbound HTTP client for connector API + OAuth calls.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin wrapper over the WordPress HTTP API for calls to KNOWN social-network API
 * and OAuth hosts (token exchange, account/content/metrics reads). One automatic
 * retry on a transport error or 5xx. Returns a normalised array; never throws.
 *
 * NOTE: this is for trusted network endpoints. Fetching an arbitrary customer-supplied
 * post URL (Method 2) goes through {@see \Yazan\Rewards\Modules\Social\UrlFetcher},
 * which adds an SSRF guard.
 */
final class Http {

	private const TIMEOUT = 20;
	private const RETRIES = 1;

	/**
	 * GET returning decoded JSON.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @return array{ok:bool,status:int,body:array,error:string}
	 */
	public function get_json( string $url, array $headers = array() ): array {
		return $this->request( 'GET', $url, array( 'headers' => $this->json_headers( $headers ) ) );
	}

	/**
	 * POST a JSON body, returning decoded JSON.
	 *
	 * @param string $url     URL.
	 * @param array  $body    Body (json-encoded).
	 * @param array  $headers Headers.
	 * @return array{ok:bool,status:int,body:array,error:string}
	 */
	public function post_json( string $url, array $body, array $headers = array() ): array {
		return $this->request(
			'POST',
			$url,
			array(
				'headers' => $this->json_headers( $headers, true ),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
	}

	/**
	 * POST a form-encoded body (the OAuth2 token-exchange content type).
	 *
	 * @param string $url     URL.
	 * @param array  $fields  Form fields.
	 * @param array  $headers Headers.
	 * @return array{ok:bool,status:int,body:array,error:string}
	 */
	public function post_form( string $url, array $fields, array $headers = array() ): array {
		$headers['Accept']       = 'application/json';
		$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		return $this->request(
			'POST',
			$url,
			array(
				'headers' => $headers,
				'body'    => http_build_query( $fields ),
			)
		);
	}

	/**
	 * Perform the request with a single retry, decoding a JSON response body.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   wp_remote args.
	 * @return array{ok:bool,status:int,body:array,error:string}
	 */
	private function request( string $method, string $url, array $args ): array {
		$args = array_merge( array( 'method' => $method, 'timeout' => self::TIMEOUT ), $args );

		$last_error = '';
		for ( $attempt = 0; $attempt <= self::RETRIES; $attempt++ ) {
			$response = wp_safe_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue; // Transport error — retry.
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 500 ) {
				$last_error = 'HTTP ' . $status;
				continue; // Server error — retry.
			}

			$decoded = json_decode( $raw, true );
			return array(
				'ok'     => $status >= 200 && $status < 300,
				'status' => $status,
				'body'   => is_array( $decoded ) ? $decoded : array(),
				'error'  => ( $status >= 400 ) ? ( 'HTTP ' . $status ) : '',
			);
		}

		return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => $last_error ?: 'request_failed' );
	}

	/**
	 * Merge default JSON headers.
	 *
	 * @param array $headers   Caller headers.
	 * @param bool  $with_type Add a JSON content-type.
	 * @return array
	 */
	private function json_headers( array $headers, bool $with_type = false ): array {
		$headers['Accept'] = $headers['Accept'] ?? 'application/json';
		if ( $with_type && empty( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}
}
