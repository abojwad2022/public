<?php
/**
 * Typed settings repository.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the option group, merged with defaults. Supports dotted paths
 * (e.g. "points.signup_bonus") so engines read their own slice cleanly.
 *
 * STORE-AWARE. The option is resolved through `Yazan_Store_Options`, which returns the current
 * store's own settings if it has any and the platform's otherwise. Nothing else in this class
 * changes: the defaults tree, the dotted paths and `feature_enabled()` behave exactly as before,
 * because the tenancy question is answered one layer down.
 */
final class Settings {

	/**
	 * Merged settings, keyed by store and store-context epoch.
	 *
	 * ⚠️ THIS USED TO BE A PLAIN `?array`, AND THAT WAS THE BUG. This object is a singleton built
	 * once in `Core\Plugin`, so a single unkeyed cache meant the first store read during a request
	 * pinned its settings for every store read afterwards — a background job iterating stores would
	 * price all of them with the first store's rules and look completely correct doing it. The
	 * kernel names this class of defect explicitly in its own docblock.
	 *
	 * The epoch is bumped by `Yazan_Store_Context::run_for()` on both push and pop, so an entry
	 * cached inside a store switch becomes unreachable rather than merely stale.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cache = array();

	/**
	 * @param string              $option_name Option key.
	 * @param array<string,mixed> $defaults    Default settings tree.
	 */
	public function __construct(
		private string $option_name,
		private array $defaults
	) {}

	/**
	 * All settings, defaults merged with saved values.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$key = $this->cache_key();

		if ( ! isset( $this->cache[ $key ] ) ) {
			$saved = \class_exists( 'Yazan_Store_Options' )
				? \Yazan_Store_Options::get( $this->option_name, array() )
				: \get_option( $this->option_name, array() );

			$this->cache[ $key ] = $this->merge_deep( $this->defaults, is_array( $saved ) ? $saved : array() );
		}

		return $this->cache[ $key ];
	}

	/**
	 * Cache key for the active store.
	 *
	 * Falls back to a constant when the tenancy kernel is absent, which restores the old
	 * single-entry behaviour rather than disabling the cache.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		if ( ! \class_exists( 'Yazan_Store_Context' ) ) {
			return 'global';
		}

		return \Yazan_Store_Context::current() . '|' . \Yazan_Store_Context::epoch();
	}

	/**
	 * Read a value by dotted path.
	 *
	 * @param string $path    e.g. "points.signup_bonus".
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	/**
	 * Whether an engine feature flag is on.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function feature_enabled( string $feature ): bool {
		return (bool) $this->get( 'features.' . $feature, true );
	}

	/**
	 * Persist the full settings tree (already validated by the caller).
	 *
	 * @param array<string,mixed> $settings New settings.
	 * @return void
	 */
	public function save( array $settings ): void {
		$merged = $this->merge_deep( $this->defaults, $settings );

		if ( \class_exists( 'Yazan_Store_Options' ) ) {
			\Yazan_Store_Options::set( $this->option_name, $merged );
		} else {
			\update_option( $this->option_name, $merged, false );
		}

		// Only the store that was just written. A save in one store must not evict another's entry
		// — that is how a "refresh" turns into a cross-tenant read.
		$this->cache[ $this->cache_key() ] = $merged;

		// A feature toggle or cron setting may have changed → let the recurring-job
		// scheduling pass re-evaluate on the next request.
		delete_option( 'yazan_rewards_cron_ready' );
	}

	/**
	 * Recursive array merge where scalar/leaf values from $override win.
	 *
	 * @param array $base     Base (defaults).
	 * @param array $override Overrides (saved).
	 * @return array
	 */
	private function merge_deep( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->merge_deep( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
