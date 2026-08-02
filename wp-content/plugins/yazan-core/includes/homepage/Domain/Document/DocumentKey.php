<?php
/**
 * The key identifying which homepage document this is.
 *
 * Always `default` today. It exists now so multi-homepage, campaign pages and A/B variants are a
 * resolver change later rather than a schema migration.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object wrapping a document key.
 */
final class DocumentKey {

	const DEFAULT_KEY = 'default';

	/** @var string */
	private $value;

	/**
	 * @param string $value Key.
	 */
	private function __construct( $value ) {
		$this->value = $value;
	}

	/** @return self */
	public static function default_key() {
		return new self( self::DEFAULT_KEY );
	}

	/**
	 * @param string $value Raw key.
	 * @return self
	 */
	public static function from( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid homepage document key.' );
		}

		return new self( $value );
	}

	/** @return string */
	public function value() {
		return $this->value;
	}

	/** @return string */
	public function __toString() {
		return $this->value;
	}
}
