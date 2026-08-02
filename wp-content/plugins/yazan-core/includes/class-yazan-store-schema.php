<?php
/**
 * The multi-tenant schema migration, as numbered steps.
 *
 * WHY ONE CLASS FOR ALL THREE PLUGINS
 * -----------------------------------
 * The tables belong to yazan-core, yazan-social-rewards and yazan-payment-bridge-lite, and each has
 * its own installer on its own version gate. Spreading twenty related ALTERs across three of them
 * would mean the migration could be half-applied whenever one plugin was deactivated, updated out
 * of step, or gated differently — and "half-applied" here means a UNIQUE key widened on one table
 * and not its sibling, which is a data-integrity failure rather than a cosmetic one.
 *
 * So the DDL lives in one place, runs as one unit, and is gated once. It touches other plugins'
 * tables only by NAME, never by calling into them, so it degrades to a no-op when a plugin is
 * absent rather than fataling.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * Nothing here reads `store_id`. The columns are added, defaulted to store 1, indexed — and then
 * ignored by every query in the system. That is deliberate: the schema and the query layer are
 * separate migrations, and doing them together means a bug in either is indistinguishable from a
 * bug in the other.
 *
 * ⚠️ WordPress and WooCommerce tables are NEVER altered. `wp_posts`, `wp_users`, `wp_terms`,
 * `wp_wc_orders` and friends carry their tenancy in {@see Yazan_Store_Object_Map} instead.
 *
 * @package Yazan_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the tenant dimension to every Yazan-owned table.
 */
class Yazan_Store_Schema {

	/** Bump to re-run the migration. */
	const VERSION = '1';

	/** Option holding the applied version. */
	const VERSION_OPTION = 'yazan_store_schema_version';

	/** Transient guarding against two concurrent runs. */
	const LOCK = 'yazan_store_schema_migrating';

	/**
	 * The tenant column, defined once.
	 *
	 * `DEFAULT 1` is the entire backfill: MySQL writes it into every existing row as part of the
	 * same ALTER, so there is no separate pass to interrupt and no window in which rows are
	 * unattributed. `NOT NULL` means a future insert that forgets the column still produces a valid
	 * row rather than a NULL that every `WHERE store_id = %d` would silently exclude.
	 */
	const STORE_COLUMN = 'bigint(20) unsigned NOT NULL DEFAULT 1';

	/* --------------------------------------------------------------------- */
	/* Table groups                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * yazan-core tables that get a store dimension, and the index to add with it.
	 *
	 * The index is composite and ALWAYS leads with `store_id`, because every future query is
	 * "this store's rows, then some other predicate" — a leading `store_id` serves that; a
	 * standalone `KEY (store_id)` would be used for the filter and then abandoned for the sort.
	 *
	 * @return array<string,array{0:string,1:string[]}> table => [index name, columns]
	 */
	private static function core_tables() {
		if ( ! class_exists( 'Yazan_DB' ) ) {
			return array();
		}

		return array(
			Yazan_DB::table( 'homepage_documents' ) => array( 'store_status', array( 'store_id', 'status' ) ),
			// Revisions are keyed by doc_key, not by a document_id FK — the homepage module
			// deliberately references documents by their stable key rather than by row id.
			Yazan_DB::table( 'homepage_revisions' ) => array( 'store_doc', array( 'store_id', 'doc_key' ) ),
			Yazan_DB::table( 'homepage_templates' ) => array( 'store_id', array( 'store_id' ) ),
			Yazan_DB::table( 'homepage_ab_stats' )  => array( 'store_doc', array( 'store_id', 'doc_key' ) ),
			Yazan_DB::table( 'audit_log' )          => array( 'store_created', array( 'store_id', 'created_at' ) ),
			Yazan_DB::table( 'ai_generations' )     => array( 'store_created', array( 'store_id', 'created_at' ) ),
		);
	}

