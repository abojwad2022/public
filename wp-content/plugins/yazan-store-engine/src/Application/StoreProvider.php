<?php
/**
 * Wires the engine's object graph.
 *
 * One file that names every dependency, so the graph can be read rather than traced. Everything is
 * a singleton: these objects are stateless apart from StoreContext's per-request memo, and a second
 * StoreContext would mean a second memo that the first one's flush() could not reach.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Container;
use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Core\Database\Database;
use Yazan\Stores\Core\Events\EventBus;
use Yazan\Stores\Core\Hooks\HookLoader;
use Yazan\Stores\Infrastructure\HostmapProjector;
use Yazan\Stores\Infrastructure\StoreBackfill;
use Yazan\Stores\Infrastructure\StoreRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service registration.
 */
final class StoreProvider {

	/**
	 * Bind everything.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public function register( Container $c ): void {
		$c->singleton( Database::class, fn(): Database => new Database() );
		$c->singleton( EventBus::class, fn(): EventBus => new EventBus() );
		$c->singleton( HookLoader::class, fn(): HookLoader => new HookLoader() );

		$c->singleton(
			StoreRepository::class,
			fn( Container $c ): StoreRepository => new StoreRepository( $c->get( Database::class ) )
		);

		/*
		 * The interface is what the application layer asks for; the concrete class is named once,
		 * here. That is the whole of the dependency-inversion story — swapping the repository (for
		 * a cached reader, or for per-site tables under multisite) is this one line.
		 */
		$c->singleton(
			StoreRepositoryInterface::class,
			fn( Container $c ): StoreRepositoryInterface => $c->get( StoreRepository::class )
		);

		$c->singleton(
			StoreBackfill::class,
			fn( Container $c ): StoreBackfill => new StoreBackfill( $c->get( Database::class ) )
		);

		$c->singleton(
			HostmapProjector::class,
			fn( Container $c ): HostmapProjector => new HostmapProjector(
				$c->get( StoreRepositoryInterface::class ),
				$c->get( EventBus::class )
			)
		);

		$c->singleton(
			StoreResolver::class,
			fn( Container $c ): StoreResolver => new StoreResolver( $c->get( StoreRepositoryInterface::class ) )
		);

		$c->singleton( StoreModules::class, fn(): StoreModules => new StoreModules() );

		$c->singleton(
			StoreContext::class,
			fn( Container $c ): StoreContext => new StoreContext(
				$c->get( StoreRepositoryInterface::class ),
				$c->get( StoreModules::class )
			)
		);

		$c->singleton(
			StoreAdminContext::class,
			fn( Container $c ): StoreAdminContext => new StoreAdminContext( $c->get( StoreRepositoryInterface::class ) )
		);

		$c->singleton(
			StoreService::class,
			fn( Container $c ): StoreService => new StoreService(
				$c->get( StoreRepositoryInterface::class ),
				$c->get( HostmapProjector::class ),
				$c->get( EventBus::class )
			)
		);

		$c->singleton(
			StoreMiddleware::class,
			fn( Container $c ): StoreMiddleware => new StoreMiddleware(
				$c->get( StoreContext::class ),
				$c->get( HostmapProjector::class )
			)
		);
	}
}
