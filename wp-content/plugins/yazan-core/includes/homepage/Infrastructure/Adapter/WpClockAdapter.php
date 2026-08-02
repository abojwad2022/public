<?php
/**
 * Clock adapter.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Adapter;

use Yazan\Homepage\Domain\Port\ClockPort;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current time from WordPress.
 */
final class WpClockAdapter implements ClockPort {

	/** @return int */
	public function now() {
		return (int) time();
	}

	/** @return string */
	public function timezone() {
		return (string) wp_timezone_string();
	}
}
