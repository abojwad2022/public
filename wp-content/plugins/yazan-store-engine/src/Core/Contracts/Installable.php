<?php
/**
 * A module that owns database tables.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contributes CREATE TABLE statements to the aggregated install.
 */
interface Installable {

	/**
	 * Table names must already carry the full prefix (use Database::table()).
	 *
	 * @param string $charset_collate Result of $wpdb->get_charset_collate().
	 * @return string[] dbDelta-formatted CREATE TABLE statements.
	 */
	public function schema( string $charset_collate ): array;
}
