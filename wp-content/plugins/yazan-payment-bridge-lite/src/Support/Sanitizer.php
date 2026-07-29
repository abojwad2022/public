<?php
/**
 * Sensitive-data scrubbing (H6).
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every string the Bridge stores or logs passes through here first.
 *
 * The Bridge never handles card data by design, but a downstream exception
 * message can still quote a gateway payload. This class makes that harmless
 * mechanically rather than relying on call-site discipline.
 */
final class Sanitizer {

	/** Hard cap matching the error_message column width. */
	public const MAX_ERROR_LENGTH = 500;

	/**
	 * Log-context keys that may be written. Anything else is dropped, so PII
	 * cannot reach a log file even if a caller passes it.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CONTEXT_KEYS = array(
		'order_id',
		'event_id',
		'event_uuid',
		'event_type',
		'integration_status',
		'payment_status',
		'gateway',
		'source',
		'code',
		'connector',
		'count',
	);

	/**
	 * Scrub an error message for storage/logging: strip markup, redact anything
	 * resembling a card number, gateway key, token, bearer credential or email,
	 * collapse whitespace, and truncate.
	 *
	 * @param string $raw Raw message.
	 * @return string Safe, bounded message.
	 */
	public static function scrub_error( string $raw ): string {
		$message = wp_strip_all_tags( $raw );

		$patterns = array(
			// Card-number-like runs of 13–19 digits, optionally spaced/hyphenated.
			'/\b(?:\d[ -]?){13,19}\b/'                         => '[redacted-pan]',
			// Stripe-style secret/publishable/restricted keys.
			'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}\b/' => '[redacted-key]',
			// Gateway object tokens (token, card, customer, payment intent, charge, setup intent).
			'/\b(?:tok|card|cus|pi|ch|seti)_[A-Za-z0-9]{8,}\b/' => '[redacted-token]',
			// Authorization headers quoted into a message.
			'/\bBearer\s+[A-Za-z0-9._\-]+/i'                   => '[redacted-bearer]',
			// A CVV explicitly labelled as such.
			'/\b(?:cvv|cvc|cid)\D{0,3}\d{3,4}\b/i'             => '[redacted-cvv]',
			// Email addresses (PII).
			'/[\w.+-]+@[\w-]+\.[\w.]+/'                        => '[redacted-email]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$replaced = preg_replace( $pattern, $replacement, $message );
			if ( null !== $replaced ) {
				$message = $replaced;
			}
		}

		$message = (string) preg_replace( '/\s+/', ' ', trim( $message ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, self::MAX_ERROR_LENGTH );
		}

		return substr( $message, 0, self::MAX_ERROR_LENGTH );
	}

	/**
	 * Reduce a log context array to the allowed, scalar-only keys (H6).
	 *
	 * @param array<string,mixed> $context Caller-supplied context.
	 * @return array<string,scalar>
	 */
	public static function scrub_context( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( ! in_array( $key, self::ALLOWED_CONTEXT_KEYS, true ) ) {
				continue;
			}
			if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}
			if ( is_string( $value ) ) {
				$clean[ $key ] = self::scrub_error( $value );
			}
		}

		return $clean;
	}
}
