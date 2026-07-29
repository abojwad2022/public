<?php
/**
 * Supported-events catalog.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalog of events a rule can react to. Grouped for the admin UI. Events
 * with a live trigger are wired by {@see RuleEngine}; the data-less Yazan events
 * are fired via `do_action( 'yazan_rewards/trigger/{key}', $user_id, $context )`
 * by the theme/yazan-core when that data exists.
 */
final class EventCatalog {

	/**
	 * All event keys (used to validate rule input).
	 *
	 * @return string[]
	 */
	public function keys(): array {
		$keys = array();
		foreach ( $this->groups() as $group ) {
			foreach ( $group['events'] as $event ) {
				$keys[] = $event['key'];
			}
		}
		return $keys;
	}

	/**
	 * Whether an event key is supported.
	 *
	 * @param string $key Event key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return in_array( $key, $this->keys(), true );
	}

	/**
	 * Grouped events for the UI.
	 *
	 * @return array<int,array{label:string,events:array<int,array{key:string,label:string,wired:bool}>}>
	 */
	public function groups(): array {
		$groups = array(
			array(
				'label'  => __( 'WooCommerce', 'yazan-rewards' ),
				'events' => array(
					array( 'key' => 'account_created', 'label' => __( 'Account created', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'first_purchase', 'label' => __( 'First purchase', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'order_completed', 'label' => __( 'Order completed', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'product_purchased', 'label' => __( 'Product purchased', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'category_purchased', 'label' => __( 'Category purchased', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'order_amount', 'label' => __( 'Order amount reached', 'yazan-rewards' ), 'wired' => true ),
				),
			),
			array(
				'label'  => __( 'Customer', 'yazan-rewards' ),
				'events' => array(
					array( 'key' => 'birthday', 'label' => __( 'Birthday', 'yazan-rewards' ), 'wired' => false ),
					array( 'key' => 'login', 'label' => __( 'Login', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'profile_completed', 'label' => __( 'Profile completion', 'yazan-rewards' ), 'wired' => false ),
					array( 'key' => 'ring_registered', 'label' => __( 'Ring registration', 'yazan-rewards' ), 'wired' => false ),
					array( 'key' => 'warranty_activated', 'label' => __( 'Warranty activation', 'yazan-rewards' ), 'wired' => false ),
				),
			),
			array(
				'label'  => __( 'Social', 'yazan-rewards' ),
				'events' => array(
					array( 'key' => 'photo_submitted', 'label' => __( 'Photo submitted', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'video_submitted', 'label' => __( 'Video submitted', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'review_submitted', 'label' => __( 'Review submitted', 'yazan-rewards' ), 'wired' => true ),
					array( 'key' => 'referral_completed', 'label' => __( 'Referral completed', 'yazan-rewards' ), 'wired' => true ),
				),
			),
		);

		/**
		 * Filter the event catalog so add-ons can register new events.
		 *
		 * @param array $groups Event groups.
		 */
		return (array) apply_filters( 'yazan_rewards/rules/event_catalog', $groups );
	}
}
