<?php
/**
 * Yazan AI — subsystem bootstrap.
 *
 * Loads every AI class in dependency order and registers the AI REST routes. Kept as one entry point
 * so the plugin's dashboard boot only gains a single require + init() call. Mirrors
 * {@see Yazan_Dashboard_Boot} exactly.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the AI gateway, providers, pipelines, and REST controller.
 */
class Yazan_AI_Boot {

	/**
	 * Require classes and register hooks. Call once at plugin load.
	 *
	 * @return void
	 */
	public static function init() {
		$ai = YAZAN_CORE_DIR . 'includes/ai/';

		// Infrastructure.
		require_once $ai . 'class-yazan-ai-exception.php';
		require_once $ai . 'class-yazan-ai-http.php';
		require_once $ai . 'class-yazan-ai-image.php';
		require_once $ai . 'class-yazan-ai-secrets.php';
		require_once $ai . 'class-yazan-ai-models.php';
		require_once $ai . 'class-yazan-ai-settings.php';
		require_once $ai . 'class-yazan-ai-cache.php';
		require_once $ai . 'class-yazan-ai-log.php';
		require_once $ai . 'class-yazan-ai-budget.php';
		require_once $ai . 'class-yazan-ai-prompts.php';
		require_once $ai . 'class-yazan-ai-knowledge.php';

		// Providers (interfaces + base BEFORE adapters that implement/extend them).
		require_once $ai . 'providers/interface-yazan-ai-provider.php';
		require_once $ai . 'providers/interface-yazan-ai-image-provider.php';
		require_once $ai . 'providers/abstract-yazan-ai-provider-openai.php';
		require_once $ai . 'providers/class-yazan-ai-provider-openrouter.php';
		require_once $ai . 'providers/class-yazan-ai-provider-openai.php';
		require_once $ai . 'providers/class-yazan-ai-provider-groq.php';
		require_once $ai . 'providers/class-yazan-ai-provider-claude.php';
		require_once $ai . 'providers/class-yazan-ai-provider-gemini.php';

		// Routing + gateway + remote AI Core client (Phase 3 extraction seam).
		require_once $ai . 'class-yazan-ai-router.php';
		require_once $ai . 'class-yazan-ai-fields-writer.php';
		require_once $ai . 'class-yazan-ai-media.php';
		require_once $ai . 'class-yazan-ai-core-client.php';
		require_once $ai . 'class-yazan-ai-gateway.php';

		// Pipelines.
		require_once $ai . 'pipelines/class-yazan-ai-product-context.php';
		require_once $ai . 'pipelines/class-yazan-ai-product.php';
		require_once $ai . 'pipelines/class-yazan-ai-seo.php';
		require_once $ai . 'pipelines/class-yazan-ai-marketing.php';
		require_once $ai . 'pipelines/class-yazan-ai-analytics.php';
		require_once $ai . 'pipelines/class-yazan-ai-chat.php';
		require_once $ai . 'pipelines/class-yazan-ai-gallery.php';

		// REST controller.
		require_once YAZAN_CORE_DIR . 'includes/dashboard/rest/class-yazan-rest-ai.php';

		// House Knowledge: CPT + prompt-filter injection (feeds concierge + every content pipeline).
		Yazan_AI_Knowledge::init();

		add_action(
			'rest_api_init',
			static function () {
				Yazan_REST_AI::register_routes();
			}
		);
	}

	/**
	 * Create the AI generation-log table. Called from {@see Yazan_Core_Installer::install()}.
	 *
	 * @return void
	 */
	public static function install() {
		if ( class_exists( 'Yazan_AI_Log' ) ) {
			Yazan_AI_Log::install_table();
		}
	}
}
