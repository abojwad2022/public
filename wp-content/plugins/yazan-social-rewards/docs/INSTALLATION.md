# Installation Guide

## Requirements

| Component | Minimum | Notes |
|---|---|---|
| WordPress | 6.5 | Tested current |
| WooCommerce | 8.0 | Required — provides orders **and Action Scheduler** (used for all background jobs) |
| PHP | 8.1 | 8.2 fully supported; needs the standard `sodium` extension for at-rest OAuth-token encryption |
| MySQL / MariaDB | WP minimum | ~23 plugin tables are created on activation |
| HTTPS | Recommended | Required for WordPress Application Passwords (server-to-server API auth) and outbound webhooks |

The plugin is self-contained — no build step is required. It ships a Composer-optional PSR-4 autoloader with a
plain-PHP fallback, so it runs on a stock server.

## Install & activate

1. Place the folder at `wp-content/plugins/yazan-social-rewards/`.
2. In **Plugins → Installed Plugins**, activate **YAZAN Social Rewards & Ambassador Platform**.
   (WooCommerce must be active first; if it is not, the platform pauses and shows an admin notice instead of
   erroring.)
3. Go to **Settings → Permalinks** and click **Save Changes** once. This flushes rewrite rules so the My-Account
   endpoints (`/my-account/rewards/`, `/my-account/yazan-ambassador/`) resolve. (Activation already flushes once;
   this is a belt-and-braces step after import/migration.)

## What activation does

The `Activator` runs, idempotently:

1. **Creates ~23 database tables** (all prefixed `wp_yazan_rw_*`) via `dbDelta` — see [DATABASE.md](../DATABASE.md).
2. **Grants two capabilities** — `manage_yazan_rewards` and `view_yazan_rewards` — to `administrator` and
   `shop_manager`.
3. **Seeds defaults** — loyalty tiers (Bronze/Silver/Gold/Platinum), starter settings, etc. (each module's
   `activate()` is safe to re-run).
4. **Registers My-Account endpoints** and **flushes rewrite rules**.
5. **Schedules background jobs** on the first `init` (digest flush, campaign lifecycle, birthday scan; points
   expiry only when enabled) via Action Scheduler.

Silent file-only updates are handled by the `Migrator` (see [UPGRADE.md](UPGRADE.md)) — no manual re-activation
is needed after a version bump.

## Post-install checklist

- [ ] WooCommerce active and processing orders normally.
- [ ] Permalinks saved once; `/my-account/rewards/` loads for a logged-in customer.
- [ ] `sodium` PHP extension present (`php -m | grep sodium`) — required to encrypt customer OAuth tokens.
- [ ] (Recommended) define a stable token key in `wp-config.php`:
      `define( 'YZRW_TOKEN_KEY', '<64+ random chars>' );`
- [ ] (If behind a trusted proxy/CDN) `define( 'YZRW_TRUST_PROXY', true );` so client IPs are read from
      `X-Forwarded-For`. Leave undefined otherwise (default is safe).
- [ ] Action Scheduler is running: **WooCommerce → Status → Scheduled Actions** shows the `yazan_rewards` group.
- [ ] Configure engines under **Yazan Rewards** (see [ADMIN-GUIDE.md](ADMIN-GUIDE.md)).

## Feature flags

Every engine can be turned off independently. Flags live in the single option `yazan_rewards_settings` under
`features.*` (all default **on**): `rules, marketing, points, wallet, rewards, campaigns, ambassador, referral,
achievement, social, analytics, antifraud, notification, activity, public_api`. Disabling one stops its hooks,
REST routes, and cron; the rest keep working. (There is no settings screen for the raw flag array yet — set it
programmatically via `Settings::save()` or a small mu-plugin if you need to disable an engine.)

## Uninstall behavior

Deleting the plugin **keeps all data by default** — the points/wallet ledgers are a financial liability record.
To purge on uninstall, set `delete_data_on_uninstall = true` in the settings option first; the uninstaller then
drops all `wp_yazan_rw_*` tables, deletes the plugin options + secrets, removes the two capabilities, and clears
`_yzrw_*` user meta. See [BACKUP.md](BACKUP.md) before enabling this.

## Local development (Local by Flywheel)

Neither `php` nor `wp` is on PATH; run plugin code via Local's PHP with the site's `php.ini` (so `wp-config`'s
`localhost` resolves to Local's MySQL port). The verification scripts under `scratchpad/` are **CLI-only**
(they return HTTP 403 if requested over the web). Example:

```bash
PHP=".../Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
INI=".../Local/run/<run-id>/conf/php/php.ini"     # re-derive <run-id> after each Local restart
"$PHP" -c "$INI" -d opcache.enable_cli=0 -d zend.multibyte=0 \
  wp-content/plugins/yazan-social-rewards/scratchpad/verify-restapi.php
```
