<?php
/**
 * Yazan AI — the gateway.
 *
 * THE single entry point to the intelligence layer. Everything (pipelines, the REST controller, the
 * future chat) calls {@see self::run()} and never talks to a provider directly. That is the whole
 * design: this method's body is the only thing that changes the day the AI Core is lifted out of
 * WordPress into a standalone service — it would swap "walk the local provider chain" for "POST the
 * remote AI Core", and every caller stays identical.
 *
 * Responsibilities, in order: budget check → cache lookup → walk the provider fallback chain →
 * log the outcome → return a normalized envelope. It never throws; failures come back as an envelope
 * with `ok = false` and a safe error.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-agnostic completion gateway.
 */
class Yazan_AI_Gateway {

	/** Wall-clock budget (seconds) for walking the whole local provider fallback chain. */
	const CHAIN_DEADLINE = 120;

	/**
	 * Run one generation.
	 *
	 * @param array $request Normalized request: system, messages[], json, temperature, max_tokens.
	 * @param array $opts    task, module, object_type, object_id, provider (override), capability
	 *                       ('text'|'vision'|'auto'), cache (bool), skip_budget (bool).
	 * @return array Envelope: ok, text, json, usage, provider, model, cached, request_id, error.
	 */
	public static function run( array $request, array $opts = array() ) {
		$config     = Yazan_AI_Settings::all();
		$task       = sanitize_text_field( $opts['task'] ?? 'generic' );
		$module     = sanitize_key( $opts['module'] ?? 'core' );
		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );

		// Fill generation defaults from settings.
		$request['temperature'] = isset( $request['temperature'] ) ? (float) $request['temperature'] : (float) $config['temperature'];
		$request['max_tokens']  = isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : (int) $config['max_tokens'];
		$request['messages']    = isset( $request['messages'] ) ? (array) $request['messages'] : array();
		$request['system']      = (string) ( $request['system'] ?? '' );
		$request['json']        = ! empty( $request['json'] );

		// Budget / rate-limit gate.
		if ( empty( $opts['skip_budget'] ) ) {
			$gate = Yazan_AI_Budget::check();
			if ( is_wp_error( $gate ) ) {
				return self::error_envelope( $gate->get_error_code(), $gate->get_error_message(), $request_id, $gate->get_error_data() );
			}
		}

		// Determine capability (vision when any message carries an image).
		$capability = $opts['capability'] ?? 'auto';
		if ( 'auto' === $capability ) {
			$capability = 'text';
			foreach ( $request['messages'] as $m ) {
				if ( ! empty( $m['images'] ) ) {
					$capability = 'vision';
					break;
				}
			}
		}

		$task_group = Yazan_AI_Models::task_group( $task );
		$chain      = Yazan_AI_Router::chain( $capability, $opts['provider'] ?? null, ! empty( $opts['diagnostic'] ), $task_group );
		if ( empty( $chain ) ) {
			$msg = ( 'vision' === $capability )
				? __( 'No AI provider with a vision-capable model and a saved key is configured.', 'yazan' )
				: __( 'No AI provider is configured. Add an API key in Provider Settings.', 'yazan' );
			self::log_row( $module, $task, $opts, '', '', 'error', 'no_provider', 0, 0, false, 0, $request_id );
			return self::error_envelope( 'no_provider', $msg, $request_id, array( 'status' => 503 ) );
		}

		// Cache lookup, keyed on the intended provider/model so a provider switch bypasses stale output.
		$cache_enabled = ! empty( $config['cache_enabled'] ) && ( ! array_key_exists( 'cache', $opts ) || $opts['cache'] );
		$cache_key     = Yazan_AI_Cache::key( $task, $chain[0]['provider'] . ':' . $chain[0]['model'], $request );
		if ( $cache_enabled ) {
			$hit = Yazan_AI_Cache::get( $cache_key );
			if ( null !== $hit ) {
				$hit['cached']     = true;
				$hit['request_id'] = $request_id;
				self::log_row( $module, $task, $opts, $hit['provider'] ?? '', $hit['model'] ?? '', 'ok', '', 0, 0, true, 0, $request_id );
				return $hit;
			}
		}

		// Execute: a remote AI Core service when configured, otherwise the in-PHP providers. If the
		// service is unreachable the remote path returns null and we transparently fall back to local —
		// so enabling the Core never risks an outage. This dispatch IS the extraction seam.
		$envelope = null;
		if ( Yazan_AI_Core_Client::is_enabled() ) {
			$envelope = self::execute_remote( $chain, $request, $capability, $module, $task, $opts, $request_id );
		}
		if ( null === $envelope ) {
			$envelope = self::execute_local( $chain, $request, $module, $task, $opts, $request_id );
		}

