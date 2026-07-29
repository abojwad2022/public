<?php
/**
 * WordPress GDPR export/erase integration.
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
 * Wires this plugin into Tools → Export/Erase Personal Data.
 *
 * Without this, WordPress's own privacy tooling reports "no data found" for a
 * customer while the plugin is in fact holding their points history, store
 * credit, notifications, referral hashes, and encrypted social tokens. That is
 * a compliance problem, not just a missing feature — the store cannot answer a
 * subject-access or erasure request truthfully.
 *
 * Export walks the same UserDataRegistry map the cleanup hook uses, one table
 * per page, so a customer with a long ledger cannot exhaust memory.
 *
 * Erasure delegates to UserCleanup so there is exactly one implementation of
 * "what happens to this data". Financial ledgers are anonymised rather than
 * deleted, and the response says so — WordPress surfaces `items_retained` and
 * the messages to the site owner, so the honest answer is the correct one.
 */
final class PersonalData {

	/** Identifier WordPress uses for our exporter/eraser. */
	private const SLUG = 'yazan-rewards';

	/** Rows pulled per export page. */
	private const PAGE_SIZE = 200;

	private Database $db;

	private UserDataRegistry $registry;

	private UserCleanup $cleanup;

	/**
	 * Constructor.
	 *
	 * @param Database         $db       Database wrapper.
	 * @param UserDataRegistry $registry User-data map.
	 * @param UserCleanup      $cleanup  Cleanup engine.
	 */
	public function __construct( Database $db, UserDataRegistry $registry, UserCleanup $cleanup ) {
		$this->db       = $db;
		$this->registry = $registry;
		$this->cleanup  = $cleanup;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add our exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ): array {
		$exporters = (array) $exporters;

		$exporters[ self::SLUG ] = array(
			'exporter_friendly_name' => __( 'Yazan Rewards', 'yazan-rewards' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add our eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ): array {
		$erasers = (array) $erasers;

		$erasers[ self::SLUG ] = array(
			'eraser_friendly_name' => __( 'Yazan Rewards', 'yazan-rewards' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export one registry table per page.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          1-based page number.
	 * @return array{data:array,done:bool}
	 */
	public function export( $email_address, $page = 1 ): array {
		$page  = max( 1, (int) $page );
		$user  = get_user_by( 'email', (string) $email_address );
		$empty = array(
			'data' => array(),
			'done' => true,
		);

		if ( ! $user instanceof \WP_User ) {
			return $empty;
		}

		$map   = array_values( $this->registry->map() );
		$index = $page - 1;

		if ( ! isset( $map[ $index ] ) ) {
			return $empty;
		}

		$entry = $map[ $index ];
		$rows  = $this->fetch_rows( $entry, (int) $user->ID );
		$data  = array();

		foreach ( $rows as $i => $row ) {
			$fields = array();

			foreach ( $row as $column => $value ) {
				// Encrypted tokens and internal hashes are not useful to the
				// subject and re-exporting a credential is a hazard in itself.
				if ( $this->is_sensitive_column( (string) $column ) ) {
					continue;
				}

				$fields[] = array(
					'name'  => $this->humanize( (string) $column ),
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				);
			}

			if ( $fields ) {
				$data[] = array(
					'group_id'    => self::SLUG . '-' . $entry['table'],
					'group_label' => $entry['label'],
					'item_id'     => $entry['table'] . '-' . ( $i + 1 ),
					'data'        => $fields,
				);
			}
		}

		return array(
			'data' => $data,
			// Done once we have walked past the last registry entry.
			'done' => ! isset( $map[ $page ] ),
		);
	}

	/**
	 * Erase (or anonymise) the subject's data.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          Page number (single pass).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email_address, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email_address );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$affected = $this->cleanup->purge( (int) $user->ID );
		$messages = array();

		$anonymised = array();
		foreach ( $this->registry->by_policy( UserDataRegistry::POLICY_ANONYMIZE ) as $entry ) {
			if ( isset( $affected[ $entry['table'] ] ) ) {
				$anonymised[] = $entry['label'];
			}
		}

		if ( $anonymised ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated list of retained record types. */
				__( 'The following records were anonymised rather than deleted, because they are financial records the store must retain for accounting: %s. They no longer identify this customer.', 'yazan-rewards' ),
				implode( ', ', $anonymised )
			);
		}

		return array(
			'items_removed'  => ! empty( $affected ),
			'items_retained' => ! empty( $anonymised ),
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Read a capped page of a user's rows from one table.
	 *
	 * @param array $entry   Registry entry.
	 * @param int   $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_rows( array $entry, int $user_id ): array {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->table( $entry['table'] );

		$clauses = array();
		foreach ( $entry['columns'] as $column ) {
			if ( preg_match( '/^[A-Za-z0-9_]{1,64}$/', $column ) ) {
				$clauses[] = "`{$column}` = %d";
			}
		}

		if ( ! $clauses ) {
			return array();
		}

		$where  = implode( ' OR ', $clauses );
		$params = array_fill( 0, count( $clauses ), $user_id );

		$sql = "SELECT * FROM `{$table}` WHERE {$where} LIMIT " . (int) self::PAGE_SIZE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Columns never included in an export.
	 *
	 * @param string $column Column name.
	 * @return bool
	 */
	private function is_sensitive_column( string $column ): bool {
		$needles = array( 'token', 'secret', 'access_token', 'refresh_token', 'hash', 'password' );

		foreach ( $needles as $needle ) {
			if ( false !== stripos( $column, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * "created_at" => "Created At".
	 *
	 * @param string $column Column name.
	 * @return string
	 */
	private function humanize( string $column ): string {
		return ucwords( str_replace( '_', ' ', $column ) );
	}
}
