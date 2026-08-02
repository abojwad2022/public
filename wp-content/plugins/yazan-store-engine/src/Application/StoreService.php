<?php
/**
 * The write side of the store registry.
 *
 * Every mutation goes through here rather than through the repository directly, because a store
 * write is never JUST a row: creating a store means giving it an address, suspending one means
 * taking it off the web, and both require the hostmap projection to be rebuilt. A caller that
 * reached the repository directly would get a store that exists and cannot be reached, or worse,
 * a suspended store that still serves traffic until something else happens to reproject.
 *
 * The service depends on StoreRepositoryInterface, not on the concrete repository, so it can be
 * exercised without a database.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Core\Events\EventBus;
use Yazan\Stores\Core\Events\Events;
use Yazan\Stores\Domain\Store\DomainType;
use Yazan\Stores\Domain\Store\Store;
use Yazan\Stores\Domain\Store\StoreStatus;
use Yazan\Stores\Infrastructure\HostmapProjector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store lifecycle operations.
 */
final class StoreService {

	public function __construct(
		private StoreRepositoryInterface $repository,
		private HostmapProjector $projector,
		private EventBus $events
	) {}

	/* ------------------------------------------------------------------ */
	/* Reads                                                               */
	/* ------------------------------------------------------------------ */

	public function find( int $id ): ?Store {
		return $this->repository->find( $id );
	}

	public function find_by_slug( string $slug ): ?Store {
		return $this->repository->find_by_slug( $slug );
	}

	/** @return Store[] */
	public function all( bool $active_only = false ): array {
		return $this->repository->all( $active_only );
	}

	/* ------------------------------------------------------------------ */
	/* Writes                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a store.
	 *
	 * Created as DRAFT unless the caller says otherwise. A store with no address yet is not
	 * something that should be reachable, and defaulting to active would mean the window between
	 * "created" and "configured" is a window in which the store is live and broken.
	 *
	 * @param array<string,mixed> $data Store fields.
	 * @return Store|\WP_Error
	 */
	public function create( array $data ) {
		$data['id'] = 0;

		if ( ! isset( $data['status'] ) ) {
			$data['status'] = StoreStatus::DRAFT;
		}

		try {
			$store = Store::from_array( $data );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'yazan_store_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( null !== $this->repository->find_by_slug( $store->slug() ) ) {
			return new \WP_Error(
				'yazan_store_duplicate',
				sprintf(
					/* translators: %s: store slug. */
					__( 'A store with the slug "%s" already exists.', 'yazan-stores' ),
					$store->slug()
				),
				array( 'status' => 409 )
			);
		}

		$id = $this->repository->insert( $store );

		if ( $id <= 0 ) {
			return new \WP_Error( 'yazan_store_write_failed', __( 'The store could not be saved.', 'yazan-stores' ), array( 'status' => 500 ) );
		}

		$store = $store->with_id( $id );

		$this->events->emit( Events::STORE_CREATED, array( 'store' => $store ) );
		$this->reproject();

		return $store;
	}

