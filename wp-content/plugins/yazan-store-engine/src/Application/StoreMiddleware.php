<?php
/**
 * Where the engine touches the request lifecycle.
 *
 * Everything it does is through published extension points — no yazan-core file is edited, and
 * nothing here is required for the site to work. With one store it is a no-op by construction:
 * every branch is guarded on "is there more than one store".
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Contracts\Hookable;
use Yazan\Stores\Infrastructure\HostmapProjector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-lifecycle integration.
 */
final class StoreMiddleware implements Hookable {

	public function __construct(
		private StoreContext $context,
		private HostmapProjector $projector
	) {}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function hooks(): array {
		return array(
			// Let the canonical-host guard know which hosts belong to stores.
			array( 'type' => 'filter', 'hook' => 'yazan_canonical_known_hosts', 'method' => 'exclude_store_hosts', 'priority' => 10, 'args' => 1 ),

			// Stamp the store's theme identity before the first stylesheet is parsed.
			array( 'type' => 'filter', 'hook' => 'language_attributes', 'method' => 'stamp_theme', 'priority' => 10, 'args' => 1 ),

			// And make it the default the theme's own pre-paint script falls back to.
			array( 'type' => 'filter', 'hook' => 'yazan_themes', 'method' => 'prefer_store_theme', 'priority' => 10, 'args' => 1 ),

			// Make the active store visible to CSS and to anyone debugging a page.
			array( 'type' => 'filter', 'hook' => 'body_class', 'method' => 'body_class', 'priority' => 10, 'args' => 1 ),
		);
	}

	/**
	 * Keep store hosts out of Yazan_Canonical_Host's redirect list.
	 *
	 * THIS IS THE ONE PIECE OF INTEGRATION THAT PREVENTS A REAL OUTAGE. That guard redirects any
	 * request arriving on a host it recognises to the single host `home_url()` names. A second
	 * store on a second hostname would therefore be 302'd straight back to store 1 — the store
	 * would appear to exist and be permanently unreachable.
	 *
	 * Filtering the list rather than disabling the guard keeps its actual purpose intact (it exists
	 * because `/dashboard` is a `<script type="module">` that a browser refuses cross-origin, which
	 * fails as a blank screen with nothing in the console).
	 *
	 * @param string[] $hosts Hosts the guard may redirect away from.
	 * @return string[]
	 */
	public function exclude_store_hosts( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return $hosts;
		}

		$store_hosts = array_map( 'strval', array_keys( $this->projector->current() ) );

		if ( array() === $store_hosts ) {
			return $hosts;
		}

		return array_values( array_diff( $hosts, $store_hosts ) );
	}

	/**
	 * Print `data-yz-theme` on <html> from the store record.
	 *
	 * SERVER-SIDE ON PURPOSE. The theme's own switcher stamps this attribute from localStorage
	 * before first paint, which is right for a visitor preference and wrong for a store identity:
	 * localStorage is per ORIGIN, so under path-based routing one store's choice would follow the
	 * visitor into another. Printing the store's value server-side gives a correct first paint; the
	 * switcher may still override it for a visitor who has expressed a preference on that origin.
	 *
	 * Done through the `language_attributes` filter rather than by editing the theme, because
	 * `yazan_default_theme()` has no filter of its own — this is the seam that exists.
	 *
	 * @param string $output Existing attribute string.
	 * @return string
	 */
	public function stamp_theme( $output ) {
		$theme = $this->context->theme();

		if ( '' === $theme || is_admin() ) {
			return $output;
		}

		if ( false !== strpos( (string) $output, 'data-yz-theme' ) ) {
			return $output;
		}

		return trim( (string) $output ) . ' data-yz-theme="' . esc_attr( $theme ) . '"';
	}

	/**
	 * Move the store's theme to the front of the registry, making it the default.
	 *
	 * WITHOUT THIS, THE SERVER-SIDE STAMP ABOVE IS OVERWRITTEN ON EVERY FIRST VISIT.
	 *
	 * The theme ships a pre-paint script that sets `data-yz-theme` from localStorage, and when
	 * localStorage is empty — which is every first visit — it falls back to the platform default.
	 * So a store pinned to `burgundy` would render `black` for every new visitor, and correctly
	 * only for people who had already used the switcher. The bug would look intermittent, which is
	 * the worst kind.
	 *
	 * `yazan_default_theme()` has no filter of its own; it returns the FIRST key of `yazan_themes()`.
	 * Reordering that array is therefore the supported way to change the default, and it needs no
	 * edit to the theme. The catalogue is unchanged — every theme stays available, and a visitor who
	 * picks one still wins, which is the correct precedence: the store decides the default, the
	 * visitor decides their preference.
	 *
	 * @param array<string,mixed> $themes Registered theme identities.
	 * @return array<string,mixed>
	 */
	public function prefer_store_theme( $themes ) {
		if ( ! is_array( $themes ) || array() === $themes ) {
			return $themes;
		}

		$store = $this->context->store();
		$theme = $store ? $store->theme() : '';

		// Not set, unknown, or already first — nothing to do.
		if ( '' === $theme || ! isset( $themes[ $theme ] ) || array_key_first( $themes ) === $theme ) {
			return $themes;
		}

		return array( $theme => $themes[ $theme ] ) + $themes;
	}

	/**
	 * Add `yz-store-{slug}` to the body classes.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		$slug = $this->context->slug();

		if ( '' !== $slug ) {
			$classes[] = 'yz-store-' . sanitize_html_class( $slug );
		}

		return $classes;
	}
}
