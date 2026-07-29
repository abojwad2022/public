<?php
/**
 * Yazan AI — OpenRouter provider (default).
 *
 * One key, many models. OpenRouter proxies the OpenAI schema, so it reuses the base and only adds the
 * recommended attribution headers. This is YAZAN's default provider per the project decision.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenRouter adapter.
 */
class Yazan_AI_Provider_OpenRouter extends Yazan_AI_Provider_OpenAI {

	/** @return string */
	public function id() {
		return 'openrouter';
	}

	/** @return string */
	public function label() {
		return 'OpenRouter';
	}

	/** @return string */
	protected function base_url() {
		return 'https://openrouter.ai/api/v1';
	}

	/**
	 * OpenRouter asks callers to identify themselves for its dashboards.
	 *
	 * @return array<string,string>
	 */
	protected function extra_headers() {
		return array(
			'HTTP-Referer' => home_url( '/' ),
			'X-Title'      => 'YAZAN',
		);
	}
}
