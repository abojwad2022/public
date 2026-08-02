<?php
/**
 * Publication status of a homepage document.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status constants.
 */
final class DocumentStatus {

	const DRAFT     = 'draft';
	const PUBLISHED = 'published';
	const SCHEDULED = 'scheduled';

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize( $value ) {
		return in_array( $value, array( self::DRAFT, self::PUBLISHED, self::SCHEDULED ), true )
			? $value
			: self::DRAFT;
	}
}
