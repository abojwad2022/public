<?php
/**
 * Someone else saved this document first.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimistic-lock failure.
 */
class VersionConflict extends HomepageException {

	/** @return string */
	public function error_code() {
		return 'yazan_homepage_version_conflict';
	}

	/** @return int */
	public function status() {
		return 409;
	}
}
