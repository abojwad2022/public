<?php
/**
 * Yazan AI — OpenAI-compatible provider base.
 *
 * OpenRouter, OpenAI, and Groq all speak the same `/chat/completions` schema, so they share this
 * base and only declare their id/label/endpoint/auth. Vision is expressed as OpenAI content parts
 * (`{type:'image_url'}`). Subclasses override {@see self::supports_vision()} when their model list
 * differs.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared implementation for OpenAI-schema providers.
 */
abstract class Yazan_AI_Provider_OpenAI implements Yazan_AI_Provider {

	/**
	 * Base URL up to (but not including) `/chat/completions`.
	 *
	 * @return string
	 */
	abstract protected function base_url();

	/**
	 * Authorization header value builder.
	 *
	 * @param string $api_key Key.
	 * @return array<string,string>
	 */
	protected function auth_headers( $api_key ) {
		return array( 'Authorization' => 'Bearer ' . $api_key );
	}

	/**
	 * Extra headers a specific vendor wants (OpenRouter likes a referer/title).
	 *
	 * @return array<string,string>
	 */
	protected function extra_headers() {
		return array();
	}

	/**
	 * Most OpenAI-schema models used here accept images; providers override when not.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public function supports_vision( $model ) {
		return true;
	}

	/**
	 * Run a completion via `/chat/completions`.
	 *
	 * @param array  $request Normalized request.
	 * @param string $model   Model id.
	 * @param string $api_key Key.
	 * @return array{text:string,tokens_in:int,tokens_out:int}
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function complete( array $request, $model, $api_key ) {
		$messages = array();

		if ( ! empty( $request['system'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => (string) $request['system'] );
		}

		foreach ( (array) $request['messages'] as $msg ) {
			$role    = ( 'assistant' === ( $msg['role'] ?? '' ) ) ? 'assistant' : 'user';
			$content = (string) ( $msg['content'] ?? '' );
			$images  = isset( $msg['images'] ) ? (array) $msg['images'] : array();

			if ( empty( $images ) ) {
				$messages[] = array( 'role' => $role, 'content' => $content );
				continue;
			}

			// Multimodal message: text part + one image_url part per image.
			$parts = array( array( 'type' => 'text', 'text' => $content ) );
			foreach ( $images as $image ) {
				$parts[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => (string) $image ) );
			}
			$messages[] = array( 'role' => $role, 'content' => $parts );
		}

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => (float) ( $request['temperature'] ?? 0.6 ),
			'max_tokens'  => (int) ( $request['max_tokens'] ?? 1200 ),
		);
		if ( ! empty( $request['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$headers = array_merge( $this->auth_headers( $api_key ), $this->extra_headers() );
		$decoded = Yazan_AI_Http::post_json( trailingslashit( $this->base_url() ) . 'chat/completions', $body, $headers );

		$choice = $decoded['choices'][0] ?? null;
		$text   = $choice['message']['content'] ?? '';
		// Empty content (no choice, or a content-filtered / truncated null) is a failure — throw it
		// retryable so the router fails over to the next provider instead of returning a blank success.
		if ( '' === trim( (string) $text ) ) {
			$reason = is_array( $choice ) && isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : 'empty';
			throw new Yazan_AI_Exception( 'empty_response', 'Provider returned empty content (' . sanitize_text_field( $reason ) . ').', 0, true );
		}

		return array(
			'text'       => (string) $text,
			'tokens_in'  => (int) ( $decoded['usage']['prompt_tokens'] ?? 0 ),
			'tokens_out' => (int) ( $decoded['usage']['completion_tokens'] ?? 0 ),
		);
	}
}
