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
			/*
			 * Three outcomes, not two. The old binary branch emitted STORE_SUSPENDED for an archive,
			 * so a listener could not tell "temporarily off the web, will return" from "finished".
			 * Those warrant different handling — a suspension might page someone; an archive should not.
			 */
			if ( $updated->is_active() ) {
				$event = Events::STORE_ACTIVATED;
			} elseif ( StoreStatus::ARCHIVED === $updated->status() ) {
				$event = Events::STORE_ARCHIVED;
			} else {
				$event = Events::STORE_SUSPENDED;
			}

			$this->events->emit( $event, array( 'store' => $updated ) );
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
	 * Copy a store's CONFIGURATION into a new one.
	 *
	 * ⚠️ ADDRESSES ARE NEVER COPIED. `UNIQUE (host, path)` would reject them anyway, but the deeper
	 * reason is that a clone inheriting an address would either steal traffic from its source or
	 * fail half-way through leaving both stores broken. A clone is born addressless and draft, and
	 * someone has to give it a home deliberately.
	 *
	 * Catalogue membership is not copied either: nothing reads `object_map` yet, so copying it would
	 * write rows whose effect is invisible and therefore unverifiable.
	 *
	 * @param int    $source_id Store to copy.
	 * @param string $slug      New slug.
	 * @param string $name      New name.
	 * @return Store|\WP_Error
	 */
	public function clone_store( int $source_id, string $slug, string $name ) {
		$source = $this->repository->find( $source_id );

		if ( null === $source ) {
			return new \WP_Error( 'yazan_store_not_found', __( 'That store does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		$data = $source->to_array();

		// A clone is a new identity, never reachable, and never live until someone says so.
		$data['id']     = 0;
		$data['uuid']   = '';
		$data['slug']   = $slug;
		$data['name']   = $name;
		$data['status'] = StoreStatus::DRAFT;

		unset( $data['created_at'], $data['updated_at'] );

		$created = $this->create( $data );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		foreach ( $this->repository->settings( $source_id ) as $key => $value ) {
			$this->repository->set_setting( $created->id(), (string) $key, (string) $value );
		}

		$this->events->emit( Events::STORE_CLONED, array( 'store' => $created, 'source_id' => $source_id ) );

		return $created;
	}

	/**
	 * Take a store off the web permanently.
	 *
	 * Archive rather than delete: orders, ledgers and audit rows reference the store id, and a hard
	 * delete would orphan every one of them. Reversible by an explicit status change.
	 *
	 * @param int $id Store.
	 * @return Store|\WP_Error
	 */
	public function archive( int $id ) {
		return $this->update( $id, array( 'status' => StoreStatus::ARCHIVED ) );
	}

	/**
	 * Write several settings at once.
	 *
	 * @param int                  $store_id Store.
	 * @param array<string,string> $settings key => value.
	 * @return int Rows written.
	 */
	public function set_settings( int $store_id, array $settings ): int {
		$written = 0;

		foreach ( $settings as $key => $value ) {
			if ( $this->set_setting( $store_id, (string) $key, (string) $value ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Staff of a store, with the roles they hold THERE.
	 *
	 * Delegates to yazan-core's RBAC rather than keeping a second membership list — two sources of
	 * truth for "who may do what" is the one thing an authorisation system must not have.
	 *
	 * @param int $store_id Store.
	 * @return array<int,array<string,mixed>>
	 */
	public function users( int $store_id ): array {
		if ( ! class_exists( 'Yazan_Roles' ) ) {
			return array();
		}

		$out = array();

		foreach ( \Yazan_Roles::staff_ids( $store_id ) as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'id'      => (int) $user_id,
				'login'   => $user->user_login,
				'name'    => $user->display_name,
				'email'   => $user->user_email,
				'roles'   => array_map( 'intval', \Yazan_Roles::for_user( (int) $user_id, $store_id ) ),
			);
		}

		return $out;
	}

	/**
	 * Replace one user's roles within a store.
	 *
	 * @param int   $store_id Store.
	 * @param int   $user_id  User.
	 * @param int[] $role_ids Roles.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function set_user_roles( int $store_id, int $user_id, array $role_ids ) {
		if ( ! class_exists( 'Yazan_Roles' ) ) {
			return new \WP_Error( 'yazan_stores_no_rbac', __( 'Permissions are unavailable.', 'yazan-stores' ), array( 'status' => 503 ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'yazan_store_user_missing', __( 'That user does not exist.', 'yazan-stores' ), array( 'status' => 404 ) );
		}

		$result = \Yazan_Roles::set_user_roles( $user_id, $role_ids, $store_id );

		$this->events->emit(
			Events::USERS_CHANGED,
			array( 'store_id' => $store_id, 'user_id' => $user_id, 'result' => $result )
		);

		return $result;
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
