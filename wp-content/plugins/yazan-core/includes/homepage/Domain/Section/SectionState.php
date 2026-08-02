<?php
/**
 * Whether a section participates in rendering at all.
 *
 * Deliberately separate from Visibility: `disabled` is an editorial decision ("not now"), device
 * visibility is a layout decision ("not here"). Collapsing them into one flag loses the difference
 * between a section someone turned off and one that is simply mobile-only.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section state constants.
 */
final class SectionState {

	const ENABLED  = 'enabled';
	const DISABLED = 'disabled';

	/**
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize( $value ) {
		return self::DISABLED === $value ? self::DISABLED : self::ENABLED;
	}
}
