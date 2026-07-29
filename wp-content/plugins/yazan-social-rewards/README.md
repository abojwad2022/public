# YAZAN Social Rewards & Ambassador Platform

An independent, modular loyalty, ambassador, referral, and social-engagement platform for the **YAZAN** luxury
store — built on WordPress + WooCommerce **without touching core, WooCommerce, the theme, or other plugins**.

- **Version:** 1.11.1 · **Schema:** 1.11.0
- **Requires:** WordPress 6.5+ · WooCommerce 8.0+ · PHP 8.1+
- **Text domain:** `yazan-rewards` · **License:** GPL-2.0-or-later

## What it does

Points, store-credit wallet, a no-code rewards catalog + redemption, referrals, ambassador commissions,
earn-multiplier + marketing/UGC campaigns, achievements & loyalty tiers, social connectors, anti-fraud, analytics,
and multi-channel notifications — plus a **public REST API** and **developer extension hooks** for third parties.

## Engine modules (14)

| Module | Purpose |
|---|---|
| Rules | No-code event → condition → action engine that drives earning |
| Points | Append-only points ledger + cached balances |
| Wallet | Store-credit wallet + checkout credit application |
| Rewards | Rewards catalog, redemption, service vouchers, My-Account hub |
| Referral | Referral attribution + multi-level earnings |
| Ambassador | Ambassador applications + commission payouts |
| Campaigns | Earn-multiplier campaigns + marketing/UGC campaigns with tasks |
| Achievement | Achievements/badges + loyalty tiers |
| Social | Social share/UGC connectors + content verification |
| Anti-Fraud | Risk scoring / hold-for-review gate |
| Analytics | Daily rollups, liability calculation, reports + CSV export |
| Notification | Email / on-site / webhook channels (+ future-ready SMS/Push/WhatsApp), preferences, digest |
| Activity | Per-user activity feed (event-bus consumer) |
| Public API | Stable `yazan/v1` REST facade |

Each engine can be toggled independently via a feature flag; disabling one leaves the rest working.

## Architecture in one paragraph

Namespaced PSR-4 OOP (`Yazan\Rewards\` → `src/`) with a DI **Container**, a dependency-ordered **ModuleRegistry**,
an **EventBus** (engines talk only through domain events + shared interfaces, never direct calls), a declarative
**HookLoader**, typed **Settings**, and an **Action Scheduler** wrapper for background jobs. Own prefixes: tables
`wp_yazan_rw_*` (23), options `yazan_rewards_*`, meta `_yzrw_*`, REST `yazan-rewards/v1` (internal) + `yazan/v1`
(public), capabilities `manage_yazan_rewards` / `view_yazan_rewards`. HPOS-safe (all order writes via WooCommerce CRUD).

## Documentation

| Guide | For |
|---|---|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Requirements, install/activate, what activation creates, local-dev recipe |
| [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md) | The admin menu tour, per-engine settings, notifications, deploy notes |
| [docs/DEVELOPER.md](docs/DEVELOPER.md) | Architecture, the public REST API, the 4 `yazan_register_*()` hooks + 24 filters, worked examples |
| [docs/UPGRADE.md](docs/UPGRADE.md) | Versioning, the migrator, backward-compat rules, rollback |
| [docs/BACKUP.md](docs/BACKUP.md) | What to back up (ledgers/options/meta), recipes, restore |
| [AUDIT-REPORT.md](AUDIT-REPORT.md) | The 1.11.1 production audit (security / performance / compatibility / architecture) |
| [STRUCTURE.md](STRUCTURE.md) · [DATABASE.md](DATABASE.md) | Source layout + database design |

## Quick start

1. Ensure WooCommerce is active (it provides Action Scheduler).
2. Copy the plugin to `wp-content/plugins/yazan-social-rewards/` and **Activate**.
3. Visit **Settings → Permalinks → Save** once (registers the My-Account endpoints).
4. Configure under **Yazan Rewards** in wp-admin. See [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md).

By default the plugin **keeps all data on uninstall** (the ledgers are a financial record); opt in to purge via
*delete data on uninstall*. See [docs/BACKUP.md](docs/BACKUP.md).
