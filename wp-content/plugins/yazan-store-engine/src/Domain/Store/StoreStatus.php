<?php
/**
 * Store lifecycle states.
 *
 * A class of constants rather than a PHP 8.1 enum: the value is persisted as a varchar and read
 * back by code that may predate the enum, and a plain string keeps the repository, the REST
 * payload and the database column identical with no serialisation layer in between.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Domain\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status vocabulary.
 */
final class StoreStatus {

	/** Serving requests. Only this status is projected into the hostmap. */
	public const ACTIVE = 'active';

	/** Exists, fully configured, deliberately not reachable. Reversible. */
	public const SUSPENDED = 'suspended';

	/** Being set up. Never reachable; not an error state. */
	public const DRAFT = 'draft';

	/** Retained for reporting and audit, but finished. Never reachable. */
	public const ARCHIVED = 'archived';

	/** @return string[] */
	public static function all(): array {
		return array( self::ACTIVE, self::SUSPENDED, self::DRAFT, self::ARCHIVED );
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
