<?php
/**
 * Rule action executor.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Events\Events;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Events\GenericEvent;
use Yazan\Rewards\Core\Support\Logger;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Modules\Achievement\AchievementRepository;
use Yazan\Rewards\Modules\Achievement\TierEngine;
use Yazan\Rewards\Modules\Notification\NotificationDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a matched rule's ACTIONS by orchestrating the existing engines, resolved
 * from the container by contract. Optional engines (achievements, notifications)
 * are resolved with `has()` so a rule degrades gracefully when a module is off —
 * it executes the actions it can and skips the rest.
 */
final class ActionExecutor {

	/**
	 * @param Container             $container Service container.
	 * @param PointsLedgerInterface $points    Points ledger.
	 * @param ActionCouponFactory   $coupons   Coupon minter.
	 * @param Money                 $money     Rounding helper.
	 * @param EventBus              $bus       Event bus.
	 * @param Logger                $logger    Logger.
	 */
	public function __construct(
		private Container $container,
		private PointsLedgerInterface $points,
		private ActionCouponFactory $coupons,
		private Money $money,
		private EventBus $bus,
		private Logger $logger
	) {}

	/**
	 * Execute every action in a rule.
	 *
	 * @param Rule  $rule    The matched rule.
	 * @param int   $user_id User id.
	 * @param array $context Matching context.
	 * @return array<int,array> Per-action results (for logging/response).
	 */
	public function run( Rule $rule, int $user_id, array $context ): array {
		$results = array();
		foreach ( $rule->actions as $raw ) {
			$action = Action::from_array( $raw );
			if ( ! $action ) {
				continue;
			}
			$results[] = $this->one( $action, $rule, $user_id, $context );
		}
		return $results;
	}

	/**
	 * Execute a single action.
	 *
	 * @param Action $action  Action.
	 * @param Rule   $rule    Owning rule.
	 * @param int    $user_id User id.
	 * @param array  $context Context.
	 * @return array
	 */
	private function one( Action $action, Rule $rule, int $user_id, array $context ): array {
		switch ( $action->type ) {
			case Action::ADD_POINTS:
				return $this->add_points( $action, $rule, $user_id, $context );

			case Action::REMOVE_POINTS:
				$amount = (int) abs( (int) $action->param( 'amount', 0 ) );
				if ( $amount > 0 ) {
					$this->points->debit( $user_id, $amount, 'rule', $rule->id, array( 'type' => 'adjust', 'note' => $rule->name ) );
				}
				return array( 'action' => $action->type, 'points' => -$amount );

			case Action::UPGRADE_LEVEL:
				$ok = false;
				if ( $this->container->has( TierEngine::class ) ) {
					$ok = $this->container->get( TierEngine::class )->set_tier( $user_id, sanitize_key( (string) $action->param( 'tier', '' ) ) );
				}
				return array( 'action' => $action->type, 'ok' => $ok, 'tier' => (string) $action->param( 'tier', '' ) );

			case Action::GIVE_BADGE:
				return $this->give_badge( $action, $user_id );

			case Action::CREATE_COUPON:
				$result = $this->coupons->create( $user_id, (array) $action->params );
				return array( 'action' => $action->type, 'ok' => ! empty( $result['ok'] ), 'code' => $result['code'] ?? '' );

			case Action::SEND_NOTIFICATION:
				return $this->send_notification( $action, $user_id );

			default:
				/**
				 * Execute a custom action type registered by an add-on.
				 *
				 * @param Action $action  Action.
				 * @param int    $user_id User id.
				 * @param array  $context Context.
				 * @param Rule   $rule    Rule.
				 */
				do_action( 'yazan_rewards/rules/execute_action', $action, $user_id, $context, $rule );
				return array( 'action' => $action->type, 'custom' => true );
		}
	}

	/**
	 * add_points: fixed amount or per-currency of the order total.
	 *
	 * @param Action $action  Action.
	 * @param Rule   $rule    Rule.
	 * @param int    $user_id User id.
	 * @param array  $context Context.
	 * @return array
	 */
	private function add_points( Action $action, Rule $rule, int $user_id, array $context ): array {
		$per_currency = (float) $action->param( 'per_currency', 0 );
		if ( $per_currency > 0 ) {
			$points = $this->money->round( $per_currency * (float) ( $context['order_total'] ?? 0 ), 'floor' );
		} else {
			$points = (int) abs( (int) $action->param( 'amount', 0 ) );
		}
		if ( $points > 0 ) {
			$this->points->credit( $user_id, $points, 'rule', $rule->id, array( 'type' => 'earn', 'note' => $rule->name ) );
		}
		return array( 'action' => $action->type, 'points' => $points );
	}

	/**
	 * give_badge: unlock an achievement by key and award its points once.
	 *
	 * @param Action $action  Action.
	 * @param int    $user_id User id.
	 * @return array
	 */
	private function give_badge( Action $action, int $user_id ): array {
		if ( ! $this->container->has( AchievementRepository::class ) ) {
			return array( 'action' => $action->type, 'ok' => false );
		}
		$repo = $this->container->get( AchievementRepository::class );
		$def  = $repo->by_key( (string) $action->param( 'achievement', '' ) );
		if ( ! $def ) {
			return array( 'action' => $action->type, 'ok' => false );
		}

		if ( ! $repo->unlock( $user_id, (int) $def->id ) ) {
			return array( 'action' => $action->type, 'ok' => true, 'already' => true ); // Already had it.
		}

		$award = (int) $def->points_award;
		if ( $award > 0 ) {
			$this->points->credit( $user_id, $award, 'achievement', (int) $def->id, array( 'type' => 'earn', 'note' => (string) $def->name ) );
		}
		$this->bus->dispatch(
			new GenericEvent(
				Events::ACHIEVEMENT_UNLOCKED,
				array( 'user_id' => $user_id, 'achievement_id' => (int) $def->id, 'key' => (string) $def->akey, 'name' => (string) $def->name, 'points_award' => $award )
			)
		);
		return array( 'action' => $action->type, 'ok' => true, 'badge' => (string) $def->akey );
	}

	/**
	 * send_notification: dispatch a custom (or named-template) notification.
	 *
	 * @param Action $action  Action.
	 * @param int    $user_id User id.
	 * @return array
	 */
	private function send_notification( Action $action, int $user_id ): array {
		if ( ! $this->container->has( NotificationDispatcher::class ) ) {
			return array( 'action' => $action->type, 'ok' => false );
		}
		$template = sanitize_key( (string) $action->param( 'template', 'custom' ) );
		$payload  = array(
			'subject' => (string) $action->param( 'subject', '' ),
			'message' => (string) $action->param( 'message', '' ),
		);
		$this->container->get( NotificationDispatcher::class )->notify( $user_id, $template, $payload );
		return array( 'action' => $action->type, 'ok' => true );
	}
}
