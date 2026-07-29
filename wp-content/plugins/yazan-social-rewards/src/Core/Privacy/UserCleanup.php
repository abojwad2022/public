<?php
/**
 * Removes or anonymises a user's rewards data when their account goes away.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Privacy;

use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Before this existed, deleting a WordPress user left every yazan_rw_* row
 * behind: points, store credit, notifications, and — worst — the encrypted
 * OAuth tokens in social_accounts. A live audit of this install found 449
 * such orphan rows across six tables.
 *
 * Hooks `deleted_user` (fires after WordPress has removed the user row, and
 * carries the id) and applies the policy declared in UserDataRegistry.
 *
 * Deliberately NOT hooked to `delete_user` (the *before* hook): if the
 * deletion is aborted downstream we would have destroyed the data for a user
 * who still exists. Cleaning up after the fact is the safe ordering.
 */
final class UserCleanup {

	private Database $db;

	private UserDataRegistry $registry;

	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Database         $db       Database wrapper.
	 * @param UserDataRegistry $registry User-data map.
	 * @param Logger           $logger   Logger.
	 */
	public function __construct( Database $db, UserDataRegistry $registry, Logger $logger ) {
		$this->db       = $db;
		$this->registry = $registry;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 10, 1 );

		// Multisite: a user removed from this site should lose this site's
		// rewards data even though the network account survives.
		add_action( 'remove_user_from_blog', array( $this, 'on_user_removed_from_blog' ), 10, 2 );
	}

	/**
	 * Handle `deleted_user`.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public function on_user_deleted( $user_id ): void {
		$this->purge( (int) $user_id );
	}

	/**
	 * Handle `remove_user_from_blog`.
	 *
	 * @param int $user_id Removed user id.
	 * @param int $blog_id Site the user was removed from.
	 * @return void
	 */
	public function on_user_removed_from_blog( $user_id, $blog_id ): void {
		if ( (int) $blog_id !== get_current_blog_id() ) {
			return;
		}
		$this->purge( (int) $user_id );
	}

	/**
	 * Apply the registry policy for one user across every mapped table.
	 *
	 * @param int $user_id User id.
	 * @return array<string,int> table => rows affected.
	 */
	public function purge( int $user_id ): array {
		$affected = array();

		if ( $user_id <= 0 ) {
			return $affected;
		}

		foreach ( $this->registry->map() as $entry ) {
			$table = $this->db->table( $entry['table'] );
			$rows  = 0;

			foreach ( $entry['columns'] as $column ) {
				if ( ! $this->is_safe_identifier( $column ) ) {
					continue;
				}

				$rows += UserDataRegistry::POLICY_DELETE === $entry['policy']
					? $this->delete_rows( $table, $column, $user_id )
					: $this->anonymize_rows( $table, $column, $user_id, $entry['pii'] );
			}

			if ( $rows > 0 ) {
				$affected[ $entry['table'] ] = $rows;
			}
		}

		if ( $affected ) {
			$this->logger->info(
				sprintf(
					'Privacy cleanup for user %d: %s',
					$user_id,
					wp_json_encode( $affected )
				)
			);
		}

		/**
		 * Fires after a user's rewards data has been purged/anonymised.
		 *
		 * @param int               $user_id  The user id.
		 * @param array<string,int> $affected table => rows affected.
		 */
		do_action( 'yazan_rewards/privacy/user_purged', $user_id, $affected );

		return $affected;
	}

	/**
	 * Delete every row belonging to a user.
	 *
	 * @param string $table   Full table name (trusted, from Database::table()).
	 * @param string $column  User column (validated identifier).
	 * @param int    $user_id User id.
	 * @return int Rows deleted.
	 */
	private function delete_rows( string $table, string $column, int $user_id ): int {
		$wpdb = $this->db->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( $column => $user_id ), array( '%d' ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Sever the user link on a row while keeping the record.
	 *
	 * @param string             $table   Full table name (trusted).
	 * @param string             $column  User column (validated identifier).
	 * @param int                $user_id User id.
	 * @param array<int,string>  $pii     Additional columns to blank.
	 * @return int Rows updated.
	 */
	private function anonymize_rows( string $table, string $column, int $user_id, array $pii ): int {
		$wpdb = $this->db->wpdb();

		$data    = array( $column => UserDataRegistry::ANONYMOUS_USER_ID );
		$formats = array( '%d' );

		foreach ( $pii as $pii_column ) {
			if ( $this->is_safe_identifier( $pii_column ) && $this->column_exists( $table, $pii_column ) ) {
				$data[ $pii_column ] = '';
				$formats[]           = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $data, array( $column => $user_id ), $formats, array( '%d' ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Does a column exist? Guards the PII-blanking step so a schema that has
	 * not migrated yet degrades to "anonymise the user link only" instead of
	 * throwing a database error.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( string $table, string $column ): bool {
		static $cache = array();

		$key = $table . '.' . $column;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$wpdb = $this->db->wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );

		$cache[ $key ] = ( null !== $found );

		return $cache[ $key ];
	}

	/**
	 * Column names come from our own hardcoded registry, never from a request.
	 * This is belt-and-braces so a bad filter value can't reach SQL.
	 *
	 * @param string $identifier Candidate column name.
	 * @return bool
	 */
	private function is_safe_identifier( string $identifier ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $identifier );
	}
}
