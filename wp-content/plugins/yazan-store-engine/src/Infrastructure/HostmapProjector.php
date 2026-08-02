<?php
/**
 * Projects the store registry into the option the tenancy kernel reads.
 *
 * THIS CLASS IS THE ENTIRE INTEGRATION WITH Yazan_Store_Context, AND THE DIRECTION MATTERS.
 *
 * The kernel (wp-content/mu-plugins/yazan-store-context.php) resolves the active store before any
 * plugin loads, and it does so by reading one autoloaded option — `yazan_store_hostmap` — as a
 * plain array lookup. It cannot query this plugin's tables, and the constraint is not stylistic:
 * resolution runs inside the `user_has_cap` path, where any capability API call re-enters the
 * filter and recurses until the process dies, and it runs on every request including wp-login.php.
 *
 * So the registry does not answer the kernel's questions at request time. It ANSWERS THEM IN
 * ADVANCE, by rewriting the option whenever the registry changes. Reads stay O(1) and query-free;
 * writes pay the cost, and writes are rare.
 *
 * The projection is a full rebuild rather than an incremental patch. A store that was deleted, a
 * domain that was removed, a store that was suspended — each of those has to REMOVE an entry, and
 * an incremental projector is exactly where a stale entry survives and keeps a suspended store
 * reachable.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Infrastructure;

use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Core\Events\EventBus;
use Yazan\Stores\Core\Events\Events;
use Yazan\Stores\Domain\Store\DomainType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry → `yazan_store_hostmap`.
 */
final class HostmapProjector {

	/** Must match Yazan_Store_Context::HOSTMAP_OPTION. */
	public const OPTION = 'yazan_store_hostmap';

	public function __construct(
		private StoreRepositoryInterface $repository,
		private EventBus $events
	) {}

	/**
	 * Rebuild the map from the registry.
	 *
	 * Only ACTIVE stores appear — `all_domains()` joins on status, so suspending a store removes it
	 * from the web on the next projection without deleting anything.
	 *
	 * Only HOST-BASED domains appear. The kernel's ladder looks a store up by Host header alone; a
	 * path row has no host to key on and would either overwrite the platform host's entry or be
	 * silently ignored. Path resolution belongs to StoreResolver, which sees the full request.
	 *
	 * @return array<string,int> The map that was written.
	 */
	public function project(): array {
		$map = array();

		foreach ( $this->repository->all_domains() as $row ) {
			$type = (string) ( $row['type'] ?? '' );

			if ( ! DomainType::is_host_based( $type ) ) {
				continue;
			}

			$host = strtolower( trim( (string) ( $row['host'] ?? '' ) ) );

			if ( '' === $host ) {
				continue;
			}

			/*
			 * First writer wins. Rows arrive ordered primary-first, so a store's primary domain
			 * decides the mapping and a duplicate host registered later cannot steal it. The
			 * UNIQUE (host, path) key makes a true duplicate impossible anyway; this is the
			 * belt to that braces.
			 */
			if ( ! isset( $map[ $host ] ) ) {
				$map[ $host ] = (int) $row['store_id'];
			}
		}

		/*
		 * NEVER WRITE AN EMPTY MAP.
		 *
		 * The kernel falls back to synthesising `[ home_url() host => 1 ]` when the option is
		 * absent or empty. Writing an empty array would be indistinguishable from "not configured"
		 * and would silently reinstate the fallback — which is fine today and catastrophic the day
		 * the fallback rung is removed, because every request would 404. Leaving the option absent
		 * is the honest representation of "the registry has nothing to say yet".
		 */
		if ( array() === $map ) {
			delete_option( self::OPTION );
			$this->events->emit( Events::HOSTMAP_PROJECTED, array( 'map' => array(), 'count' => 0 ) );

			return array();
		}

		// Autoloaded: the kernel reads it on every request, so it must live in alloptions.
		update_option( self::OPTION, $map, true );

		$this->events->emit( Events::HOSTMAP_PROJECTED, array( 'map' => $map, 'count' => count( $map ) ) );

		return $map;
	}

	/**
	 * The map as currently stored.
	 *
	 * @return array<string,int>
	 */
	public function current(): array {
		$map = get_option( self::OPTION, array() );

		return is_array( $map ) ? $map : array();
	}
}
