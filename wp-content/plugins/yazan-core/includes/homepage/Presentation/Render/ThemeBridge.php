<?php
/**
 * The seam between the homepage document and the theme that already renders the homepage.
 *
 * `astra-child/inc/homepage.php` ends every content read with a filter:
 *
 *     return apply_filters( 'yazan_home_text_'  . $key, $value );
 *     return apply_filters( 'yazan_home_image_' . $key, $url   );
 *
 * so the document can supply the content WITHOUT touching a single line of the nine section
 * templates. Their markup, their GSAP hooks, their RTL handling and their design tokens all stay
 * exactly as they are — the safest possible way to put a builder in front of a design that was
 * made by hand.
 *
 * Three guarantees, in order of importance:
 *
 *   1. NO published document  → every filter passes its value straight through and the homepage is
 *      byte-for-byte what it is today.
 *   2. A field left empty     → the theme's own default wins. Emptying a field never blanks a band.
 *   3. Module disabled        → nothing is registered and (1) applies.
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Presentation\Render;

use Yazan\Homepage\Domain\Component\ComponentDefinition;
use Yazan\Homepage\Domain\Design\DesignCompiler;
use Yazan\Homepage\Domain\Document\DocumentKey;
use Yazan\Homepage\Domain\Port\MediaPort;
use Yazan\Homepage\Domain\Port\TermQueryPort;
use Yazan\Homepage\Infrastructure\Bootstrap\ServiceFactory;
use Yazan\Homepage\Infrastructure\Woo\ProductQueryAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds the published document onto the theme's content hooks.
 */
final class ThemeBridge {

	/**
	 * Runs after `inc/home-content.php`, which hooks the same array filters at 99.
	 *
	 * The editor's explicit choice has to beat the theme's automatic one, or saving a curated list
	 * of categories would appear to do nothing.
	 */
	const LATE_PRIORITY = 200;

	/** Cron hook that purges the page cache at a schedule boundary. */
	const BOUNDARY_HOOK = 'yazan_homepage_schedule_boundary';

	/** @var array<int,array{section:\Yazan\Homepage\Domain\Section\Section,definition:ComponentDefinition}> */
	private static $plan = array();

	/** @var bool */
	private static $filters_bound = false;

	/** @var DocumentKey|null The document being rendered on this request. */
	private static $key = null;

	/**
	 * @var bool Is this request the builder's preview?
	 *
	 * Only a preview prints the section markers the patcher needs. A public page must not carry
	 * comments naming internal ids — they are noise for every visitor and a small map of the
	 * document's structure for anyone reading source.
	 */
	private static $preview = false;

	/**
	 * Register the bridge. Cheap: nothing below runs off the front page.
	 *
	 * @return void
	 */
	public static function register( DocumentKey $key = null ) {
		$key = $key ? $key : DocumentKey::default_key();

		// A running experiment can send this visitor to a different document entirely. Resolved
		// FIRST, so everything below — the plan, the assets, the preview override — is about the
		// document this particular visitor is actually getting.
		self::$key = ExperimentRunner::resolve( $key );

		/*
		 * The plan is built HERE, on template_redirect, and not when the section list is asked
		 * for. `front-page.php` runs after wp_head, so a stylesheet decided at that point would
		 * never reach the document head — the section CSS would arrive after the sections.
		 */
		self::prepare();

		// Only now is it known whether the arm this visitor was sent to renders anything. An arm
		// that renders nothing is not an audience — see ExperimentRunner::commit().
		ExperimentRunner::commit( self::has_plan() );

		add_filter( 'yazan_home_sections', array( __CLASS__, 'sections' ), 10, 1 );
		add_filter( 'yazan_home_render_section', array( __CLASS__, 'render_section' ), 10, 3 );
		add_action( 'yazan_home_before_section', array( __CLASS__, 'before_section' ), 10, 2 );
		add_action( 'yazan_home_after_section', array( __CLASS__, 'after_section' ), 10, 2 );
		add_action( self::BOUNDARY_HOOK, array( __CLASS__, 'on_schedule_boundary' ) );
	}

