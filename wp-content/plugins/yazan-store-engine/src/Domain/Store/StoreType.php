<?php
/**
 * Store ownership model.
 *
 * VENDOR is reserved and unused today. It is declared now because the hybrid decision on record is
 * "owner-operated stores first, external vendors later without a rebuild" — and a type column added
 * later would mean a migration plus a default that lies about existing rows.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Domain\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Type vocabulary.
 */
final class StoreType {

	/** Operated by the platform owner. Every store today. */
	public const OWNED = 'owned';

	/** Operated by an external seller. Reserved — nothing implements the isolation this implies yet. */
	public const VENDOR = 'vendor';

	/** @return string[] */
	public static function all(): array {
		return array( self::OWNED, self::VENDOR );
	}

	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}
}
