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
 * Reads/writes the single option group, merged with defaults. Supports dotted
 * paths (e.g. "points.signup_bonus") so engines read their own slice cleanly.
 */
final class Settings {

	/**
	 * Merged settings cache (null until first read).
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

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
		if ( null === $this->cache ) {
			$saved       = get_option( $this->option_name, array() );
			$this->cache = $this->merge_deep( $this->defaults, is_array( $saved ) ? $saved : array() );
		}
		return $this->cache;
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
		update_option( $this->option_name, $merged, false );
		$this->cache = $merged;

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
