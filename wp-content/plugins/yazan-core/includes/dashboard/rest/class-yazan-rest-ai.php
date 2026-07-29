<?php
/**
 * Yazan Dashboard — AI REST controller (yazan/v1/ai/*).
 *
 * The thin HTTP surface for the whole AI subsystem: authenticate → call the gateway/pipeline → return
 * a normalized response. It holds no intelligence itself. This is also the extraction contract — if the
 * AI Core later becomes a standalone service, these routes stay identical and only the gateway changes.
 *
 * Permissions: generation routes need `edit_products` (the dashboard capability); configuration,
 * credentials, analytics, and logs need `manage_woocommerce`. Secrets are NEVER returned — the
 * credentials GET reports only whether a key is set and its last 4 characters.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /ai/* endpoints.
 */
class Yazan_REST_AI {

	/** Capability for configuration / secrets / analytics. */
	const ADMIN_CAP = 'manage_woocommerce';

	/** Capability for content generation. */
	const GEN_CAP = 'edit_products';

	/**
	 * Register routes. Hook: rest_api_init.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns    = Yazan_Dashboard_Auth::NS;
		$admin = Yazan_Dashboard_Auth::require_cap( self::ADMIN_CAP );
		$gen   = Yazan_Dashboard_Auth::require_cap( self::GEN_CAP );

		register_rest_route(
			$ns,
			'/ai/settings',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $admin ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update_settings' ), 'permission_callback' => $admin ),
			)
		);

		register_rest_route(
			$ns,
			'/ai/credentials',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_credentials' ), 'permission_callback' => $admin ),
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'set_credential' ), 'permission_callback' => $admin ),
			)
		);

		register_rest_route(
			$ns,
			'/ai/test',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => $admin )
		);

		register_rest_route(
			$ns,
			'/ai/test-all',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'test_all' ), 'permission_callback' => $admin )
		);

		register_rest_route(
			$ns,
			'/ai/core/test',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'core_test' ), 'permission_callback' => $admin )
		);

		register_rest_route(
			$ns,
			'/ai/product',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'product' ), 'permission_callback' => $gen )
		);

		register_rest_route(
			$ns,
			'/ai/seo',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'seo' ), 'permission_callback' => $gen )
		);

		register_rest_route(
			$ns,
			'/ai/marketing',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'marketing' ), 'permission_callback' => $gen )
		);

		register_rest_route(
			$ns,
			'/ai/gallery/plan',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'gallery_plan' ), 'permission_callback' => $gen )
		);

		register_rest_route(
			$ns,
			'/ai/gallery/generate',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'gallery_generate' ), 'permission_callback' => $gen )
		);

		register_rest_route(
			$ns,
			'/ai/analytics',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'analytics' ), 'permission_callback' => $admin )
		);

		register_rest_route(
			$ns,
			'/ai/logs',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'logs' ), 'permission_callback' => $admin )
		);

		// PUBLIC — the storefront concierge. No login; protected by a page nonce + per-IP throttle +
		// the global budget cap. Retrieval-grounded, so it can only surface real catalogue products.
		register_rest_route(
			$ns,
			'/ai/chat',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'chat' ), 'permission_callback' => '__return_true' )
		);

		// PUBLIC — hands the widget a fresh chat nonce so a stale page-embedded one (caching / expiry /
		// changed login session) self-heals instead of dead-ending on "session expired". A nonce is not a
		// secret and this is same-origin, so exposing a refresh route is safe.
		register_rest_route(
			$ns,
			'/ai/chat-nonce',
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'chat_nonce' ), 'permission_callback' => '__return_true' )
		);

		// PUBLIC — human-support handoff: emails the transcript to the owner, optionally POSTs it to a CRM
		// webhook, and returns a WhatsApp deep link. Same CSRF nonce as chat + a stricter per-IP throttle.
		register_rest_route(
			$ns,
			'/ai/chat/handoff',
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'chat_handoff' ), 'permission_callback' => '__return_true' )
		);
	}

	/** Chat nonce action (minted into the storefront page). */
	const CHAT_NONCE = 'yazan_ai_chat';

	/** Public chat rate limit: requests per IP per window. */
	const CHAT_MAX = 20;

	/** Chat rate-limit window (seconds). */
	const CHAT_WINDOW = 600; // 10 minutes.

	/** Handoff rate limit: requests per IP per window (stricter — it emails / POSTs to a CRM). */
	const HANDOFF_MAX = 5;

