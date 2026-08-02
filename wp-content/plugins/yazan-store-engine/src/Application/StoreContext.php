<?php
/**
 * Everything the application needs to know about the store it is serving.
 *
 * A FAÇADE OVER Yazan_Store_Context, NOT A REPLACEMENT.
 *
 * The mu-plugin already answers "which store is this request", and it has to, because it runs
 * before any plugin loads and before the first capability check. Re-deriving that answer here
 * would create two definitions of "current store" that can disagree — and the shape of that
 * disagreement is a page rendered with one store's identity and another store's data.
 *
 * So: identity comes from the kernel; everything else is enriched here.
 *
 * EVERY MEMBER IS DERIVED LAZILY AND CACHED AGAINST THE KERNEL'S EPOCH.
 *
 * That is the one design rule that makes this safe inside Yazan_Store_Context::run_for(). The
 * kernel bumps its epoch on every push and pop, so a cache keyed by "store id + epoch" is
 * unreachable — not merely stale — the moment the context changes. It is the same idiom the RBAC
 * layer documents: put the version in the KEY, so there is no invalidation to forget.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

use Yazan\Stores\Core\Contracts\StoreRepositoryInterface;
use Yazan\Stores\Domain\Store\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The seven-member store context.
 */
final class StoreContext {

	/**
	 * Per-request memo, keyed "storeId|epoch|slice".
	 *
	 * @var array<string,mixed>
	 */
	private array $memo = array();

	public function __construct(
		private StoreRepositoryInterface $repository,
		private StoreModules $modules
	) {}

	/* ------------------------------------------------------------------ */
	/* 1 + 2. Current store and store id                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The active store id.
	 *
	 * Delegated, never recomputed. The literal fallback exists because this can be called from a
	 * request where the mu-plugin is legitimately absent (a partial deploy, a rescue copy of the
	 * site) and a fatal here would be worse than a wrong-but-safe answer of "the only store".
	 */
	public function id(): int {
		return class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::current() : 1;
	}

	/**
	 * The active store record.
	 *
	 * Returns null when the registry has no row for the resolved id — which is the normal state
	 * before any store has been created, and is why every caller must handle null rather than
	 * assume a store exists.
	 */
	public function store(): ?Store {
		return $this->remember(
			'store',
			fn(): ?Store => $this->repository->find( $this->id() )
		);
	}

	/** Convenience for logging and cache keys. */
	public function slug(): string {
		$store = $this->store();

		return $store ? $store->slug() : '';
	}

	/* ------------------------------------------------------------------ */
	/* 3. Store settings                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * All settings for the active store.
	 *
	 * @return array<string,string>
	 */
	public function settings(): array {
		return $this->remember(
			'settings',
			fn(): array => $this->repository->settings( $this->id() )
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Returned when the store has not set it.
	 * @return mixed
	 */
	public function setting( string $key, $default = null ) {
		$settings = $this->settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/* ------------------------------------------------------------------ */
	/* 4. Store theme                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The theme identity this store renders with.
	 *
	 * Falls back to the platform default rather than to a hard-coded name, so adding a theme stays
	 * the "zero edits" operation the theme's own token contract promises.
	 */
	public function theme(): string {
		return $this->remember(
			'theme',
			function (): string {
				$store = $this->store();
				$theme = $store ? $store->theme() : '';

				if ( '' !== $theme && $this->theme_exists( $theme ) ) {
					return $theme;
				}

				return $this->default_theme();
			}
		);
	}

	/**
	 * Is this theme identity registered?
	 *
	 * Reads the theme's own `yazan_themes` filter, so a store cannot be pinned to a theme that was
	 * removed — it silently falls back rather than rendering with no tokens at all.
	 */
	private function theme_exists( string $theme ): bool {
		$themes = function_exists( 'yazan_themes' ) ? (array) yazan_themes() : array();

		return isset( $themes[ $theme ] );
	}

	/** The platform default theme identity. */
	private function default_theme(): string {
		if ( function_exists( 'yazan_default_theme' ) ) {
			return (string) yazan_default_theme();
		}

		$themes = function_exists( 'yazan_themes' ) ? (array) yazan_themes() : array();
		$keys   = array_keys( $themes );

		return $keys[0] ?? '';
	}

	/* ------------------------------------------------------------------ */
	/* 5. Enabled modules                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Feature flags for the active store.
	 *
	 * @return array<string,bool>
	 */
	public function modules(): array {
		return $this->remember(
			'modules',
			fn(): array => $this->modules->for_store( $this->id(), $this->settings() )
		);
	}

	/**
	 * Is a module enabled for this store?
	 *
	 * DEFAULT-OPEN, matching the convention already established by the rewards engine's
	 * `feature_enabled()`. A store that has never been configured behaves like the platform, which
	 * is what makes creating a store a one-field operation instead of a checklist.
	 */
	public function module_enabled( string $module ): bool {
		$modules = $this->modules();

		return array_key_exists( $module, $modules ) ? (bool) $modules[ $module ] : true;
	}

	/* ------------------------------------------------------------------ */
	/* 6. Store permissions                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Does a user hold a permission IN THIS STORE?
	 *
	 * Delegates to yazan-core's RBAC, which already carries a trailing `$store_id` argument. This
	 * method exists so store-aware code has one obvious call rather than each caller remembering
	 * to pass the third argument — forgetting it silently resolves against the ACTIVE store, which
	 * is right in a request and wrong inside run_for().
	 *
	 * @param string   $permission Permission slug.
	 * @param int|null $user_id    User, or null for the current user.
	 * @return bool
	 */
	public function user_can( string $permission, ?int $user_id = null ): bool {
		if ( ! class_exists( 'Yazan_Permissions' ) ) {
			return false;
		}

		return (bool) \Yazan_Permissions::can( $permission, $user_id, $this->id() );
	}

	/* ------------------------------------------------------------------ */
	/* Memoisation                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Cache a derived value for as long as the context does not change.
	 *
	 * The key carries the kernel's epoch, which is bumped on every run_for() push AND pop. A value
	 * computed under store A is therefore unreachable once the context moves, rather than merely
	 * out of date — no invalidation call to forget, and no window in which a stale entry is
	 * readable.
	 *
	 * @param string   $slice   Cache slice name.
	 * @param callable $compute Producer.
	 * @return mixed
	 */
	private function remember( string $slice, callable $compute ) {
		$epoch = class_exists( 'Yazan_Store_Context' ) ? \Yazan_Store_Context::epoch() : 0;
		$key   = $this->id() . '|' . $epoch . '|' . $slice;

		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $compute();
		}

		return $this->memo[ $key ];
	}

	/** Drop the per-request memo. Used after a write, and by tests. */
	public function flush(): void {
		$this->memo = array();
	}
}