	/**
	 * Replace the theme's hard-coded section list with the published document's.
	 *
	 * @param array $default The theme's own list.
	 * @return array
	 */
	public static function sections( $default ) {
		if ( ! self::$plan ) {
			// Nothing published — guarantee (1): the theme's own list, untouched.
			return (array) $default;
		}

		$slugs = array();

		foreach ( self::$plan as $entry ) {
			// 'template-parts/home/hero' → 'hero', which is what front-page.php appends.
			$slugs[] = self::slug_of( $entry );
		}

		return $slugs;
	}

	/**
	 * Resolve what will render, and enqueue exactly what it needs.
	 *
	 * @return void
	 */
	private static function prepare() {
		try {
			$pipeline = new RenderPipeline(
				ServiceFactory::documents(),
				ServiceFactory::components(),
				ServiceFactory::clock(),
				ServiceFactory::template_repository()
			);

			$plan = $pipeline->plan( self::$key, self::preview_override() );
		} catch ( \Throwable $e ) {
			// The homepage is the most visible page on the site. If anything in this module is
			// broken, the correct outcome is the theme's own homepage, not a fatal error.
			return;
		}

		if ( ! $plan ) {
			return;
		}

		self::$plan = array_values( $plan );
		self::bind_filters();
		self::book_boundary_purge( $pipeline );

		// Only now do we know what is on the page — the only honest moment to decide what the
		// visitor should pay for.
		$css = '';

		foreach ( self::$plan as $entry ) {
			AssetCollector::collect( $entry['definition'] );

			$design = $entry['section']->design();

			if ( $design ) {
				$rules = DesignCompiler::compile( $entry['section']->id()->value(), $design );

				if ( '' !== $rules ) {
					AssetCollector::require_design();
					$css .= $rules;
				}
			}
		}

		AssetCollector::enqueue( $css );

		if ( self::$preview ) {
			// Independent of the section assets above: a preview made entirely of theme-rendered
			// bands still needs the patcher, and enqueue() returns early for those.
			AssetCollector::enqueue_preview_agent();
		}
	}

	/**
	 * Render the DRAFT instead of the published document, for a preview request.
	 *
	 * The builder's iframe loads the real front page with `?yz_preview=1`. That means the preview
	 * is produced by the production renderer and the production templates — it cannot drift from
	 * what ships, which is the failure that makes people stop trusting a builder.
	 *
	 * Gated on `homepage.preview`, not on a token: the request carries the operator's own login
	 * cookie and the check is the same authorization service the REST layer uses. It is a read,
	 * so there is nothing for a forged GET to change. What it MUST NOT do is get cached — a
	 * cached preview would serve one editor's unpublished draft to every visitor.
	 *
	 * @return array|null Draft sections, or null when this is a normal page view.
	 */
	private static function preview_override() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, capability checked below.
		if ( empty( $_GET['yz_preview'] ) ) {
			return null;
		}

		if ( ! ServiceFactory::auth()->can( 'homepage.preview' ) ) {
			return null;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();

		self::$preview = true;

		$document = ServiceFactory::documents()->get( self::$key );

		// An empty draft returns an empty array, which sections() treats as "nothing published" —
		// so an untouched preview shows the theme's own homepage, exactly like the live page.
		return $document->sections()->to_array();
	}

	/**
	 * Draw a section the theme has no template for.
	 *
	 * `front-page.php` asks this filter first and only calls get_template_part() if the answer is
	 * false. That one line is what lets the module add sections the theme never had, without the
	 * theme needing to know they exist.
	 *
	 * @param bool   $handled Whether something already rendered it.
	 * @param string $slug    Section slug.
	 * @param int    $index   Loop index.
	 * @return bool
	 */
	public static function render_section( $handled, $slug, $index ) {
		if ( $handled ) {
			return $handled;
		}

		$entry = self::entry_for( $slug, (int) $index );

		if ( ! $entry ) {
			return false;
		}

		return PluginTemplateRenderer::render( $entry['section'], $entry['definition'] );
	}

