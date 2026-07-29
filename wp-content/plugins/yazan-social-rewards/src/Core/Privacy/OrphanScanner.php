<?php
/**
 * Referential-integrity report for user-linked rewards tables.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Privacy;

use Yazan\Rewards\Core\Database\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * None of the 25 custom tables carry a real FOREIGN KEY — integrity is enforced
 * in application code only, which is normal for WordPress but means nothing
 * catches a row whose user has vanished.
 *
 * UserCleanup stops NEW orphans appearing. This class deals with the ones
 * already on disk: it counts them, and can clean them up using exactly the same
 * policy map, so a purge here is indistinguishable from what the hook would
 * have done at deletion time.
 *
 * SCANNING IS READ-ONLY AND IS THE DEFAULT. `purge()` only runs when a caller
 * explicitly passes confirmation — deleting live rows is never implicit.
 */
final class OrphanScanner {

	private Database $db;

	private UserDataRegistry $registry;

	private UserCleanup $cleanup;

	/**
	 * Constructor.
	 *
	 * @param Database         $db       Database wrapper.
	 * @param UserDataRegistry $registry User-data map.
	 * @param UserCleanup      $cleanup  Cleanup engine (policy reuse).
	 */
	public function __construct( Database $db, UserDataRegistry $registry, UserCleanup $cleanup ) {
		$this->db       = $db;
		$this->registry = $registry;
		$this->cleanup  = $cleanup;
	}

	/**
	 * Count rows whose referenced user no longer exists. Read-only.
	 *
	 * @return array{total:int,tables:array<int,array{table:string,column:string,policy:string,count:int}>}
	 */
	public function scan(): array {
		$wpdb   = $this->db->wpdb();
		$users  = $wpdb->users;
		$tables = array();
		$total  = 0;

		foreach ( $this->registry->map() as $entry ) {
			$table = $this->db->table( $entry['table'] );

			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			foreach ( $entry['columns'] as $column ) {
				if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $column ) ) {
					continue;
				}

				// Table and column are both trusted identifiers (registry +
				// $wpdb->users); there are no bound values in this statement.
				$sql = "SELECT COUNT(*) FROM `{$table}` a
						LEFT JOIN `{$users}` u ON u.ID = a.`{$column}`
						WHERE u.ID IS NULL AND a.`{$column}` <> 0";

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$count = (int) $wpdb->get_var( $sql );

				if ( $count > 0 ) {
					$tables[] = array(
						'table'  => $entry['table'],
						'column' => $column,
						'policy' => $entry['policy'],
						'count'  => $count,
					);
					$total   += $count;
				}
			}
		}

		return array(
			'total'  => $total,
			'tables' => $tables,
		);
	}

	/**
	 * Apply the registry policy to orphaned rows.
	 *
	 * @param bool $confirm Must be true. Without it this is a no-op that just
	 *                      returns the scan, so an accidental call cannot
	 *                      destroy data.
	 * @return array{dry_run:bool,total:int,tables:array,affected:array<string,int>}
	 */
	public function purge( bool $confirm = false ): array {
		$scan = $this->scan();

		if ( ! $confirm ) {
			return array(
				'dry_run'  => true,
				'total'    => $scan['total'],
				'tables'   => $scan['tables'],
				'affected' => array(),
			);
		}

		$wpdb     = $this->db->wpdb();
		$users    = $wpdb->users;
		$affected = array();

		foreach ( $this->registry->map() as $entry ) {
			$table = $this->db->table( $entry['table'] );

			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			foreach ( $entry['columns'] as $column ) {
				if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $column ) ) {
					continue;
				}

				$where = "`{$column}` <> 0 AND `{$column}` NOT IN ( SELECT ID FROM `{$users}` )";

				if ( UserDataRegistry::POLICY_DELETE === $entry['policy'] ) {
					$sql = "DELETE FROM `{$table}` WHERE {$where}";
				} else {
					$sets = array( "`{$column}` = " . UserDataRegistry::ANONYMOUS_USER_ID );
					foreach ( $entry['pii'] as $pii_column ) {
						if ( preg_match( '/^[A-Za-z0-9_]{1,64}$/', $pii_column ) ) {
							$sets[] = "`{$pii_column}` = ''";
						}
					}
					$sql = "UPDATE `{$table}` SET " . implode( ', ', $sets ) . " WHERE {$where}";
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->query( $sql );

				if ( $rows > 0 ) {
					$key              = $entry['table'] . '.' . $column;
					$affected[ $key ] = (int) $rows;
				}
			}
		}

		/**
		 * Fires after orphaned rows have been cleaned up.
		 *
		 * @param array<string,int> $affected table.column => rows affected.
		 */
		do_action( 'yazan_rewards/privacy/orphans_purged', $affected );

		return array(
			'dry_run'  => false,
			'total'    => $scan['total'],
			'tables'   => $scan['tables'],
			'affected' => $affected,
		);
	}

	/**
	 * Guard against scanning a table a migration has not created yet.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		static $cache = array();

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		$wpdb = $this->db->wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$cache[ $table ] = ( null !== $found );

		return $cache[ $table ];
	}
}
