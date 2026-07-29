# Backup & Restore Strategy

The plugin holds **financial-liability data** — points and store-credit ledgers are money owed to customers.
Treat its tables like accounting records: back them up on the same schedule as your WooCommerce orders, and
**always** back up before an upgrade, a settings import, or enabling *delete data on uninstall*.

## What to back up

### 1. Database tables — the 23 `wp_yazan_rw_*` tables

Critical (append-only ledgers / liability — never lose these):

- `wp_yazan_rw_points_ledger`, `wp_yazan_rw_wallet_ledger`, `wp_yazan_rw_referral_earnings`
- `wp_yazan_rw_redemptions`, `wp_yazan_rw_service_vouchers`

Program/state (regenerable in theory, painful in practice):

- `rules`, `rewards`, `tiers`, `achievements`, `user_achievements`, `ambassadors`, `referrals`,
  `campaigns`, `campaign_tasks`, `marketing_campaigns`, `campaign_participants`, `campaign_submissions`,
  `social_actions`, `social_accounts`, `fraud_flags`, `notifications`, `analytics_daily`, `activity_logs`

(A `SHOW TABLES LIKE 'wp\_yazan\_rw\_%'` lists them all. See [DATABASE.md](../DATABASE.md).)

### 2. Options (in `wp_options`)

- `yazan_rewards_settings` — all configuration + feature flags
- `yazan_rewards_db_version`, `yazan_rewards_version` — schema/plugin version markers
- `yazan_rewards_social_secrets` — social OAuth app credentials (**contains secrets**)
- `yazan_rewards_notification_webhook_secret` — webhook signing secret (**a secret**)
- `yazan_rewards_campaign_new_notified`, `yazan_rewards_campaign_ending_notified`, `yazan_rewards_cron_ready` — dedup/state flags

### 3. User meta — the `_yzrw_*` keys

Cached balances + tier + preferences (e.g. `_yzrw_tier`, `_yzrw_notif_prefs`, `_yzrw_expiry_reminded`). These are
**derived** (recomputable from the ledgers), but backing them up avoids a post-restore reconciliation pass.

### 4. Files

The plugin folder `wp-content/plugins/yazan-social-rewards/` (code is stateless; a copy enables fast rollback).

## Backup recipes

**Full DB (mysqldump):**
```bash
mysqldump --single-transaction wordpress > wp-backup.sql
```

**Plugin tables only:**
```bash
TBL=$(mysql -N -e "SHOW TABLES LIKE 'wp\\_yazan\\_rw\\_%'" wordpress)
mysqldump --single-transaction wordpress $TBL > yazan-tables.sql
```

**WP-CLI (tables + the plugin options):**
```bash
wp db export --tables=$(wp db tables 'wp_yazan_rw_*' --format=csv)
wp option get yazan_rewards_settings --format=json > yazan-settings.json
```

Schedule these alongside your existing WooCommerce/site backups (a managed-host snapshot, UpdraftPlus, or a
mysqldump cron). There is **no in-plugin backup UI** — automated backup/restore for this store is provided by the
separate **`yazan-core`** plugin (dashboard **Backup** tab / wp-admin **Tools**); this plugin's data is included
in any full-site DB backup it takes.

## Restore

1. Put the site in maintenance mode.
2. Restore the plugin folder (matching the backed-up version — see [UPGRADE.md](UPGRADE.md)).
3. Import the SQL (`wp db import wp-backup.sql`, or the tables-only dump) and the options JSON if separate.
4. Load wp-admin once — the Migrator reconciles `db_version`/`version` and re-schedules background jobs.
5. Spot-check: a customer's points balance matches the ledger, **WooCommerce → Status → Scheduled Actions** shows
   the `yazan_rewards` group, and a test redemption + a test notification work.

## Data-loss safeguards built in

- **Deactivation never deletes data** — it only unschedules jobs and flushes rewrites.
- **Uninstall keeps everything by default.** Purge happens only when `delete_data_on_uninstall = true`, and even
  then only drops the plugin's own `wp_yazan_rw_*` tables, its options/secrets, its two capabilities, and `_yzrw_*`
  meta — never core or WooCommerce data. **Back up before enabling that flag.**
- Ledgers are **append-only**, so balances can always be recomputed from history after a partial restore.