	/**
	 * Render a handful of DRAFT sections on their own, for the preview patcher.
	 *
	 * Same plan, same filters, same templates as a whole page — only the loop is shorter. That is
	 * the point: a patch produced by a second, simpler renderer would drift from the page it is
	 * patching, and a preview that is subtly wrong is worse than one that reloads.
	 *
	 * The plan is filtered BEFORE rendering, so asking for one section costs one section's queries
	 * rather than the whole homepage's.
	 *
	 * @param DocumentKey $key Document.
	 * @param string[]    $ids Section ids to render; empty means all of them.
	 * @return array<string,string> Section id => HTML, markers included.
	 */
	public static function render_sections( DocumentKey $key, array $ids = array() ) {
		self::$key     = $key;
		self::$preview = true;

		try {
			$pipeline = new RenderPipeline(
				ServiceFactory::documents(),
				ServiceFactory::components(),
				ServiceFactory::clock(),
				ServiceFactory::template_repository()
			);

			$document = ServiceFactory::documents()->get( $key );
			$plan     = $pipeline->plan( $key, $document->sections()->to_array() );
		} catch ( \Throwable $e ) {
			return array();
		}

		self::$plan = array_values( (array) $plan );
		self::bind_filters();

		$wanted = array_flip( array_values( $ids ) );
		$out    = array();

		foreach ( self::$plan as $index => $entry ) {
			$id = $entry['section']->id()->value();

			if ( $ids && ! isset( $wanted[ $id ] ) ) {
				continue;
			}

			$slug = self::slug_of( $entry );

			ob_start();
			self::before_section( $slug, $index );

			if ( ! apply_filters( 'yazan_home_render_section', false, $slug, $index ) ) {
				get_template_part( 'template-parts/home/' . $slug );
			}

			self::after_section( $slug, $index );
			$out[ $id ] = (string) ob_get_clean();
		}

		return $out;
	}

	/**
	 * Did anything survive the plan? The page renderer only takes over a page when it did.
	 *
	 * @return bool
	 */
	public static function has_plan() {
		return ! empty( self::$plan );
	}

	/**
	 * Push the section about to render.
	 *
	 * Matched by index first; if another filter reordered the list behind us, fall back to the
	 * first unconsumed section of that slug rather than rendering someone else's content.
	 *
	 * @param string $slug  Section slug.
	 * @param int    $index Loop index.
	 * @return void
	 */
	public static function before_section( $slug, $index ) {
		$entry = self::entry_for( $slug, (int) $index );

		if ( ! $entry ) {
			return;
		}

		RenderContext::push( $entry['section'], $entry['definition'] );

		// Comment markers, not a wrapper element: the patcher needs to know where a section starts
		// and ends, and a real element would change the DOM the preview is supposed to be showing
		// faithfully. Comments are invisible to CSS and to layout.
		if ( self::$preview ) {
			echo "\n<!--yzhp:s:" . esc_html( $entry['section']->id()->value() ) . "-->\n";
		}

		$design = $entry['section']->design();

		if ( ! DesignCompiler::has_effect( $design ) ) {
			/*
			 * A section with no design of its own is NOT wrapped. That keeps the promise the whole
			 * module rests on: with nothing configured, the markup is byte-for-byte what the theme
			 * produces today — no stray div for a stylesheet rule to trip over.
			 *
			 * Asked of the compiler, not of the array: the editor saves the schema's defaults, so
			 * `$design` is never empty in practice and the old truthiness check wrapped every
			 * single section.
			 */
			return;
		}

		$wrapper = DesignCompiler::wrapper( $entry['section']->id()->value(), $design );

		printf(
			'<div class="%s"%s>',
			esc_attr( $wrapper['class'] ),
			$wrapper['attributes'] // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in DesignCompiler::wrapper().
		);
	}

