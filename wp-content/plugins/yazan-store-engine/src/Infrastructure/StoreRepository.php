<?php
/**
 * wpdb-backed store persistence.
 *
 * The only class in the engine that knows SQL exists. Everything above it talks to
 * StoreRepositoryInterface, which is what lets StoreService be reasoned about — and tested —
 * without a database.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Infrastructure;

use Yazan\Stores\Core\Contracts\Installable;
use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Core\Database\AbstractRepository;
use Yazan\Stores\Domain\Store\DomainType;
use Yazan\Stores\Domain\Store\Store;
use Yazan\Stores\Domain\Store\StoreStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, their domains and their settings.
 */
final class StoreRepository extends AbstractRepository implements StoreRepositoryInterface, Installable {

	protected function table_name(): string {
		return 'stores';
	}

	private function domains_table(): string {
		return $this->db->table( 'domains' );
	}

	private function settings_table(): string {
		return $this->db->table( 'settings' );
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Three tables, and the split is deliberate.
	 *
	 * Domains are a separate table because ONE STORE CAN HAVE SEVERAL ADDRESSES AT ONCE — a
	 * subdomain, a custom domain the owner bought, and a path — and a `host` column on `stores`
	 * would have forced exactly one. Settings are separate because they are sparse and open-ended;
	 * a column per setting would mean an ALTER per feature.
	 *
	 * dbDelta formatting is picky: two spaces after PRIMARY KEY, lowercase types, KEY not INDEX,
	 * one definition per line.
	 *
	 * @param string $charset_collate Collation clause.
	 * @return string[]
	 */
	public function schema( string $charset_collate ): array {
		$stores   = $this->table();
		$domains  = $this->domains_table();
		$settings = $this->settings_table();

		$objects      = $this->db->table( 'object_map' );
		$certificates = $this->db->table( 'certificates' );

		return array(
			/*
			 * `uuid` is a stable external identity that survives a database rebuild, a restore into
			 * a different environment, and an id sequence that starts again from 1. The numeric id
			 * stays the internal key — it is smaller, it indexes better, and every foreign column
			 * already references it — but anything that leaves this database (an API payload, a
			 * webhook, a mobile client's cached record) should carry the uuid instead.
			 *
			 * `languages` is a comma-separated list rather than a join table: it is read whole,
			 * never queried by member, and a table for it would be three joins to answer "what
			 * languages does this store speak".
			 *
			 * Addresses are NOT here. A store can hold a subdomain AND a custom domain AND a path
			 * at once, so `subdomain`/`custom_domain` columns would force exactly one of each and
			 * contradict the domains table that already models them properly.
			 */
			"CREATE TABLE {$stores} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	uuid char(36) NOT NULL DEFAULT '',
	slug varchar(64) NOT NULL DEFAULT '',
	name varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'draft',
	type varchar(20) NOT NULL DEFAULT 'owned',
	parent_store_id bigint(20) unsigned NOT NULL DEFAULT 0,
	currency char(3) NOT NULL DEFAULT '',
	locale varchar(20) NOT NULL DEFAULT '',
	languages varchar(191) NOT NULL DEFAULT '',
	timezone varchar(64) NOT NULL DEFAULT '',
	theme varchar(64) NOT NULL DEFAULT '',
	logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
	owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug),
	KEY uuid (uuid),
	KEY status (status),
	KEY parent_store_id (parent_store_id)
) {$charset_collate};",

			/*
			 * Tenancy for the entities WordPress and WooCommerce own.
			 *
			 * `wp_posts`, `wp_users`, `wp_terms` and `wp_wc_orders` cannot take a `store_id` column
			 * — altering them breaks core upgrades, WP_Query, and HPOS's own schema tooling. This
			 * table carries the same information from the outside.
			 *
			 * UNIQUE is (object_type, object_id, store_id) and NOT (object_type, object_id),
			 * because this models MEMBERSHIP rather than ownership: a customer may legitimately
			 * shop at several stores, and a product may be listed by more than one. A two-column
			 * key would have quietly made both impossible.
			 */
			"CREATE TABLE {$objects} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	store_id bigint(20) unsigned NOT NULL DEFAULT 1,
	object_type varchar(32) NOT NULL DEFAULT '',
	object_id bigint(20) unsigned NOT NULL DEFAULT 0,
	is_primary tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY object_store (object_type,object_id,store_id),
	KEY store_type (store_id,object_type),
	KEY object_lookup (object_type,object_id)
) {$charset_collate};",