	/**
	 * Update mutable fields.
	 *
	 * The slug is deliberately NOT updatable. It appears in hostnames and paths, and changing it
	 * would silently break every address already pointing at the store. Renaming is `name`;
	 * re-addressing is add_domain()/remove_domain().
	 *
	 * @param int                 $id      Store id.
	 * @param array<string,mixed> $changes Fields to change.
	 * @return Store|\WP_Error
	 */
	public function update( int $id, array $changes ) {
		$store = $this->repository->find( $id );

		if ( null === $store ) {
			return new \WP_Error( 'yazan_store_not_found', __( 'That store does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		$allowed = array( 'name', 'status', 'type', 'currency', 'locale', 'theme', 'owner_user_id', 'parent_store_id' );
		$data    = $store->to_array();

		foreach ( $changes as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$data[ $key ] = $value;
			}
		}

		try {
			$updated = Store::from_array( $data );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'yazan_store_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( ! $this->repository->update( $updated ) ) {
			return new \WP_Error( 'yazan_store_write_failed', __( 'The store could not be saved.', 'yazan-stores' ), array( 'status' => 500 ) );
		}

		$this->events->emit( Events::STORE_UPDATED, array( 'store' => $updated, 'previous' => $store ) );

		/*
		 * Status changes decide reachability, so the map has to be rebuilt. Rebuilding on every
		 * update rather than only on a status change is the cheaper mistake: the projection is one
		 * query and one option write, and the alternative is a suspended store still serving.
		 */
		$this->reproject();

		if ( $store->status() !== $updated->status() ) {
			$this->events->emit(
				$updated->is_active() ? Events::STORE_ACTIVATED : Events::STORE_SUSPENDED,
				array( 'store' => $updated )
			);
		}

		return $updated;
	}

	/**
	 * Give a store an address.
	 *
	 * @param int    $store_id   Store.
	 * @param string $host       Hostname, or '' for a path row on any host this install answers to.
	 * @param string $path       Path prefix for DomainType::PATH, otherwise ''.
	 * @param string $type       One of DomainType.
	 * @param bool   $is_primary Is this the store's canonical address?
	 * @return int|\WP_Error New domain id.
	 */
	public function add_domain( int $store_id, string $host, string $path = '', string $type = DomainType::SUBDOMAIN, bool $is_primary = false ) {
		if ( null === $this->repository->find( $store_id ) ) {
			return new \WP_Error( 'yazan_store_not_found', __( 'That store does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		if ( ! DomainType::is_valid( $type ) ) {
			return new \WP_Error( 'yazan_domain_invalid', __( 'Unknown address type.', 'yazan-stores' ), array( 'status' => 400 ) );
		}

		$host = StoreResolver::normalise_host( $host );
		$path = DomainType::PATH === $type ? StoreResolver::normalise_path( $path ) : '';

		if ( '' === $host ) {
			/*
			 * EVERY address needs an explicit host, including a path address.
			 *
			 * A path row with a blank host would mean "this prefix, on any domain this install
			 * answers to" — and that is a tenancy hole: store A's `/jewelry` would resolve on
			 * store B's domain, making one store reachable from another store's address. Pinning
			 * the host is what confines a path to the domain it was meant for, and it is also what
			 * lets the resolver prefer host+path over host alone without reopening that hole.
			 */
			return new \WP_Error(
				'yazan_domain_invalid',
				__( 'Every address needs a hostname — including a path address, which is confined to the host it is registered on.', 'yazan-stores' ),
				array( 'status' => 400 )
			);
		}

		if ( DomainType::PATH === $type && ( '' === $path || '/' === $path ) ) {
			return new \WP_Error( 'yazan_domain_invalid', __( 'A path address needs a path segment.', 'yazan-stores' ), array( 'status' => 400 ) );
		}

		$id = $this->repository->add_domain( $store_id, $host, $path, $type, $is_primary );

		if ( $id <= 0 ) {
			// The UNIQUE (host, path) key is what rejects a duplicate, so the database is the
			// arbiter rather than a SELECT that two concurrent callers could both pass.
			return new \WP_Error(
				'yazan_domain_taken',
				__( 'That address is already assigned to a store.', 'yazan-stores' ),
				array( 'status' => 409 )
			);
		}

		$this->events->emit( Events::DOMAIN_ADDED, array( 'store_id' => $store_id, 'host' => $host, 'path' => $path, 'type' => $type ) );
		$this->reproject();

		return $id;
	}

	/**
	 * Remove an address.
	 *
	 * @param int $domain_id Domain row id.
	 * @return bool
	 */
	public function remove_domain( int $domain_id ): bool {
		$ok = $this->repository->remove_domain( $domain_id );

		if ( $ok ) {
			$this->events->emit( Events::DOMAIN_REMOVED, array( 'domain_id' => $domain_id ) );
			$this->reproject();
		}

		return $ok;
	}

	/**
	 * Write one store setting.
	 *
	 * @param int    $store_id Store.
	 * @param string $key      Setting key.
	 * @param string $value    Value.
	 * @return bool
	 */
	public function set_setting( int $store_id, string $key, string $value ): bool {
		$key = trim( $key );

		if ( '' === $key ) {
			return false;
		}

		$ok = $this->repository->set_setting( $store_id, $key, $value );

		if ( $ok ) {
			$this->events->emit( Events::SETTING_CHANGED, array( 'store_id' => $store_id, 'key' => $key ) );
		}

		return $ok;
	}

	/**
	 * Turn a module on or off for a store.
	 *
	 * @param int    $store_id Store.
	 * @param string $module   Module id from the catalogue.
	 * @param bool   $enabled  New state.
	 * @return bool
	 */
	public function set_module( int $store_id, string $module, bool $enabled ): bool {
		return $this->set_setting( $store_id, StoreModules::SETTING_PREFIX . $module, $enabled ? '1' : '0' );
	}

	/** @return array<int,array<string,mixed>> */
	public function domains( int $store_id ): array {
		return $this->repository->domains( $store_id );
	}

	/** @return array<string,string> */
	public function settings( int $store_id ): array {
		return $this->repository->settings( $store_id );
	}

	/**
	 * Rebuild the hostmap the tenancy kernel reads.
	 *
	 * Called after EVERY write that could change reachability. Centralised here so no caller has
	 * to remember it — the one thing that must never be forgotten is the one thing not left to a
	 * caller.
	 */
	private function reproject(): void {
		$this->projector->project();
	}
}