	/**
	 * Pop the finished section.
	 *
	 * @param string $slug  Section slug.
	 * @param int    $index Loop index.
	 * @return void
	 */
	public static function after_section( $slug, $index ) {
		if ( ! RenderContext::is_active() ) {
			return;
		}

		$section = RenderContext::section();

		// Must mirror before_section exactly, or an unwrapped section closes a div it never opened.
		if ( $section && DesignCompiler::has_effect( $section->design() ) ) {
			echo '</div>';
		}

		// AFTER the wrapper closes, so the marked range contains the whole section including its
		// wrapper. A range that ends inside the div would leave an orphan </div> behind on patch.
		if ( self::$preview && $section ) {
			echo "\n<!--yzhp:e:" . esc_html( $section->id()->value() ) . "-->\n";
		}

		RenderContext::pop();
	}

	/**
	 * Locate the plan entry for a slug at an index.
	 *
	 * @param string $slug  Slug.
	 * @param int    $index Index.
	 * @return array|null
	 */
	private static function entry_for( $slug, $index ) {
		if ( isset( self::$plan[ $index ] ) && self::slug_of( self::$plan[ $index ] ) === $slug ) {
			return self::$plan[ $index ];
		}

		foreach ( self::$plan as $entry ) {
			if ( self::slug_of( $entry ) === $slug ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The slug a plan entry renders under.
	 *
	 * A theme-backed component uses its template part's basename; a plugin-rendered one uses its
	 * own type, because it has no template part to name it.
	 *
	 * @param array $entry Plan entry.
	 * @return string
	 */
	private static function slug_of( array $entry ) {
		$part = $entry['definition']->theme_part();

		return $part ? basename( $part ) : $entry['section']->type()->value();
	}

	/* ------------------------------------------------------------------ binding */

	/**
	 * Attach one filter per bound theme key, for every registered component.
	 *
	 * Done once, and only on a front page that has a published document — so a product page pays
	 * nothing for a homepage builder it never touches.
	 *
	 * @return void
	 */
	public static function bind_filters() {
		if ( self::$filters_bound ) {
			return;
		}

		self::$filters_bound = true;

		foreach ( ServiceFactory::components()->all() as $definition ) {
			$bindings = $definition->bindings();

			foreach ( $bindings['text'] as $key => $path ) {
				add_filter(
					'yazan_home_text_' . $key,
					static function ( $value ) use ( $path ) {
						return self::text_value( $path, $value );
					},
					self::LATE_PRIORITY
				);
			}

			foreach ( $bindings['image'] as $key => $path ) {
				add_filter(
					'yazan_home_image_' . $key,
					static function ( $value ) use ( $path ) {
						return self::image_value( $path, $value );
					},
					self::LATE_PRIORITY
				);
			}

			foreach ( $bindings['array'] as $filter => $binding ) {
				add_filter(
					$filter,
					static function ( $value ) use ( $binding ) {
						return self::array_value( $binding, $value );
					},
					self::LATE_PRIORITY
				);
			}
		}
	}

	/**
	 * Document value for a text key, or the theme's default.
	 *
	 * @param string $path    Dotted content path.
	 * @param mixed  $default Theme default.
	 * @return mixed
	 */
	private static function text_value( $path, $default ) {
		if ( ! RenderContext::is_active() ) {
			return $default;
		}

		$value = RenderContext::value( $path, null );

		// Guarantee (2): an empty field means "use the theme's copy", not "show nothing".
		if ( null === $value || '' === $value ) {
			return $default;
		}

		return $value;
	}

	/**
	 * Document value for an image key, resolved to a URL.
	 *
	 * @param string $path    Dotted content path.
	 * @param mixed  $default Theme default.
	 * @return mixed
	 */
	private static function image_value( $path, $default ) {
		if ( ! RenderContext::is_active() ) {
			return $default;
		}

		$attachment_id = (int) RenderContext::value( $path, 0 );

		if ( $attachment_id <= 0 ) {
			return $default;
		}

		/** @var MediaPort $media */
		$media = ServiceFactory::media();
		$url   = $media->url( $attachment_id, 'full' );

		return $url ? $url : $default;
	}

	/**
	 * Document value for a whole-array filter.
	 *
	 * @param array $binding path + shape.
	 * @param mixed $default Theme default.
	 * @return mixed
	 */
	private static function array_value( array $binding, $default ) {
		if ( ! RenderContext::is_active() ) {
			return $default;
		}

		$value = RenderContext::value( $binding['path'], null );

		if ( null === $value || array() === $value ) {
			return $default;
		}

		switch ( $binding['shape'] ) {
			case 'term_cards':
				return self::shape_term_cards( (array) $value, $default );

			case 'reviews':
				return self::shape_reviews( (array) $value, $default );

			case 'trust_items':
				return self::shape_trust_items( (array) $value, $default );

			case 'query_args':
				return self::shape_query_args( (array) $value, (array) $default );

			default:
				return $default;
		}
	}

	/**
	 * Term ids → the { name, url, image } cards both the swatch rail and the collection grid expect.
	 *
	 * @param int[] $term_ids Term ids.
	 * @param mixed $default  Theme default.
	 * @return array
	 */
	private static function shape_term_cards( array $term_ids, $default ) {
		/** @var TermQueryPort $terms */
		$terms = ServiceFactory::terms();
		$media = ServiceFactory::media();

		$rows = $terms->terms( 'product_cat', $term_ids, count( $term_ids ) );

		if ( ! $rows ) {
			return $default;
		}

		$cards = array();

		foreach ( $rows as $row ) {
			$cards[] = array(
				'name'  => $row['name'],
				'url'   => $row['url'],
				// Both templates take a URL string here, not an attachment id.
				'image' => $row['image'] ? $media->url( $row['image'], 'large' ) : '',
			);
		}

		return $cards;
	}

	/**
	 * Repeater rows → the { quote, name, city } shape reviews.php reads.
	 *
	 * @param array $rows    Rows.
	 * @param mixed $default Theme default.
	 * @return array
	 */
	private static function shape_reviews( array $rows, $default ) {
		$out = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['quote'] ) ) {
				continue;
			}

			$out[] = array(
				'quote' => (string) $row['quote'],
				'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'city'  => isset( $row['city'] ) ? (string) $row['city'] : '',
			);
		}

		return $out ? $out : $default;
	}