	/**
	 * yazan-social-rewards tables.
	 *
	 * Named by string, not by asking the plugin — this must degrade to a no-op when the plugin is
	 * absent rather than fatal on a missing class.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	private static function rewards_tables() {
		global $wpdb;

		$p = $wpdb->prefix . 'yazan_rw_';

		return array(
			// Ledgers: every read is "this user, in this store, newest first".
			$p . 'points_ledger'        => array( 'store_user', array( 'store_id', 'user_id', 'id' ) ),
			$p . 'wallet_ledger'        => array( 'store_user', array( 'store_id', 'user_id', 'id' ) ),
			$p . 'activity_logs'        => array( 'store_user', array( 'store_id', 'user_id', 'id' ) ),
			$p . 'notifications'        => array( 'store_user', array( 'store_id', 'user_id', 'status' ) ),
			$p . 'ambassadors'          => array( 'store_status', array( 'store_id', 'status' ) ),
			$p . 'referrals'            => array( 'store_id', array( 'store_id' ) ),
			$p . 'referral_earnings'    => array( 'store_id', array( 'store_id' ) ),
			$p . 'rewards'              => array( 'store_id', array( 'store_id' ) ),
			$p . 'redemptions'          => array( 'store_user', array( 'store_id', 'user_id' ) ),
			$p . 'tiers'                => array( 'store_id', array( 'store_id' ) ),
			$p . 'achievements'         => array( 'store_id', array( 'store_id' ) ),
			$p . 'user_achievements'    => array( 'store_user', array( 'store_id', 'user_id' ) ),
			$p . 'analytics_daily'      => array( 'store_date', array( 'store_id', 'stat_date' ) ),
			$p . 'rules'                => array( 'store_id', array( 'store_id' ) ),
			$p . 'campaigns'            => array( 'store_id', array( 'store_id' ) ),
			$p . 'marketing_campaigns'  => array( 'store_id', array( 'store_id' ) ),
			$p . 'campaign_tasks'       => array( 'store_id', array( 'store_id' ) ),
			$p . 'campaign_participants'=> array( 'store_id', array( 'store_id' ) ),
			$p . 'campaign_submissions' => array( 'store_id', array( 'store_id' ) ),
			$p . 'social_accounts'      => array( 'store_user', array( 'store_id', 'user_id' ) ),
			$p . 'social_actions'       => array( 'store_id', array( 'store_id' ) ),
			$p . 'fraud_flags'          => array( 'store_id', array( 'store_id' ) ),
			$p . 'service_vouchers'     => array( 'store_id', array( 'store_id' ) ),
		);
	}

	/**
	 * yazan-payment-bridge-lite.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	private static function bridge_tables() {
		global $wpdb;

		return array(
			$wpdb->prefix . 'yazan_payment_events' => array( 'store_created', array( 'store_id', 'created_at' ) ),
		);
	}

	/**
	 * UNIQUE keys that must gain the store dimension, and their target shape.
	 *
	 * ⚠️ EVERY ONE OF THESE IS A CORRECTNESS BUG IF LEFT ALONE. `UNIQUE(slug)` on the tiers table
	 * means the second store cannot have a tier called "gold". `UNIQUE(user_id)` on ambassadors
	 * means one person can be an ambassador for one store, ever. These are not optimisations.
	 *
	 * 🛑 `yazan_payment_events.uq_order_event(order_id, event_type)` is DELIBERATELY ABSENT.
	 * It is the idempotency guard, and HPOS order ids are a single global sequence — widening it
	 * with `store_id` would let the same order emit the same event twice. `store_id` goes on that
	 * table as a plain indexed filter column and nowhere near its unique keys.
	 *
	 * @return array<int,array{0:string,1:string,2:string[]}> [table, index, columns]
	 */
	private static function unique_keys() {
		global $wpdb;

		$p    = $wpdb->prefix . 'yazan_rw_';
		$keys = array(
			array( $p . 'tiers', 'slug', array( 'store_id', 'slug' ) ),
			array( $p . 'achievements', 'akey', array( 'store_id', 'akey' ) ),
			array( $p . 'user_achievements', 'user_achievement', array( 'store_id', 'user_id', 'achievement_id' ) ),
			array( $p . 'service_vouchers', 'code', array( 'store_id', 'code' ) ),
			array( $p . 'ambassadors', 'user_id', array( 'store_id', 'user_id' ) ),
			array( $p . 'campaign_participants', 'campaign_user', array( 'store_id', 'campaign_id', 'user_id' ) ),
			array( $p . 'social_accounts', 'user_platform', array( 'store_id', 'user_id', 'platform' ) ),
		);

		if ( class_exists( 'Yazan_DB' ) ) {
			$keys[] = array( Yazan_DB::table( 'homepage_documents' ), 'doc_key', array( 'store_id', 'doc_key' ) );
			$keys[] = array( Yazan_DB::table( 'homepage_ab_stats' ), 'arm_day', array( 'store_id', 'doc_key', 'arm', 'stat_date' ) );
		}

		return $keys;
	}

