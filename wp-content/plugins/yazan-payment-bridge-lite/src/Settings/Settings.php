<?php
/**
 * Settings accessor + sanitizer.
 *
 * @package Yazan\PaymentBridge
 */

declare( strict_types=1 );

namespace Yazan\PaymentBridge\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and validates the single settings option. Registered through the
 * WordPress Settings API with this class's sanitize() as the callback (H5).
 */
final class Settings {

	/** Option name holding the settings array. */
	public const OPTION = 'yazan_payment_bridge_settings';

	/** Settings API group. */
	public const GROUP = 'yazan_payment_bridge';

	/**
	 * Merged settings, keyed by store and store-context epoch.
	 *
	 * Keyed rather than a plain `?array` because this object outlives a store switch: a job that
	 * iterates stores would otherwise process all of them with the first store's gateway config.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cache = array();

	/**
	 * Shipped defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = require YAZAN_PB_DIR . 'config/default-settings.php';
		return is_array( $defaults ) ? $defaults : array();
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$key = $this->cache_key();

		if ( ! isset( $this->cache[ $key ] ) ) {
			$stored = \class_exists( 'Yazan_Store_Options' )
				? \Yazan_Store_Options::get( self::OPTION, array() )
				: \get_option( self::OPTION, array() );

			$this->cache[ $key ] = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->cache[ $key ];
	}

	/**
	 * Cache key for the active store.
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
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Read a boolean setting.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_enabled( string $key ): bool {
		return (bool) $this->get( $key, false );
	}

	/**
	 * Drop the in-request cache (used after a save, and by tests).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * The anchored SKU pattern, or '' when the stored pattern is unusable.
	 *
	 * @return string
	 */
	public function sku_pattern(): string {
		$pattern = trim( (string) $this->get( 'sku_pattern', '' ) );
		return self::is_valid_pattern( $pattern ) ? $pattern : '';
	}

	/**
	 * Whether a pattern compiles and is anchored at both ends (spec requirement:
	 * strict anchored matching, never a substring test).
	 *
	 * @param string $pattern Bare regex body, without delimiters.
	 * @return bool
	 */
	public static function is_valid_pattern( string $pattern ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		if ( ! str_starts_with( $pattern, '^' ) || ! str_ends_with( $pattern, '$' ) ) {
			return false;
		}

		// Compile-test against an empty subject; a broken pattern returns false.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== @preg_match( '/' . $pattern . '/', '' );
	}

	/**
	 * Settings API sanitize callback (H5).
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->all();

		$clean = array(
			'enable_ownership'         => empty( $input['enable_ownership'] ) ? 0 : 1,
			'enable_warranty'          => empty( $input['enable_warranty'] ) ? 0 : 1,
			'debug_logging'            => empty( $input['debug_logging'] ) ? 0 : 1,
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
		);

		$pattern = isset( $input['sku_pattern'] ) ? trim( sanitize_text_field( wp_unslash( (string) $input['sku_pattern'] ) ) ) : '';

		if ( '' === $pattern ) {
			// Empty is legitimate: eligibility then relies on the serial alone.
			$clean['sku_pattern'] = '';
		} elseif ( self::is_valid_pattern( $pattern ) ) {
			$clean['sku_pattern'] = $pattern;
		} else {
			$clean['sku_pattern'] = (string) ( $current['sku_pattern'] ?? '' );
			add_settings_error(
				self::OPTION,
				'yazan_pb_bad_pattern',
				__( 'The SKU pattern was rejected: it must compile as a regular expression and be anchored with ^ and $. The previous value was kept.', 'yazan-payment-bridge' ),
				'error'
			);
		}

		$this->cache = null;

		return $clean;
	}
}
