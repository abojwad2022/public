<?php
/**
 * Yazan AI — provider exception.
 *
 * A thin exception carrying a STABLE error code (not just a message) so the router can decide whether
 * a failure is worth failing over on. The message is developer-facing; the code is what the system
 * branches on and what surfaces (sanitized) in the generation log.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised by provider adapters on any non-success.
 */
class Yazan_AI_Exception extends Exception {

	/** Stable machine code, e.g. 'auth', 'rate_limited', 'timeout', 'server', 'bad_response'. */
	protected $error_code;

	/** HTTP status from the provider, when there was one. */
	protected $http_status;

	/** Whether the router should try the next provider in the chain. */
	protected $retryable;

	/**
	 * @param string $error_code  Stable code.
	 * @param string $message     Developer message.
	 * @param int    $http_status Provider HTTP status (0 when none).
	 * @param bool   $retryable   Fail over to the next provider?
	 */
	public function __construct( $error_code, $message = '', $http_status = 0, $retryable = true ) {
		parent::__construct( $message );
		$this->error_code  = sanitize_key( $error_code );
		$this->http_status = (int) $http_status;
		$this->retryable   = (bool) $retryable;
	}

	/** @return string */
	public function error_code() {
		return $this->error_code;
	}

	/** @return int */
	public function http_status() {
		return $this->http_status;
	}

	/** @return bool */
	public function is_retryable() {
		return $this->retryable;
	}

	/**
	 * Map an HTTP status to a stable code + retryability. Handy for every adapter.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body (for the message).
	 * @return Yazan_AI_Exception
	 */
	public static function from_http( $status, $body = '' ) {
		$status = (int) $status;
		$snippet = substr( wp_strip_all_tags( (string) $body ), 0, 300 );

		if ( 401 === $status || 403 === $status ) {
			// An invalid key will never succeed on retry to the SAME provider, but the router should
			// still try the NEXT provider — so this stays retryable at the chain level.
			return new self( 'auth', "Provider auth failed ({$status}): {$snippet}", $status, true );
		}
		if ( 429 === $status ) {
			return new self( 'rate_limited', "Provider rate limited ({$status}): {$snippet}", $status, true );
		}
		if ( $status >= 500 ) {
			return new self( 'server', "Provider server error ({$status}): {$snippet}", $status, true );
		}
		if ( $status >= 400 ) {
			// A 4xx (bad request / model not found) usually indicates a config mistake; still worth a
			// failover so one bad model id doesn't kill the whole request.
			return new self( 'bad_request', "Provider rejected the request ({$status}): {$snippet}", $status, true );
		}
		return new self( 'unknown', "Unexpected provider status ({$status}): {$snippet}", $status, true );
	}
}
