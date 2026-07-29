<?php
/**
 * Yazan AI — Google Gemini provider.
 *
 * Speaks the Generative Language API `:generateContent`: a `contents` array of role+parts, an optional
 * `systemInstruction`, and `generationConfig`. Images travel as `inline_data` (base64 + mime). Strong
 * native image understanding and low cost — a natural fit for the product-photo analysis pipeline. The
 * API key is passed as a query parameter, not a header.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini adapter.
 */
class Yazan_AI_Provider_Gemini implements Yazan_AI_Provider, Yazan_AI_Image_Provider {

	/** @return string */
	public function id() {
		return 'gemini';
	}

	/** @return string */
	public function label() {
		return 'Google Gemini';
	}

	/**
	 * Generate images from the source photo via generateContent on an image model (e.g.
	 * gemini-2.5-flash-image). Gemini returns one image per call, so we loop to reach `count`.
	 *
	 * @param array  $request { prompt, image, count }.
	 * @param string $model   Image model id.
	 * @param string $api_key Key.
	 * @return array<int,array{mime:string,b64:string}>
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function generate_image( array $request, $model, $api_key ) {
		$src = Yazan_AI_Image::to_base64( (string) ( $request['image'] ?? '' ) );
		if ( ! $src ) {
			throw new Yazan_AI_Exception( 'bad_request', 'No usable source image for Gemini image generation.', 0, false );
		}
		$count  = max( 1, min( 8, (int) ( $request['count'] ?? 1 ) ) );
		$prompt = (string) ( $request['prompt'] ?? '' );
		$url    = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

		$out       = array();
		$last_error = null;
		for ( $i = 0; $i < $count; $i++ ) {
			$body = array(
				'contents'         => array(
					array(
						'role'  => 'user',
						'parts' => array(
							array( 'text' => $prompt ),
							array( 'inline_data' => array( 'mime_type' => $src['mime'], 'data' => $src['data'] ) ),
						),
					),
				),
				'generationConfig' => array( 'responseModalities' => array( 'IMAGE' ) ),
			);

			try {
				$decoded = Yazan_AI_Http::post_json( $url, $body, array() );
			} catch ( Yazan_AI_Exception $e ) {
				$last_error = $e;
				break; // Stop on first hard error; return whatever we already have.
			}

			$parts = $decoded['candidates'][0]['content']['parts'] ?? array();
			foreach ( (array) $parts as $part ) {
				$inline = $part['inlineData'] ?? ( $part['inline_data'] ?? null );
				if ( is_array( $inline ) && ! empty( $inline['data'] ) ) {
					$out[] = array( 'mime' => (string) ( $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png' ), 'b64' => (string) $inline['data'] );
				}
			}
		}

		if ( empty( $out ) ) {
			throw $last_error ?: new Yazan_AI_Exception( 'bad_response', 'Gemini returned no image data.', 0, true );
		}
		return $out;
	}

	/**
	 * The 1.5 family (and newer) are all multimodal.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public function supports_vision( $model ) {
		return true;
	}

	/**
	 * Run a completion via generateContent.
	 *
	 * @param array  $request Normalized request.
	 * @param string $model   Model id.
	 * @param string $api_key Key.
	 * @return array{text:string,tokens_in:int,tokens_out:int}
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function complete( array $request, $model, $api_key ) {
		$contents = array();

		foreach ( (array) $request['messages'] as $msg ) {
			// Gemini roles: 'user' and 'model' (its name for the assistant).
			$role   = ( 'assistant' === ( $msg['role'] ?? '' ) ) ? 'model' : 'user';
			$text   = (string) ( $msg['content'] ?? '' );
			$images = isset( $msg['images'] ) ? (array) $msg['images'] : array();

			$parts = array();
			if ( '' !== $text ) {
				$parts[] = array( 'text' => $text );
			}
			foreach ( $images as $image ) {
				$inline = Yazan_AI_Image::to_base64( $image );
				if ( $inline ) {
					$parts[] = array(
						'inline_data' => array(
							'mime_type' => $inline['mime'],
							'data'      => $inline['data'],
						),
					);
				}
			}
			if ( empty( $parts ) ) {
				$parts[] = array( 'text' => '' );
			}

			$contents[] = array( 'role' => $role, 'parts' => $parts );
		}

		$generation = array(
			'temperature'     => (float) ( $request['temperature'] ?? 0.6 ),
			'maxOutputTokens' => (int) ( $request['max_tokens'] ?? 1200 ),
		);
		if ( ! empty( $request['json'] ) ) {
			$generation['responseMimeType'] = 'application/json';
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => $generation,
		);
		if ( ! empty( $request['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
		}

		// Key rides in the query string; keep it out of logs by not echoing the URL anywhere.
		$url = add_query_arg(
			'key',
			rawurlencode( $api_key ),
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent'
		);

		$decoded = Yazan_AI_Http::post_json( $url, $body, array() );

		$text = '';
		if ( isset( $decoded['candidates'][0]['content']['parts'] ) && is_array( $decoded['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		if ( '' === $text && ! isset( $decoded['candidates'][0] ) ) {
			// A blocked prompt returns promptFeedback instead of candidates.
			$reason = $decoded['promptFeedback']['blockReason'] ?? 'no_candidates';
			throw new Yazan_AI_Exception( 'bad_response', 'Gemini returned no content: ' . sanitize_text_field( $reason ), 0, false );
		}

		return array(
			'text'       => $text,
			'tokens_in'  => (int) ( $decoded['usageMetadata']['promptTokenCount'] ?? 0 ),
			'tokens_out' => (int) ( $decoded['usageMetadata']['candidatesTokenCount'] ?? 0 ),
		);
	}
}
