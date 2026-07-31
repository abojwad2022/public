<?php
/**
 * Yazan AI — Moonshot AI (Kimi) provider.
 *
 * Moonshot exposes an OpenAI-compatible chat-completions endpoint, so it reuses the shared base adapter.
 * The Kimi models are strong bilingual (Arabic/English) writers with long context — a good fit for the
 * concierge and long-form product copy. Only the `-vision-` model ids accept images; Kimi does not
 * generate images, so its `image` slot stays empty.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moonshot (Kimi) adapter.
 */
class Yazan_AI_Provider_Moonshot extends Yazan_AI_Provider_OpenAI {

	/** @return string */
	public function id() {
		return 'moonshot';
	}

	/** @return string */
	public function label() {
		return 'Moonshot (Kimi)';
	}

	/** @return string */
	protected function base_url() {
		return 'https://api.moonshot.ai/v1';
	}

	/**
	 * Only Kimi's vision model ids accept images.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public function supports_vision( $model ) {
		return false !== strpos( strtolower( (string) $model ), 'vision' );
	}
}
