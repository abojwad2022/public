<?php
/**
 * Installable contract — modules that own database tables.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module that contributes CREATE TABLE statements to the schema installer.
 * The Schema aggregator collects these and runs them through dbDelta().
 */
interface Installable {

	/**
	 * Return one or more dbDelta-formatted CREATE TABLE statements. Table names
	 * must already carry the full prefix (use $wpdb->prefix . 'yazan_rw_...').
	 *
	 * @param string $charset_collate Result of $wpdb->get_charset_collate().
	 * @return string[]
	 */
	public function schema( string $charset_collate ): array;
}
