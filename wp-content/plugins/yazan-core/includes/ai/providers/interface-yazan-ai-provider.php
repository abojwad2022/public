<?php
/**
 * Yazan AI — provider contract.
 *
 * Every LLM backend (OpenRouter, OpenAI, Claude, Gemini, Groq) implements this one interface, so the
 * router and gateway never know which vendor they are talking to. Adding a provider later is a new
 * class implementing this — nothing else in the system changes. This is the seam that also lets the
 * whole intelligence layer be lifted out of WordPress later without touching callers.
 *
 * The normalized request every provider receives:
 *   [
 *     'system'      => string,                       // system / brand-voice preamble ('' when none)
 *     'messages'    => [ [ 'role' => 'user'|'assistant', 'content' => string,
 *                          'images' => [ data-uri|url, ... ] ], ... ],
 *     'json'        => bool,                          // ask the model for a strict JSON object
 *     'temperature' => float,
 *     'max_tokens'  => int,
 *   ]
 *
 * The normalized success return:
 *   [ 'text' => string, 'tokens_in' => int, 'tokens_out' => int ]
 *
 * On any failure a provider throws {@see Yazan_AI_Exception} carrying a stable error code.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract implemented by every provider adapter.
 */
interface Yazan_AI_Provider {

	/**
	 * Stable provider id (matches the keys in {@see Yazan_AI_Secrets::providers()}).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable name for the UI.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether this provider/model can accept image inputs. Used by the router to skip a provider for
	 * vision tasks when its selected model is text-only.
	 *
	 * @param string $model Concrete model id.
	 * @return bool
	 */
	public function supports_vision( $model );

	/**
	 * Run one completion.
	 *
	 * @param array  $request Normalized request (see interface docblock).
	 * @param string $model   Concrete model id.
	 * @param string $api_key Provider API key (never empty — the router checks first).
	 * @return array{text:string,tokens_in:int,tokens_out:int}
	 * @throws Yazan_AI_Exception On transport, auth, rate-limit, or parse failure.
	 */
	public function complete( array $request, $model, $api_key );
}
