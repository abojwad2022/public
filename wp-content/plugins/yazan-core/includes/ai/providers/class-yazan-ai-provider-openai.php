<?php
/**
 * Yazan AI — OpenAI provider.
 *
 * Direct OpenAI `/chat/completions` for text/vision (via the shared base), plus image generation
 * (image-to-image) through `/v1/images/edits` with gpt-image-1.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI adapter.
 */
class Yazan_AI_Provider_OpenAI_Direct extends Yazan_AI_Provider_OpenAI implements Yazan_AI_Image_Provider {

	/** @return string */
	public function id() {
		return 'openai';
	}

	/** @return string */
	public function label() {
		return 'OpenAI';
	}

	/** @return string */
	protected function base_url() {
		return 'https://api.openai.com/v1';
	}

	/**
	 * Generate images from the source photo via `/v1/images/edits` (image-to-image).
	 *
	 * @param array  $request { prompt, image, count }.
	 * @param string $model   Image model (e.g. gpt-image-1).
	 * @param string $api_key Key.
	 * @return array<int,array{mime:string,b64:string}>
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function generate_image( array $request, $model, $api_key ) {
		$src = Yazan_AI_Image::to_base64( (string) ( $request['image'] ?? '' ) );
		if ( ! $src ) {
			throw new Yazan_AI_Exception( 'bad_request', 'No usable source image for OpenAI edit.', 0, false );
		}
		$count = max( 1, min( 8, (int) ( $request['count'] ?? 1 ) ) );

		$ext  = ( false !== strpos( $src['mime'], 'png' ) ) ? 'png' : ( ( false !== strpos( $src['mime'], 'webp' ) ) ? 'webp' : 'jpg' );
		$file = array(
			'field'    => 'image',
			'filename' => 'source.' . $ext,
			'mime'     => $src['mime'],
			'bytes'    => base64_decode( $src['data'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		);
		$fields = array(
			'model'  => $model,
			'prompt' => (string) ( $request['prompt'] ?? '' ),
			'n'      => (string) $count,
			'size'   => '1024x1024',
		);

		$decoded = Yazan_AI_Http::post_multipart( 'https://api.openai.com/v1/images/edits', $fields, $file, array( 'Authorization' => 'Bearer ' . $api_key ) );

		$out = array();
		foreach ( (array) ( $decoded['data'] ?? array() ) as $img ) {
			if ( ! empty( $img['b64_json'] ) ) {
				$out[] = array( 'mime' => 'image/png', 'b64' => (string) $img['b64_json'] );
			}
		}
		if ( empty( $out ) ) {
			throw new Yazan_AI_Exception( 'bad_response', 'OpenAI returned no image data.', 0, true );
		}
		return $out;
	}
}
