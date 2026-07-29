# YAZAN Social Rewards — Structure & Foundation Map

This plugin implements the requested foundation (activation, deactivation, database installation, a
migration system, a module loader, a settings framework, a permission system, and logging) plus
**fourteen engine modules**. It uses a **modern namespaced PSR-4 layout** under `src/` — the "Modern
namespaced OOP" architecture chosen for this project.

> **Current at a glance (v1.11.1, schema 1.11.0):** 14 modules · 23 tables (`wp_yazan_rw_*`) · 25 REST
> controllers across `yazan-rewards/v1` (internal) + `yazan/v1` (public) · 2 capabilities · 15 feature
> flags · 24 filter extension points + 4 `yazan_register_*()` developer functions. Beyond the map below,
> the live module set also includes `Activity` (per-user feed) and `PublicApi` (the `yazan/v1` facade).

This document maps the **requested file-based layout** to the **actual PSR-4 files**, so anyone
expecting `core/loader.php` can find its equivalent immediately. Namespace root: `Yazan\Rewards\`
(maps to `src/`). Every class is loaded by the self-contained PSR-4 autoloader in the main plugin
file — no `composer install` required.

---

## 1. Requested layout → actual files

| Requested path | Actual implementation | Role |
|---|---|---|
| `yazan-social-rewards.php` | `yazan-social-rewards.php` | Bootstrap: plugin header, constants (`YAZAN_REWARDS_*`), self-contained PSR-4 autoloader, HPOS `FeaturesUtil` declaration, `register_activation_hook`/`register_deactivation_hook`, `plugins_loaded` boot. |
| `core/loader.php` | `src/Core/Plugin.php` + `src/Core/Container.php` + `src/Core/ModuleRegistry.php` | Module/service loader: `Plugin` orchestrates; `Container` is the DI container; `ModuleRegistry` dependency-orders the modules. |
| `core/database.php` | `src/Core/Database/Database.php` + `src/Core/Database/AbstractRepository.php` + `src/Core/Install/Schema.php` | `Database` = `$wpdb` wrapper + `yazan_rw_` table naming + prepared insert/update; `AbstractRepository` = base for typed repos; `Schema` = `dbDelta` installer. |
| `core/settings.php` | `src/Core/Settings/Settings.php` (+ `config/default-settings.php`) | Options framework: dotted-path reads, deep-merged defaults, per-engine feature flags. |
| `core/hooks.php` | `src/Core/Hooks/HookLoader.php` + `src/Core/Events/EventBus.php` (+ `Events/Event.php`, `Events/Events.php`, `Events/GenericEvent.php`) | `HookLoader` wires declarative `Hookable` objects; `EventBus` is the internal domain-event backbone (namespaced `do_action`). |
| `core/permissions.php` | `src/Core/Security/Capabilities.php` + `src/Core/Security/Nonce.php` + `src/Core/Security/RateLimiter.php` + `src/Core/Rest/Auth.php` (+ `config/capabilities.php`) | Caps grant/revoke, nonce helpers, rate limiting, REST permission callbacks. |
| `core/logger.php` | `src/Core/Support/Logger.php` | `WP_DEBUG`-gated, prefixed logger (info/warning/error). |
| `modules/rules-engine/` | `src/Modules/Rules/` | Rules engine (`RulesModule`, `RuleEvaluator`, `ConditionMatcher`, `RuleRepository`, `Rule`). |
| `modules/rewards-engine/` | `src/Modules/Rewards/` | Catalog + redemption (`RewardsModule`, `RedemptionService`, `CouponIssuer`, `WalletIssuer`, repos). |
| `modules/campaign-engine/` | `src/Modules/Campaigns/` | Earn multipliers (`CampaignsModule`, `MultiplierResolver`, `CampaignRepository`). |
| `modules/ambassador-engine/` | `src/Modules/Ambassador/` | Applications + commission (`AmbassadorModule`, `ApplicationService`, `CommissionService`, `StoreCreditPayoutAdapter`). |
| `modules/referral-engine/` | `src/Modules/Referral/` | Attribution (`ReferralModule`, `LinkTracker`, `AttributionService`, `ReferralCodes`, repo). |
| `modules/achievement-engine/` | `src/Modules/Achievement/` | Badges + loyalty tiers (`AchievementModule`, `ProgressTracker`, `TierEngine`, repos). |
| `modules/social-connectors/` | `src/Modules/Social/` | Share + UGC (`SocialModule`, `SocialService`, `ManualPlatformAdapter`, repo). |
| `modules/analytics/` | `src/Modules/Analytics/` | Rollups + liability (`AnalyticsModule`, `RollupService`, `LiabilityCalculator`, `ReportRepository`). |
| `modules/anti-fraud/` | `src/Modules/AntiFraud/` | Risk gate (`AntiFraudModule`, `RiskEngine`, `FraudRepository`). |
| `modules/notifications/` | `src/Modules/Notification/` | Multi-channel (`NotificationModule`, `NotificationDispatcher`, `Channels/*`, `TemplateRenderer`). |
| *(supporting)* | `src/Modules/Points/` + `src/Modules/Wallet/` | Points ledger + store-credit wallet — the currencies the engines above operate on. |
| `admin/` | `src/Admin/` *(reserved)* — admin REST already lives under `src/Rest/V1/*Controller.php` (reports, ambassador approve/suspend, social review). Admin React SPA not built yet. |
| `frontend/` | `src/Frontend/` — `AccountHub.php` (My Account "Rewards" endpoint), `Assets.php`; template in `templates/account/rewards-hub.php`; assets in `assets/css/account.css`, `assets/js/account.js`. |
| `api/` | `src/Rest/` (`RestBootstrap`, `AbstractController`, `Auth` in `src/Core/Rest/`) + `src/Rest/V1/` (per-domain controllers). REST namespace `yazan-rewards/v1`. |
| `assets/` | `assets/` (root) — front CSS/JS. |
| `languages/` | `languages/` (root) — holds the future `yazan-rewards.pot`; loaded by `src/Core/I18n.php`. |
| *(integration glue)* | `src/Integration/WooCommerce/` (`OrderObserver`, `RefundObserver`) + `src/Integration/WordPress/` (`UserObserver`, `ReviewObserver`) — translate store hooks into domain events. |
| *(config)* | `config/modules.php` (module list), `config/capabilities.php` (cap→role), `config/default-settings.php` (defaults). |

---

## 2. The eight foundation capabilities

| Capability | Implementation | Runs on |
|---|---|---|
| **Plugin activation** | `Yazan\Rewards\Core\Install\Activator::activate()` — builds the container, installs schema, grants caps, runs each module's `activate()` (seed data), flushes rewrite rules. | `register_activation_hook` |
| **Plugin deactivation** | `Core\Install\Deactivator::deactivate()` — unschedules Action Scheduler jobs, runs each module's `deactivate()`, flushes rewrites. **Never deletes data.** | `register_deactivation_hook` |
| **Database installation** | `Core\Install\Schema::install()` — collects every `Installable` module's `schema()` and runs them through `dbDelta()`; writes `yazan_rewards_db_version`. Table names via `Database::table()` (`{prefix}yazan_rw_*`). | activation + migration |
| **Migration system** | `Core\Install\Migrator::maybe_migrate()` — compares `yazan_rewards_version` to the code version and, on a bump, re-runs `Schema::install()` + each module's `activate()` + a rewrite flush + clears the cron-ready flag. Idempotent (`dbDelta` diffs). | `init` (priority 1) |
| **Module loader** | `Core\Plugin::load_modules()` reads `config/modules.php` (filterable via `yazan_rewards/modules`) → `ModuleRegistry` sorts by each module's `dependencies()` → calls `register()` (bind services) then `boot()` (add hooks). | `plugins_loaded` |
| **Settings framework** | `Core\Settings\Settings` — one option (`yazan_rewards_settings`) merged with `config/default-settings.php`; `get('a.b.c')` dotted paths; `feature_enabled('points')`. | on demand |
| **Permission system** | `Core\Security\Capabilities` (grant/revoke `manage_yazan_rewards` / `view_yazan_rewards` to roles) + `Core\Rest\Auth` permission callbacks + `RateLimiter` + `Nonce`. | activation + per-request |
| **Logging system** | `Core\Support\Logger` — `info()/warning()/error()`, prefixed, only when `WP_DEBUG`. | on demand |

---

## 3. Adding a future module WITHOUT changing core code

The framework is open for extension by design. To add an engine:

1. Create `src/Modules/<Name>/<Name>Module.php` implementing
   `Yazan\Rewards\Core\Contracts\ModuleInterface` (extend `Core\Module\AbstractModule` for defaults).
   - `id()` — unique key. `dependencies()` — other module ids it needs (the registry orders by these).
   - `register(Container $c)` — bind this module's services (no hooks yet).
   - `boot(Container $c)` — add hooks / subscribe to `EventBus` events / register REST controllers.
   - `activate(Container $c)` / `deactivate(Container $c)` — optional per-module lifecycle.
   - If it owns DB tables, also implement `Core\Contracts\Installable::schema()` — the installer picks
     it up automatically.
2. Register it **one of two ways, both without editing `src/Core/`:**
   - add one line to `config/modules.php`, **or**
   - from a *separate* plugin: `add_filter( 'yazan_rewards/modules', fn($m) => [...$m, MyModule::class] )`.

That's it — the loader, installer, migrator, settings, permissions, and event bus all pick the new
module up. Reference templates: `src/Modules/Rules/RulesModule.php` (simple + `Installable` + seeding)
and `src/Modules/Points/PointsModule.php` (services + observers + event subscribers + REST). All 14
engine modules were added exactly this way, with **zero edits** to `src/Core/`. A *third-party* plugin
does the same via `yazan_register_module( MyModule::class )` — see [docs/DEVELOPER.md](docs/DEVELOPER.md).

**Talking to other engines** stays decoupled: depend on an *interface* resolved from the container
(e.g. `PointsLedgerInterface`, `WalletServiceInterface`) for synchronous values, or subscribe to a
domain event from `Core\Events\Events` (e.g. `Events::ORDER_REWARDED`) for reactions. Never reference
another module's concrete classes.

---

## 4. Verification

The foundation is verified end-to-end on live WordPress/WooCommerce (via Local's PHP — see the
project `HANDOFF.md` for the CLI recipe). The Phase-1 smoke test builds the container and resolves
every foundation service:

```
"$PHP" -c "$INI" verify-core.php
→ RESULT: PASS (all core services resolve)
  Table name check: wp_yazan_rw_points_ledger
  Settings points.signup_bonus = 100
  EventBus round-trip payload n = 42
```

Full activation installs all **23** tables and loads all **14** modules; each engine has its own
passing end-to-end test (earning, redemption, referral/ambassador, tiers/campaigns/achievements,
social/anti-fraud/analytics/notifications, the public REST API, and the developer hooks).

> The plugin is **ACTIVE** in wp-admin (schema live at 1.11.0, plugin 1.11.1). See
> [AUDIT-REPORT.md](AUDIT-REPORT.md) for the production audit and [docs/](docs/) for the full guides.
