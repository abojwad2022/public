<?php
/**
 * Turns a request address into a store.
 *
 * Handles all three addressing modes the platform is specified to support:
 *
 *   subdomain      jewelry.yazan.com
 *   custom domain  example.com
 *   path           yazan.com/jewelry
 *
 * ⚠️ ALL THREE RESOLVE HERE; ONLY THE HOST-BASED TWO DRIVE THE STOREFRONT TODAY.
 *
 * That is not a shortcut, it is what the platform currently permits, and the reasons are concrete
 * and were verified against the live site:
 *
 *   - WooCommerce resolves Shop, Cart, Checkout and My Account through four SINGLETON page ids in
 *     wp_options (38, 39, 40, 41 here). One set for the whole install.
 *   - CartFlows overrides checkout globally with one flow id (9) and `override_global_checkout`
 *     enabled — one checkout for every path.
 *   - All six rewrite rules in the platform are anchored at `^` with no store segment, and three
 *     of them are add_rewrite_endpoint( EP_ROOT | EP_PAGES ), which is structurally a path SUFFIX
 *     and cannot carry a prefix.
 *   - permalink_structure is /%postname%/, which leaves no segment for a store.
 *   - The theme identity is persisted in one localStorage key per ORIGIN. Two path-routed stores
 *     share an origin, so one store's theme choice would follow the visitor into the other.
 *
 * Path resolution is therefore implemented, tested, and used for the dashboard and REST surfaces —
 * where none of the five constraints apply — and is ready the day the storefront constraints are
 * lifted. Resolving a path to a store is the part that has to be right; wiring WooCommerce to it is
 * a separate project with its own risks.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Domain\Store\DomainType;
use Yazan\Stores\Domain\Store\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Address → store.
 */
final class StoreResolver {

	public function __construct( private StoreRepositoryInterface $repository ) {}

	/**
	 * Resolve the store for an address.
	 *
	 * Host wins over path. A request to `jewelry.yazan.com/honey` belongs to the jewelry store —
	 * the host is the stronger claim, and treating the path as an override would let any store be
	 * addressed from any other store's domain, which is a tenancy hole rather than a feature.
	 *
	 * @param string $host Request host (port and case are normalised here).
	 * @param string $path Request path, e.g. '/jewelry/product/x'.
	 * @return Store|null Null when the address matches no active store.
	 */
	public function resolve( string $host, string $path = '/' ): ?Store {
		$host = self::normalise_host( $host );

		$domains = $this->repository->all_domains();

		$host_match = $this->match_host( $domains, $host );

		if ( null !== $host_match ) {
			return $this->repository->find( (int) $host_match['store_id'] );
		}

		$path_match = $this->match_path( $domains, $host, $path );

		if ( null !== $path_match ) {
			return $this->repository->find( (int) $path_match['store_id'] );
		}

		return null;
	}

	/**
	 * Exact host match against subdomain and custom-domain rows.
	 *
	 * Deliberately EXACT, never a suffix test. A suffix test ("does the host end in yazan.com")
	 * would match `yazan.com.attacker.example`, and the Host header is attacker-controlled.
	 *
	 * @param array<int,array<string,mixed>> $domains Domain rows.
	 * @param string                         $host    Normalised host.
	 * @return array<string,mixed>|null
	 */
	private function match_host( array $domains, string $host ): ?array {
		if ( '' === $host ) {
			return null;
		}

		foreach ( $domains as $row ) {
			$type = (string) ( $row['type'] ?? '' );

			if ( ! DomainType::is_host_based( $type ) ) {
				continue;
			}

			if ( strtolower( (string) $row['host'] ) === $host ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Path-prefix match, scoped to the platform host the row was registered against.
	 *
	 * The host check is what stops a path row from matching on a foreign domain. Longest prefix
	 * wins, so `/jewelry-vintage` cannot be swallowed by `/jewelry` — a plain `str_starts_with`
	 * ordered by insertion would have made store creation order decide routing.
	 *
	 * @param array<int,array<string,mixed>> $domains Domain rows.
	 * @param string                         $host    Normalised host.
	 * @param string                         $path    Request path.
	 * @return array<string,mixed>|null
	 */
	private function match_path( array $domains, string $host, string $path ): ?array {
		$path = self::normalise_path( $path );
		$best = null;
		$best_len = -1;

		foreach ( $domains as $row ) {
			if ( DomainType::PATH !== (string) ( $row['type'] ?? '' ) ) {
				continue;
			}

			$row_host = strtolower( (string) ( $row['host'] ?? '' ) );

			// A blank host on a path row means "any host this install answers to".
			if ( '' !== $row_host && $row_host !== $host ) {
				continue;
			}

			$prefix = self::normalise_path( (string) ( $row['path'] ?? '' ) );

			if ( '/' === $prefix || '' === $prefix ) {
				continue;
			}

			$is_match = ( $path === $prefix ) || str_starts_with( $path, rtrim( $prefix, '/' ) . '/' );

			if ( $is_match && strlen( $prefix ) > $best_len ) {
				$best     = $row;
				$best_len = strlen( $prefix );
			}
		}

		return $best;
	}

	/**
	 * Lower-case, strip the port, and keep only characters legal in a hostname.
	 *
	 * The last step matters: the Host header is client-supplied and reaches a database lookup and,
	 * downstream, the hostmap option. Whitelisting the character class here means nothing exotic
	 * can travel further.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	public static function normalise_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		return (string) preg_replace( '/[^a-z0-9.\-]/', '', $host );
	}

	/**
	 * Leading slash, no trailing slash, no query string.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function normalise_path( string $path ): string {
		$path = (string) strtok( $path, '?' );
		$path = '/' . ltrim( trim( $path ), '/' );
		$path = rtrim( $path, '/' );

		return '' === $path ? '/' : $path;
	}
}
