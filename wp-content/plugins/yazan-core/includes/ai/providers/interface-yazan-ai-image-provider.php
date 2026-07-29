<?php
/**
 * Yazan AI — image-generation contract.
 *
 * Implemented ONLY by providers that can actually render images (OpenAI gpt-image-1, Google Gemini
 * image). Text/vision-only providers (Claude, Groq, and the OpenRouter chat proxy) deliberately do not
 * implement it, so the router can detect the capability with a simple `instanceof` and return an honest
 * validation message when the configured provider can't generate.
 *
 * The normalized image request:
 *   [
 *     'prompt' => string,          // the luxury visual direction + product context
 *     'image'  => string,          // source product photo (data: URI or http URL) — image-to-image
 *     'count'  => int,             // how many images to return (1..8)
 *   ]
 *
 * Returns an array of generated images: [ [ 'mime' => 'image/png', 'b64' => '<base64>' ], ... ].
 * Throws {@see Yazan_AI_Exception} on any failure.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for providers that generate/edit images.
 */
interface Yazan_AI_Image_Provider {

	/**
	 * Generate images from a source photo + prompt (image-to-image).
	 *
	 * @param array  $request Normalized image request (see interface docblock).
	 * @param string $model   Concrete image model id.
	 * @param string $api_key Provider API key.
	 * @return array<int,array{mime:string,b64:string}>
	 * @throws Yazan_AI_Exception On failure.
	 */
	public function generate_image( array $request, $model, $api_key );
}
