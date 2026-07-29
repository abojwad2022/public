<?php
/**
 * Yazan AI — image input helper.
 *
 * Providers disagree on how images travel: OpenAI-schema takes any URL (including a data URI) as-is;
 * Anthropic accepts either a URL source or a base64 source; Gemini needs raw base64 + mime type. This
 * helper normalizes whatever the pipeline hands us (a `data:` URI or an http(s) URL) into the shape a
 * given provider needs, fetching a remote URL only when a provider cannot take a URL directly.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image normalization for provider payloads.
 */
class Yazan_AI_Image {

	/** Largest remote image we will fetch + inline (bytes). */
	const MAX_BYTES = 8388608; // 8 MB.

	/**
	 * Classify a source string.
	 *
	 * @param string $src Data URI or http(s) URL.
	 * @return array{kind:string,mime:string,data:string,url:string} kind = 'data'|'url'|'invalid'.
	 */
	public static function parse( $src ) {
		$src = (string) $src;

		if ( 0 === strpos( $src, 'data:' ) ) {
			// data:[<mime>][;base64],<data>
			if ( preg_match( '#^data:([^;,]+)?(;base64)?,(.*)$#is', $src, $m ) ) {
				$mime = $m[1] ? strtolower( $m[1] ) : 'image/jpeg';
				$data = isset( $m[2] ) && ';base64' === $m[2] ? $m[3] : base64_encode( rawurldecode( $m[3] ) );
				return array( 'kind' => 'data', 'mime' => $mime, 'data' => $data, 'url' => '' );
			}
			return array( 'kind' => 'invalid', 'mime' => '', 'data' => '', 'url' => '' );
		}

		$url = esc_url_raw( $src );
		if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
			return array( 'kind' => 'url', 'mime' => '', 'data' => '', 'url' => $url );
		}

		return array( 'kind' => 'invalid', 'mime' => '', 'data' => '', 'url' => '' );
	}

	/**
	 * Return raw base64 + mime for providers that cannot take a URL (Gemini; Anthropic base64 path).
	 * Fetches a remote URL when necessary.
	 *
	 * @param string $src Source.
	 * @return array{mime:string,data:string}|null Null when the source is unusable.
	 */
	public static function to_base64( $src ) {
		$parsed = self::parse( $src );

		if ( 'data' === $parsed['kind'] ) {
			return array( 'mime' => $parsed['mime'], 'data' => $parsed['data'] );
		}

		if ( 'url' === $parsed['kind'] ) {
			// SSRF guard: wp_safe_remote_get() runs wp_http_validate_url(), which rejects loopback,
			// link-local (169.254.x — cloud metadata) and RFC1918 hosts, and refuses redirects to
			// them. Never use the unguarded wp_remote_get() for a URL that may originate from user
			// input. limit_response_size caps the download so a hostile URL cannot exhaust memory
			// before the post-hoc size check.
			$response = wp_safe_remote_get(
				$parsed['url'],
				array(
					'timeout'             => 20,
					'redirection'         => 2,
					'limit_response_size' => self::MAX_BYTES + 1,
					'reject_unsafe_urls'  => true,
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
				return null;
			}
			$mime = wp_remote_retrieve_header( $response, 'content-type' );
			$mime = $mime ? strtok( (string) $mime, ';' ) : 'image/jpeg';
			return array( 'mime' => $mime, 'data' => base64_encode( $body ) );
		}

		return null;
	}
}
