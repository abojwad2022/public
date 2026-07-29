<?php
/**
 * Yazan AI — model map & cost table.
 *
 * Two responsibilities:
 *  1. The DEFAULT model id per provider per capability (text vs vision). These are just defaults —
 *     the owner overrides any of them in Provider Settings, so they never hard-code a choice.
 *  2. A small price table (USD per 1M tokens) used only to estimate the cost logged per call. Unknown
 *     models simply log 0 cost rather than guessing.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model defaults and cost estimation.
 */
class Yazan_AI_Models {

	/**
	 * Task groups the owner can assign a specific model to (per-task model selection). `product` is the
	 * vision task; the rest are text. A generation whose task maps to none of these uses the plain
	 * capability default.
	 */
	const TASKS = array( 'chat', 'product', 'seo', 'marketing', 'analytics' );

	/**
	 * Map a runtime task string (e.g. 'chat.decide', 'product.generate') to its task group, or '' when
	 * the task should never be overridden (ping / generic).
	 *
	 * @param string $task Task string from the gateway opts.
	 * @return string One of self::TASKS, or ''.
	 */
	public static function task_group( $task ) {
		$task = (string) $task;
		if ( 0 === strpos( $task, 'chat.' ) ) {
			return 'chat';
		}
		if ( 0 === strpos( $task, 'product.' ) ) {
			return 'product';
		}
		if ( 0 === strpos( $task, 'seo.' ) ) {
			return 'seo';
		}
		if ( 0 === strpos( $task, 'marketing.' ) ) {
			return 'marketing';
		}
		if ( 0 === strpos( $task, 'analytics.' ) ) {
			return 'analytics';
		}
		return '';
	}

	/**
	 * Default model ids. `text` handles description/SEO/marketing/chat; `vision` must accept images.
	 * Editable per install via {@see Yazan_AI_Settings}.
	 *
	 * @return array<string,array{text:string,vision:string}>
	 */
	public static function default_map() {
		return array(
			// OpenRouter ids are namespaced `vendor/model`; both defaults accept images. No image
			// GENERATION (the chat proxy can't render images), so its `image` slot stays empty.
			'openrouter' => array(
				'text'   => 'openai/gpt-4o-mini',
				'vision' => 'openai/gpt-4o-mini',
				'image'  => '',
			),
			'openai'     => array(
				'text'   => 'gpt-4o-mini',
				'vision' => 'gpt-4o-mini',
				'image'  => 'gpt-image-1',
			),
			'claude'     => array(
				'text'   => 'claude-3-5-haiku-20241022',
				'vision' => 'claude-3-5-sonnet-20241022',
				'image'  => '', // Claude does not generate images.
			),
			'gemini'     => array(
				// Use the rolling alias: concrete ids (1.5/2.5) get retired or gated to existing users,
				// while `gemini-flash-latest` always points at the current stable multimodal flash.
				'text'   => 'gemini-flash-latest',
				'vision' => 'gemini-flash-latest',
				'image'  => 'gemini-2.5-flash-image',
			),
			'groq'       => array(
				// Text: fast versatile Llama. Vision: Llama 4 Scout is Groq's current multimodal model
				// (the older llama-3.2-*-vision-preview ids were retired).
				'text'   => 'llama-3.3-70b-versatile',
				'vision' => 'meta-llama/llama-4-scout-17b-16e-instruct',
				'image'  => '', // Groq does not generate images.
			),
		);
	}

	/**
	 * Providers capable of image GENERATION (a non-empty `image` model + an adapter implementing
	 * {@see Yazan_AI_Image_Provider}). Purely capability metadata — key presence is checked elsewhere.
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function supports_image( $provider ) {
		return '' !== self::resolve( $provider, 'image' );
	}

	/**
	 * Resolve the model id for a provider from current settings.
	 *
	 * Precedence: per-task override → capability default (text/vision) → built-in default. An empty
	 * task group (or no override for it) falls straight through to the capability default, so behavior
	 * is unchanged when no per-task models are configured.
	 *
	 * @param string $provider   Provider id.
	 * @param string $capability 'text' | 'vision'.
	 * @param string $task_group Optional task group (see {@see self::task_group()}).
	 * @return string
	 */
	public static function resolve( $provider, $capability = 'text', $task_group = '' ) {
		$capability = in_array( $capability, array( 'vision', 'image' ), true ) ? $capability : 'text';

		// 1) Per-task override.
		if ( '' !== $task_group ) {
			$task_models = Yazan_AI_Settings::get( 'task_models', array() );
			if ( isset( $task_models[ $task_group ][ $provider ] ) && '' !== $task_models[ $task_group ][ $provider ] ) {
				return (string) $task_models[ $task_group ][ $provider ];
			}
		}

		// 2) Capability default.
		$models = Yazan_AI_Settings::get( 'models', self::default_map() );
		if ( isset( $models[ $provider ][ $capability ] ) && '' !== $models[ $provider ][ $capability ] ) {
			return (string) $models[ $provider ][ $capability ];
		}

		// 3) Built-in default.
		$defaults = self::default_map();
		return $defaults[ $provider ][ $capability ] ?? '';
	}

	/**
	 * Price table: model id (or a matching prefix) => [ input_per_million, output_per_million ] USD.
	 * Deliberately small; used for estimates only.
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	private static function prices() {
		return array(
			'gpt-4o-mini'        => array( 0.15, 0.60 ),
			'gpt-4o'             => array( 2.50, 10.00 ),
			'claude-3-5-haiku'   => array( 0.80, 4.00 ),
			'claude-3-5-sonnet'  => array( 3.00, 15.00 ),
			'gemini-flash-lite'  => array( 0.10, 0.40 ),
			'gemini-flash'       => array( 0.30, 2.50 ),
			'gemini-2.5-flash'   => array( 0.30, 2.50 ),
			'gemini-2.5-pro'     => array( 1.25, 10.00 ),
			'gemini-2.0-flash'   => array( 0.10, 0.40 ),
			'gemini-1.5-flash'   => array( 0.075, 0.30 ),
			'gemini-1.5-pro'     => array( 1.25, 5.00 ),
			'llama-4'            => array( 0.11, 0.34 ),
			'llama-3.3-70b'      => array( 0.59, 0.79 ),
			'llama-3.2-11b'      => array( 0.18, 0.18 ),
		);
	}

	/**
	 * Estimate USD cost for a call. Matches the model id against the table by longest prefix; the
	 * `vendor/` namespace used by OpenRouter is stripped first so `openai/gpt-4o-mini` still matches.
	 *
	 * @param string $model      Concrete model id.
	 * @param int    $tokens_in  Prompt tokens.
	 * @param int    $tokens_out Completion tokens.
	 * @return float
	 */
	public static function estimate_cost( $model, $tokens_in, $tokens_out ) {
		$needle = strtolower( (string) $model );
		if ( false !== strpos( $needle, '/' ) ) {
			$needle = substr( $needle, strpos( $needle, '/' ) + 1 );
		}

		$best = null;
		$best_len = 0;
		foreach ( self::prices() as $prefix => $rate ) {
			if ( 0 === strpos( $needle, $prefix ) && strlen( $prefix ) > $best_len ) {
				$best     = $rate;
				$best_len = strlen( $prefix );
			}
		}
		if ( null === $best ) {
			return 0.0;
		}

		return round(
			( (int) $tokens_in / 1000000 ) * $best[0] + ( (int) $tokens_out / 1000000 ) * $best[1],
			6
		);
	}
}
