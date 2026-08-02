<?php
/**
 * Renders sections the theme has no template for.
 *
 * The nine original bands are hand-built in astra-child and stay there. These are the ones that
 * never existed — video, statistics, FAQ — so the plugin has to draw them.
 *
 * They are drawn with the THEME's own classes (`yz-home-section`, `yz-container`, `yz-label`,
 * `yz-reveal`, `yz-btn-ghost`, `yz-section--ink`) and its CSS custom properties. That is what makes
 * a section added today inherit the Obsidian/Maroon switcher, the GSAP reveal, the RTL rules and
 * the type scale for free — instead of arriving as a stranger with its own opinions about colour.
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
 * Plugin-side section rendering.
 */
final class PluginTemplateRenderer {

	/**
	 * Render one section, or return false if this component is the theme's job.
	 *
	 * @param Section             $section    Section.
	 * @param ComponentDefinition $definition Definition.
	 * @return bool True when this class produced the output.
	 */
	public static function render( Section $section, ComponentDefinition $definition ) {
		if ( 'plugin_template' !== $definition->renderer() ) {
			return false;
		}

		$file = YAZAN_CORE_DIR . 'includes/homepage/Presentation/Templates/' . $definition->type() . '.php';

		if ( ! is_readable( $file ) ) {
			return false;
		}

		/*
		 * The template reads these three. Every value it prints is escaped THERE, at the point of
		 * output — never here, where it would be escaped for the wrong context.
		 *
		 * $schedule is exposed because the sale countdown counts down to the section's own end
		 * time rather than to a second date of its own. One date means the countdown, the
		 * server-side hiding and the page-cache purge can never disagree with each other.
		 */
		$content  = $section->content();
		$design   = $section->design();
		$schedule = $section->schedule()->to_array();

		/*
		 * Buffered so we can tell whether the template actually drew anything. Several of them
		 * bail early — an FAQ with no questions, a sale with nothing discounted — and a section
		 * that printed nothing must not be described in the page's structured data.
		 */
		ob_start();
		include $file;
		$html = (string) ob_get_clean();

		if ( '' === trim( $html ) ) {
			return true;
		}

		StructuredData::collect( $section, $definition );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes at output.

		return true;
	}
}
