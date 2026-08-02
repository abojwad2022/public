<?php
/**
 * A section names a component type that is not registered.
 *
 * On import this rejects the whole package. At render time it is tolerated — see RenderPipeline —
 * because unregistering a component must never destroy content an editor already wrote.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unregistered component type.
 */
class UnknownComponentType extends HomepageException {

	/** @return string */
	public function error_code() {
		return 'yazan_homepage_unknown_component';
	}

	/** @return int */
	public function status() {
		return 400;
	}
}
