<?php
/**
 * Minimal logger.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around error_log(), gated on WP_DEBUG. Prefixed so platform log
 * lines are greppable. Never logs raw PII beyond ids.
 */
final class Logger {

	private const PREFIX = 'Yazan Rewards';

	/**
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Small scalar context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'INFO', $message, $context );
	}

	/**
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Small scalar context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'WARN', $message, $context );
	}

	/**
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Small scalar context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'ERROR', $message, $context );
	}

	/**
	 * @param string              $level   Level label.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$suffix = empty( $context ) ? '' : ' ' . wp_json_encode( $context );
		error_log( sprintf( '[%s][%s] %s%s', self::PREFIX, $level, $message, (string) $suffix ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
