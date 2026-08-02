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
	 * THE MOST SPECIFIC ADDRESS WINS: host + path is checked before host alone.
	 *
	 * The obvious rule — "host always beats path" — is wrong, and wrong in a way that silently
	 * disables path routing altogether. The platform host is itself a registered address (it is how
	 * store 1 is reached), so a bare host match would claim EVERY request to `yazan.com`, and
	 * `yazan.com/jewelry` could never resolve to anything but store 1. Path routing would look
	 * implemented and never fire.
	 *
	 * Specificity ordering fixes that without opening the hole the naive ordering was guarding
	 * against, because a path row is pinned to an exact host (see add_domain). So
	 * `shadow.example/platform-path` does NOT match store 1's path row — that row belongs to
	 * `yazan.com` — and falls through to the host match, which is the correct answer. One store's
	 * path can never be borrowed from another store's domain.
	 *
	 * @param string $host Request host (port and case are normalised here).
	 * @param string $path Request path, e.g. '/jewelry/product/x'.
	 * @return Store|null Null when the address matches no active store.
	 */
	public function resolve( string $host, string $path = '/' ): ?Store {
		$host = self::normalise_host( $host );

		$domains = $this->repository->all_domains();

		$path_match = $this->match_path( $domains, $host, $path );

		if ( null !== $path_match ) {
			return $this->repository->find( (int) $path_match['store_id'] );
		}

		$host_match = $this->match_host( $domains, $host );

		if ( null !== $host_match ) {
			return $this->repository->find( (int) $host_match['store_id'] );
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
	 * Path-prefix match, pinned to the exact host the row was registered against.
	 *
	 * TWO RULES, BOTH LOad-BEARING.
	 *
	 * The host must match EXACTLY. A path row with a wildcard host would match on every domain this
	 * install answers to, which means store A's path prefix would resolve on store B's domain —
	 * one store reachable from another store's address, which is a tenancy hole rather than a
	 * convenience. add_domain() refuses to create such a row; this is the read-side half of that.
	 *
	 * The LONGEST prefix wins. With a first-match-wins loop, `/jewelry-vintage` would be swallowed
	 * by `/jewelry` whenever the shorter row happened to be created first — routing decided by
	 * insertion order is routing nobody can reason about.
	 *
	 * @param array<int,array<string,mixed>> $domains Domain rows.
	 * @param string                         $host    Normalised host.
	 * @param string                         $path    Request path.
	 * @return array<string,mixed>|null
	 */
	private function match_path( array $domains, string $host, string $path ): ?array {
		if ( '' === $host ) {
			return null;
		}

		$path     = self::normalise_path( $path );
		$best     = null;
		$best_len = -1;

		foreach ( $domains as $row ) {
			if ( DomainType::PATH !== (string) ( $row['type'] ?? '' ) ) {
				continue;
			}

			if ( strtolower( (string) ( $row['host'] ?? '' ) ) !== $host ) {
				continue;
			}

			$prefix = self::normalise_path( (string) ( $row['path'] ?? '' ) );

			if ( '/' === $prefix || '' === $prefix ) {
				continue;
			}

			// Segment-aware: `/jewel` must not match `/jewellery`.
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
