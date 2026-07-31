<?php
/**
 * Yazan AI — provider router.
 *
 * Knows how to instantiate each provider adapter and how to build the ORDERED chain the gateway will
 * try: the requested (or default) provider first, then the configured fallback list, de-duplicated,
 * and filtered to providers that (a) have a usable key and (b) can actually handle the capability
 * (a text-only model is skipped for a vision task). The gateway walks this chain until one succeeds.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider registry + fallback-chain builder.
 */
class Yazan_AI_Router {

	/**
	 * Instantiate a provider adapter by id.
	 *
	 * @param string $id Provider id.
	 * @return Yazan_AI_Provider|null
	 */
	public static function provider( $id ) {
		switch ( $id ) {
			case 'openrouter':
				return new Yazan_AI_Provider_OpenRouter();
			case 'openai':
				return new Yazan_AI_Provider_OpenAI_Direct();
			case 'claude':
				return new Yazan_AI_Provider_Claude();
			case 'gemini':
				return new Yazan_AI_Provider_Gemini();
			case 'groq':
				return new Yazan_AI_Provider_Groq();
			case 'moonshot':
				return new Yazan_AI_Provider_Moonshot();
			case 'custom':
				return new Yazan_AI_Provider_Custom();
			default:
				return null;
		}
	}

	/**
	 * Build the ordered fallback chain of image-GENERATION providers: the configured default first, then
	 * the fallback list, then any remaining known provider — de-duplicated and filtered to those that can
	 * actually generate images ({@see Yazan_AI_Image_Provider}) AND have a usable key. The gallery pipeline
	 * walks this until one succeeds, so a rate-limited/over-budget provider transparently yields to the next.
	 *
	 * Only OpenAI (gpt-image-1) and Gemini implement the image interface today; text-only providers
	 * (OpenRouter/Claude/Groq) are skipped automatically.
	 *
	 * @return array<int,array{provider:string,model:string}> Possibly empty (→ honest validation message).
	 */
	public static function image_chain() {
		$config = Yazan_AI_Settings::all();
		$order  = array_values( array_unique( array_merge( array( $config['default_provider'] ), (array) $config['fallback'], Yazan_AI_Settings::PROVIDERS ) ) );

		$chain = array();
		foreach ( $order as $id ) {
			$adapter = self::provider( $id );
			if ( $adapter instanceof Yazan_AI_Image_Provider && Yazan_AI_Secrets::has( $id ) && Yazan_AI_Models::supports_image( $id ) ) {
				$chain[] = array( 'provider' => $id, 'model' => Yazan_AI_Models::resolve( $id, 'image' ) );
			}
		}
		return $chain;
	}

	/**
	 * The single best image-GENERATION provider: the first entry of {@see self::image_chain()}, or null
	 * when none is configured. Kept for callers that only need "is an image provider available?".
	 *
	 * @return array{provider:string,model:string}|null
	 */
	public static function image_provider() {
		$chain = self::image_chain();
		return $chain ? $chain[0] : null;
	}

	/**
	 * Build the ordered list of provider ids to attempt for a capability.
	 *
	 * @param string      $capability 'text' | 'vision'.
	 * @param string|null $preferred  Force this provider first (per-request override).
	 * @param bool        $exclusive  Test ONLY $preferred — no default/fallback fall-through. Used by
	 *                                the "Test connection" diagnostics so a broken provider can't be
	 *                                masked by a healthy fallback (and vice-versa).
	 * @param string      $task_group Optional task group for per-task model selection (see
	 *                                {@see Yazan_AI_Models::resolve()}).
	 * @return array<int,array{provider:string,model:string}> Each entry is ready to attempt.
	 */
	public static function chain( $capability = 'text', $preferred = null, $exclusive = false, $task_group = '' ) {
		$config = Yazan_AI_Settings::all();

		$order        = array();
		$has_preferred = $preferred && in_array( $preferred, Yazan_AI_Settings::PROVIDERS, true );
		if ( $has_preferred ) {
			$order[] = $preferred;
		}
		// Skip the default + fallback list entirely when exclusively testing one provider.
		if ( ! ( $exclusive && $has_preferred ) ) {
			$order[] = $config['default_provider'];
			foreach ( $config['fallback'] as $p ) {
				$order[] = $p;
			}
		}
		$order = array_values( array_unique( $order ) );

		$chain = array();
		foreach ( $order as $id ) {
			$adapter = self::provider( $id );
			if ( ! $adapter ) {
				continue;
			}
			if ( ! Yazan_AI_Secrets::has( $id ) ) {
				continue; // No key — cannot use this provider.
			}
			$model = Yazan_AI_Models::resolve( $id, $capability, $task_group );
			if ( '' === $model ) {
				continue;
			}
			if ( 'vision' === $capability && ! $adapter->supports_vision( $model ) ) {
				continue; // Selected model can't see images.
			}
			$chain[] = array( 'provider' => $id, 'model' => $model );
		}

		return $chain;
	}
}
