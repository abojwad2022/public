<?php
/**
 * Dynamic rule engine.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Contracts\SubscriberInterface;
use Yazan\Rewards\Core\Events\Event;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes admin-defined EVENT→CONDITIONS→ACTIONS rules. Subscribes to domain
 * events and to a generic `yazan_rewards/trigger/{event}` action (so any event,
 * including the data-less Yazan ones, can drive rules). For each incoming event it
 * builds the context, finds matching action-rules, applies an idempotency guard,
 * and runs their actions via the {@see ActionExecutor}.
 */
final class RuleEngine implements SubscriberInterface {

	/**
	 * @param RuleRepository   $rules    Rule repository.
	 * @param ConditionMatcher $matcher  Condition matcher.
	 * @param ContextBuilder   $context  Context builder.
	 * @param ActionExecutor   $executor Action executor.
	 * @param EventCatalog     $events   Event catalog.
	 * @param Settings         $settings Settings.
	 */
	public function __construct(
		private RuleRepository $rules,
		private ConditionMatcher $matcher,
		private ContextBuilder $context,
		private ActionExecutor $executor,
		private EventCatalog $events,
		private Settings $settings
	) {}

	/**
	 * @inheritDoc
	 */
	public function subscribed_events(): array {
		return array(
			Events::ORDER_REWARDED         => array( 'on_order', 50 ),
			Events::USER_REGISTERED        => array( 'on_registered', 50 ),
			Events::REVIEW_CREATED         => array( 'on_review', 50 ),
			Events::REFERRAL_CONVERTED     => array( 'on_referral', 50 ),
			Events::SOCIAL_ACTION_APPROVED => array( 'on_social', 50 ),
		);
	}

