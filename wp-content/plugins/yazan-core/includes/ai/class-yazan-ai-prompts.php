<?php
/**
 * Yazan AI — prompt manager.
 *
 * The brand voice lives here, not scattered across pipelines. Every generation is prefixed with the
 * YAZAN system preamble so descriptions, SEO, marketing, and (later) chat all sound like one house.
 * Prompts are versioned (the version is logged with each generation) and filterable via
 * `yazan_ai_prompt_system` / `yazan_ai_prompt_task`, so copy can be tuned without touching pipelines.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand voice + per-task system prompts.
 */
class Yazan_AI_Prompts {

	/** Bump when the wording below changes materially (shows in the generation log). */
	const VERSION = '1';

	/**
	 * The house voice. Shared by every task.
	 *
	 * @return string
	 */
	public static function brand_voice() {
		$voice = "You are the in-house copywriter and gemologist for YAZAN, a luxury jewelry house "
			. "specializing in Yemeni agate (aqeeq / عقيق يمني) rings and sterling silver. "
			. "Voice: premium, heritage-driven, quietly confident, never salesy or exaggerated. "
			. "You honor craftsmanship, provenance, and the one-of-one nature of each stone. "
			. "Avoid hype words (best, amazing, cheap), emojis, and invented facts. "
			. "Never fabricate gemological claims, prices, stock, certificates, or serial numbers — "
			. "work only from what you are given. When you describe a stone you can see in an image, "
			. "describe what is visibly true (color, banding, cut, setting) and stay measured.";

		return (string) apply_filters( 'yazan_ai_prompt_system', $voice );
	}

	/**
	 * Language directive for the requested output language.
	 *
	 * @param string $lang 'both' | 'en' | 'ar'.
	 * @return string
	 */
	public static function language_directive( $lang ) {
		switch ( $lang ) {
			case 'ar':
				return 'Write all customer-facing copy in Modern Standard Arabic (فصحى راقية) suitable for a luxury brand.';
			case 'en':
				return 'Write all customer-facing copy in polished English.';
			case 'both':
			default:
				return 'Provide every customer-facing text field in BOTH English (the `en` key) and Arabic (the `ar` key). '
					. 'The Arabic must be elegant Modern Standard Arabic, not a literal translation.';
		}
	}

	/**
	 * Full system prompt for a task = brand voice + language directive + task-specific guidance.
	 *
	 * @param string $task Task id.
	 * @param string $lang Output language.
	 * @return string
	 */
	public static function system( $task, $lang ) {
		$parts = array( self::brand_voice(), self::language_directive( $lang ) );

		$task_systems = array(
			'product.generate'   => 'Task: from a product photo (and any hints), produce a complete, on-brand ring listing.',
			'seo.generate'       => 'Task: produce search-optimized metadata that stays elegant and never keyword-stuffs.',
			'marketing.generate' => 'Task: produce short marketing copy for social, email, and ads — refined, not loud.',
			'analytics.insights' => 'Task: read store metrics and give the owner clear, specific, actionable observations. '
				. 'Be concrete and cite the numbers you were given; do not invent data.',
			'image.analyze'      => 'Task: analyze the jewelry in the image and classify its attributes objectively.',
		);

		if ( isset( $task_systems[ $task ] ) ) {
			$parts[] = $task_systems[ $task ];
		}

		// Owner-authored overrides from Provider Settings. `voice` applies to everything; the per-task
		// instruction (e.g. how the description should read) applies to its pipeline only.
		$custom = (array) Yazan_AI_Settings::get( 'prompts', array() );
		if ( ! empty( $custom['voice'] ) ) {
			$parts[] = 'Additional brand guidance from the store owner (follow it): ' . $custom['voice'];
		}
		$task_key = array(
			'product.generate'   => 'product',
			'seo.generate'       => 'seo',
			'marketing.generate' => 'marketing',
		);
		if ( isset( $task_key[ $task ] ) && ! empty( $custom[ $task_key[ $task ] ] ) ) {
			$parts[] = 'Owner instructions for this content (follow it): ' . $custom[ $task_key[ $task ] ];
		}

		$system = implode( "\n\n", array_filter( $parts ) );

		return (string) apply_filters( 'yazan_ai_prompt_task', $system, $task, $lang );
	}
}
