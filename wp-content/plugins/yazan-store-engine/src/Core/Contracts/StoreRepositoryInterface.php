<?php
/**
 * The port through which the application layer reaches stores.
 *
 * Exists so StoreService depends on an abstraction rather than on wpdb (DIP). It is also what
 * makes the service testable without a database, and what would let the storage move — to a
 * cache-backed reader, or to per-site tables under multisite — without the service noticing.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Contracts;

use Yazan\Stores\Domain\Store\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store persistence port.
 */
interface StoreRepositoryInterface {

	public function find( int $id ): ?Store;

	public function find_by_slug( string $slug ): ?Store;

	/** @return Store[] */
	public function all( bool $active_only = false ): array;

	/** @return int New store id. */
	public function insert( Store $store ): int;

	public function update( Store $store ): bool;

	/** Domains for a store, ordered primary first. @return array<int,array<string,mixed>> */
	public function domains( int $store_id ): array;

	/** Every domain row across every store. @return array<int,array<string,mixed>> */
	public function all_domains(): array;

	public function add_domain( int $store_id, string $host, string $path, string $type, bool $is_primary ): int;

	public function remove_domain( int $domain_id ): bool;

	/** @return array<string,string> setting_key => value */
	public function settings( int $store_id ): array;

	public function set_setting( int $store_id, string $key, string $value ): bool;
}
