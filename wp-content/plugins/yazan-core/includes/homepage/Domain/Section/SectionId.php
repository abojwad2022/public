<?php
/**
 * A section identifier (UUID v4).
 *
 * A UUID rather than an auto-increment because a section keeps its identity across export,
 * import, template application and cloning — none of which share a database sequence.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object wrapping a UUID.
 */
final class SectionId {

	/** @var string */
	private $value;

	/**
	 * @param string $value UUID.
	 */
	private function __construct( $value ) {
		$this->value = $value;
	}

	/**
	 * @param string $value Raw UUID.
	 * @return self
	 */
	public static function from( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid section id.' );
		}

		return new self( $value );
	}

	/**
	 * Mint a new identifier.
	 *
	 * Uses WordPress's own generator, which is cryptographically seeded.
	 *
	 * @return self
	 */
	public static function generate() {
		return new self( wp_generate_uuid4() );
	}

	/** @return string */
	public function value() {
		return $this->value;
	}

	/**
	 * @param self $other Other id.
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
