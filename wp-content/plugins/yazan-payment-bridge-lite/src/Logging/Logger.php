<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Logging;

use Yazan\PaymentBridge\Settings\Settings;
use Yazan\PaymentBridge\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to WooCommerce's logger (WooCommerce → Status → Logs) under the source
 * `yazan-payment-bridge`.
 *
 * No PII at any level (H6): every context array is filtered down to an
 * allow-list of identifiers and status codes by {@see Sanitizer::scrub_context()},
 * and every message is scrubbed by {@see Sanitizer::scrub_error()}. Debug is off
 * unless explicitly enabled in settings.
 */
final class Logger {

	/** WooCommerce log source. */
	public const SOURCE = 'yazan-payment-bridge';

	/**
	 * @param Settings $settings Settings reader (gates debug output).
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Debug-level message. No-op unless debug logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context (allow-listed).
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( ! $this->settings->is_enabled( 'debug_logging' ) ) {
			return;
		}
		$this->write( 'debug', $message, $context );
	}

	/**
	 * Info-level message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context (allow-listed).
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Warning-level message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context (allow-listed).
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * Error-level message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context (allow-listed).
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Funnel every level through one scrubbed write.
	 *
	 * @param string              $level   WC log level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return; // WooCommerce absent; the Bridge is inert anyway.
		}

		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}

		$clean = Sanitizer::scrub_context( $context );
		$line  = Sanitizer::scrub_error( $message );

		if ( $clean ) {
			$encoded = wp_json_encode( $clean );
			$line   .= ' ' . ( is_string( $encoded ) ? $encoded : '' );
		}

		$logger->log( $level, $line, array( 'source' => self::SOURCE ) );
	}
}
