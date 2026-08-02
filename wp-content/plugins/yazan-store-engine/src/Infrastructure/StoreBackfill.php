<?php
/**
 * One-time data seeding that the DDL alone cannot express.
 *
 * `ALTER TABLE … DEFAULT 1` attributes every existing row to store 1 in the same statement, which
 * is why this migration needs no backfill for the tenant column. Two things do need one:
 *
 *   - a uuid for stores created before the column existed (a DEFAULT cannot be per-row unique);
 *   - certificate rows for the serials that until now lived only in post meta.
 *
 * Both are idempotent and both are additive — nothing is deleted, and the source of each remains
 * exactly where it was.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Infrastructure;

use Yazan\Stores\Core\Database\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds uuids and certificates.
 */
final class StoreBackfill {

	/** Post meta key holding a ring's serial. Owned by yazan-core; read, never written, here. */
	public const SERIAL_META = '_yz_serial';

	/** Attachment id of the uploaded certificate document. */
	public const CERT_META = '_yz_certificate_id';

	/** Attachment id of the generated QR code. */
	public const QR_META = '_yz_qr_code';

	/** draft | certified | retired. */
	public const STATUS_META = '_yz_verification_status';

	/** Never scan more than this in one pass. */
	private const LIMIT = 5000;

	public function __construct( private Database $db ) {}

	/**
	 * Run every backfill. Safe to call on each migration.
	 *
	 * @return array<string,int>
	 */
	public function run(): array {
		return array(
			'uuids'        => $this->backfill_uuids(),
			'certificates' => $this->backfill_certificates(),
		);
	}

	/**
	 * Give every store a uuid.
	 *
	 * Generated per row rather than defaulted, because a column DEFAULT is one constant value and
	 * a uuid that is the same for every store is not a uuid.
	 *
	 * @return int Rows updated.
	 */
	public function backfill_uuids(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->table( 'stores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE uuid = '' OR uuid IS NULL LIMIT " . self::LIMIT );

		$count = 0;

		foreach ( (array) $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update( $table, array( 'uuid' => wp_generate_uuid4() ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );

			if ( false !== $ok ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Promote every `_yz_serial` in post meta into a certificate row.
	 *
	 * ⚠️ THE META IS NOT DELETED. It stays the compatibility surface — `Yazan_Core_Verify::lookup()`,
	 * the products controller and the payment bridge's eligibility service all read it, and the
	 * public `/verify-ring/{serial}/` page is built on it. This table becomes the UNIQUENESS
	 * AUTHORITY, which is what was missing: serial collisions were previously prevented by two PHP
	 * checks in two REST controllers and by nothing at all in the database.
	 *
	 * Every product is attributed to store 1, matching the tenant column's default. A product that
	 * later moves store carries its certificate with it, because the row is keyed by product.
	 *
	 * @return int Rows inserted.
	 */
	public function backfill_certificates(): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->table( 'certificates' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT %d",
				self::SERIAL_META,
				self::LIMIT
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $row ) {
			$product_id = (int) $row['post_id'];
			$serial     = (string) $row['meta_value'];

			/*
			 * INSERT IGNORE against UNIQUE(store_id, serial): re-running the backfill is a no-op,
			 * and — more importantly — a duplicate serial already in the meta table is skipped
			 * rather than aborting the whole pass. Those duplicates are exactly what the unique key
			 * exists to prevent from here on; the ones already in the data are reported, not
			 * silently merged.
			 */
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(store_id, serial, product_id, certificate_attachment_id, qr_attachment_id, status, issued_at, created_at, updated_at)
					VALUES (%d, %s, %d, %d, %d, %s, %s, %s, %s)",
				1,
				$serial,
				$product_id,
				(int) get_post_meta( $product_id, self::CERT_META, true ),
				(int) get_post_meta( $product_id, self::QR_META, true ),
				(string) ( get_post_meta( $product_id, self::STATUS_META, true ) ?: 'draft' ),
				$now,
				$now,
				$now
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query( $sql );

			if ( $affected ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Serials that appear on more than one product.
	 *
	 * Nothing at the database level has ever prevented this, so it is worth knowing before the
	 * unique key starts refusing new ones. Returns an empty array on a healthy catalogue.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function duplicate_serials(): array {
		$wpdb = $this->db->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS serial, COUNT(*) AS total
				   FROM {$wpdb->postmeta}
				  WHERE meta_key = %s AND meta_value <> ''
			   GROUP BY meta_value
				 HAVING COUNT(*) > 1
				  LIMIT 100",
				self::SERIAL_META
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
