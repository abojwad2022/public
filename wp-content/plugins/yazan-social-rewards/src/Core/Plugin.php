<?php
/**
 * Platform orchestrator.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core;

use Yazan\Rewards\Admin\DataIntegrityAdminPage;
use Yazan\Rewards\Core\Contracts\ModuleInterface;
use Yazan\Rewards\Core\Database\Database;
use Yazan\Rewards\Core\Events\EventBus;
use Yazan\Rewards\Core\Hooks\HookLoader;
use Yazan\Rewards\Core\Install\Migrator;
use Yazan\Rewards\Core\Install\Schema;
use Yazan\Rewards\Core\Privacy\OrphanScanner;
use Yazan\Rewards\Core\Privacy\PersonalData;
use Yazan\Rewards\Core\Privacy\UserCleanup;
use Yazan\Rewards\Core\Privacy\UserDataRegistry;
use Yazan\Rewards\Core\Rest\Auth;
use Yazan\Rewards\Core\Rest\RestBootstrap;
use Yazan\Rewards\Core\Security\Capabilities;
use Yazan\Rewards\Core\Security\Nonce;
use Yazan\Rewards\Core\Security\RateLimiter;
use Yazan\Rewards\Core\Settings\Settings;
use Yazan\Rewards\Core\Support\Assets;
use Yazan\Rewards\Core\Support\Cache;
use Yazan\Rewards\Core\Support\Logger;
use Yazan\Rewards\Core\Support\Money;
use Yazan\Rewards\Core\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single entry point. Builds the container + core services, loads the module
 * registry, and drives every module through register() then boot(). Deliberately
 * thin — all real work lives in modules and services.
 */
final class Plugin {

	/** Shared REST namespace for the whole platform (internal controllers). */
	public const REST_NS = 'yazan-rewards/v1';

	/**
	 * Public, stable REST namespace for third-party/developer consumers.
	 *
	 * Moved off the shared `yazan/v1` (see LEGACY_PUBLIC_REST_NS). That namespace belongs to
	 * yazan-core, which polices it with a default-deny filter and skips foreign sub-trees via a
	 * hand-maintained allow-list. Publishing into it meant these routes were invisible to central
	 * enforcement, and that any new controller added here would be denied at runtime with no
	 * registration-time warning. Owning a namespace removes that whole class of failure.
	 */
	public const PUBLIC_REST_NS = 'yazan-rewards/public/v1';

	/**
	 * The namespace the public controllers used to answer on.
	 *
	 * Still registered, so storefront JS and any third-party consumer keeps working. Remove after
	 * one release — and when it goes, `Yazan_REST_Guard::FOREIGN_PREFIXES` in yazan-core can drop
	 * back to just the namespace index.
	 */
	public const LEGACY_PUBLIC_REST_NS = 'yazan/v1';

	/** Singleton. */
	private static ?Plugin $instance = null;

	private Container $container;

	private ModuleRegistry $registry;

	private bool $booted = false;

	/**
	 * Whether the container/services/modules have been assembled.
	 *
	 * @var bool
	 */
	private bool $built = false;

	private function __construct() {
		$this->container = new Container();
		$this->registry  = new ModuleRegistry();
	}

	/**
	 * Accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The service container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Assemble core services + modules. Idempotent. Safe to call from both boot()
	 * and the activation path (which needs the container without adding hooks).
	 *
	 * @return void
	 */
	public function build(): void {
		if ( $this->built ) {
			return;
		}

		$this->register_core_services();
		$this->load_modules();

		// Let each module bind its services (no hooks yet).
		foreach ( $this->registry->all() as $module ) {
			$module->register( $this->container );
		}

		$this->built = true;
	}