	/** Handoff rate-limit window (seconds). */
	const HANDOFF_WINDOW = 600; // 10 minutes.

	/**
	 * Site-wide handoff ceiling per UTC day, across ALL IPs.
	 *
	 * The per-IP throttle bounds one abuser; it does nothing against a
	 * distributed one, because a nonce is free to obtain and every fresh IP gets
	 * its own allowance. /ai/chat is protected from that by the global AI budget
	 * cap — handoff had no equivalent, so a botnet could send an unbounded number
	 * of owner emails and CRM webhook POSTs. This is that missing ceiling.
	 * Filterable via `yazan_ai_handoff_daily_max`; 0 disables the cap.
	 */
	const HANDOFF_DAILY_MAX = 50;

	/** Chat-nonce dispenser: requests per IP per window. A real widget needs one or two. */
	const NONCE_MAX = 30;

	/** Chat-nonce dispenser window (seconds). */
	const NONCE_WINDOW = 600; // 10 minutes.

	/* --------------------------------------------------------------------- */
	/* Settings                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /ai/settings — current config + provider metadata (no secrets).
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return new WP_REST_Response(
			array(
				'settings'    => Yazan_AI_Settings::all(),
				'providers'   => self::provider_meta(),
				'credentials' => Yazan_AI_Secrets::status(),
				'usage'       => self::usage_snapshot(),
				// Phase 3: is the external AI Core actually active (enabled + URL + secret present)?
				'ai_core'     => array( 'active' => Yazan_AI_Core_Client::is_enabled() ),
				// Task groups for the per-task model table.
				'tasks'       => self::task_meta(),
			),
			200
		);
	}

	/**
	 * Task groups + human labels for the per-task model UI.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private static function task_meta() {
		$labels = array(
			'chat'      => __( 'Customer chat', 'yazan' ),
			'product'   => __( 'Product listing (vision)', 'yazan' ),
			'seo'       => __( 'SEO', 'yazan' ),
			'marketing' => __( 'Marketing', 'yazan' ),
			'analytics' => __( 'Analytics', 'yazan' ),
		);
		$out = array();
		foreach ( Yazan_AI_Models::TASKS as $task ) {
			$out[] = array( 'key' => $task, 'label' => $labels[ $task ] ?? $task );
		}
		return $out;
	}

	/**
	 * POST /ai/core/test — probe the external AI Core service (health + signed auth round-trip).
	 *
	 * @return WP_REST_Response
	 */
	public static function core_test() {
		return new WP_REST_Response( Yazan_AI_Core_Client::ping(), 200 );
	}

