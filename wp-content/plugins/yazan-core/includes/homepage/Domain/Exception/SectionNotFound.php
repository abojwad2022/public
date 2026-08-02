<?php
/**
 * A referenced section is not part of the document.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Missing section.
 */
class SectionNotFound extends HomepageException {

	/** @return string */
	public function error_code() {
		return 'yazan_homepage_section_not_found';
	}

	/** @return int */
	public function status() {
		return 404;
	}
}
