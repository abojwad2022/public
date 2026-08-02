<?php
/**
 * Public REST API module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\PublicApi;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Plugin;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Rest\PublicV1\CampaignController;
use Yazan\Rewards\Rest\PublicV1\CustomerController;
use Yazan\Rewards\Rest\PublicV1\RewardController;
use Yazan\Rewards\Rest\PublicV1\StatisticsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the stable public `yazan/v1` REST surface — a thin facade over the
 * platform's own services (points, profile, campaign submission, reward redemption,
 * statistics). Owns no tables and binds no services; the controllers resolve the
 * existing container-bound services lazily at `rest_api_init`. Gated by the
 * `public_api` feature flag so the whole surface can be switched off.
 */
final class PublicApiModule extends AbstractModule {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'public_api';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		// No bindings — the controllers reuse services bound by other modules.
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		if ( ! $c->get( Settings::class )->feature_enabled( 'public_api' ) ) {
			return;
		}
		$rest = $c->get( RestBootstrap::class );

		$controllers = array(
			CustomerController::class,
			CampaignController::class,
			RewardController::class,
			StatisticsController::class,
		);

		foreach ( $controllers as $controller ) {
			// The real home: a namespace this plugin owns.
			$rest->add( static fn( Container $c ) => new $controller( $c ) );

			/*
			 * Compatibility alias on the old shared namespace.
			 *
			 * Same controller, same services, same permission callbacks — only the namespace
			 * differs — so the two can never drift. Storefront JS and any third-party consumer
			 * still calling /yazan/v1/customer/points keeps working through the deprecation
			 * window. Delete this line (and Plugin::LEGACY_PUBLIC_REST_NS) to end it.
			 */
			$rest->add( static fn( Container $c ) => new $controller( $c, Plugin::LEGACY_PUBLIC_REST_NS ) );
		}
	}
}
