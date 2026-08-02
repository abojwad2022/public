<?php
/**
 * Loads section CSS only for sections that are actually on the page.
 *
 * A homepage with no video block must not download the video block's styles. The collector is told
 * which components survived the render plan, and enqueues from that — so the cost of adding twenty
 * more section types to the registry is zero for every page that uses none of them.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Component\ComponentDefinition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional asset loading.
 */
final class AssetCollector {

	/** Handle for the shared stylesheet of plugin-rendered sections. */
	const SECTIONS_HANDLE = 'yazan-homepage-sections';

	/** Handle for the one script any of these sections needs (the sale countdown). */
	const SCRIPT_HANDLE = 'yazan-homepage-sections-js';

	/** Handle for the builder-only preview agent. Never loaded for a visitor. */
	const PREVIEW_HANDLE = 'yazan-homepage-preview';

	/** Components whose section needs the countdown script. Nothing else ships JavaScript. */
	const NEEDS_SCRIPT = array( 'flash-sale', 'hero-slider', 'product-carousel' );

	/** @var bool */
	private static $needs_sections_css = false;

	/** @var bool */
	private static $needs_script = false;

	/** @var bool */
	private static $needs_design = false;

	/**
	 * Note that a component will render.
	 *
	 * @param ComponentDefinition $definition Definition.
	 * @return void
	 */
	public static function collect( ComponentDefinition $definition ) {
		if ( 'plugin_template' !== $definition->renderer() ) {
			return;
		}

		self::$needs_sections_css = true;

		if ( in_array( $definition->type(), self::NEEDS_SCRIPT, true ) ) {
			self::$needs_script = true;
		}
	}

	/**
	 * A section carries its own design, so the shared stylesheet and the motion script are needed
	 * even if every section on the page is theme-rendered.
	 *
	 * @return void
	 */
	public static function require_design() {
		self::$needs_sections_css = true;
		self::$needs_script       = true;
		self::$needs_design       = true;
	}

	/**
	 * Enqueue whatever the collected components need.
	 *
	 * Called after the plan is built and before the templates run, which on `template_redirect` is
	 * still early enough for wp_enqueue_style to land in <head>.
	 *
	 * @param string $inline_css Compiled per-section rules.
	 * @return void
	 */
	public static function enqueue( $inline_css = '' ) {
		if ( ! self::$needs_sections_css ) {
			return;
		}

		$css = YAZAN_CORE_DIR . 'assets/homepage/sections.css';

		wp_enqueue_style(
			self::SECTIONS_HANDLE,
			YAZAN_CORE_URL . 'assets/homepage/sections.css',
			array(),
			is_readable( $css ) ? (string) filemtime( $css ) : YAZAN_CORE_VERSION
		);

		if ( '' !== $inline_css ) {
			// Attached to the stylesheet rather than printed loose, so it inherits its position in
			// the head and cannot land before the rules it overrides.
			wp_add_inline_style( self::SECTIONS_HANDLE, $inline_css );
		}

		if ( ! self::$needs_script ) {
			return;
		}

		$js = YAZAN_CORE_DIR . 'assets/homepage/sections.js';

		// Deferred and dependency-free. One countdown is not worth blocking the parser, and it is
		// the only JavaScript any of these sections ships.
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			YAZAN_CORE_URL . 'assets/homepage/sections.js',
			array(),
			is_readable( $js ) ? (string) filemtime( $js ) : YAZAN_CORE_VERSION,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}

	/**
	 * The preview agent: the only script that exists purely for the builder.
	 *
	 * Enqueued for a preview request and NOWHERE else, and never a dependency of anything a
	 * visitor loads. It listens for patched section HTML from the builder window and swaps it in,
	 * which is what turns "reload the whole homepage after every keystroke burst" into "replace
	 * the paragraph that changed".
	 *
	 * @return void
	 */
	public static function enqueue_preview_agent() {
		$file = YAZAN_CORE_DIR . 'assets/homepage/preview.js';

		if ( ! is_readable( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::PREVIEW_HANDLE,
			YAZAN_CORE_URL . 'assets/homepage/preview.js',
			array(),
			(string) filemtime( $file ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}

	/**
	 * Reset — tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$needs_sections_css = false;
		self::$needs_script       = false;
		self::$needs_design       = false;
	}
}
