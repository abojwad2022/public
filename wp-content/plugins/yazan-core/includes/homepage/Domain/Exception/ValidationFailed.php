<?php
/**
 * The document or a section failed validation.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation failure, carrying the per-field errors in its context.
 */
class ValidationFailed extends HomepageException {

	/** @return string */
	public function error_code() {
		return 'yazan_homepage_invalid';
	}

	/** @return int */
	public function status() {
		return 422;
	}
}
