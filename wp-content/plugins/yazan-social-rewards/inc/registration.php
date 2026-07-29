<?php
/**
 * Developer registration API.
 *
 * Global helper functions that let third-party plugins extend the platform without
 * touching its code. Each is a thin, documented wrapper over an existing filter/
 * registry seam. Call them at your plugin's load time (before `plugins_loaded`
 * priority 20, when the module/connector/event filters are read) — e.g. directly in
 * your main file or on an early `plugins_loaded` hook.
 *
 * @package Yazan\Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'yazan_register_module' ) ) {
	/**
	 * Register a third-party engine module.
	 *
	 * The class must implement {@see \Yazan\Rewards\Core\Contracts\ModuleInterface}
	 * (or extend {@see \Yazan\Rewards\Core\Module\AbstractModule}) and have a no-arg
	 * constructor — dependencies arrive via the Container in register()/boot().
	 *
	 * @param string $module_class Fully-qualified module class name.
	 * @return void
	 */
	function yazan_register_module( string $module_class ): void {
		add_filter(
			'yazan_rewards/modules',
			static function ( $modules ) use ( $module_class ) {
				$modules   = (array) $modules;
				$modules[] = $module_class;
				return $modules;
			}
		);
	}
}

if ( ! function_exists( 'yazan_register_connector' ) ) {
	/**
	 * Register a social connector.
	 *
	 * The class must implement {@see \Yazan\Rewards\Core\Contracts\ConnectorInterface}
	 * (typically by extending {@see \Yazan\Rewards\Modules\Social\Connectors\AbstractConnector})
	 * and accept the fixed constructor `( SocialSecrets, Http, Settings )`.
	 *
	 * @param string $connector_class Fully-qualified connector class name.
	 * @return void
	 */
	function yazan_register_connector( string $connector_class ): void {
		add_filter(
			'yazan_rewards/social/connectors',
			static function ( $connectors ) use ( $connector_class ) {
				$connectors   = (array) $connectors;
				$connectors[] = $connector_class;
				return $connectors;
			}
		);
	}
}

if ( ! function_exists( 'yazan_register_reward_provider' ) ) {
	/**
	 * Register a custom reward fulfillment provider for a reward type.
	 *
	 * At redemption, a reward whose `type` matches is fulfilled by this provider
	 * (after points are debited); returning `ok => false` triggers an automatic
	 * points refund. Overrides a built-in type if the key collides.
	 *
	 * @param string                                                   $type     Reward type key (e.g. "gift_card").
	 * @param \Yazan\Rewards\Modules\Rewards\RewardIssuerInterface     $provider Fulfillment provider.
	 * @return void
	 */
	function yazan_register_reward_provider( string $type, \Yazan\Rewards\Modules\Rewards\RewardIssuerInterface $provider ): void {
		add_filter(
			'yazan_rewards/rewards/providers',
			static function ( $registry ) use ( $type, $provider ) {
				if ( $registry instanceof \Yazan\Rewards\Modules\Rewards\RewardProviderRegistry ) {
					$registry->register( $type, $provider );
				}
				return $registry;
			}
		);
	}
}

if ( ! function_exists( 'yazan_register_event' ) ) {
	/**
	 * Register a custom event so reward rules can react to it.
	 *
	 * Adds the event to the rules catalog (selectable in the rule builder) and — via
	 * {@see \Yazan\Rewards\Modules\Rules\RuleEngine} — auto-wires a trigger action.
	 * The extension fires the event with:
	 *   `do_action( 'yazan_rewards/trigger/{key}', int $user_id, array $context );`
	 *
	 * @param string               $key  Event key (sanitized to a slug).
	 * @param array<string,mixed>  $args Optional: { label, group, wired }.
	 * @return void
	 */
	function yazan_register_event( string $key, array $args = array() ): void {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}
		add_filter(
			'yazan_rewards/rules/event_catalog',
			static function ( $groups ) use ( $key, $args ) {
				$groups      = (array) $groups;
				$label       = isset( $args['label'] ) ? (string) $args['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
				$group_label = isset( $args['group'] ) ? (string) $args['group'] : __( 'Extensions', 'yazan-rewards' );
				$entry       = array( 'key' => $key, 'label' => $label, 'wired' => ! empty( $args['wired'] ) );

				foreach ( $groups as &$group ) {
					if ( isset( $group['label'] ) && $group['label'] === $group_label ) {
						$group['events'][] = $entry;
						unset( $group );
						return $groups;
					}
				}
				unset( $group );

				$groups[] = array( 'label' => $group_label, 'events' => array( $entry ) );
				return $groups;
			}
		);
	}
}