	/**
	 * PUT /ai/settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$incoming = $request->get_param( 'settings' );
		if ( ! is_array( $incoming ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Nothing to save.', 'yazan' ), array( 'status' => 400 ) );
		}
		$config = Yazan_AI_Settings::update( $incoming );
		Yazan_Dashboard_Audit::log( 'ai.settings.update', 'ai', 0, array( 'keys' => array_keys( $incoming ) ) );

		return new WP_REST_Response( array( 'settings' => $config, 'saved' => true ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Credentials                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /ai/credentials — set/unset status only.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_credentials() {
		return new WP_REST_Response( array( 'credentials' => Yazan_AI_Secrets::status() ), 200 );
	}

	/**
	 * POST /ai/credentials — store or clear one provider key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_credential( WP_REST_Request $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$key      = (string) $request->get_param( 'key' );

		if ( ! Yazan_AI_Secrets::set( $provider, $key ) ) {
			return new WP_Error( 'yazan_invalid', __( 'Unknown AI provider.', 'yazan' ), array( 'status' => 400 ) );
		}
		// Log only that a key changed — never the key or its value.
		Yazan_Dashboard_Audit::log( 'ai.credential.update', 'ai', 0, array( 'provider' => $provider, 'cleared' => '' === trim( $key ) ? 1 : 0 ) );

		return new WP_REST_Response( array( 'credentials' => Yazan_AI_Secrets::status() ), 200 );
	}

	/**
	 * POST /ai/test — smoke-test a provider with a tiny prompt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function test( WP_REST_Request $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$provider = in_array( $provider, Yazan_AI_Settings::PROVIDERS, true ) ? $provider : null;

		$started  = microtime( true );
		$envelope = Yazan_AI_Gateway::run(
			array(
				'system'     => 'You are a connection test.',
				'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word: OK' ) ),
				// Enough headroom that a "thinking" model still emits a visible word after its reasoning.
				'max_tokens' => 64,
			),
			array( 'task' => 'ping', 'module' => 'diagnostics', 'provider' => $provider, 'capability' => 'text', 'cache' => false, 'skip_budget' => true, 'diagnostic' => true )
		);
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		return new WP_REST_Response(
			array(
				'ok'         => ! empty( $envelope['ok'] ),
				'provider'   => $envelope['provider'] ?? '',
				'model'      => $envelope['model'] ?? '',
				'latency_ms' => $latency,
				'reply'      => isset( $envelope['text'] ) ? substr( (string) $envelope['text'], 0, 120 ) : '',
				'error'      => $envelope['error'] ?? null,
			),
			200
		);
	}

	/**
	 * POST /ai/test-all — health-check every provider that has a key, in isolation.
	 *
	 * @return WP_REST_Response
	 */
	public static function test_all() {
		$results = array();
		foreach ( Yazan_AI_Settings::PROVIDERS as $provider ) {
			if ( ! Yazan_AI_Secrets::has( $provider ) ) {
				$results[] = array( 'provider' => $provider, 'is_set' => false, 'ok' => false, 'latency_ms' => 0, 'model' => '', 'error' => null );
				continue;
			}

			$started  = microtime( true );
			$envelope = Yazan_AI_Gateway::run(
				array(
					'system'     => 'You are a connection test.',
					'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word: OK' ) ),
					'max_tokens' => 64,
				),
				array( 'task' => 'ping', 'module' => 'diagnostics', 'provider' => $provider, 'capability' => 'text', 'cache' => false, 'skip_budget' => true, 'diagnostic' => true )
			);
			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			$results[] = array(
				'provider'   => $provider,
				'is_set'     => true,
				'ok'         => ! empty( $envelope['ok'] ),
				'latency_ms' => $latency,
				'model'      => $envelope['model'] ?? '',
				'error'      => $envelope['error'] ?? null,
			);
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Generation                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /ai/product — image → full listing draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function product( WP_REST_Request $request ) {
		$image = self::resolve_image( $request );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$result = Yazan_AI_Product::generate(
			array(
				'image'        => $image,
				'hints'        => (array) $request->get_param( 'hints' ),
				'instructions' => (string) $request->get_param( 'instructions' ),
				'language'     => $request->get_param( 'language' ),
				'object_id'    => absint( $request->get_param( 'product_id' ) ),
			)
		);

		return self::respond( $result );
	}

