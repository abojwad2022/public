<?php
/**
 * Time boundary — injected so scheduling logic is testable without sleeping.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current time.
 */
interface ClockPort {

	/** @return int Current UTC timestamp. */
	public function now();

	/** @return string Site timezone string. */
	public function timezone();
}