	/* --------------------------------------------------------------------- */
	/* Running                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Has the migration already been applied?
	 *
	 * @return bool
	 */
	public static function is_current() {
		return get_option( self::VERSION_OPTION ) === self::VERSION;
	}

	/**
	 * Run the migration if it has not been applied.
	 *
	 * Hooked on `init` priority 3 — after the three plugin installers at priority 1 and the
	 * homepage installer at 2, so every table this alters is guaranteed to exist first. Altering a
	 * table that a later installer then re-runs dbDelta over is harmless (dbDelta is additive and
	 * our column is already there), but altering one before it is created is not.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( self::is_current() ) {
			return;
		}

		// A migration running twice in parallel would race on DROP INDEX / ADD UNIQUE.
		if ( get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );

		$report = self::migrate();

		delete_transient( self::LOCK );

		/*
		 * Only stamp the version when nothing failed. A partial migration that records itself as
		 * complete can never self-heal — the next request sees the version and returns early,
		 * leaving a UNIQUE key un-widened forever.
		 */
		if ( empty( $report['failed'] ) ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}

		/**
		 * The tenant schema migration finished.
		 *
		 * @param array $report Per-step results.
		 */
		do_action( 'yazan_store_schema_migrated', $report );
	}

	/**
	 * Apply every step. Safe to call repeatedly — each step is guarded.
	 *
	 * @return array<string,mixed> Report.
	 */
	public static function migrate() {
		Yazan_Schema_Migrator::reset_log();

		self::step_columns();
		self::step_unique_keys();
		self::step_primary_keys();

		$log    = Yazan_Schema_Migrator::log();
		$counts = array(
			Yazan_Schema_Migrator::APPLIED => 0,
			Yazan_Schema_Migrator::SKIPPED => 0,
			Yazan_Schema_Migrator::ABSENT  => 0,
			Yazan_Schema_Migrator::FAILED  => 0,
		);

		$failed = array();

		foreach ( $log as $entry ) {
			++$counts[ $entry['result'] ];

			if ( Yazan_Schema_Migrator::FAILED === $entry['result'] ) {
				$failed[] = $entry;
			}
		}

		return array(
			'version' => self::VERSION,
			'applied' => $counts[ Yazan_Schema_Migrator::APPLIED ],
			'skipped' => $counts[ Yazan_Schema_Migrator::SKIPPED ],
			'absent'  => $counts[ Yazan_Schema_Migrator::ABSENT ],
			'failed'  => $failed,
			'log'     => $log,
		);
	}

	/**
	 * M3 — the store column and its index, on every owned table.
	 *
	 * @return void
	 */
	private static function step_columns() {
		$tables = array_merge( self::core_tables(), self::rewards_tables(), self::bridge_tables() );

		foreach ( $tables as $table => $index ) {
			Yazan_Schema_Migrator::add_column( $table, 'store_id', self::STORE_COLUMN );
			Yazan_Schema_Migrator::add_index( $table, $index[0], $index[1] );
		}
	}

	/**
	 * M4 — widen the UNIQUE keys dbDelta cannot touch.
	 *
	 * @return void
	 */
	private static function step_unique_keys() {
		foreach ( self::unique_keys() as $key ) {
			Yazan_Schema_Migrator::rekey_unique( $key[0], $key[1], $key[2] );
		}
	}

	/**
	 * M5 — rebuild the one PRIMARY KEY that needs the store dimension.
	 *
	 * `yazan_rw_analytics_daily` has no surrogate id; its PRIMARY KEY is the natural key
	 * `(stat_date, metric, dimension)`. Without `store_id` in it, two stores' rollups for the same
	 * day and metric collide — the second one overwrites the first, and the loss is silent because
	 * the writer is an upsert.
	 *
	 * @return void
	 */
	private static function step_primary_keys() {
		global $wpdb;

		Yazan_Schema_Migrator::rebuild_primary_key(
			$wpdb->prefix . 'yazan_rw_analytics_daily',
			array( 'store_id', 'stat_date', 'metric', 'dimension' )
		);
	}

	/**
	 * Every table this migration touches. Used by the tests and the rollback documentation.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array_keys( array_merge( self::core_tables(), self::rewards_tables(), self::bridge_tables() ) );
	}
}
