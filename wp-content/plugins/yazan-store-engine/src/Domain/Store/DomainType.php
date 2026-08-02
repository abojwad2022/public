<?php
/**
 * How a store is addressed.
 *
 * All three resolve in the engine. Only the two host-based kinds are wired to the storefront —
 * see StoreResolver for why PATH cannot drive WooCommerce today.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Domain\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain kind vocabulary.
 */
final class DomainType {

	/** jewelry.yazan.com — a label under the platform's own domain. */
	public const SUBDOMAIN = 'subdomain';

	/** example.com — a domain the store owner controls. */
	public const CUSTOM = 'custom';

	/** yazan.com/jewelry — a path segment on the platform host. */
	public const PATH = 'path';

	/** @return string[] */
	public static function all(): array {
		return array( self::SUBDOMAIN, self::CUSTOM, self::PATH );
	}

	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/** Is this kind matched by the request host rather than by the request path? */
	public static function is_host_based( string $type ): bool {
		return in_array( $type, array( self::SUBDOMAIN, self::CUSTOM ), true );
	}
}
