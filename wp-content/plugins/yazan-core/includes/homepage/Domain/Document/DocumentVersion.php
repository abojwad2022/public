<?php
/**
 * Optimistic concurrency token.
 *
 * Every write carries the version the editor believes it is changing. A mismatch is a 409, not a
 * silent overwrite — which is what "two people edited the homepage and one of them lost an hour"
 * actually looks like in practice.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monotonic version counter.
 */
final class DocumentVersion {

	/** @var int */
	private $value;

	/**
	 * @param int $value Version.
	 */
	private function __construct( $value ) {
		$this->value = max( 0, (int) $value );
	}

	/** @return self */
	public static function initial() {
		return new self( 1 );
	}

	/**
	 * @param int $value Raw version.
	 * @return self
	 */
	public static function from( $value ) {
		return new self( $value );
	}

	/** @return self */
	public function next() {
		return new self( $this->value + 1 );
	}

	/** @return int */
	public function value() {
		return $this->value;
	}

	/**
	 * @param self $other Other version.
	 * @return bool
	 */
	public function equals( self $other ) {
		return $this->value === $other->value;
	}
}
