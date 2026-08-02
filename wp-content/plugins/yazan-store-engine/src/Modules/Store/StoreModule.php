<?php
/**
 * The store module: binds the engine and attaches its hooks.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Modules\Store;

use Yazan\Stores\Application\StoreMiddleware;
use Yazan\Stores\Application\StoreProvider;
use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Contracts\Installable;
use Yazan\Stores\Core\Hooks\HookLoader;
use Yazan\Stores\Core\Module\AbstractModule;
use Yazan\Stores\Infrastructure\HostmapProjector;
use Yazan\Stores\Infrastructure\StoreRepository;
use Yazan\Stores\Rest\V1\StoresController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the store engine.
 */
final class StoreModule extends AbstractModule implements Installable {

	public function id(): string {
		return 'store';
	}

	public function register( Container $container ): void {
		( new StoreProvider() )->register( $container );
	}

	public function boot( Container $container ): void {
		$container->get( HookLoader::class )->register( $container->get( StoreMiddleware::class ) );

		add_action(
			'rest_api_init',
			static function () use ( $container ) {
				( new StoresController( $container ) )->register_routes();
			}
		);
	}

	/**
	 * Schema is delegated to the repository, which owns the tables it reads.
	 *
	 * @param string $charset_collate Collation.
	 * @return string[]
	 */
	public function schema( string $charset_collate ): array {
		return array();
	}

	/**
	 * Seed the platform store and project the map.
	 *
	 * MUST BE IDEMPOTENT — the migrator re-runs every module's activate() on each version bump.
	 * Every step below is therefore a check-then-act against a unique key.
	 *
	 * Store 1 is seeded from home_url() so the registry agrees with what the tenancy kernel already
	 * synthesises when the option is absent. If those two disagreed, installing this plugin would
	 * change which store a request resolves to — which is precisely the behaviour change this phase
	 * promises not to make.
	 */
	public function activate( Container $container ): void {
		$repository = $container->get( StoreRepository::class );

		if ( ! $repository->is_installed() ) {
			return;
		}

		$service = $container->get( \Yazan\Stores\Application\StoreService::class );
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$slug    = 'platform';

		$store = $repository->find_by_slug( $slug );

		if ( null === $store ) {
			$created = $service->create(
				array(
					'slug'   => $slug,
					'name'   => get_bloginfo( 'name' ) ?: __( 'Platform store', 'yazan-stores' ),
					'status' => \Yazan\Stores\Domain\Store\StoreStatus::ACTIVE,
					'type'   => \Yazan\Stores\Domain\Store\StoreType::OWNED,
				)
			);

			if ( is_wp_error( $created ) ) {
				return;
			}

			$store = $created;
		}

		if ( '' !== $host ) {
			$has_host = false;

			foreach ( $service->domains( $store->id() ) as $domain ) {
				if ( strtolower( (string) $domain['host'] ) === strtolower( $host ) ) {
					$has_host = true;
					break;
				}
			}

			if ( ! $has_host ) {
				$service->add_domain(
					$store->id(),
					$host,
					'',
					\Yazan\Stores\Domain\Store\DomainType::SUBDOMAIN,
					true
				);
			}
		}

		$container->get( HostmapProjector::class )->project();
	}
}
