<?php
/**
 * JSON-LD for the sections that earn it.
 *
 * Two rules, and the first one is the only one that matters:
 *
 *   1. Schema is collected at RENDER time, from sections that actually produced output. A band
 *      that bailed — an FAQ with no questions, a video whose file was deleted — contributes
 *      nothing. Describing content that is not on the page is not an SEO tactic, it is a
 *      misrepresentation, and Google treats it as one.
 *
 *   2. A component supplies its own builder. Nothing here switches on component type, so a section
 *      added later can describe itself without this file changing.
 *
 * Rank Math already emits the site's own graph; these are additional, self-contained nodes and do
 * not touch it.
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
 * Collects and prints structured data.
 */
final class StructuredData {

	/** @var array<int,array> */
	private static $nodes = array();

	/**
	 * Ask a component to describe a section that just rendered.
	 *
	 * @param Section             $section    Section.
	 * @param ComponentDefinition $definition Definition.
	 * @return void
	 */
	public static function collect( Section $section, ComponentDefinition $definition ) {
		$builder = $definition->structured_data();

		if ( ! $builder || ! is_callable( $builder ) ) {
			return;
		}

		$node = call_user_func( $builder, $section->content() );

		if ( is_array( $node ) && ! empty( $node['@type'] ) ) {
			self::$nodes[] = $node;
		}
	}

	/**
	 * Print whatever was collected. Hooked to wp_footer.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::$nodes ) {
			return;
		}

		$graph = array();

		foreach ( self::$nodes as $node ) {
			$node['@context'] = 'https://schema.org';
			$graph[]          = $node;
		}

		$json = wp_json_encode( 1 === count( $graph ) ? $graph[0] : $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			// wp_json_encode already produced valid JSON; the only remaining risk in a <script>
			// block is a literal "</script>" inside a string, which this closes off.
			str_replace( '<', '<', $json ) // phpcs:ignore WordPress.Security.EscapeOutput
		);

		self::$nodes = array();
	}

	/**
	 * Reset — tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$nodes = array();
	}

	/**
	 * What has been collected — tests and debugging.
	 *
	 * @return array<int,array>
	 */
	public static function nodes() {
		return self::$nodes;
	}
}
