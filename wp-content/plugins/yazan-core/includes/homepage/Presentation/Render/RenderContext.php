<?php
/**
 * The section currently being rendered.
 *
 * The theme's content helpers (`yazan_home_text()`, `yazan_home_image()`) read GLOBAL keys —
 * `hero_line_1`, not "this instance's line 1". That is fine while each section appears once, and
 * breaks the moment two Collections blocks exist. This stack is what fixes it: the bridge pushes
 * the section about to render, every bound filter answers from it, and the pop restores the
 * previous one. A nested render therefore cannot leak values into its parent.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Section\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render-time section stack.
 */
final class RenderContext {

	/** @var array<int,array{section:Section,definition:ComponentDefinition}> */
	private static $stack = array();

	/**
	 * @param Section             $section    Section.
	 * @param ComponentDefinition $definition Definition.
	 * @return void
	 */
	public static function push( Section $section, ComponentDefinition $definition ) {
		self::$stack[] = array(
			'section'    => $section,
			'definition' => $definition,
		);
	}

	/** @return void */
	public static function pop() {
		array_pop( self::$stack );
	}

	/** @return Section|null */
	public static function section() {
		$top = end( self::$stack );
		return $top ? $top['section'] : null;
	}

	/** @return ComponentDefinition|null */
	public static function definition() {
		$top = end( self::$stack );
		return $top ? $top['definition'] : null;
	}

	/** @return bool */
	public static function is_active() {
		return ! empty( self::$stack );
	}

	/**
	 * Read a dotted path out of the current section's content.
	 *
	 * @param string $path    e.g. `stories.0.title`.
	 * @param mixed  $default Fallback when absent or empty.
	 * @return mixed
	 */
	public static function value( $path, $default = null ) {
		$section = self::section();

		if ( ! $section ) {
			return $default;
		}

		$value = $section->content();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}
			return $default;
		}

		// A responsive field stores three breakpoints; the theme wants one value.
		if ( is_array( $value ) && array_key_exists( 'desktop', $value ) ) {
			$value = $value['desktop'];
		}

		return $value;
	}

	/**
	 * Reset — tests and error recovery only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$stack = array();
	}
}
