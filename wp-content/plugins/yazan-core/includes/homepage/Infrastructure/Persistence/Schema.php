<?php
/**
 * Homepage Manager tables.
 *
 * Same shape and idioms as Yazan_RBAC_Schema so operations stays uniform, and so the tables are
 * picked up automatically by Yazan_Backup_Engine (which dumps every table in the database).
 *
 * @package Yazan_Core
 */

namespace Yazan\Homepage\Infrastructure\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * dbDelta schema.
 */
final class Schema {

	/** Bump when any table below changes. */
	const SCHEMA_VERSION = '3';

	/** Option holding the installed schema version. */
	const SCHEMA_OPTION = 'yazan_homepage_schema_version';

	/**
	 * Table name for a suffix.
	 *
	 * @param string $suffix documents | revisions | templates | ab_stats.
	 * @return string
	 */
	public static function table( $suffix ) {
		return \Yazan_DB::table( 'homepage_' . $suffix );
	}

	/**
	 * Create or upgrade the tables. Idempotent.
	 *
	 * @return bool
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$docs    = self::table( 'documents' );
		$revs    = self::table( 'revisions' );
		$tpls    = self::table( 'templates' );

		/*
		 * draft_payload / live_payload hold the whole document as JSON.
		 *
		 * Why not a row per section: publishing becomes ONE atomic write (there is no half-published
		 * state to reach), a revision is a copy, a rollback is a replace, an export is the payload
		 * itself, and the storefront read is a single row. The cost is that SQL cannot see inside a
		 * section — paid back by the section index table in a later phase, when something actually
		 * needs it.
		 */
		$sql_documents = "CREATE TABLE {$docs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_key VARCHAR(64) NOT NULL,
			title VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			version BIGINT UNSIGNED NOT NULL DEFAULT 1,
			schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			draft_payload LONGTEXT NULL,
			live_payload LONGTEXT NULL,
			scheduled_at DATETIME NULL DEFAULT NULL,
			published_revision_id BIGINT UNSIGNED NULL DEFAULT NULL,
			bound_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title_note VARCHAR(191) NOT NULL DEFAULT '',
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY doc_key (doc_key),
			KEY status_scheduled (status, scheduled_at),
			KEY bound_page (bound_page_id)
		) {$charset};";

		$sql_revisions = "CREATE TABLE {$revs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_key VARCHAR(64) NOT NULL,
			revision_no BIGINT UNSIGNED NOT NULL DEFAULT 1,
			payload LONGTEXT NULL,
			note VARCHAR(191) NOT NULL DEFAULT '',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_publish TINYINT(1) NOT NULL DEFAULT 0,
			size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY doc_revision (doc_key, revision_no),
			KEY doc_created (doc_key, created_at)
		) {$charset};";

		$sql_templates = "CREATE TABLE {$tpls} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(20) NOT NULL DEFAULT 'section',
			component_type VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(191) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			preview_media_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_global TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY kind_type (kind, component_type)
		) {$charset};";

		$stats = self::table( 'ab_stats' );

		/*
		 * The A/B tally. One row per document, arm and DAY — not per view, and not one running
		 * total either. Per-day is what makes "the variant only won on the weekend" visible, and
		 * it keeps the row count bounded by time rather than by traffic.
		 *
		 * The unique key is what the counter upsert collides on; without it every view would
		 * insert a new row and the totals would be a table scan.
		 */
		$sql_ab_stats = "CREATE TABLE {$stats} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_key VARCHAR(64) NOT NULL,
			arm VARCHAR(64) NOT NULL,
			stat_date DATE NOT NULL,
			views BIGINT UNSIGNED NOT NULL DEFAULT 0,
			orders BIGINT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY arm_day (doc_key, arm, stat_date),
			KEY doc_date (doc_key, stat_date)
		) {$charset};";

		dbDelta( $sql_documents );
		dbDelta( $sql_revisions );
		dbDelta( $sql_templates );
		dbDelta( $sql_ab_stats );

		if ( ! self::is_installed() ) {
			return false;
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

		return true;
	}

	/**
	 * Are all three tables present?
	 *
	 * @return bool
	 */
	public static function is_installed() {
		global $wpdb;

		foreach ( array( 'documents', 'revisions', 'templates', 'ab_stats' ) as $suffix ) {
			$table = self::table( $suffix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}
}