		if ( $cache_enabled && ! empty( $envelope['ok'] ) ) {
			Yazan_AI_Cache::set( $cache_key, $envelope );
		}
		return $envelope;
	}

	/**
	 * Execute the chain against the in-PHP provider adapters — the original path, and the fallback when
	 * a remote Core is unreachable. Walks the chain until one provider succeeds; logs every attempt.
	 *
	 * @return array Envelope (success or final error).
	 */
	private static function execute_local( array $chain, array $request, $module, $task, array $opts, $request_id ) {
		$last_error = null;
		// Chain-wide deadline so a long fallback chain can't stack up into a multi-minute request
		// (each provider can take timeout+retry ~90s; three of them would be ~270s otherwise).
		$deadline = microtime( true ) + self::CHAIN_DEADLINE;
		foreach ( $chain as $attempt ) {
			if ( microtime( true ) > $deadline ) {
				break; // Out of time budget — stop trying further providers.
			}
			$provider = Yazan_AI_Router::provider( $attempt['provider'] );
			$api_key  = Yazan_AI_Secrets::get( $attempt['provider'] );
			$started  = microtime( true );

			try {
				$result   = $provider->complete( $request, $attempt['model'], $api_key );
				$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
				$cost     = Yazan_AI_Models::estimate_cost( $attempt['model'], $result['tokens_in'], $result['tokens_out'] );

				Yazan_AI_Budget::tick();
				self::log_row(
					$module, $task, $opts, $attempt['provider'], $attempt['model'], 'ok', '',
					$result['tokens_in'], $result['tokens_out'], false, $duration, $request_id, $cost
				);

				return self::success_envelope( $result['text'], $result['tokens_in'], $result['tokens_out'], $cost, $attempt['provider'], $attempt['model'], $request_id, 'local', ! empty( $request['json'] ) );

			} catch ( Yazan_AI_Exception $e ) {
				$duration   = (int) round( ( microtime( true ) - $started ) * 1000 );
				$last_error = $e;
				self::log_row(
					$module, $task, $opts, $attempt['provider'], $attempt['model'], 'error', $e->error_code(),
					0, 0, false, $duration, $request_id
				);
				if ( ! $e->is_retryable() ) {
					break; // A non-retryable failure (e.g. blocked content) stops the chain.
				}
				// Otherwise fall through to the next provider.
			}
		}

		$code = $last_error ? $last_error->error_code() : 'unknown';

		// In diagnostic mode (the "Test connection" button) surface the REAL provider error — an
		// invalid key, a missing-credits 402, a bad model id — instead of the generic customer-safe
		// message, which otherwise hides exactly the detail an admin needs to fix their setup.
		$diagnostic = ! empty( $opts['diagnostic'] );
		$detail     = ( $diagnostic && $last_error ) ? self::clean_detail( $last_error->getMessage() ) : '';
		$message    = ( $diagnostic && '' !== $detail )
			? $detail
			: self::failure_message( $code );

		return self::error_envelope( $code, $message, $request_id, array( 'status' => 502 ), $detail );
	}

	/**
	 * Execute the chain against the remote AI Core service. Returns null when the service is unusable
	 * (unreachable / bad secret) so the caller falls back to local; otherwise returns an envelope. The
	 * service owns the fallback loop, so we make one call and log its single outcome.
	 *
	 * @return array|null
	 */
	private static function execute_remote( array $chain, array $request, $capability, $module, $task, array $opts, $request_id ) {
		// Attach keys to the chain — WordPress owns them; the stateless service needs them per request.
		$signed_chain = array();
		foreach ( $chain as $a ) {
			$signed_chain[] = array( 'provider' => $a['provider'], 'model' => $a['model'], 'api_key' => Yazan_AI_Secrets::get( $a['provider'] ) );
		}

		$started = microtime( true );
		try {
			$res = Yazan_AI_Core_Client::complete( $request, $signed_chain, $capability );
		} catch ( Yazan_AI_Exception $e ) {
			// Log the remote failure (so a flaky Core is diagnosable in the ledger) THEN signal fallback
			// to local providers by returning null — the local path will log its own outcome next.
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
			self::log_row( $module, $task, $opts, 'core-remote', '', 'error', $e->error_code(), 0, 0, false, $duration, $request_id );
			return null;
		}
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! empty( $res['ok'] ) ) {
			$in    = (int) ( $res['tokens_in'] ?? 0 );
			$out   = (int) ( $res['tokens_out'] ?? 0 );
			$model = (string) ( $res['model'] ?? '' );
			$cost  = Yazan_AI_Models::estimate_cost( $model, $in, $out );

			Yazan_AI_Budget::tick();
			self::log_row( $module, $task, $opts, (string) ( $res['provider'] ?? '' ), $model, 'ok', '', $in, $out, false, $duration, $request_id, $cost );

			return self::success_envelope( (string) ( $res['text'] ?? '' ), $in, $out, $cost, (string) ( $res['provider'] ?? '' ), $model, $request_id, 'core', ! empty( $request['json'] ) );
		}

		// Service reached but every provider failed (the same keys would fail locally too) — return it.
		$code       = (string) ( $res['error_code'] ?? 'unknown' );
		$diagnostic = ! empty( $opts['diagnostic'] );
		$detail     = ( $diagnostic && ! empty( $res['error_message'] ) ) ? self::clean_detail( (string) $res['error_message'] ) : '';
		$message    = ( $diagnostic && '' !== $detail )
			? $detail
			: self::failure_message( $code );
		self::log_row( $module, $task, $opts, '', '', 'error', $code, 0, 0, false, $duration, $request_id );
		return self::error_envelope( $code, $message, $request_id, array( 'status' => 502 ), $detail );
	}

	/**
	 * Build the customer-safe failure message for a chain error. Shop managers get an actionable hint
	 * (so an admin running SEO/marketing/product knows it's a quota/rate limit and how to fix it), while
	 * storefront visitors — e.g. the public concierge chat — get a neutral "busy, try later" so provider
	 * or configuration details never leak. The diagnostic "Test connection" path builds its own detail.
	 *
	 * @param string $code Error code from the last provider failure (e.g. 'rate_limited').
	 * @return string
	 */
	private static function failure_message( $code ) {
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );

		if ( 'rate_limited' === $code ) {
			return $is_admin
				? __( 'The AI provider is rate-limited or out of quota right now. Wait a little and retry — or add another provider key (e.g. Groq, OpenRouter, OpenAI) in AI Settings so requests can fall back automatically.', 'yazan' )
				: __( 'The AI assistant is busy right now. Please try again in a little while.', 'yazan' );
		}

		return __( 'The AI service is temporarily unavailable. Please try again.', 'yazan' );
	}

	/**
	 * Normalise a developer error message for admin display: strip tags, collapse whitespace, trim.
	 * Only ever shown on the admin-only diagnostics path — never surfaced to storefront customers.
	 *
	 * @param string $message Raw exception message.
	 * @return string
	 */
	private static function clean_detail( $message ) {
		$message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $message ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 240 ) : substr( $message, 0, 240 );
	}

	/**
	 * Build a normalized success envelope. `via` records which path served it ('local' | 'core').
	 *
	 * @return array
	 */
	private static function success_envelope( $text, $in, $out, $cost, $provider, $model, $request_id, $via, $json_expected ) {
		return array(
			'ok'         => true,
			'text'       => (string) $text,
			'json'       => self::maybe_json( $text, $json_expected ),
			'usage'      => array( 'in' => (int) $in, 'out' => (int) $out, 'cost' => $cost ),
			'provider'   => (string) $provider,
			'model'      => (string) $model,
			'via'        => (string) $via,
			'cached'     => false,
			'request_id' => $request_id,
			'error'      => null,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Parse a model's JSON output into an array, tolerating a ```json fence or leading prose.
	 *
	 * @param string $text     Model text.
	 * @param bool   $expected Whether JSON was requested.
	 * @return array|null
	 */
	private static function maybe_json( $text, $expected ) {
		if ( ! $expected ) {
			return null;
		}
		$text = trim( (string) $text );

		// Strip a Markdown code fence if the model wrapped its JSON.
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-zA-Z]*\s*/', '', $text );
			$text = preg_replace( '/\s*```$/', '', $text );
		}

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Last resort: grab the outermost {...} block.
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Standard failure envelope.
	 *
	 * @param string $code       Error code.
	 * @param string $message    User-safe message.
	 * @param string $request_id Request id.
	 * @param mixed  $data       Optional WP_Error data (for status).
	 * @return array
	 */
	private static function error_envelope( $code, $message, $request_id, $data = array(), $detail = '' ) {
		return array(
			'ok'         => false,
			'text'       => '',
			'json'       => null,
			'usage'      => array( 'in' => 0, 'out' => 0, 'cost' => 0.0 ),
			'provider'   => '',
			'model'      => '',
			'cached'     => false,
			'request_id' => $request_id,
			'error'      => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
				// Developer-facing detail, populated ONLY on the admin diagnostics path (empty otherwise,
				// so the customer-facing chat never exposes provider internals).
				'detail'  => (string) $detail,
				'status'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502,
			),
		);
	}

	/**
	 * Write one ledger row (best-effort).
	 */
	private static function log_row( $module, $task, array $opts, $provider, $model, $status, $error_code, $in, $out, $cached, $duration, $request_id, $cost = 0.0 ) {
		Yazan_AI_Log::record(
			array(
				'module'      => $module,
				'task'        => $task,
				'object_type' => sanitize_key( $opts['object_type'] ?? '' ),
				'object_id'   => absint( $opts['object_id'] ?? 0 ),
				'provider'    => $provider,
				'model'       => $model,
				'status'      => $status,
				'error_code'  => $error_code,
				'tokens_in'   => $in,
				'tokens_out'  => $out,
				'cost_usd'    => $cost,
				'cached'      => $cached,
				'duration_ms' => $duration,
				'request_id'  => $request_id,
			)
		);

		// Surface a broken provider key instead of silently failing over: flag it for the diagnostics
		// UI and fire an action integrations can hook (e.g. email the owner).
		if ( 'error' === $status && 'auth' === $error_code && $provider ) {
			set_transient( 'yazan_ai_auth_alert_' . sanitize_key( $provider ), current_time( 'mysql' ), DAY_IN_SECONDS );
			/**
			 * Fires when a provider rejects its API key (auth failure), so the owner can be alerted.
			 *
			 * @param string $provider Provider id.
			 * @param string $model    Model that was attempted.
			 */
			do_action( 'yazan_ai_auth_error', $provider, $model );
		}
	}
}