	/**
	 * POST /ai/seo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function seo( WP_REST_Request $request ) {
		return self::respond(
			Yazan_AI_SEO::generate(
				array(
					'product_id' => absint( $request->get_param( 'product_id' ) ),
					'language'   => $request->get_param( 'language' ),
				)
			)
		);
	}

	/**
	 * POST /ai/marketing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function marketing( WP_REST_Request $request ) {
		return self::respond(
			Yazan_AI_Marketing::generate(
				array(
					'product_id' => absint( $request->get_param( 'product_id' ) ),
					'language'   => $request->get_param( 'language' ),
					'channels'   => (array) $request->get_param( 'channels' ),
				)
			)
		);
	}

	/**
	 * POST /ai/gallery/plan — validate a product's gallery configuration (no generation).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function gallery_plan( WP_REST_Request $request ) {
		return new WP_REST_Response(
			Yazan_AI_Gallery::plan(
				array(
					'product_id' => absint( $request->get_param( 'product_id' ) ),
					'mode'       => (string) $request->get_param( 'mode' ),
					'count'      => absint( $request->get_param( 'count' ) ),
					'prompt'     => (string) $request->get_param( 'prompt' ),
				)
			),
			200
		);
	}

	/**
	 * POST /ai/gallery/generate — generate the AI gallery (image-to-image), returned for review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function gallery_generate( WP_REST_Request $request ) {
		$result = Yazan_AI_Gallery::generate(
			array(
				'product_id' => absint( $request->get_param( 'product_id' ) ),
				'media_id'   => absint( $request->get_param( 'media_id' ) ),
				'count'      => absint( $request->get_param( 'count' ) ),
				'prompt'     => (string) $request->get_param( 'prompt' ),
			)
		);
		// Soft failures (validation / provider) come back 200 with ok:false so the UI shows them inline;
		// only include the top-level message the shared client reads.
		if ( empty( $result['ok'] ) ) {
			$result['message'] = $result['error']['message'] ?? ( $result['validation_errors'][0] ?? __( 'Gallery generation failed.', 'yazan' ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /ai/analytics?days=30.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function analytics( WP_REST_Request $request ) {
		return self::respond(
			Yazan_AI_Analytics::insights(
				array(
					'days'     => absint( $request->get_param( 'days' ) ?: 30 ),
					'language' => $request->get_param( 'language' ),
				)
			)
		);
	}

	/**
	 * GET /ai/logs — recent generation ledger for Diagnostics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function logs( WP_REST_Request $request ) {
		$data = Yazan_AI_Log::query(
			array(
				'page'     => absint( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => absint( $request->get_param( 'per_page' ) ?: 50 ),
				'module'   => sanitize_key( (string) $request->get_param( 'module' ) ),
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			)
		);
		$data['usage'] = self::usage_snapshot();
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /ai/chat — public storefront concierge. Nonce + per-IP throttle guard it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function chat( WP_REST_Request $request ) {
		if ( ! Yazan_AI_Settings::get( 'enabled', true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'The assistant is currently offline.', 'yazan' ), 'error' => array( 'code' => 'disabled' ) ), 503 );
		}

		// CSRF-ish: the storefront mints this nonce; reject requests without a valid one.
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, self::CHAT_NONCE ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Your session expired. Please refresh the page.', 'yazan' ), 'error' => array( 'code' => 'bad_nonce' ) ), 403 );
		}

		// Per-IP throttle so an anonymous endpoint can't burn the budget.
		$ip  = self::client_ip();
		$key = 'yz_ai_chat_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= self::CHAT_MAX ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'You are sending messages too quickly. Please wait a moment.', 'yazan' ), 'error' => array( 'code' => 'rate_limited' ) ), 429 );
		}
		set_transient( $key, $hit + 1, self::CHAT_WINDOW );

		$result = Yazan_AI_Chat::reply(
			array(
				'message'     => (string) $request->get_param( 'message' ),
				'history'     => (array) $request->get_param( 'history' ),
				'session_id'  => sanitize_text_field( (string) $request->get_param( 'session_id' ) ),
				// Server-derived identity ONLY — never trust a client-supplied id. 0 when logged out.
				'customer_id' => get_current_user_id(),
			)
		);

		if ( empty( $result['ok'] ) ) {
			$result['message'] = $result['error']['message'] ?? __( 'The assistant is unavailable right now.', 'yazan' );
			return new WP_REST_Response( $result, 200 ); // Soft failure: the widget shows the message inline.
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /ai/chat-nonce — return a fresh chat nonce for the current session. Lets the storefront widget
	 * recover from a stale page-embedded nonce without a manual refresh.
	 *
	 * @return WP_REST_Response
	 */
	public static function chat_nonce() {
		// Throttle the dispenser too. A nonce is not a secret, but an unlimited
		// supply of them makes it worthless as anything but a CSRF token — and
		// leaves hammering this route free. A legitimate widget needs one nonce
		// per session, so this ceiling is invisible to real shoppers.
		$key = 'yz_ai_nonce_' . md5( self::client_ip() );
		$hit = (int) get_transient( $key );
		if ( $hit >= self::NONCE_MAX ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'message' => __( 'Too many requests. Please try again shortly.', 'yazan' ), 'error' => array( 'code' => 'rate_limited' ) ),
				429
			);
		}
		set_transient( $key, $hit + 1, self::NONCE_WINDOW );

		return new WP_REST_Response( array( 'ok' => true, 'nonce' => wp_create_nonce( self::CHAT_NONCE ) ), 200 );
	}

	/**
	 * POST /ai/chat/handoff — escalate the concierge conversation to a human. Emails the transcript to the
	 * owner, optionally POSTs it to a CRM webhook, and returns a WhatsApp deep link. Nonce + stricter throttle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function chat_handoff( WP_REST_Request $request ) {
		$support = (array) Yazan_AI_Settings::get( 'support', array() );
		if ( empty( $support['enabled'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Live support is not available right now.', 'yazan' ), 'error' => array( 'code' => 'disabled' ) ), 200 );
		}

		// Same CSRF nonce as the chat route.
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), self::CHAT_NONCE ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Your session expired. Please refresh the page.', 'yazan' ), 'error' => array( 'code' => 'bad_nonce' ) ), 403 );
		}

		// Stricter per-IP throttle — this path sends email / POSTs to a CRM.
		$ip  = self::client_ip();
		$key = 'yz_ai_handoff_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= self::HANDOFF_MAX ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Too many requests. Please try again shortly.', 'yazan' ), 'error' => array( 'code' => 'rate_limited' ) ), 429 );
		}
		set_transient( $key, $hit + 1, self::HANDOFF_WINDOW );

		// Site-wide daily ceiling. The per-IP limit above bounds a single abuser;
		// this is what bounds a distributed one, since every new IP would
		// otherwise get a fresh allowance of owner emails and CRM POSTs.
		$day_max = (int) apply_filters( 'yazan_ai_handoff_daily_max', self::HANDOFF_DAILY_MAX );
		if ( $day_max > 0 ) {
			$day_key = 'yz_ai_handoff_day_' . gmdate( 'Ymd' );
			$day_hit = (int) get_transient( $day_key );
			if ( $day_hit >= $day_max ) {
				return new WP_REST_Response(
					array( 'ok' => false, 'message' => __( 'Live support has reached today\'s limit. Please try again tomorrow.', 'yazan' ), 'error' => array( 'code' => 'daily_limit' ) ),
					429
				);
			}
			set_transient( $day_key, $day_hit + 1, DAY_IN_SECONDS );
		}

		$name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$note       = sanitize_textarea_field( mb_substr( (string) $request->get_param( 'message' ), 0, 1000 ) );
		$transcript = self::format_transcript( (array) $request->get_param( 'history' ) );

		// Email the owner (recipient override, else the owner-alert address). From-name is branded globally.
		$to = ! empty( $support['email'] )
			? $support['email']
			: ( class_exists( 'Yazan_Core_Notifications' ) ? Yazan_Core_Notifications::owner_email() : get_option( 'admin_email' ) );
		if ( $to ) {
			wp_mail(
				$to,
				__( 'YAZAN Concierge — a shopper would like to talk', 'yazan' ),
				self::handoff_email_html( $name, $email, $note, $transcript ),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}

		// Optional CRM webhook (admin-configured + https-validated on save).
		if ( ! empty( $support['crm_webhook_url'] ) ) {
			wp_remote_post(
				$support['crm_webhook_url'],
				array(
					'timeout' => 8,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'source'     => 'yazan-concierge',
							'name'       => $name,
							'email'      => $email,
							'message'    => $note,
							'transcript' => $transcript,
							'site'       => home_url( '/' ),
						)
					),
				)
			);
		}

		// WhatsApp deep link, built server-side from the stored number (empty when unconfigured).
		$wa = '';
		if ( ! empty( $support['whatsapp'] ) ) {
			$prefill = $name
				/* translators: %s: shopper's name. */
				? sprintf( __( 'Hello YAZAN, this is %s. I was speaking with your concierge and would like some help.', 'yazan' ), $name )
				: __( 'Hello YAZAN, I was speaking with your concierge and would like some help.', 'yazan' );
			$wa = 'https://wa.me/' . rawurlencode( $support['whatsapp'] ) . '?text=' . rawurlencode( $prefill );
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'message'      => __( 'Thank you — our team has your conversation and will reach out. You can also continue on WhatsApp.', 'yazan' ),
				'whatsapp_url' => $wa,
			),
			200
		);
	}

	/**
	 * Flatten a chat history array into a readable plain-text transcript (last 20 turns, capped).
	 *
	 * @param array $history [{role,content}].
	 * @return string
	 */
	private static function format_transcript( array $history ) {
		$lines = array();
		foreach ( array_slice( $history, -20 ) as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = ( 'assistant' === ( $turn['role'] ?? '' ) ) ? 'Concierge' : 'Shopper';
			$text = trim( wp_strip_all_tags( (string) ( $turn['content'] ?? '' ) ) );
			if ( '' !== $text ) {
				$lines[] = $role . ': ' . mb_substr( $text, 0, 1000 );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Branded HTML body for the handoff email. Every dynamic value is escaped.
	 *
	 * @param string $name       Shopper name.
	 * @param string $email      Shopper email.
	 * @param string $note       Optional note.
	 * @param string $transcript Plain-text transcript.
	 * @return string
	 */
	private static function handoff_email_html( $name, $email, $note, $transcript ) {
		$rows = '';
		if ( '' !== $name ) {
			$rows .= '<p><strong>' . esc_html__( 'Name', 'yazan' ) . ':</strong> ' . esc_html( $name ) . '</p>';
		}
		if ( '' !== $email ) {
			$rows .= '<p><strong>' . esc_html__( 'Email', 'yazan' ) . ':</strong> ' . esc_html( $email ) . '</p>';
		}
		if ( '' !== $note ) {
			$rows .= '<p><strong>' . esc_html__( 'Message', 'yazan' ) . ':</strong> ' . esc_html( $note ) . '</p>';
		}
		return '<div style="font-family:Arial,Helvetica,sans-serif;color:#141210">'
			. '<h2 style="font-weight:600;margin:0 0 12px">' . esc_html__( 'A shopper would like to talk', 'yazan' ) . '</h2>'
			. $rows
			. '<h3 style="margin:16px 0 6px">' . esc_html__( 'Conversation', 'yazan' ) . '</h3>'
			. '<pre style="white-space:pre-wrap;background:#f5f1e9;padding:12px;border:1px solid #e0d9cc;font-family:inherit">' . esc_html( $transcript ) . '</pre>'
			. '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Direct connection IP (proxy headers are spoofable and not trusted).
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Resolve the image input: a `media_id` (preferred — turned into a data URI server-side), or a
	 * direct `image` data-uri/URL. Using the attachment id keeps large base64 out of the request body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private static function resolve_image( WP_REST_Request $request ) {
		$media_id = absint( $request->get_param( 'media_id' ) );
		if ( $media_id ) {
			$path = get_attached_file( $media_id );
			if ( ! $path || ! file_exists( $path ) ) {
				return new WP_Error( 'yazan_invalid', __( 'That media item was not found.', 'yazan' ), array( 'status' => 400 ) );
			}
			$mime = get_post_mime_type( $media_id );
			if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
				return new WP_Error( 'yazan_invalid', __( 'The selected media is not an image.', 'yazan' ), array( 'status' => 400 ) );
			}
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $bytes ) {
				return new WP_Error( 'yazan_invalid', __( 'The image could not be read.', 'yazan' ), array( 'status' => 400 ) );
			}
			return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
		}

		$image = (string) $request->get_param( 'image' );
		if ( '' !== $image ) {
			return $image;
		}

		return new WP_Error( 'yazan_invalid', __( 'Provide a media_id or an image.', 'yazan' ), array( 'status' => 400 ) );
	}

	/**
	 * Turn a pipeline result into an HTTP response (maps the error status when the gateway set one).
	 *
	 * @param array $result Pipeline result with ok/error.
	 * @return WP_REST_Response
	 */
	private static function respond( array $result ) {
		if ( empty( $result['ok'] ) ) {
			$status = isset( $result['error']['status'] ) ? (int) $result['error']['status'] : 0;
			// Client-side validation failures are 400; provider outages are 5xx.
			if ( ! $status ) {
				$code   = $result['error']['code'] ?? '';
				$status = in_array( $code, array( 'missing_image', 'not_found', 'unparsable' ), true ) ? 400 : 502;
			}
			// The shared REST client reads a TOP-LEVEL string `message` (falling back to `error`) to
			// build its thrown error. Our `error` is an object, so surface the string here too —
			// otherwise the toast shows "[object Object]".
			$result['message'] = $result['error']['message'] ?? __( 'AI request failed.', 'yazan' );
			$result['code']    = $result['error']['code'] ?? 'error';
			return new WP_REST_Response( $result, $status );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Provider metadata for the settings UI (labels + default model ids). No secrets.
	 *
	 * @return array
	 */
	private static function provider_meta() {
		$out    = array();
		$models = Yazan_AI_Models::default_map();
		foreach ( Yazan_AI_Settings::PROVIDERS as $id ) {
			$adapter = Yazan_AI_Router::provider( $id );
			$out[]   = array(
				'id'              => $id,
				'label'          => $adapter ? $adapter->label() : $id,
				'default_models' => $models[ $id ] ?? array(),
			);
		}
		return $out;
	}

	/**
	 * This-month usage snapshot for the header/budget widget.
	 *
	 * @return array
	 */
	private static function usage_snapshot() {
		$since  = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$totals = Yazan_AI_Log::totals_since( $since );
		return array(
			'month_cost'   => round( (float) $totals['cost'], 4 ),
			'month_calls'  => (int) $totals['count'],
			'month_budget' => (float) Yazan_AI_Settings::get( 'monthly_budget', 0 ),
		);
	}
}
