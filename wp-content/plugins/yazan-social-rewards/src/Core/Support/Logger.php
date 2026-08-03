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
		/*
		 * ⚠️ THE `WP_DEBUG` GATE IS GONE, AND ITS ABSENCE IS THE POINT.
		 *
		 * This method used to return early unless `WP_DEBUG` was on — which is `false` in
		 * production, so this logger wrote NOTHING at exactly the moment anyone would want to read
		 * it. Every `info()`, `warning()` and `error()` call in this plugin was decoration.
		 *
		 * `Yazan_Log` applies a per-store LEVEL FLOOR instead: debug is off by default, warnings and
		 * errors always land, and one noisy tenant can be turned up without flooding the other 99.
		 * The class and its container registration are unchanged, so no call site moves.
		 */
		if ( \class_exists( 'Yazan_Log' ) ) {
			\Yazan_Log::write( $level, $message, $context, 'rewards' );

			return;
		}

		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$suffix = empty( $context ) ? '' : ' ' . wp_json_encode( $context );
		error_log( sprintf( '[%s][%s] %s%s', self::PREFIX, $level, $message, (string) $suffix ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