			/*
			 * Certificates: the first real storage this concept has had.
			 *
			 * A ring's certificate was a projection computed at read time from `_yz_serial` in
			 * post meta, and serial uniqueness was enforced only by two PHP checks in two REST
			 * controllers — nothing at the database level. That was survivable with one store. With
			 * several it is not: two stores minting the same serial is exactly the collision the
			 * PHP checks cannot see, because they each query their own scope.
			 *
			 * UNIQUE (store_id, serial) makes the database the authority. The meta key stays where
			 * it is, so every existing reader keeps working.
			 */
			"CREATE TABLE {$certificates} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	store_id bigint(20) unsigned NOT NULL DEFAULT 1,
	serial varchar(64) NOT NULL DEFAULT '',
	product_id bigint(20) unsigned NOT NULL DEFAULT 0,
	certificate_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
	qr_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'draft',
	issued_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY store_serial (store_id,serial),
	KEY product_id (product_id),
	KEY store_status (store_id,status)
) {$charset_collate};",

			/*
			 * UNIQUE (host, path) rather than UNIQUE (host): a path-routed store shares the
			 * platform host with every other path-routed store, so host alone would allow exactly
			 * one of them. The pair is what makes all three addressing modes coexist in one table.
			 */
			"CREATE TABLE {$domains} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	store_id bigint(20) unsigned NOT NULL DEFAULT 0,
	host varchar(191) NOT NULL DEFAULT '',
	path varchar(191) NOT NULL DEFAULT '',
	type varchar(20) NOT NULL DEFAULT 'subdomain',
	is_primary tinyint(1) NOT NULL DEFAULT 0,
	is_verified tinyint(1) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY host_path (host,path),
	KEY store_id (store_id),
	KEY type (type)
) {$charset_collate};",

			"CREATE TABLE {$settings} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	store_id bigint(20) unsigned NOT NULL DEFAULT 0,
	setting_key varchar(191) NOT NULL DEFAULT '',
	setting_value longtext,
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY store_key (store_id,setting_key),
	KEY store_id (store_id)
) {$charset_collate};",
		);
	}

	/** Are all three tables present? */
	public function is_installed(): bool {
		$wpdb = $this->wpdb();

		foreach ( $this->db->tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Stores                                                              */
	/* ------------------------------------------------------------------ */

	public function find( int $id ): ?Store {
		if ( $id <= 0 ) {
			return null;
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? Store::from_array( $row ) : null;
	}

	public function find_by_slug( string $slug ): ?Store {
		$slug = strtolower( trim( $slug ) );

		if ( '' === $slug ) {
			return null;
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );

		return $row ? Store::from_array( $row ) : null;
	}

	/**
	 * @param bool $active_only Only stores that may serve requests.
	 * @return Store[]
	 */
	public function all( bool $active_only = false ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 500", StoreStatus::ACTIVE ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 500", ARRAY_A );
		}

		return array_map( array( Store::class, 'from_array' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return int New id, or 0 on failure.
	 */
	public function insert( Store $store ): int {
		$wpdb = $this->wpdb();
		$now  = current_time( 'mysql', true );
		$data = $store->to_array();

		unset( $data['id'] );
		$data['created_at'] = '' !== $data['created_at'] ? $data['created_at'] : $now;
		$data['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $this->table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update( Store $store ): bool {
		if ( ! $store->exists() ) {
			return false;
		}

		$wpdb = $this->wpdb();
		$data = $store->to_array();
		$id   = (int) $data['id'];

		unset( $data['id'], $data['created_at'] );
		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Domains                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function domains( int $store_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->domains_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE store_id = %d ORDER BY is_primary DESC, id ASC", $store_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every domain of every ACTIVE store, for the hostmap projection.
	 *
	 * Joined rather than two queries so a domain belonging to a suspended store can never reach the
	 * map — suspension has to take a store off the web, and the join is what guarantees it rather
	 * than a filter someone might forget downstream.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_domains(): array {
		$wpdb    = $this->wpdb();
		$domains = $this->domains_table();
		$stores  = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.* FROM {$domains} d
				 INNER JOIN {$stores} s ON s.id = d.store_id
				 WHERE s.status = %s
				 ORDER BY d.store_id ASC, d.is_primary DESC, d.id ASC
				 LIMIT 2000",
				StoreStatus::ACTIVE
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function add_domain( int $store_id, string $host, string $path, string $type, bool $is_primary ): int {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$this->domains_table(),
			array(
				'store_id'    => $store_id,
				'host'        => strtolower( $host ),
				'path'        => $path,
				'type'        => DomainType::is_valid( $type ) ? $type : DomainType::SUBDOMAIN,
				'is_primary'  => $is_primary ? 1 : 0,
				'is_verified' => 0,
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function remove_domain( int $domain_id ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( $this->domains_table(), array( 'id' => $domain_id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<string,string>
	 */
	public function settings( int $store_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->settings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT setting_key, setting_value FROM {$table} WHERE store_id = %d LIMIT 1000", $store_id ),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['setting_key'] ] = (string) $row['setting_value'];
		}

		return $out;
	}

	/**
	 * Upsert one setting.
	 *
	 * REPLACE rather than SELECT-then-INSERT/UPDATE: the unique key (store_id, setting_key) makes
	 * the database arbitrate, so two concurrent writers cannot both decide the row is absent and
	 * both insert.
	 */
	public function set_setting( int $store_id, string $key, string $value ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->replace(
			$this->settings_table(),
			array(
				'store_id'      => $store_id,
				'setting_key'   => $key,
				'setting_value' => $value,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}
