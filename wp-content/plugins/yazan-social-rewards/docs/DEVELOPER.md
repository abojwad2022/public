# Developer Documentation

## Architecture

Namespaced PSR-4 OOP — `Yazan\Rewards\` maps to `src/`. The bootstrap (`yazan-social-rewards.php`) prefers
Composer's autoloader and falls back to a self-contained PSR-4 loader, then `require`s `inc/registration.php`
(the global developer functions), then boots on `plugins_loaded` priority 20.

**Core pieces (`src/Core/`):**

- **`Plugin`** — the orchestrator. Builds the `Container`, loads modules from `config/modules.php`, drives each
  through `register()` (bind services) then `boot()` (add hooks). Holds `REST_NS = 'yazan-rewards/v1'` and
  `PUBLIC_REST_NS = 'yazan/v1'`.
- **`Container`** — minimal DI (singletons + factories + aliases).
- **`ModuleRegistry`** — topologically orders modules by their `dependencies()`.
- **`Events\EventBus`** — engines communicate ONLY through domain events (`GenericEvent`) + shared interfaces,
  never by calling each other. `dispatch()` fans out to `SubscriberInterface`s and to
  `do_action('yazan_rewards/event/{name}', $event)`.
- **`Hooks\HookLoader`** — wires a `Hookable`'s declarative `hooks()` array into `add_action`/`add_filter`.
- **`Settings`** — typed reader over the single `yazan_rewards_settings` option (dotted paths, feature flags).
- **`Support\Scheduler`** — thin Action Scheduler wrapper (`recurring`/`single`/`async`). **Rule:** recurring
  jobs self-schedule on `init` (never in module `boot()`) — see [UPGRADE.md](UPGRADE.md).
- **`Database\AbstractRepository`** — base for the 23 tables; `table()` = `wp_` + `yazan_rw_` + name.
- **`Rest\{AbstractController, Auth, RestBootstrap}`** — REST plumbing.
- **`Security\{Capabilities, Nonce, RateLimiter}`**, **`Support\{Money, Cache, Logger, Assets}`**.

**A module** implements `ModuleInterface` (or extends `AbstractModule`): `id()`, `dependencies()`, `register()`,
`boot()`, `activate()`, `deactivate()`; add `Installable` to contribute a `schema()`. Modules are instantiated
with **no constructor arguments** — dependencies come from the `Container` passed to `register()`/`boot()`.

## REST API

Two namespaces. Auth is the same for both: a logged-in cookie + the `wp_rest` nonce (`X-WP-Nonce` header), and —
because permission callbacks use `is_user_logged_in()`/`current_user_can()` — **WordPress Application Passwords
work automatically** for server-to-server calls (send HTTP Basic auth). No custom token/key system.

### Public API — `yazan/v1` (stable, for third parties)

| Method | Route | Auth | Returns |
|---|---|---|---|
| `GET` | `/yazan/v1/customer/points` | logged-in | `{ user_id, balance, label, wallet }` |
| `GET` | `/yazan/v1/customer/profile` | logged-in | balance + wallet + tier + level + ranking |
| `POST` | `/yazan/v1/campaign/submit` | logged-in | body `{ campaign_id, task_id, url, metric? }` |
| `POST` | `/yazan/v1/reward/redeem` | logged-in | body `{ reward_id }` |
| `GET` | `/yazan/v1/statistics?range=7\|30\|90\|365\|all` | `view_yazan_rewards` | store-wide analytics |

Example (server-to-server with an Application Password):

```bash
curl -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  https://store.example/wp-json/yazan/v1/customer/points
```

### Internal API — `yazan-rewards/v1`

Powers the first-party UI: `me`, `wallet`, `rewards`, `service-vouchers`, `rules`, `reports`, `referral`,
`ambassador`, `achievements`, `campaigns`, `social`, `activity`, `notifications` (customer), plus admin surfaces
`admin/{points,rewards,campaigns,referrals,social,fraud,analytics,notifications}` (all `manage_yazan_rewards`).
Add your own controller by extending `AbstractController` and calling
`RestBootstrap::add( fn($c) => new MyController($c) )`, or hook `do_action('yazan_rewards/rest/register', $bootstrap)`.

## Extending the platform — the 4 developer functions

Defined in `inc/registration.php` (loaded at plugin-load). **Call them before `plugins_loaded` priority 20** —
e.g. at your plugin's include time or on an earlier `plugins_loaded` — since the module/connector/event filters
are read at priority 20.

```php
add_action( 'plugins_loaded', function () {

    // 1) Register a whole engine module (implements ModuleInterface, no-arg ctor).
    yazan_register_module( \Acme\Loyalty\GiftCardModule::class );

    // 2) Register a social connector (extends AbstractConnector; 3-arg ctor).
    yazan_register_connector( \Acme\Loyalty\SnapchatConnector::class );

    // 3) Register a custom reward fulfillment provider for a new reward type.
    yazan_register_reward_provider( 'gift_card', new \Acme\Loyalty\GiftCardIssuer() );

    // 4) Register a custom event so reward RULES can react to it.
    yazan_register_event( 'store_visit', [ 'label' => 'In-store visit' ] );

}, 5 ); // priority < 20
```

### `yazan_register_reward_provider()` — the reward-provider seam

A provider implements `Yazan\Rewards\Modules\Rewards\RewardIssuerInterface`:

```php
use Yazan\Rewards\Modules\Rewards\RewardIssuerInterface;
use Yazan\Rewards\Modules\Rewards\Reward;

