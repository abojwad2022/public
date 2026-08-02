<?php
/**
 * The ordered list of sections in a document.
 *
 * Order is an array position, never a stored integer that can drift. Anything that reorders goes
 * through here, so "two sections claim position 3" is not a state this system can reach.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

use Yazan\Homepage\Domain\Exception\SectionNotFound;
use Yazan\Homepage\Domain\Section\Section;
use Yazan\Homepage\Domain\Section\SectionId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordered section list.
 */
final class SectionCollection {

	/** @var Section[] Zero-indexed, order IS the array order. */
	private $sections;

	/**
	 * @param Section[] $sections Sections.
	 */
	private function __construct( array $sections ) {
		$this->sections = array_values( $sections );
	}

	/** @return self */
	public static function empty_collection() {
		return new self( array() );
	}

	/**
	 * @param Section[] $sections Sections.
	 * @return self
	 */
	public static function of( array $sections ) {
		return new self( $sections );
	}

	/** @return Section[] */
	public function all() {
		return $this->sections;
	}

	/** @return int */
	public function count() {
		return count( $this->sections );
	}

	/**
	 * @param SectionId $id Section id.
	 * @return Section
	 * @throws SectionNotFound When absent.
	 */
	public function get( SectionId $id ) {
		foreach ( $this->sections as $section ) {
			if ( $section->id()->equals( $id ) ) {
				return $section;
			}
		}
		throw new SectionNotFound( 'Section ' . $id->value() . ' is not in this document.' );
	}

	/**
	 * @param SectionId $id Section id.
	 * @return bool
	 */
	public function has( SectionId $id ) {
		foreach ( $this->sections as $section ) {
			if ( $section->id()->equals( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many sections of a given component type exist.
	 *
	 * @param string $type Component type.
	 * @return int
	 */
	public function count_of_type( $type ) {
		$count = 0;
		foreach ( $this->sections as $section ) {
			if ( $section->type()->value() === $type ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @param Section  $section  Section.
	 * @param int|null $position Insert index; null appends.
	 * @return self
	 */
	public function add( Section $section, $position = null ) {
		$sections = $this->sections;

		if ( null === $position || $position >= count( $sections ) ) {
			$sections[] = $section;
		} else {
			array_splice( $sections, max( 0, (int) $position ), 0, array( $section ) );
		}

		return new self( $sections );
	}

	/**
	 * @param Section $section Replacement (matched by id).
	 * @return self
	 * @throws SectionNotFound When absent.
	 */
	public function replace( Section $section ) {
		$found    = false;
		$sections = array();

		foreach ( $this->sections as $existing ) {
			if ( $existing->id()->equals( $section->id() ) ) {
				$sections[] = $section;
				$found      = true;
			} else {
				$sections[] = $existing;
			}
		}

		if ( ! $found ) {
			throw new SectionNotFound( 'Section ' . $section->id()->value() . ' is not in this document.' );
		}

		return new self( $sections );
	}

	/**
	 * @param SectionId $id Section id.
	 * @return self
	 * @throws SectionNotFound When absent.
	 */
	public function remove( SectionId $id ) {
		$sections = array();
		$found    = false;

		foreach ( $this->sections as $section ) {
			if ( $section->id()->equals( $id ) ) {
				$found = true;
				continue;
			}
			$sections[] = $section;
		}

		if ( ! $found ) {
			throw new SectionNotFound( 'Section ' . $id->value() . ' is not in this document.' );
		}

		return new self( $sections );
	}

	/**
	 * Reorder to exactly the given id list.
	 *
	 * The list must be a permutation of what is here — a partial list would silently drop
	 * sections, which is the classic drag-and-drop data-loss bug.
	 *
	 * @param string[] $ordered_ids Section ids in the new order.
	 * @return self
	 */
	public function reorder( array $ordered_ids ) {
		$by_id = array();
		foreach ( $this->sections as $section ) {
			$by_id[ $section->id()->value() ] = $section;
		}

		$ordered_ids = array_values( array_unique( array_map( 'strval', $ordered_ids ) ) );

		if ( count( $ordered_ids ) !== count( $by_id ) ) {
			throw new \InvalidArgumentException( 'Reorder must list every section exactly once.' );
		}

		$sections = array();
		foreach ( $ordered_ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Reorder referenced an unknown section: ' . $id );
			}
			$sections[] = $by_id[ $id ];
		}

		return new self( $sections );
	}

	/**
	 * Position of a section, or null.
	 *
	 * @param SectionId $id Section id.
	 * @return int|null
	 */
	public function position_of( SectionId $id ) {
		foreach ( $this->sections as $index => $section ) {
			if ( $section->id()->equals( $id ) ) {
				return $index;
			}
		}
		return null;
	}

	/** @return array */
	public function to_array() {
		$out = array();
		foreach ( $this->sections as $section ) {
			$out[] = $section->to_array();
		}
		return $out;
	}
}
