<?php
/**
 * One A/B test: two homepage documents, a split, and the rule for who sees which.
 *
 * The whole point of this object is that ASSIGNMENT IS PURE. It takes a roll — a number the caller
 * produced — and returns a variant. No random(), no cookies, no superglobals. That is what makes
 * "60/40 really is 60/40" a thing a test can prove rather than a thing everyone hopes.
 *
 * Two rules are baked in rather than left to the caller:
 *
 *   1. A stopped experiment always answers CONTROL. Turning a test off must take effect on the
 *      next request, not when the last visitor's cookie expires a month later.
 *   2. A split of 0 or 100 is legal and means exactly what it says. Ramping a variant from 5% to
 *      100% is how a careful person ships, and refusing the endpoints would push them to delete
 *      the experiment instead — losing the record of what was measured.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Experiment;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Exception\ValidationFailed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An A/B test between a control document and a variant document.
 */
final class Experiment {

	/** The name recorded for visitors who saw the original. */
	const CONTROL = 'control';

	/** @var DocumentKey The document being tested against — what visitors see today. */
	private $control;

	/** @var DocumentKey The challenger. */
	private $variant;

	/** @var int 0-100: the percentage of visitors sent to the VARIANT. */
	private $split;

	/** @var bool */
	private $running;

	/** @var int|null UTC timestamp the test started, null if it never has. */
	private $started_at;

	/**
	 * @param DocumentKey $control    Control document.
	 * @param DocumentKey $variant    Variant document.
	 * @param int         $split      Percentage to the variant.
	 * @param bool        $running    Is it live.
	 * @param int|null    $started_at Start time.
	 * @throws ValidationFailed When the two documents are the same.
	 */
	public function __construct( DocumentKey $control, DocumentKey $variant, $split, $running = false, $started_at = null ) {
		if ( $control->value() === $variant->value() ) {
			// Not a pedantic check: a self-test would report two sets of numbers for one page and
			// read as a result.
			throw new ValidationFailed( __( 'An experiment needs two different layouts.', 'yazan' ) );
		}

		$this->control    = $control;
		$this->variant    = $variant;
		$this->split      = max( 0, min( 100, (int) $split ) );
		$this->running    = (bool) $running;
		$this->started_at = $started_at ? (int) $started_at : null;
	}

	/**
	 * Rebuild from storage.
	 *
	 * @param array $row Stored shape.
	 * @return self|null Null when the row does not describe a usable experiment.
	 */
	public static function from_array( array $row ) {
		if ( empty( $row['control'] ) || empty( $row['variant'] ) ) {
			return null;
		}

		try {
			return new self(
				DocumentKey::from( (string) $row['control'] ),
				DocumentKey::from( (string) $row['variant'] ),
				isset( $row['split'] ) ? $row['split'] : 50,
				! empty( $row['running'] ),
				isset( $row['started_at'] ) ? $row['started_at'] : null
			);
		} catch ( \Throwable $e ) {
			// A stored experiment pointing at a deleted layout must not take the homepage down.
			return null;
		}
	}

	/** @return array */
	public function to_array() {
		return array(
			'control'    => $this->control->value(),
			'variant'    => $this->variant->value(),
			'split'      => $this->split,
			'running'    => $this->running,
			'started_at' => $this->started_at,
		);
	}

	/** @return DocumentKey */
	public function control() {
		return $this->control;
	}

	/** @return DocumentKey */
	public function variant() {
		return $this->variant;
	}

	/** @return int */
	public function split() {
		return $this->split;
	}

	/** @return bool */
	public function is_running() {
		return $this->running;
	}

	/** @return int|null */
	public function started_at() {
		return $this->started_at;
	}

	/**
	 * Start it. Recording the moment, because a result without a window is a number without a
	 * denominator.
	 *
	 * @param int $now Timestamp.
	 * @return self
	 */
	public function start( $now ) {
		$copy             = clone $this;
		$copy->running    = true;
		$copy->started_at = $this->started_at ? $this->started_at : (int) $now;

		return $copy;
	}

	/** @return self */
	public function stop() {
		$copy          = clone $this;
		$copy->running = false;

		return $copy;
	}

	/**
	 * @param int $split New percentage to the variant.
	 * @return self
	 */
	public function with_split( $split ) {
		$copy        = clone $this;
		$copy->split = max( 0, min( 100, (int) $split ) );

		return $copy;
	}

	/**
	 * Which arm a fresh visitor belongs to.
	 *
	 * @param int $roll 0-99, from the caller.
	 * @return string CONTROL, or the variant document key.
	 */
	public function assign( $roll ) {
		if ( ! $this->running ) {
			return self::CONTROL;
		}

		$roll = (int) $roll;

		if ( $roll < 0 || $roll > 99 ) {
			// A roll outside the range would silently bias the split. Refuse to guess.
			return self::CONTROL;
		}

		return $roll < $this->split ? $this->variant->value() : self::CONTROL;
	}

	/**
	 * Is a remembered assignment still valid?
	 *
	 * A returning visitor keeps their arm — that is the whole basis of a comparison — but only
	 * while it still names one of THIS experiment's two documents. Change the variant and the old
	 * cookie is meaningless, so it is re-rolled rather than honoured.
	 *
	 * @param string $remembered Value from the visitor's cookie.
	 * @return bool
	 */
	public function recognises( $remembered ) {
		$remembered = (string) $remembered;

		return self::CONTROL === $remembered || $this->variant->value() === $remembered;
	}

	/**
	 * The document key a recorded arm should render.
	 *
	 * @param string $arm CONTROL or the variant key.
	 * @return DocumentKey
	 */
	public function document_for( $arm ) {
		return self::CONTROL === (string) $arm ? $this->control : $this->variant;
	}
}
