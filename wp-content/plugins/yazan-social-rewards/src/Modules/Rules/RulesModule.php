<?php
/**
 * Rules engine module.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Modules\Rules;

use Yazan\Rewards\Core\Container;
use Yazan\Rewards\Core\Contracts\Installable;
use Yazan\Rewards\Core\Contracts\PointsLedgerInterface;
use Yazan\Rewards\Core\Contracts\RulesEngineInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Module\AbstractModule;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Logger;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Core\Support\Scheduler;
use Yazan\Rewards\Admin\RulesAdminPage;
use Yazan\Rewards\Integration\WordPress\LoginObserver;
use Yazan\Rewards\Rest\V1\RulesController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `rules` table, the legacy points evaluator (bound as
 * RulesEngineInterface for the base earn loop), and the dynamic
 * EVENT→CONDITIONS→ACTIONS engine (RuleEngine + executor + catalogs + admin UI).
 *
 * No hard module dependency: the engine resolves Points/Achievement/Notification
 * services lazily at boot/execution time, so declaring a dependency here (Points
 * already depends on Rules) would create a cycle.
 */
final class RulesModule extends AbstractModule implements Installable {

	/**
	 * @inheritDoc
	 */
	public function id(): string {
		return 'rules';
	}

	/**
	 * @inheritDoc
	 */
	public function register( Container $c ): void {
		$c->singleton( RuleRepository::class, static fn( Container $c ) => new RuleRepository( $c->get( Database::class ) ) );
		$c->singleton( ConditionMatcher::class, static fn() => new ConditionMatcher() );
		$c->singleton(
			RuleEvaluator::class,
			static fn( Container $c ) => new RuleEvaluator(
				$c->get( RuleRepository::class ),
				$c->get( ConditionMatcher::class ),
				$c->get( Money::class )
			)
		);
		// Contract binding — this is what the base earn loop depends on.
		$c->alias( RulesEngineInterface::class, RuleEvaluator::class );

		/* --- Dynamic rules engine (EVENT → CONDITIONS → ACTIONS) --------- */
		$c->singleton( ContextBuilder::class, static fn( Container $c ) => new ContextBuilder( $c ) );
		$c->singleton( ActionCouponFactory::class, static fn() => new ActionCouponFactory() );
		$c->singleton( EventCatalog::class, static fn() => new EventCatalog() );
		$c->singleton( ConditionCatalog::class, static fn() => new ConditionCatalog() );
		$c->singleton( ActionCatalog::class, static fn() => new ActionCatalog() );

		$c->singleton(
			ActionExecutor::class,
			static fn( Container $c ) => new ActionExecutor(
				$c,
				$c->get( PointsLedgerInterface::class ),
				$c->get( ActionCouponFactory::class ),
				$c->get( Money::class ),
				$c->get( EventBus::class ),
				$c->get( Logger::class )
			)
		);
		$c->singleton(
			RuleEngine::class,
			static fn( Container $c ) => new RuleEngine(
				$c->get( RuleRepository::class ),
				$c->get( ConditionMatcher::class ),
				$c->get( ContextBuilder::class ),
				$c->get( ActionExecutor::class ),
				$c->get( EventCatalog::class ),
				$c->get( Settings::class )
			)
		);
		$c->singleton( BirthdayScheduler::class, static fn( Container $c ) => new BirthdayScheduler( $c->get( Scheduler::class ) ) );
		$c->singleton( LoginObserver::class, static fn() => new LoginObserver() );
		$c->singleton( RulesAdminPage::class, static fn( Container $c ) => new RulesAdminPage( $c->get( Assets::class ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function boot( Container $c ): void {
		/** @var HookLoader $hooks */
		$hooks = $c->get( HookLoader::class );

		// The dynamic engine: subscribe to domain events + register the generic
		// yazan_rewards/trigger/{event} hooks (incl. the data-less Yazan events).
		$engine = $c->get( RuleEngine::class );
		$c->get( EventBus::class )->subscribe( $engine );
		$engine->register_triggers();

		// Login bridge + birthday scan (self-schedules on `init`).
		$hooks->register( $c->get( LoginObserver::class ) );
		$hooks->register( $c->get( BirthdayScheduler::class ) );

		// Admin rule-builder page + REST CRUD.
		$hooks->register( $c->get( RulesAdminPage::class ) );
		$c->get( RestBootstrap::class )->add( static fn( Container $c ) => new RulesController( $c ) );
	}

	/**
	 * @inheritDoc
	 */
	public function activate( Container $c ): void {
		$this->seed_defaults( $c );
	}

	/**
	 * Seed a default purchase rule from settings, once, if none exists yet.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	private function seed_defaults( Container $c ): void {
		$repo = $c->get( RuleRepository::class );
		if ( $repo->count_for_event( 'order_completed' ) > 0 ) {
			return;
		}

		$settings = $c->get( Settings::class );
		$repo->create(
			array(
				'name'        => __( 'Purchase points', 'yazan-rewards' ),
				'event'       => 'order_completed',
				'conditions'  => array(),
				'award_type'  => 'per_currency',
				'award_value' => (float) $settings->get( 'points.points_per_currency', 1 ),
				'priority'    => 10,
				'active'      => true,
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function schema( string $charset_collate ): array {
		global $wpdb;
		$table = $wpdb->prefix . Database::TABLE_PREFIX . 'rules';

		return array(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				event varchar(50) NOT NULL DEFAULT '',
				conditions longtext NULL,
				actions longtext NULL,
				award_type varchar(20) NOT NULL DEFAULT 'fixed',
				award_value decimal(19,4) NOT NULL DEFAULT 0,
				priority int(11) NOT NULL DEFAULT 10,
				per_user_cap bigint(20) unsigned NOT NULL DEFAULT 0,
				global_cap bigint(20) unsigned NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				starts_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				ends_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY event (event),
				KEY active (active)
			) {$charset_collate};",
		);
	}
}
