<?php
/**
 * Which feature modules are enabled for a store.
 *
 * Follows the pattern the rewards engine already proved: a flat map of flags, DEFAULT-OPEN, read
 * once at boot. Two differences, both because this map is per-store rather than per-install:
 *
 *   - the source is the store's own settings rows, not one global option;
 *   - the catalogue of known modules is a filter, so a plugin can declare a module without this
 *     class growing a hard-coded list.
 *
 * @package Yazan\Stores
 */

declare( strict_types=1 );

namespace Yazan\Stores\Application;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-store feature flags.
 */
final class StoreModules {

	/** Settings keys carrying a module flag are prefixed with this. */
	public const SETTING_PREFIX = 'module.';

	/**
	 * The modules a store can toggle.
	 *
	 * Values are the DEFAULTS for a store that has said nothing. Everything defaults to enabled:
	 * creating a store should give you a working store, not a checklist. Turning something off is
	 * the deliberate act.
	 *
	 * @return array<string,bool>
	 */
	public function catalogue(): array {
		$modules = array(
			'storefront'   => true,
			'checkout'     => true,
			'rewards'      => true,
			'homepage'     => true,
			'ai'           => true,
			'social_auth'  => true,
			'wishlist'     => true,
			'notifications'=> true,
		);

		/**
		 * Filter the per-store module catalogue.
		 *
		 * A plugin adds its module here and it becomes toggleable per store with no change to this
		 * class. The value is the default for a store that has not chosen.
		 *
		 * @param array<string,bool> $modules module id => default enabled.
		 */
		$modules = (array) apply_filters( 'yazan_stores_module_catalogue', $modules );

		return array_map( 'boolval', $modules );
	}

	/**
	 * Resolve flags for one store.
	 *
	 * Settings are passed in rather than fetched so this stays a pure function of its inputs — it
	 * is called from StoreContext, which has already loaded them and does not need a second query.
	 *
	 * @param int                   $store_id Store.
	 * @param array<string,string>  $settings The store's settings rows.
	 * @return array<string,bool>
	 */
	public function for_store( int $store_id, array $settings ): array {
		$flags = $this->catalogue();

		foreach ( $settings as $key => $value ) {
			if ( 0 !== strpos( $key, self::SETTING_PREFIX ) ) {
				continue;
			}

			$module = substr( $key, strlen( self::SETTING_PREFIX ) );

			// Only known modules. An unknown key is stale configuration, not a new feature.
			if ( '' === $module || ! array_key_exists( $module, $flags ) ) {
				continue;
			}

			$flags[ $module ] = in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		/**
		 * Filter the resolved flags for a specific store.
		 *
		 * @param array<string,bool> $flags    Resolved flags.
		 * @param int                $store_id Store id.
		 */
		return (array) apply_filters( 'yazan_stores_module_flags', $flags, $store_id );
	}
}
