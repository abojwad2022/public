<?php
/**
 * A component type slug.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object wrapping a component type string.
 */
final class ComponentType {

	/** @var string */
	private $value;

	/**
	 * @param string $value Type slug.
	 */
	private function __construct( $value ) {
		$this->value = $value;
	}

	/**
	 * @param string $value Raw slug.
	 * @return self
	 */
	public static function from( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid component type.' );
		}

		return new self( $value );
	}

	/** @return string */
	public function value() {
		return $this->value;
	}

	/**
	 * @param self $other Other type.
	 * @return bool
	 */
	public function equals( self $other ) {
		return $this->value === $other->value;
	}

	/** @return string */
	public function __toString() {
		return $this->value;
	}
}
