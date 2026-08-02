<?php
/**
 * Renders a bound WordPress page from its own document.
 *
 * A landing page is the same builder, the same components and the same renderer — pointed at a
 * page instead of the front page. Nothing about the module needed rewriting for it, which was the
 * point of never assuming the document key was 'default'.
 *
 * STRICTLY OPT-IN. A page is only affected if someone deliberately bound a layout to it, and even
 * then the takeover is skipped when that layout has nothing published — so a half-built landing
 * page shows its ordinary content rather than a blank screen.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page takeover.
 */
final class PageRenderer {

	/** @var bool */
	private static $active = false;

	/**
	 * Decide whether this request is a bound page, and prepare it if so.
	 *
	 * @return bool True when this page will be rendered from a document.
	 */
	public static function maybe_take_over() {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return false;
		}

		$page_id = (int) get_queried_object_id();

		if ( ! $page_id ) {
			return false;
		}

		$key = ServiceFactory::documents()->key_for_page( $page_id );

		if ( ! $key ) {
			return false;
		}

		ThemeBridge::register( DocumentKey::from( $key ) );

		if ( ! ThemeBridge::has_plan() ) {
			// Bound but empty: leave the page exactly as it was. A blank page is a worse answer
			// than the page someone already had.
			return false;
		}

		self::$active = true;

		// Highest possible priority, deliberately. This site alone has three other callbacks on
		// `template_include` (one at 90) — header/footer builders and SEO plugins hand back their
		// own file for every singular page. A page the operator deliberately bound to a layout
		// outranks a generic template swap, so this one goes last and wins.
		add_filter( 'template_include', array( __CLASS__, 'template' ), PHP_INT_MAX );

		return true;
	}

	/**
	 * Swap in the module's page template.
	 *
	 * @param string $template Theme's chosen template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( ! self::$active ) {
			return $template;
		}

		return YAZAN_CORE_DIR . 'includes/homepage/Presentation/Templates/page-document.php';
	}
}
