<?php
/**
 * Shared query plumbing for repositories.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base repository. Subclasses name a table and get safe WHERE building for free.
 */
abstract class AbstractRepository {

	public function __construct( protected Database $db ) {}

	/** Table name segment this repository reads, e.g. 'stores'. */
	abstract protected function table_name(): string;

	protected function table(): string {
		return $this->db->table( $this->table_name() );
	}

	protected function wpdb(): \wpdb {
		return $this->db->wpdb();
	}

	/**
	 * Build a parameterised WHERE clause from a column => value map.
	 *
	 * Column names cannot be parameterised by prepare(), so they are validated against a strict
	 * `[a-z0-9_]` pattern and silently dropped otherwise. That is the whole defence against
	 * injection through a key, and it is why callers may pass user input as VALUES but never as
	 * KEYS.
	 *
	 * @param array<string,mixed> $where column => value.
	 * @return array{0:string,1:array<int,mixed>} SQL fragment (without the word WHERE) and bindings.
	 */
	protected function build_where( array $where ): array {
		$sql    = array();
		$params = array();

		foreach ( $where as $column => $value ) {
			if ( ! is_string( $column ) || ! preg_match( '/^[a-z0-9_]+$/', $column ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				if ( array() === $value ) {
					// An IN () with no members matches nothing; say so explicitly rather than
					// emitting invalid SQL or, worse, dropping the condition and matching all.
					$sql[] = '1=0';
					continue;
				}

				$placeholders = implode( ',', array_fill( 0, count( $value ), '%s' ) );
				$sql[]        = "`{$column}` IN ({$placeholders})";
				$params       = array_merge( $params, array_values( $value ) );
				continue;
			}

			if ( null === $value ) {
				$sql[] = "`{$column}` IS NULL";
				continue;
			}

			$sql[]    = "`{$column}` = %s";
			$params[] = $value;
		}

		return array( $sql ? implode( ' AND ', $sql ) : '1=1', $params );
	}
}
