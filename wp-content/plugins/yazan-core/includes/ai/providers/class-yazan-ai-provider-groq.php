<?php
/**
 * Yazan AI — Groq provider.
 *
 * Groq exposes an OpenAI-compatible endpoint and is prized for very low latency — a good fit for the
 * future customer chat. Only its multimodal Llama models accept images, so vision support is gated on
 * the model id.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groq adapter.
 */
class Yazan_AI_Provider_Groq extends Yazan_AI_Provider_OpenAI {

	/** @return string */
	public function id() {
		return 'groq';
	}

	/** @return string */
	public function label() {
		return 'Groq';
	}

	/** @return string */
	protected function base_url() {
		return 'https://api.groq.com/openai/v1';
	}

	/**
	 * Groq's multimodal models: the Llama 4 family (Scout/Maverick) and the older *-vision-preview ids.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public function supports_vision( $model ) {
		$model = strtolower( (string) $model );
		foreach ( array( 'vision', 'llama-4', 'scout', 'maverick' ) as $needle ) {
			if ( false !== strpos( $model, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
