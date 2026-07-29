# Upgrade Strategy

## Two version numbers

- **Plugin version** — `YAZAN_REWARDS_VERSION` + the plugin header (currently **1.11.1**). Stored after a
  migration in the option `yazan_rewards_version`.
- **Schema version** — `Schema::VERSION` (currently **1.11.0**). Stored in `yazan_rewards_db_version`. Bump this
  **only when a module's `schema()` changes** (new/altered table or column).

They move independently: a patch that changes no table (like 1.11.1) bumps the plugin version but not the schema.

## How upgrades apply — the Migrator

There is **no manual re-activation** needed after a file update. `Migrator::maybe_migrate()` runs on `init`
(priority 1), gated on `yazan_rewards_version` ≠ `YAZAN_REWARDS_VERSION` and `class_exists('WooCommerce')`. When
the code version moves ahead it, once:

1. **Runs `dbDelta`** over every module's `schema()` — **additive only** (new tables/columns; dbDelta never
   drops). Idempotent.
2. **Re-runs each module's `activate()`** — idempotent seeds, back-fills, and endpoint registration the raw
   dbDelta misses (e.g. new tier fields, new My-Account endpoints).
3. **Flushes rewrite rules** so any newly-added endpoint resolves without a manual permalink save.
4. **Clears the `yazan_rewards_cron_ready` flag** so the recurring-job scheduling pass re-evaluates (a new
   version may add jobs).
5. **Persists** `yazan_rewards_version`.

This makes drop-in file updates safe: deploy the new files, and the DB catches up on the next admin/front-end load.

## The golden rule for background jobs

**Recurring jobs MUST self-schedule on the `init` hook, never in a module's `boot()`.** Action Scheduler's data
store is not ready at `plugins_loaded`/`boot()` time, so a recurring action queued there never persists. Every
scheduler is a `Hookable` that adds an `init → ensure_scheduled` hook (priority 20) plus its job-hook handler;
`ensure_scheduled()` short-circuits once the cron-ready flag is set. If you add a recurring job, follow this
pattern (see `Modules/Notification/NotificationScheduler`).

## Backward-compatibility guarantees

- **Data:** ledgers are append-only and never dropped by an upgrade; balances are recomputable from them.
- **Settings:** defaults are deep-merged with saved values, so new settings keys appear without wiping saved ones.
- **Public API:** `yazan/v1` is the stable contract; internal `yazan-rewards/v1` may change between versions.
- **Extension points:** the 24 filters + the 4 `yazan_register_*()` functions are the supported extension surface;
  build add-ons against those, not against internal classes.

## Pre-upgrade checklist

1. **Back up** the database (especially the ledger tables) and the plugin folder — see [BACKUP.md](BACKUP.md).
2. Note the current `yazan_rewards_version` / `yazan_rewards_db_version` (Tools → Site Health → Info, or the DB).
3. On a busy store, deploy during low traffic; the first request after deploy triggers the migration.
4. Keep the previous plugin folder as `yazan-social-rewards.bak` for a fast rollback.

## Rollback procedure

Because migrations are **additive**, a code rollback is safe — the newer columns/tables simply go unused by older
code:

1. Restore the previous plugin folder (swap `yazan-social-rewards.bak` back).
2. If the older code checks `yazan_rewards_db_version`, either leave it (newer schema is a superset) or restore
   the DB from your pre-upgrade backup if the release notes flag a non-additive change.
3. Load wp-admin once so the older Migrator writes its version back.
4. If a rollback must also revert data, restore the full DB backup (this is the only case that loses post-upgrade
   activity — hence the pre-upgrade backup).

> Non-additive schema changes (dropping/renaming a column) are avoided by policy; if one is ever required it will
> be called out explicitly in the release notes with its own migration + rollback steps.

## Verifying an upgrade

- Front-end health: home / shop / `/my-account/` return 200.
- **WooCommerce → Status → Scheduled Actions** shows the `yazan_rewards` group with the expected recurring jobs.
- A test redemption and a test notification (**Yazan Rewards → Notifications → Send test**) succeed.
- The `scratchpad/` CLI verification suites pass (dev/staging only).