final class GiftCardIssuer implements RewardIssuerInterface {
    public function issue( int $user_id, Reward $reward ): array {
        $code = my_mint_gift_card( $user_id, (float) ( $reward->value['amount'] ?? 0 ) );
        // Return ok=false to trigger an automatic points refund + stock release.
        return $code
            ? [ 'ok' => true, 'type' => 'gift_card', 'ref' => (string) $code, 'code' => $code ]
            : [ 'ok' => false, 'type' => 'gift_card', 'ref' => '' ];
    }
}
```

At redemption, `RedemptionService` resolves the issuer for `$reward->type` **before** debiting points; an
unknown type is rejected cleanly (`unsupported_reward`) with no points lost.

### Firing a custom event

`yazan_register_event()` adds the event to the rule catalog and auto-wires a trigger action. Fire it when the
event happens:

```php
do_action( 'yazan_rewards/trigger/store_visit', $user_id, [ /* context facts */ ] );
```

### Emitting / consuming domain events (EventBus)

Any string works as a domain event — no registration needed to dispatch or subscribe:

```php
add_action( 'yazan_rewards/event/points_credited', function ( $event ) {
    $user_id = (int) $event->get( 'user_id' );
    $points  = (int) $event->get( 'points' );
} );
```

## Filter & action extension points (24 filters)

- **Loader:** `yazan_rewards/modules`
- **Rules:** `.../rules/event_catalog`, `.../rules/condition_catalog`, `.../rules/action_catalog`,
  `.../rules/context`, `.../rules/match_condition`, `.../points/earn_amount`
- **Rewards / Social:** `.../rewards/providers`, `.../social/connectors`
- **Campaigns:** `.../campaign/eligible`, `.../campaign/audience`
- **Notifications:** `.../notification/message`, `.../notification/categories`,
  `.../notification/{sms|push|whatsapp}_provider`, `.../notification/{sms|push|whatsapp}_recipient`
- **Anti-fraud:** `.../fraud/detectors`
- **Frontend / service:** `.../service/owner_email`, `.../account_hub_template`, `.../ambassador_dashboard_template`
- **Actions:** `yazan_rewards/booted`, `yazan_rewards/rest/register`, `yazan_rewards/activated`,
  `yazan_rewards/deactivated`, `yazan_rewards/migrated`, `yazan_rewards/trigger/{key}`,
  `yazan_rewards/campaign/audience_capped`

## Database conventions

- Tables `wp_yazan_rw_<name>` via `AbstractRepository`; each module contributes `schema()` (dbDelta-compatible).
- Financial ledgers (`points_ledger`, `wallet_ledger`, `referral_earnings`) are **append-only**; balances are
  cached in `_yzrw_*` user meta and reconciled from the ledger.
- Never query orders via `get_post_meta()` — use `wc_get_order()`/`wc_get_orders()` (HPOS-safe). The only raw
  order read is the analytics aggregate, gated by `OrderUtil::custom_orders_table_usage_is_enabled()`.

## Coding standards

WordPress Coding Standards; `declare(strict_types=1)` in every file; constructor property promotion + typed
properties; `ABSPATH` guard at the top of every PHP file; sanitize on input, escape on output, `$wpdb->prepare()`
for all SQL; nonce + capability on every write. See [AUDIT-REPORT.md](../AUDIT-REPORT.md) for the security ruleset
the codebase follows.
