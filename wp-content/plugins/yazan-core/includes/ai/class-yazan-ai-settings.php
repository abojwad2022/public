<?php
/**
 * Yazan AI — non-secret configuration.
 *
 * Everything about the AI subsystem EXCEPT the API keys: which provider is the default, the
 * per-capability model map, the fallback order, generation defaults, the monthly budget cap, and
 * the default output language. Stored in a single option and written through a strict allow-list
 * (the same safety idiom as {@see Yazan_REST_Settings}) so a request can never set an arbitrary field.
 *
 * Secrets live in {@see Yazan_AI_Secrets}, deliberately apart from this.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write helper for the yazan_ai_settings option.
 */
class Yazan_AI_Settings {

	/** Option storing the whole non-secret AI config. */
	const OPTION = 'yazan_ai_settings';

	/** Known provider ids, in the default fallback order. */
	const PROVIDERS = array( 'openrouter', 'openai', 'claude', 'gemini', 'groq', 'moonshot', 'custom' );

	/**
	 * Defaults. OpenRouter is the primary per the project decision (one key, many models).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_provider' => 'openrouter',
			'fallback'         => array( 'openrouter', 'gemini', 'openai' ),
			'temperature'      => 0.6,
			// Generous default: "thinking" models (Gemini flash, etc.) spend output tokens reasoning
			// before the answer, so a low ceiling truncates structured output. Pipelines raise this
			// further per task; individual providers still stop as soon as they're done.
			'max_tokens'       => 2048,
			'language'         => 'both', // 'both' | 'en' | 'ar' — default output language for generations.
			// USD/month spend backstop (0 = no cap). A non-zero default gives the public concierge a
			// money brake out of the box (the per-IP throttle is the per-visitor brake); the owner can
			// raise or clear it in AI Settings.
			'monthly_budget'   => 50.0,
			'user_rate_limit'  => 60,     // Generations per user per hour; 0 = no limit.
			'cache_enabled'    => true,
			'enabled'          => true,   // Master switch for the whole subsystem.
			'models'           => Yazan_AI_Models::default_map(),
			// Per-task model overrides: task_models[ task ][ provider ] = model id. Empty = inherit the
			// capability default from `models`. See {@see Yazan_AI_Models::resolve()}.
			'task_models'      => array(),
			// Owner-editable prompt guidance. `voice` augments the house brand voice on every
			// generation; the per-task keys add instructions to that specific pipeline (e.g. how the
			// product description should read). All optional — empty means "use the built-in prompt".
			'prompts'          => array(
				'voice'     => '',
				'product'   => '',
				'seo'       => '',
				'marketing' => '',
				'chat'      => '',
			),
			// Phase 3 — external AI Core service. When enabled + URL set + shared secret saved, the
			// gateway delegates provider execution to it (with automatic fallback to in-PHP providers).
			'ai_core'          => array(
				'enabled' => false,
				'url'     => '',
			),
			// Human-support handoff for the storefront concierge. When enabled, a "talk to a person"
			// action emails the transcript to the owner, POSTs it to a CRM webhook (if set), and/or opens
			// WhatsApp. All optional — empty channels are simply skipped.
			'support'          => array(
				'enabled'         => false,
				'whatsapp'        => '', // digits only, international format (no +), e.g. 9677xxxxxxx
				'email'           => '', // override recipient; empty = the owner-alert email
				'crm_webhook_url' => '',
			),
		);
	}

	/**
	 * The current, fully-resolved config (stored values merged over defaults).
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$config = array_merge( self::defaults(), $stored );

		// The model map merges per provider so a partial save never drops a provider.
		$config['models'] = self::merge_models( isset( $stored['models'] ) && is_array( $stored['models'] ) ? $stored['models'] : array() );

		// Per-task overrides are stored as-is (already normalised on write).
		$config['task_models'] = isset( $stored['task_models'] ) && is_array( $stored['task_models'] ) ? $stored['task_models'] : array();

		// Prompt overrides merge over defaults so a partial save keeps the other keys.
		$config['prompts'] = array_merge(
			self::defaults()['prompts'],
			isset( $stored['prompts'] ) && is_array( $stored['prompts'] ) ? $stored['prompts'] : array()
		);

		// AI Core config merges over defaults too.
		$config['ai_core'] = array_merge(
			self::defaults()['ai_core'],
			isset( $stored['ai_core'] ) && is_array( $stored['ai_core'] ) ? $stored['ai_core'] : array()
		);

		// Support-handoff config merges over defaults so a partial save keeps the other keys.
		$config['support'] = array_merge(
			self::defaults()['support'],
			isset( $stored['support'] ) && is_array( $stored['support'] ) ? $stored['support'] : array()
		);

		// Guard the provider references against stale/removed ids.
		if ( ! in_array( $config['default_provider'], self::PROVIDERS, true ) ) {
			$config['default_provider'] = 'openrouter';
		}
		$config['fallback'] = array_values(
			array_unique(
				array_filter(
					(array) $config['fallback'],
					static function ( $p ) {
						return in_array( $p, self::PROVIDERS, true );
					}
				)
			)
		);
		if ( empty( $config['fallback'] ) ) {
			$config['fallback'] = array( $config['default_provider'] );
		}

		return $config;
	}

	/**
	 * Convenience getter for a single top-level key.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Apply a sanitized partial update. Only known keys are written; unknown keys are ignored.
	 *
	 * @param array $incoming Raw payload from the controller.
	 * @return array The new, fully-resolved config.
	 */
	public static function update( array $incoming ) {
		$config = self::all();

		if ( array_key_exists( 'default_provider', $incoming ) ) {
			$p = sanitize_key( (string) $incoming['default_provider'] );
			if ( in_array( $p, self::PROVIDERS, true ) ) {
				$config['default_provider'] = $p;
			}
		}
		if ( array_key_exists( 'fallback', $incoming ) ) {
			$chain = array();
			foreach ( (array) $incoming['fallback'] as $p ) {
				$p = sanitize_key( (string) $p );
				if ( in_array( $p, self::PROVIDERS, true ) && ! in_array( $p, $chain, true ) ) {
					$chain[] = $p;
				}
			}
			if ( $chain ) {
				$config['fallback'] = $chain;
			}
		}
		if ( array_key_exists( 'temperature', $incoming ) ) {
			$config['temperature'] = max( 0.0, min( 2.0, (float) $incoming['temperature'] ) );
		}
		if ( array_key_exists( 'max_tokens', $incoming ) ) {
			$config['max_tokens'] = max( 64, min( 8192, absint( $incoming['max_tokens'] ) ) );
		}
		if ( array_key_exists( 'language', $incoming ) ) {
			$lang = sanitize_key( (string) $incoming['language'] );
			$config['language'] = in_array( $lang, array( 'both', 'en', 'ar' ), true ) ? $lang : 'both';
		}
		if ( array_key_exists( 'monthly_budget', $incoming ) ) {
			$config['monthly_budget'] = max( 0.0, (float) $incoming['monthly_budget'] );
		}
		if ( array_key_exists( 'user_rate_limit', $incoming ) ) {
			$config['user_rate_limit'] = max( 0, min( 100000, absint( $incoming['user_rate_limit'] ) ) );
		}
		if ( array_key_exists( 'cache_enabled', $incoming ) ) {
			$config['cache_enabled'] = (bool) wc_string_to_bool( $incoming['cache_enabled'] );
		}
		if ( array_key_exists( 'enabled', $incoming ) ) {
			$config['enabled'] = (bool) wc_string_to_bool( $incoming['enabled'] );
		}
		if ( array_key_exists( 'models', $incoming ) && is_array( $incoming['models'] ) ) {
			$config['models'] = self::merge_models( $incoming['models'] );
		}
		if ( array_key_exists( 'task_models', $incoming ) && is_array( $incoming['task_models'] ) ) {
			$config['task_models'] = self::sanitize_task_models( $incoming['task_models'] );
		}
		if ( array_key_exists( 'prompts', $incoming ) && is_array( $incoming['prompts'] ) ) {
			$clean = array();
			foreach ( array( 'voice', 'product', 'seo', 'marketing', 'chat' ) as $k ) {
				if ( isset( $incoming['prompts'][ $k ] ) ) {
					// Cap length so a runaway prompt can't bloat every request; textarea-sanitized.
					$clean[ $k ] = substr( sanitize_textarea_field( (string) $incoming['prompts'][ $k ] ), 0, 2000 );
				}
			}
			$config['prompts'] = array_merge( self::defaults()['prompts'], $config['prompts'], $clean );
		}
		if ( array_key_exists( 'ai_core', $incoming ) && is_array( $incoming['ai_core'] ) ) {
			$ac = $config['ai_core'];
			if ( array_key_exists( 'enabled', $incoming['ai_core'] ) ) {
				$ac['enabled'] = (bool) wc_string_to_bool( $incoming['ai_core']['enabled'] );
			}
			if ( array_key_exists( 'url', $incoming['ai_core'] ) ) {
				$raw_url = esc_url_raw( trim( (string) $incoming['ai_core']['url'] ) );
				if ( '' === $raw_url ) {
					$ac['url'] = '';
				} else {
					// The gateway POSTs all resolved provider API keys to this URL, so cleartext http is
					// only acceptable on loopback. A remote endpoint MUST be https, otherwise the keys
					// would travel in the clear (and an attacker able to write this setting could point
					// them at their own host). Reject an unsafe value — keep the previously stored one.
					$host        = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_HOST ) );
					$scheme      = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_SCHEME ) );
					$is_loopback = in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true );
					if ( 'https' === $scheme || ( 'http' === $scheme && $is_loopback ) ) {
						$ac['url'] = $raw_url;
					}
				}
			}
			$config['ai_core'] = $ac;
		}
		if ( array_key_exists( 'support', $incoming ) && is_array( $incoming['support'] ) ) {
			$sp = $config['support'];
			if ( array_key_exists( 'enabled', $incoming['support'] ) ) {
				$sp['enabled'] = (bool) wc_string_to_bool( $incoming['support']['enabled'] );
			}
			if ( array_key_exists( 'whatsapp', $incoming['support'] ) ) {
				// Keep digits only (wa.me wants an international number without punctuation).
				$sp['whatsapp'] = preg_replace( '/\D+/', '', (string) $incoming['support']['whatsapp'] );
			}
			if ( array_key_exists( 'email', $incoming['support'] ) ) {
				$raw            = trim( (string) $incoming['support']['email'] );
				$sp['email']    = ( '' === $raw ) ? '' : sanitize_email( $raw );
			}
			if ( array_key_exists( 'crm_webhook_url', $incoming['support'] ) ) {
				$raw_url = esc_url_raw( trim( (string) $incoming['support']['crm_webhook_url'] ) );
				if ( '' === $raw_url ) {
					$sp['crm_webhook_url'] = '';
				} else {
					// The transcript (which may include the shopper's own message) is POSTed here, so a
					// remote endpoint MUST be https; http is only tolerated on loopback. Reject otherwise.
					$host        = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_HOST ) );
					$scheme      = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_SCHEME ) );
					$is_loopback = in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true );
					if ( 'https' === $scheme || ( 'http' === $scheme && $is_loopback ) ) {
						$sp['crm_webhook_url'] = $raw_url;
					}
				}
			}
			$config['support'] = $sp;
		}

		update_option( self::OPTION, $config, true );
		return $config;
	}

	/**
	 * Sanitize a per-task model map, keeping only known TASK/PROVIDER keys and non-empty ids.
	 *
	 * @param array $incoming task => { provider => model id }.
	 * @return array
	 */
	private static function sanitize_task_models( array $incoming ) {
		$out = array();
		foreach ( Yazan_AI_Models::TASKS as $task ) {
			if ( ! isset( $incoming[ $task ] ) || ! is_array( $incoming[ $task ] ) ) {
				continue;
			}
			foreach ( self::PROVIDERS as $provider ) {
				if ( isset( $incoming[ $task ][ $provider ] ) && is_string( $incoming[ $task ][ $provider ] ) ) {
					$clean = trim( sanitize_text_field( $incoming[ $task ][ $provider ] ) );
					if ( '' !== $clean ) {
						$out[ $task ][ $provider ] = $clean;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Merge an incoming (possibly partial) model map over the defaults, sanitizing every id.
	 *
	 * @param array $incoming provider => { text: id, vision: id }.
	 * @return array
	 */
	private static function merge_models( array $incoming ) {
		$base = Yazan_AI_Models::default_map();
		foreach ( self::PROVIDERS as $provider ) {
			foreach ( array( 'text', 'vision', 'image' ) as $cap ) {
				if ( isset( $incoming[ $provider ][ $cap ] ) && is_string( $incoming[ $provider ][ $cap ] ) ) {
					$clean = trim( sanitize_text_field( $incoming[ $provider ][ $cap ] ) );
					if ( '' !== $clean ) {
						$base[ $provider ][ $cap ] = $clean;
					}
				}
			}
		}
		return $base;
	}
}
