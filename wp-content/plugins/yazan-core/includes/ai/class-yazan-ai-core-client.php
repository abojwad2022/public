<?php
/**
 * Yazan AI — remote AI Core client (Phase 3).
 *
 * When an external AI Core service is configured, the gateway delegates provider execution to it over
 * this client instead of calling the in-PHP adapters. The service is stateless — WordPress still owns
 * keys, prompts, budget, cache, and logging — so this client simply signs and POSTs the request +
 * the resolved chain (with keys) and returns the normalized result.
 *
 * Auth: X-Yazan-Signature = HMAC-SHA256( "<timestamp>.<rawBody>", shared secret ), matching the
 * service's verifier. A transport/auth/service failure throws {@see Yazan_AI_Exception}, which the
 * gateway treats as "service unusable" and falls back to local providers — so enabling the Core is
 * zero-risk.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed HTTP client for the external AI Core.
 */
class Yazan_AI_Core_Client {

	/** Provider id under which the shared secret is stored in {@see Yazan_AI_Secrets}. */
	const SECRET_ID = 'core';

	/** Request timeout (seconds) — generous, LLM calls run on the service. */
	const TIMEOUT = 60;

	/**
	 * Whether a usable remote Core is configured (enabled + URL + secret).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$config = Yazan_AI_Settings::get( 'ai_core', array() );
		if ( empty( $config['enabled'] ) || empty( $config['url'] ) ) {
			return false;
		}
		return '' !== Yazan_AI_Secrets::get( self::SECRET_ID );
	}

	/**
	 * Configured base URL (no trailing slash), or ''.
	 *
	 * @return string
	 */
	public static function url() {
		$config = Yazan_AI_Settings::get( 'ai_core', array() );
		return isset( $config['url'] ) ? untrailingslashit( (string) $config['url'] ) : '';
	}

	/**
	 * Run a completion on the remote service.
	 *
	 * @param array  $request    Normalized request (system/messages/json/temperature/max_tokens).
	 * @param array  $chain      Ordered [{provider, model, api_key}] attempts.
	 * @param string $capability 'text' | 'vision'.
	 * @return array Normalized result: { ok, text, tokens_in, tokens_out, provider, model, error_code?, attempts? }.
	 * @throws Yazan_AI_Exception On transport/auth/service failure (→ gateway falls back to local).
	 */
	public static function complete( array $request, array $chain, $capability = 'text' ) {
		return self::post(
			'/v1/complete',
			array(
				'capability' => 'vision' === $capability ? 'vision' : 'text',
				'request'    => $request,
				'chain'      => array_values( $chain ),
			)
		);
	}

	/**
	 * Liveness + signed round-trip probe for the Test button.
	 *
	 * @return array{ok:bool,version:string,latency_ms:int,error:?string}
	 */
	public static function ping() {
		$url = self::url();
		if ( '' === $url ) {
			return array( 'ok' => false, 'version' => '', 'latency_ms' => 0, 'error' => __( 'No AI Core URL configured.', 'yazan' ) );
		}

		$started  = microtime( true );
		$response = wp_remote_get( $url . '/health', array( 'timeout' => 10 ) );
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'version' => '', 'latency_ms' => $latency, 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || empty( $decoded['ok'] ) ) {
			return array( 'ok' => false, 'version' => '', 'latency_ms' => $latency, 'error' => sprintf( 'HTTP %d', $status ) );
		}

		// Confirm the shared secret matches with a tiny signed no-op (empty chain → auth-checked).
		$auth_ok = true;
		$auth_err = '';
		try {
			self::post( '/v1/complete', array( 'capability' => 'text', 'request' => array( 'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ) ), 'chain' => array() ) );
		} catch ( Yazan_AI_Exception $e ) {
			if ( in_array( $e->error_code(), array( 'auth', 'core_auth' ), true ) || 401 === $e->http_status() ) {
				$auth_ok  = false;
				$auth_err = __( 'The shared secret does not match the service.', 'yazan' );
			}
			// Other errors (transport handled above) are fine — the health call already succeeded.
		}

		return array(
			'ok'         => $auth_ok,
			'version'    => (string) ( $decoded['version'] ?? '' ),
			'latency_ms' => $latency,
			'error'      => $auth_ok ? null : $auth_err,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Internals                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Sign + POST a JSON body to the service, returning the decoded array.
	 *
	 * @param string $path Path (e.g. /v1/complete).
	 * @param array  $body Body.
	 * @return array
	 * @throws Yazan_AI_Exception On any non-success.
	 */
	private static function post( $path, array $body ) {
		$url    = self::url();
		$secret = Yazan_AI_Secrets::get( self::SECRET_ID );
		if ( '' === $url || '' === $secret ) {
			throw new Yazan_AI_Exception( 'core_config', 'AI Core is not fully configured.', 0, true );
		}

		$json      = wp_json_encode( $body );
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $json, $secret );

		$response = wp_remote_post(
			$url . $path,
			array(
				'timeout'     => self::TIMEOUT,
				'headers'     => array(
					'Content-Type'      => 'application/json',
					'X-Yazan-Timestamp' => $timestamp,
					'X-Yazan-Signature' => $signature,
				),
				'body'        => $json,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			// Unreachable → retryable so the gateway falls back to local providers.
			throw new Yazan_AI_Exception( 'core_transport', $response->get_error_message(), 0, true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status ) {
			throw new Yazan_AI_Exception( 'core_auth', 'AI Core rejected the signature.', 401, true );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new Yazan_AI_Exception( 'core_http', sprintf( 'AI Core HTTP %d', $status ), $status, true );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new Yazan_AI_Exception( 'core_bad_response', 'AI Core returned a non-JSON body.', $status, true );
		}
		return $decoded;
	}
}
