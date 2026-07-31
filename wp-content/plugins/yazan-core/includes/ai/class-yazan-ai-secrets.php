<?php
/**
 * Yazan AI — provider secret storage.
 *
 * The ONE place API keys live. There is no established secret convention in the plugin, so this
 * class defines one carefully:
 *
 *  - Keys are stored in a single autoloaded option (`yazan_ai_secrets`) as an associative map
 *    provider => key. They are written ONLY through {@see self::set()} and read ONLY server-side
 *    through {@see self::get()} — never serialized into any REST response.
 *  - A `wp-config.php` constant always wins over the stored value, so production can keep keys out of
 *    the database entirely (e.g. define( 'YAZAN_AI_OPENROUTER_KEY', '…' )).
 *  - The UI only ever learns whether a key is set and its last 4 characters ({@see self::status()}),
 *    never the key itself.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-only-from-the-UI store for LLM provider API keys.
 */
class Yazan_AI_Secrets {

	/**
	 * Option holding the provider => key map.
	 *
	 * Stored with autoload = NO so raw keys are not loaded into memory (nor into every `alloptions`
	 * cache dump) on unrelated front-end requests; they are read only on the AI code paths that call
	 * get(). Prefer wp-config.php constants in production so keys never touch the database.
	 */
	const OPTION = 'yazan_ai_secrets';

	/**
	 * Provider ids this store knows about, mapped to the wp-config.php constant that overrides them.
	 *
	 * @return array<string,string>
	 */
	public static function providers() {
		return array(
			'openrouter' => 'YAZAN_AI_OPENROUTER_KEY',
			'openai'     => 'YAZAN_AI_OPENAI_KEY',
			'claude'     => 'YAZAN_AI_CLAUDE_KEY',
			'gemini'     => 'YAZAN_AI_GEMINI_KEY',
			'groq'       => 'YAZAN_AI_GROQ_KEY',
			'moonshot'   => 'YAZAN_AI_MOONSHOT_KEY',
			// Bring-your-own OpenAI-compatible endpoint (any other model/service).
			'custom'     => 'YAZAN_AI_CUSTOM_KEY',
			// Not an LLM provider: the shared secret for the external AI Core service (Phase 3). Stored
			// write-only like a key, overridable by the YAZAN_AI_CORE_SECRET constant.
			'core'       => 'YAZAN_AI_CORE_SECRET',
		);
	}

	/**
	 * Resolve a provider's key: constant override first, then the stored value. Server-side only.
	 *
	 * @param string $provider Provider id.
	 * @return string Empty string when unset.
	 */
	public static function get( $provider ) {
		$provider  = sanitize_key( $provider );
		$constants = self::providers();

		if ( isset( $constants[ $provider ] ) && defined( $constants[ $provider ] ) ) {
			$value = constant( $constants[ $provider ] );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$store = get_option( self::OPTION, array() );
		return is_array( $store ) && ! empty( $store[ $provider ] ) ? (string) $store[ $provider ] : '';
	}

	/**
	 * Store (or clear) a provider key. An empty value removes it. Never logs the key.
	 *
	 * @param string $provider Provider id.
	 * @param string $key      Raw API key (or '' to clear).
	 * @return bool True when the provider id is known.
	 */
	public static function set( $provider, $key ) {
		$provider = sanitize_key( $provider );
		if ( ! isset( self::providers()[ $provider ] ) ) {
			return false;
		}

		$store = get_option( self::OPTION, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}

		// Keys are opaque; strip only surrounding whitespace and control characters.
		$key = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $key ) );

		if ( '' === $key ) {
			unset( $store[ $provider ] );
		} else {
			$store[ $provider ] = $key;
		}

		update_option( self::OPTION, $store, false );
		return true;
	}

	/**
	 * True when a usable key exists for the provider (constant or stored).
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function has( $provider ) {
		return '' !== self::get( $provider );
	}

	/**
	 * UI-safe status for every provider: whether a key is set, its source, and its last 4 chars.
	 * NEVER returns the key.
	 *
	 * @return array<int,array{provider:string,is_set:bool,source:string,last4:string}>
	 */
	public static function status() {
		$out = array();
		foreach ( self::providers() as $provider => $constant ) {
			$key    = self::get( $provider );
			$source = 'none';
			if ( '' !== $key ) {
				$source = defined( $constant ) && '' !== (string) constant( $constant ) ? 'constant' : 'stored';
			}
			$out[] = array(
				'provider' => $provider,
				'is_set'   => '' !== $key,
				'source'   => $source,
				'last4'    => '' !== $key ? substr( $key, -4 ) : '',
			);
		}
		return $out;
	}
}
