<?php
/**
 * Yazan AI — HTTP helper.
 *
 * One place that performs the outbound JSON POST every provider needs. This is the first outbound
 * HTTP in the whole plugin, so it establishes the conventions: a generous-but-bounded timeout, one
 * automatic retry on a transient network/5xx failure, and translation of both WP transport errors and
 * provider HTTP errors into a {@see Yazan_AI_Exception} with a stable code.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, retrying JSON POST for provider calls.
 */
class Yazan_AI_Http {

	/** Per-attempt timeout (seconds). LLM calls are slow; keep it comfortably above p95. */
	const TIMEOUT = 45;

	/**
	 * POST a JSON body and return the decoded array.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $body    Body to JSON-encode.
	 * @param array  $headers Request headers.
	 * @return array Decoded JSON response.
	 * @throws Yazan_AI_Exception On transport error, HTTP error, or undecodable body.
	 */
	public static function post_json( $url, array $body, array $headers ) {
		$args = array(
			'timeout'     => self::TIMEOUT,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
		);

		$attempts = 0;
		$last     = null;

		while ( $attempts < 2 ) {
			++$attempts;
			if ( $attempts > 1 ) {
				usleep( 400000 ); // 0.4s backoff before the single retry so we don't hammer a struggling provider.
			}
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				// Network-level failure (DNS, connection, timeout) — retry once, then give up.
				$last = new Yazan_AI_Exception( 'transport', $response->get_error_message(), 0, true );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 200 && $status < 300 ) {
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					throw new Yazan_AI_Exception( 'bad_response', 'Provider returned a non-JSON body.', $status, true );
				}
				return $decoded;
			}

			// A 5xx is worth one retry; 4xx will not change on retry to the same endpoint.
			$last = Yazan_AI_Exception::from_http( $status, $raw );
			if ( $status < 500 ) {
				throw $last;
			}
		}

		throw $last instanceof Yazan_AI_Exception
			? $last
			: new Yazan_AI_Exception( 'transport', 'Provider request failed.', 0, true );
	}

	/** Timeout for image generation, which is slower than text (seconds). */
	const IMAGE_TIMEOUT = 90;

	/**
	 * POST a multipart/form-data request with one file part (for OpenAI image edits, which are not JSON).
	 * No auto-retry — image generation is expensive and slow; a single attempt is intentional.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $fields  Scalar form fields (name => value); array values expand to repeated parts.
	 * @param array  $file    { field, filename, mime, bytes } for the single file part.
	 * @param array  $headers Request headers (auth).
	 * @return array Decoded JSON response.
	 * @throws Yazan_AI_Exception On failure.
	 */
	public static function post_multipart( $url, array $fields, array $file, array $headers ) {
		$boundary = 'yzbnd' . md5( uniqid( '', true ) );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			foreach ( is_array( $value ) ? $value : array( $value ) as $v ) {
				$body .= '--' . $boundary . $eol;
				$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
				$body .= $v . $eol;
			}
		}
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file['field'] . '"; filename="' . $file['filename'] . '"' . $eol;
		$body .= 'Content-Type: ' . $file['mime'] . $eol . $eol;
		$body .= $file['bytes'] . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::IMAGE_TIMEOUT,
				'headers' => array_merge( $headers, array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Yazan_AI_Exception( 'transport', $response->get_error_message(), 0, true );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw Yazan_AI_Exception::from_http( $status, $raw );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new Yazan_AI_Exception( 'bad_response', 'Image provider returned a non-JSON body.', $status, true );
		}
		return $decoded;
	}
}