	/**
	 * Boot the platform on `plugins_loaded`. Requires WooCommerce; if it is
	 * absent, commerce is unavailable and we show an admin notice instead of
	 * fataling, leaving the store untouched.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		$this->build();

		// i18n.
		add_action( 'init', array( $this->container->get( 'i18n' ), 'load' ), 1 );

		// REST controllers.
		add_action( 'rest_api_init', array( $this->container->get( RestBootstrap::class ), 'register' ) );

		// Run schema migrations after a silent plugin update. Hooked on `init` (not
		// just admin_init) so the additive dbDelta lands BEFORE any front-end,
		// REST, payment-webhook, or WP-Cron write path runs — otherwise a file-only
		// update would let order/signup writes hit the old table shape and lose data
		// in the window before an admin happens to load wp-admin. The version-option
		// check makes this a cheap no-op on every request after the one-time upgrade.
		add_action( 'init', array( $this->container->get( Migrator::class ), 'maybe_migrate' ), 1 );

		// Data lifecycle. Registered before the modules so that `deleted_user`
		// cleanup is in place even if a module later fatals during boot —
		// leaving orphaned points, store credit and encrypted OAuth tokens
		// behind is worse than losing a feature.
		$this->container->get( UserCleanup::class )->register();
		$this->container->get( PersonalData::class )->register();

		if ( is_admin() ) {
			$this->container->get( HookLoader::class )->register(
				$this->container->get( DataIntegrityAdminPage::class )
			);
		}

		// Boot every module (hooks + event subscribers).
		foreach ( $this->registry->all() as $module ) {
			$module->boot( $this->container );
		}

		// After the recurring-job scheduling pass (schedulers hook init@20), flag it
		// done so later requests skip the Action Scheduler existence lookups. Cleared
		// on settings change / migration / deactivation to force a re-evaluation.
		add_action(
			'init',
			function () {
				$this->container->get( Scheduler::class )->mark_ready();
			},
			99
		);

		$this->booted = true;

		/**
		 * Fires once the platform has fully booted. The last chance for add-ons
		 * to hook in with all services available.
		 *
		 * @param Plugin $plugin The platform instance.
		 */
		do_action( 'yazan_rewards/booted', $this );
	}

	/**
	 * Modules in dependency order (used by the Activator too).
	 *
	 * @return ModuleInterface[]
	 */
	public function modules(): array {
		return $this->registry->all();
	}

	/**
	 * Bind the framework services into the container.
	 *
	 * @return void
	 */
	private function register_core_services(): void {
		$c = $this->container;

		$c->instance( Container::class, $c );
		$c->instance( self::class, $this );

		$c->singleton( Logger::class, static fn() => new Logger() );
		$c->singleton( Cache::class, static fn() => new Cache() );
		$c->singleton( Database::class, static fn() => new Database() );
		$c->singleton( HookLoader::class, static fn() => new HookLoader() );

		$c->singleton( EventBus::class, static fn( Container $c ) => new EventBus( $c ) );

		$c->singleton(
			Settings::class,
			static fn() => new Settings( 'yazan_rewards_settings', require YAZAN_REWARDS_DIR . 'config/default-settings.php' )
		);

		$c->singleton( Money::class, static fn() => new Money() );
		$c->singleton( Scheduler::class, static fn() => new Scheduler( 'yazan_rewards' ) );
		$c->singleton( Assets::class, static fn() => new Assets() );

		$c->singleton( Capabilities::class, static fn() => new Capabilities( require YAZAN_REWARDS_DIR . 'config/capabilities.php' ) );
		$c->singleton( Nonce::class, static fn() => new Nonce() );
		$c->singleton( RateLimiter::class, static fn() => new RateLimiter() );

		// Privacy / data lifecycle. UserDataRegistry is the single map of every
		// user-linked column; the cleanup hook, the GDPR exporter/eraser and the
		// orphan scanner all read from it so they cannot drift apart.
		$c->singleton( UserDataRegistry::class, static fn() => new UserDataRegistry() );
		$c->singleton(
			UserCleanup::class,
			static fn( Container $c ) => new UserCleanup(
				$c->get( Database::class ),
				$c->get( UserDataRegistry::class ),
				$c->get( Logger::class )
			)
		);
		$c->singleton(
			PersonalData::class,
			static fn( Container $c ) => new PersonalData(
				$c->get( Database::class ),
				$c->get( UserDataRegistry::class ),
				$c->get( UserCleanup::class )
			)
		);
		$c->singleton(
			OrphanScanner::class,
			static fn( Container $c ) => new OrphanScanner(
				$c->get( Database::class ),
				$c->get( UserDataRegistry::class ),
				$c->get( UserCleanup::class )
			)
		);
		$c->singleton(
			DataIntegrityAdminPage::class,
			static fn( Container $c ) => new DataIntegrityAdminPage( $c->get( OrphanScanner::class ) )
		);

		$c->singleton( Auth::class, static fn() => new Auth() );
		$c->singleton( Schema::class, static fn( Container $c ) => new Schema( $c ) );
		$c->singleton( Migrator::class, static fn( Container $c ) => new Migrator( $c ) );
		$c->singleton( RestBootstrap::class, static fn( Container $c ) => new RestBootstrap( $c ) );

		// Convenience string aliases used across the codebase.
		$c->alias( 'events', EventBus::class );
		$c->alias( 'db', Database::class );
		$c->alias( 'settings', Settings::class );
		$c->alias( 'hooks', HookLoader::class );
		$c->alias( 'logger', Logger::class );
		$c->alias( 'money', Money::class );
		$c->alias( 'scheduler', Scheduler::class );

		$c->singleton( 'i18n', static fn() => new I18n() );
	}

	/**
	 * Instantiate the modules named in config/modules.php (filterable).
	 *
	 * @return void
	 */
	private function load_modules(): void {
		$classes = (array) require YAZAN_REWARDS_DIR . 'config/modules.php';

		/**
		 * Filter the list of module classes that make up the platform.
		 *
		 * @param string[] $classes Fully-qualified module class names.
		 */
		$classes = (array) apply_filters( 'yazan_rewards/modules', $classes );

		foreach ( $classes as $class ) {
			if ( is_string( $class ) && class_exists( $class ) ) {
				$module = new $class();
				if ( $module instanceof ModuleInterface ) {
					$this->registry->add( $module );
				}
			}
		}
	}

	/**
	 * Is WooCommerce active?
	 *
	 * @return bool
	 */
	private function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 *
	 * @return void
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'YAZAN Social Rewards:', 'yazan-rewards' ),
			esc_html__( 'WooCommerce is required and is not active. The rewards platform is paused until WooCommerce is enabled.', 'yazan-rewards' )
		);
	}
}