	/**
	 * Repeater rows → the { i, t, d } shape trust.php reads.
	 *
	 * @param array $rows    Rows.
	 * @param mixed $default Theme default.
	 * @return array
	 */
	private static function shape_trust_items( array $rows, $default ) {
		$out = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['title'] ) ) {
				continue;
			}

			$out[] = array(
				'i' => isset( $row['icon'] ) ? (string) $row['icon'] : 'gem',
				't' => (string) $row['title'],
				'd' => isset( $row['description'] ) ? (string) $row['description'] : '',
			);
		}

		return $out ? $out : $default;
	}

	/**
	 * Product source spec → WP_Query arguments, merged over whatever the theme built.
	 *
	 * @param array $spec    Query spec.
	 * @param array $default Theme args.
	 * @return array
	 */
	private static function shape_query_args( array $spec, array $default ) {
		if ( empty( $spec['source'] ) ) {
			return $default;
		}

		$adapter = new ProductQueryAdapter();

		return $adapter->query_args( $spec );
	}

	/* ---------------------------------------------------------------- scheduling */

	/**
	 * Book a cache purge for the next moment a scheduled section starts or stops.
	 *
	 * Without this the page cache decides when a scheduled band appears, which is not a schedule.
	 * One event, replaced whenever the boundary moves.
	 *
	 * @param RenderPipeline $pipeline Pipeline.
	 * @return void
	 */
	private static function book_boundary_purge( RenderPipeline $pipeline ) {
		$next = $pipeline->next_schedule_boundary( self::$key );

		if ( ! $next ) {
			return;
		}

		if ( wp_next_scheduled( self::BOUNDARY_HOOK ) === $next ) {
			return;
		}

		wp_clear_scheduled_hook( self::BOUNDARY_HOOK );
		wp_schedule_single_event( $next, self::BOUNDARY_HOOK );
	}

	/**
	 * A scheduled section just became due (or expired) — drop the caches.
	 *
	 * @return void
	 */
	public static function on_schedule_boundary() {
		ServiceFactory::cache()->flush();

		do_action( 'litespeed_purge_url', home_url( '/' ) );

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( (int) get_option( 'page_on_front' ) );
		}
	}
}
