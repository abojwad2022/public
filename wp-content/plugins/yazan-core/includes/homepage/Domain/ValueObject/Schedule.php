<?php
/**
 * An optional display window for a section.
 *
 * Stored as UTC timestamps. The editor sends and receives site-local strings; conversion happens
 * at the REST boundary so the domain never has to know what timezone anyone is in.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\ValueObject;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable from/to window.
 */
final class Schedule {

	/** @var int|null UTC timestamp. */
	private $from;

	/** @var int|null UTC timestamp. */
	private $to;

	/**
	 * @param int|null $from Start.
	 * @param int|null $to   End.
	 */
	private function __construct( $from, $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	/** @return self */
	public static function always() {
		return new self( null, null );
	}

	/**
	 * @param array $data Serialised form: from, to (UTC timestamps or null).
	 * @return self
	 */
	public static function from_array( array $data ) {
		$from = isset( $data['from'] ) && $data['from'] ? (int) $data['from'] : null;
		$to   = isset( $data['to'] ) && $data['to'] ? (int) $data['to'] : null;

		if ( $from && $to && $to <= $from ) {
			throw new \InvalidArgumentException( 'A schedule must end after it starts.' );
		}

		return new self( $from, $to );
	}

	/**
	 * @param int $now Current UTC timestamp.
	 * @return bool
	 */
	public function is_active_at( $now ) {
		if ( $this->from && $now < $this->from ) {
			return false;
		}
		if ( $this->to && $now >= $this->to ) {
			return false;
		}
		return true;
	}

	/** @return bool */
	public function is_bounded() {
		return null !== $this->from || null !== $this->to;
	}

	/** @return array */
	public function to_array() {
		return array(
			'from' => $this->from,
			'to'   => $this->to,
		);
	}
}
