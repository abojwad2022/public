<?php
/**
 * Yazan AI — Claude (Anthropic) provider.
 *
 * Speaks the Anthropic Messages API (`/v1/messages`): a top-level `system` string, a `messages` array
 * whose content is an array of typed blocks, and `usage.input_tokens` / `output_tokens`. Images are
 * sent as base64 `image` blocks. Prized for the most fluent luxury storytelling and strong bilingual
 * (AR/EN) copy.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic adapter.
 */
class Yazan_AI_Provider_Claude implements Yazan_AI_Provider {

	/** Anthropic API version header. */
	const API_VERSION = '2023-06-01';

	/** @return string */
	public function id() {
		return 'claude';
	}

	/** @return string */
	public function label() {
		return 'Claude (Anthropic)';
	}

	/**
	 * All current Claude 3+ models accept images.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public function supports_vision( $model ) {
		return true;
	}

	/**
	 * Run a completion via the Messages API.
	 *
	 * @param array  $request Normalized request.
	 * @param string $model   Model id.
	 * @param string $api_key Key.
	 * @return array{text:string,tokens_in:int,tokens_out:int}
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function complete( array $request, $model, $api_key ) {
		$messages = array();

		foreach ( (array) $request['messages'] as $msg ) {
			$role   = ( 'assistant' === ( $msg['role'] ?? '' ) ) ? 'assistant' : 'user';
			$text   = (string) ( $msg['content'] ?? '' );
			$images = isset( $msg['images'] ) ? (array) $msg['images'] : array();

			$blocks = array();
			foreach ( $images as $image ) {
				$inline = Yazan_AI_Image::to_base64( $image );
				if ( $inline ) {
					$blocks[] = array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => $inline['mime'],
							'data'       => $inline['data'],
						),
					);
				}
			}
			$blocks[] = array( 'type' => 'text', 'text' => $text );

			$messages[] = array( 'role' => $role, 'content' => $blocks );
		}

		// Anthropic requires the JSON instruction in-prompt; a nudge on the first user turn is enough.
		$system = (string) ( $request['system'] ?? '' );
		if ( ! empty( $request['json'] ) ) {
			$system = trim( $system . "\nRespond with a single valid JSON object and nothing else." );
		}

		$body = array(
			'model'       => $model,
			'max_tokens'  => (int) ( $request['max_tokens'] ?? 1200 ),
			'temperature' => (float) ( $request['temperature'] ?? 0.6 ),
			'messages'    => $messages,
		);
		if ( '' !== $system ) {
			$body['system'] = $system;
		}

		$headers = array(
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
		);

		$decoded = Yazan_AI_Http::post_json( 'https://api.anthropic.com/v1/messages', $body, $headers );

		// content is an array of blocks; concatenate the text blocks.
		$text = '';
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			foreach ( $decoded['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}
		if ( '' === $text && ! isset( $decoded['content'] ) ) {
			throw new Yazan_AI_Exception( 'bad_response', 'No content in Claude response.', 0, true );
		}

		return array(
			'text'       => $text,
			'tokens_in'  => (int) ( $decoded['usage']['input_tokens'] ?? 0 ),
			'tokens_out' => (int) ( $decoded['usage']['output_tokens'] ?? 0 ),
		);
	}
}
