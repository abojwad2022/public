<?php
/**
 * The actor lacks the permission this operation requires.
 *
 * Thrown by AuthorizationPort::require() so a handler never has to branch on a boolean and then
 * remember to return early — forgetting that return is how authorization bugs actually happen.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorization failure.
 */
class Forbidden extends HomepageException {

	/** @return string */
	public function error_code() {
		return 'yazan_homepage_forbidden';
	}

	/** @return int */
	public function status() {
		return 403;
	}
}
