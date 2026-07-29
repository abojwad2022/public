<?php
/**
 * Yazan AI — House Knowledge base.
 *
 * An owner-editable store of curated facts (agate types, silver, symbolism, care, heritage) that the AI
 * may draw on. A capped, cached digest is injected into the ONE shared preamble filter
 * (`yazan_ai_prompt_system` in {@see Yazan_AI_Prompts::brand_voice()}), so it reaches the concierge AND
 * every content pipeline with no per-pipeline edits. Retrieval-honest: the guardrail line tells the model
 * never to contradict a product's real data nor invent beyond the knowledge given.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom post type + prompt injection for house knowledge.
 */
class Yazan_AI_Knowledge {

	/** Post type id. */
	const CPT = 'yazan_kb';

	/** Transient holding the assembled digest. */
	const CACHE = 'yazan_kb_brief';

	/** Max characters of knowledge injected into the preamble. */
	const MAX_CHARS = 1800;

	/** Per-entry excerpt cap. */
	const ENTRY_CHARS = 280;

	/**
	 * Register the CPT, the prompt-filter injection, cache invalidation, and a one-time seed.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
		add_filter( 'yazan_ai_prompt_system', array( __CLASS__, 'inject' ) );

		// Any edit to the knowledge invalidates the cached digest.
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Admin-only CPT ("House Knowledge"). No front-end pages — it exists only to feed the AI.
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'House Knowledge', 'yazan' ),
					'singular_name' => __( 'Knowledge entry', 'yazan' ),
					'add_new_item'  => __( 'Add knowledge entry', 'yazan' ),
					'edit_item'     => __( 'Edit knowledge entry', 'yazan' ),
					'search_items'  => __( 'Search knowledge', 'yazan' ),
					'menu_name'     => __( 'House Knowledge', 'yazan' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true, // enables the block editor
				'menu_position'       => 58,
				'menu_icon'           => 'dashicons-book-alt',
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'page-attributes' ),
				'capability_type'     => 'post',
			)
		);
	}

	/**
	 * Append the knowledge digest to the shared AI preamble.
	 *
	 * @param string $voice The brand-voice preamble.
	 * @return string
	 */
	public static function inject( $voice ) {
		return (string) $voice . self::brief();
	}

	/**
	 * A compact, cached digest of the published knowledge, under a hard character budget.
	 *
	 * @return string Leading double-newline so it appends cleanly; '' when there is no knowledge.
	 */
	public static function brief() {
		$cached = get_transient( self::CACHE );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'no_found_rows'  => true,
			)
		);

		$lines = array();
		$used  = 0;
		foreach ( $query->posts as $p ) {
			$title = trim( wp_strip_all_tags( $p->post_title ) );
			$body  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $p->post_content ) ) );
			if ( '' === $title && '' === $body ) {
				continue;
			}
			$entry = '- ' . $title . ': ' . mb_substr( $body, 0, self::ENTRY_CHARS );
			$len   = mb_strlen( $entry ) + 1;
			if ( $used + $len > self::MAX_CHARS ) {
				break;
			}
			$lines[] = $entry;
			$used   += $len;
		}
		wp_reset_postdata();

		$brief = empty( $lines )
			? ''
			: "\n\nHOUSE KNOWLEDGE (accurate background you may draw on; never contradict a product's real data, and never invent specifics beyond this):\n" . implode( "\n", $lines );

		set_transient( self::CACHE, $brief, HOUR_IN_SECONDS );
		return $brief;
	}

	/**
	 * Drop the cached digest (on any knowledge edit/delete).
	 */
	public static function flush() {
		delete_transient( self::CACHE );
	}

	/**
	 * Seed a few accurate starter entries once, so the base is useful out of the box. The owner edits or
	 * deletes them freely. Guarded by an option so it never re-runs or fights the owner's curation.
	 */
	public static function maybe_seed() {
		if ( get_option( 'yazan_kb_seeded' ) ) {
			return;
		}
		update_option( 'yazan_kb_seeded', 1, false );

		$entries = array(
			array(
				'title'   => 'Yemeni Agate (Aqeeq / عقيق يمني)',
				'content' => 'Aqeeq is a natural agate — a banded variety of chalcedony (microcrystalline quartz). Yemeni agate has been treasured for centuries across the Arabian Peninsula for its depth of colour and its place in heritage and adornment. Each stone is natural, so its banding, tone and character are unique — no two are alike. Colours range from deep liver-red and carnelian to honey, black, blue and green.',
			),
			array(
				'title'   => 'Sterling Silver 925',
				'content' => 'Our rings are set in sterling silver, an alloy that is 92.5% pure silver (hence the "925" hallmark) with the balance added for strength. It takes fine detail and a lasting finish. Oxidised silver is intentionally darkened in the recesses to bring out relief work and engraving. Silver can naturally patina over time; gentle cleaning restores its lustre.',
			),
			array(
				'title'   => 'Ring Care',
				'content' => 'To keep a piece at its best: avoid contact with perfume, lotions, chlorine and household chemicals; remove the ring before swimming or heavy work; wipe it with a soft, dry cloth after wear; and store it dry, away from direct sunlight and other jewellery that could scratch it. Agate is durable but, like any natural stone, benefits from gentle handling. Rings can usually be resized by a jeweller.',
			),
		);

		$order = 1;
		foreach ( $entries as $e ) {
			wp_insert_post(
				array(
					'post_type'    => self::CPT,
					'post_status'  => 'publish',
					'post_title'   => $e['title'],
					'post_content' => $e['content'],
					'menu_order'   => $order++,
				)
			);
		}
		self::flush();
	}
}