	/**
	 * Register the generic `yazan_rewards/trigger/{event}` hooks for every catalog
	 * event, so external code (theme/yazan-core) can fire any event — including the
	 * data-less ones (birthday, ring_registered, warranty_activated, profile_completed).
	 * Called from the module's boot().
	 *
	 * @return void
	 */
	public function register_triggers(): void {
		foreach ( $this->events->keys() as $key ) {
			add_action(
				'yazan_rewards/trigger/' . $key,
				function ( $user_id, $ctx = array() ) use ( $key ) {
					$this->process( $key, (int) $user_id, is_array( $ctx ) ? $ctx : array() );
				},
				10,
				2
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Domain-event handlers                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * @param Event $e order_rewarded.
	 * @return void
	 */
	public function on_order( Event $e ): void {
		$user_id  = (int) $e->get( 'customer_id' );
		$order_id = (int) $e->get( 'order_id' );
		if ( $user_id <= 0 || $order_id <= 0 ) {
			return;
		}
		$raw = $this->order_context( $order_id, (float) $e->get( 'order_total' ) );

		// One order satisfies several event-keys; conditions differentiate.
		$keys = array( 'order_completed', 'order_amount', 'product_purchased', 'category_purchased' );
		if ( $this->is_first_order( $user_id ) ) {
			$keys[] = 'first_purchase';
		}
		foreach ( $keys as $key ) {
			$this->process( $key, $user_id, $raw );
		}
	}

	/**
	 * @param Event $e user_registered.
	 * @return void
	 */
	public function on_registered( Event $e ): void {
		$this->process( 'account_created', (int) $e->get( 'user_id' ), array() );
	}

	/**
	 * @param Event $e review_created.
	 * @return void
	 */
	public function on_review( Event $e ): void {
		$this->process( 'review_submitted', (int) $e->get( 'user_id' ), array( 'product_ids' => array( (int) $e->get( 'product_id' ) ) ) );
	}

	/**
	 * @param Event $e referral_converted.
	 * @return void
	 */
	public function on_referral( Event $e ): void {
		// Pass order_total so `per_currency` referral rewards + order-value conditions
		// can compute — but deliberately NOT order_id: the guard treats any order-scoped
		// event as "once per order", which would bypass a referral rule's per_user_cap
		// (each conversion is a different order). Referral rules are capped per referrer.
		$this->process(
			'referral_completed',
			(int) $e->get( 'referrer_id' ),
			array(
				'referral_id' => (int) $e->get( 'referral_id' ),
				'order_total' => (float) $e->get( 'order_total' ),
			)
		);
	}

	/**
	 * @param Event $e social_action_approved.
	 * @return void
	 */
	public function on_social( Event $e ): void {
		// Fire the event that matches the submission's media type (now captured on the
		// social action). Video/reel drive `video_submitted`, everything else `photo_submitted`.
		$media = (string) $e->get( 'media_type' );
		$event = in_array( $media, array( 'video', 'reel' ), true ) ? 'video_submitted' : 'photo_submitted';
		$this->process(
			$event,
			(int) $e->get( 'user_id' ),
			array( 'action_id' => (int) $e->get( 'action_id' ), 'media_type' => $media )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Core                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Run every matching action-rule for an event.
	 *
	 * @param string $event_key Event key.
	 * @param int    $user_id   User id.
	 * @param array  $raw       Raw event facts.
	 * @return void
	 */
	public function process( string $event_key, int $user_id, array $raw ): void {
		if ( $user_id <= 0 || ! $this->settings->feature_enabled( 'rules' ) ) {
			return;
		}

		$rules = array_filter(
			$this->rules->active_for_event( $event_key ),
			static fn( Rule $r ) => $r->has_actions() && $r->is_live()
		);
		if ( empty( $rules ) ) {
			return;
		}

		$context = $this->context->build( $user_id, $raw );

		foreach ( $rules as $rule ) {
			if ( ! $this->matcher->matches( $rule->conditions, $context ) ) {
				continue;
			}
			if ( ! $this->guard_ok( $rule, $user_id, $raw ) ) {
				continue;
			}
			$this->executor->run( $rule, $user_id, $context );
			$this->mark_ran( $rule, $user_id, $raw );
		}
	}

	/**
	 * Idempotency / cap gate. Order-scoped events run at most once per order;
	 * other events honour per_user_cap.
	 *
	 * @param Rule  $rule    Rule.
	 * @param int   $user_id User id.
	 * @param array $raw     Raw facts (may carry order_id).
	 * @return bool
	 */
	private function guard_ok( Rule $rule, int $user_id, array $raw ): bool {
		$order_id = (int) ( $raw['order_id'] ?? 0 );
		if ( $order_id > 0 ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $order instanceof \WC_Order ) {
				return '' === (string) $order->get_meta( '_yzrw_rule_ran_' . $rule->id );
			}
			return true;
		}

		if ( $rule->per_user_cap > 0 ) {
			$runs = (int) get_user_meta( $user_id, '_yzrw_rule_runs_' . $rule->id, true );
			return $runs < $rule->per_user_cap;
		}
		return true; // Uncapped non-order event.
	}

	/**
	 * Record that a rule ran (matching the guard's keys).
	 *
	 * @param Rule  $rule    Rule.
	 * @param int   $user_id User id.
	 * @param array $raw     Raw facts.
	 * @return void
	 */
	private function mark_ran( Rule $rule, int $user_id, array $raw ): void {
		$order_id = (int) ( $raw['order_id'] ?? 0 );
		if ( $order_id > 0 ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $order instanceof \WC_Order ) {
				$order->update_meta_data( '_yzrw_rule_ran_' . $rule->id, 'yes' );
				$order->save();
			}
			return;
		}
		if ( $rule->per_user_cap > 0 ) {
			$runs = (int) get_user_meta( $user_id, '_yzrw_rule_runs_' . $rule->id, true );
			update_user_meta( $user_id, '_yzrw_rule_runs_' . $rule->id, $runs + 1 );
		}
	}

	/**
	 * Build order facts for the context.
	 *
	 * @param int   $order_id Order id.
	 * @param float $total    Order total.
	 * @return array<string,mixed>
	 */
	private function order_context( int $order_id, float $total ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$products = array();
		$cats     = array();
		$payment  = '';

		if ( $order instanceof \WC_Order ) {
			$total   = (float) $order->get_total();
			$payment = (string) $order->get_payment_method();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$pid        = (int) $item->get_product_id();
					$products[] = $pid;
					$cats       = array_merge( $cats, (array) wc_get_product_cat_ids( $pid ) );
				}
			}
		}

		return array(
			'order_id'       => $order_id,
			'order_total'    => $total,
			'payment_method' => $payment,
			'product_ids'    => array_values( array_unique( array_map( 'intval', $products ) ) ),
			'product_cats'   => array_values( array_unique( array_map( 'intval', $cats ) ) ),
		);
	}

	/**
	 * Whether the customer's completed order count is exactly one.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_first_order( int $user_id ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-completed', 'wc-processing' ),
				'limit'       => 2,
				'return'      => 'ids',
			)
		);
		return is_array( $orders ) && count( $orders ) === 1;
	}
}
