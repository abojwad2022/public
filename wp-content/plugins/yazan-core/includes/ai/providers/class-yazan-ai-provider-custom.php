<?php
/**
 * Yazan AI — custom (bring-your-own) OpenAI-compatible provider.
 *
 * Lets the owner plug in ANY other model or service that speaks the OpenAI chat-completions API —
 * DeepSeek, Mistral, Together, Fireworks, a self-hosted vLLM/Ollama gateway, a new vendor, etc. The
 * endpoint base URL and display label are configured in AI Settings (`custom_provider` in
 * `yazan_ai_settings`); the model ids come from the normal per-provider model fields, and the key from
 * the normal secret store (`custom`). Nothing is hard-coded, so a new model needs no code change.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owner-configured OpenAI-compatible adapter.
 */
class Yazan_AI_Provider_Custom extends Yazan_AI_Provider_OpenAI {

	/** @return string */
	public function id() {
		return 'custom';
	}

	/**
	 * The owner's label (falls back to a generic one).
	 *
	 * @return string
	 */
	public function label() {
		$cfg   = (array) Yazan_AI_Settings::get( 'custom_provider', array() );
		$label = isset( $cfg['label'] ) ? trim( (string) $cfg['label'] ) : '';
		return '' !== $label ? $label : __( 'Custom (OpenAI-compatible)', 'yazan' );
	}

	/**
	 * The owner's endpoint base (e.g. https://api.deepseek.com/v1). Empty until configured, in which
	 * case the shared base adapter simply cannot reach anything and the provider is skipped.
	 *
	 * @return string
	 */
	protected function base_url() {
		$cfg = (array) Yazan_AI_Settings::get( 'custom_provider', array() );
		return isset( $cfg['base_url'] ) ? untrailingslashit( (string) $cfg['base_url'] ) : '';
	}

	/**
	 * The owner declares whether their model accepts images (we cannot infer it from an unknown id).
	 *
	 * @param string $model Model id (unused — capability is a per-endpoint setting).
	 * @return bool
	 */
	public function supports_vision( $model ) {
		$cfg = (array) Yazan_AI_Settings::get( 'custom_provider', array() );
		return ! empty( $cfg['vision'] );
	}
}
