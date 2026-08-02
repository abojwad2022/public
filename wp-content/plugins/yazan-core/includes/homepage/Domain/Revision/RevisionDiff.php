<?php
/**
 * What changed between two versions of the homepage.
 *
 * Compares by section ID, not by position: a section that merely moved is reported as MOVED, not
 * as one deletion plus one addition. Anything else makes the diff useless the first time someone
 * drags a block.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Domain\Revision;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section-level and field-level comparison.
 */
final class RevisionDiff {

	/**
	 * @param array $before Sections payload.
	 * @param array $after  Sections payload.
	 * @return array{added:array,removed:array,changed:array,moved:array,total:int}
	 */
	public static function between( array $before, array $after ) {
		$old = self::index( $before );
		$new = self::index( $after );

		$added   = array();
		$removed = array();
		$changed = array();
		$moved   = array();

		foreach ( $new as $id => $section ) {
			if ( ! isset( $old[ $id ] ) ) {
				$added[] = array(
					'id'   => $id,
					'type' => $section['type'],
				);
				continue;
			}

			$fields = self::changed_paths(
				isset( $old[ $id ]['content'] ) ? (array) $old[ $id ]['content'] : array(),
				isset( $section['content'] ) ? (array) $section['content'] : array()
			);

			$state_changed = ( $old[ $id ]['state'] ?? '' ) !== ( $section['state'] ?? '' );

			if ( $fields || $state_changed ) {
				$changed[] = array(
					'id'     => $id,
					'type'   => $section['type'],
					'fields' => $fields,
					'state'  => $state_changed ? ( $section['state'] ?? '' ) : null,
				);
			}

			if ( $old[ $id ]['position'] !== $section['position'] ) {
				$moved[] = array(
					'id'   => $id,
					'type' => $section['type'],
					'from' => $old[ $id ]['position'],
					'to'   => $section['position'],
				);
			}
		}

		foreach ( $old as $id => $section ) {
			if ( ! isset( $new[ $id ] ) ) {
				$removed[] = array(
					'id'   => $id,
					'type' => $section['type'],
				);
			}
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'moved'   => $moved,
			'total'   => count( $added ) + count( $removed ) + count( $changed ) + count( $moved ),
		);
	}

	/**
	 * Sections keyed by id, carrying their position.
	 *
	 * @param array $sections Sections.
	 * @return array<string,array>
	 */
	private static function index( array $sections ) {
		$out = array();

		foreach ( array_values( $sections ) as $position => $section ) {
			$section              = (array) $section;
			$id                   = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$section['position']  = $position;
			$section['type']      = isset( $section['type'] ) ? (string) $section['type'] : '';
			$out[ $id ]           = $section;
		}

		return $out;
	}

	/**
	 * Dotted paths whose value differs, walked recursively.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param string $prefix Path prefix.
	 * @return string[]
	 */
	private static function changed_paths( array $before, array $after, $prefix = '' ) {
		$paths = array();
		$keys  = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		foreach ( $keys as $key ) {
			$path = $prefix ? $prefix . '.' . $key : (string) $key;

			$old = $before[ $key ] ?? null;
			$new = $after[ $key ] ?? null;

			if ( is_array( $old ) && is_array( $new ) ) {
				$paths = array_merge( $paths, self::changed_paths( $old, $new, $path ) );
				continue;
			}

			// Compared as JSON so 0 and '0' and false do not read as three different values.
			if ( wp_json_encode( $old ) !== wp_json_encode( $new ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}
}
