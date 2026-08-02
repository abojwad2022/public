<?php
/**
 * What actually changed between two versions of a draft, in words.
 *
 * The audit table has one row per operation and the builder saves the WHOLE draft at once, so
 * without this the only honest thing a save could report was "sections: 3" — a count tells you
 * something happened and nothing about what. The specification asked for the old value and the new
 * value; this is where they come from.
 *
 * Deliberately a list of short STRINGS rather than a nested structure: the Activity log renders a
 * flat list of key/value pairs, and an audit row that renders as "[object Object]" is worse than no
 * audit row at all. Values are truncated and the list is capped, because an audit trail that
 * doubles the size of the table on every autosave gets purged, and a purged trail proves nothing.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diffs two arrays of serialised sections.
 */
final class SectionDiff {

	/** Longest single value kept in an audit row. */
	const MAX_VALUE = 120;

	/** Most lines recorded for one save. */
	const MAX_LINES = 40;

	/**
	 * Describe the change from one draft to another.
	 *
	 * @param array $before Sections as `SectionCollection::to_array()` returns them.
	 * @param array $after  Sections after the change.
	 * @return string[] Human-readable lines, already truncated and capped.
	 */
	public static function describe( array $before, array $after ) {
		$old   = self::by_id( $before );
		$new   = self::by_id( $after );
		$lines = array();

		foreach ( $new as $id => $section ) {
			if ( ! isset( $old[ $id ] ) ) {
				$lines[] = 'added ' . self::name( $section );
				continue;
			}

			foreach ( self::field_changes( $old[ $id ], $section ) as $line ) {
				$lines[] = self::name( $section ) . ' ' . $line;
			}
		}

		foreach ( $old as $id => $section ) {
			if ( ! isset( $new[ $id ] ) ) {
				$lines[] = 'removed ' . self::name( $section );
			}
		}

		// Order is a change in its own right, and one nobody thinks to look for after the fact.
		$common_before = array_values( array_intersect( array_keys( $old ), array_keys( $new ) ) );
		$common_after  = array_values( array_intersect( array_keys( $new ), array_keys( $old ) ) );

		if ( $common_before !== $common_after ) {
			$lines[] = 'reordered';
		}

		return self::cap( $lines );
	}

	/**
	 * Index a section list by id.
	 *
	 * @param array $sections Serialised sections.
	 * @return array
	 */
	private static function by_id( array $sections ) {
		$out = array();

		foreach ( $sections as $section ) {
			if ( is_array( $section ) && ! empty( $section['id'] ) ) {
				$out[ (string) $section['id'] ] = $section;
			}
		}

		return $out;
	}

	/**
	 * A short, recognisable name: the type plus the first segment of the uuid, because two
	 * "Questions" sections on one page are otherwise indistinguishable in the log.
	 *
	 * @param array $section Serialised section.
	 * @return string
	 */
	private static function name( array $section ) {
		$type = isset( $section['type'] ) ? (string) $section['type'] : 'section';
		$id   = isset( $section['id'] ) ? (string) $section['id'] : '';
		$head = $id ? substr( $id, 0, 8 ) : '';

		return $head ? $type . '#' . $head : $type;
	}

	/**
	 * Field-by-field comparison of two versions of the same section.
	 *
	 * @param array $before Before.
	 * @param array $after  After.
	 * @return string[]
	 */
	private static function field_changes( array $before, array $after ) {
		$flat_before = self::flatten( $before );
		$flat_after  = self::flatten( $after );
		$paths       = array_unique( array_merge( array_keys( $flat_before ), array_keys( $flat_after ) ) );
		$lines       = array();

		foreach ( $paths as $path ) {
			if ( 'id' === $path || 'type' === $path ) {
				continue;
			}

			$old = isset( $flat_before[ $path ] ) ? $flat_before[ $path ] : '';
			$new = isset( $flat_after[ $path ] ) ? $flat_after[ $path ] : '';

			if ( $old === $new ) {
				continue;
			}

			$lines[] = $path . ': ' . self::quote( $old ) . ' → ' . self::quote( $new );
		}

		return $lines;
	}

	/**
	 * Turn a nested array into `path.to.field => scalar`.
	 *
	 * @param mixed  $value  Value.
	 * @param string $prefix Path so far.
	 * @return array
	 */
	private static function flatten( $value, $prefix = '' ) {
		if ( ! is_array( $value ) ) {
			return array( $prefix => self::scalar( $value ) );
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$out  = array_merge( $out, self::flatten( $item, $path ) );
		}

		// An emptied array would otherwise vanish from the diff entirely — the one change most
		// worth recording, because it is the one that makes a section stop rendering.
		if ( ! $out && '' !== $prefix ) {
			$out[ $prefix ] = '';
		}

		return $out;
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

		if ( '' === $value ) {
			return '(empty)';
		}

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) > self::MAX_VALUE : strlen( $value ) > self::MAX_VALUE ) {
			$value = ( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_VALUE ) : substr( $value, 0, self::MAX_VALUE ) ) . '…';
		}

		return '“' . $value . '”';
	}

	/**
	 * @param string[] $lines Lines.
	 * @return string[]
	 */
	private static function cap( array $lines ) {
		if ( count( $lines ) <= self::MAX_LINES ) {
			return $lines;
		}

		$kept      = array_slice( $lines, 0, self::MAX_LINES );
		$remaining = count( $lines ) - self::MAX_LINES;

		// Say so rather than truncating in silence: a log that hides its own limit is a log that
		// gets trusted for something it never recorded.
		$kept[] = '+' . $remaining . ' more change(s) not recorded';

		return $kept;
	}
}
