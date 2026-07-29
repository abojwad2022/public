# YAZAN Social Rewards — Database Layer

**Twenty-three** dedicated custom tables, all prefixed `{wp_}yazan_rw_`, created and upgraded through one
schema installer. This document lists every table with its indexes and maps the requested spec's
table/field names onto the actual schema. (Current as of plugin 1.11.1 / schema 1.11.0.)

## How the layer works

- **One installer, many contributors.** Each engine module implements `Installable::schema()` and
  returns its `CREATE TABLE` statements. `Core\Install\Schema::install()` collects them all and runs
  them through `dbDelta()`. Adding a table = adding a `schema()` entry in a module — no core change.
- **Upgrade compatibility.** The schema version is `Schema::VERSION` (currently `1.11.0`), stored in
  the `yazan_rewards_db_version` option. On plugin **activation** the installer runs unconditionally;
  on a **silent update** `Core\Install\Migrator::maybe_migrate()` (hooked to `init`, priority 1) re-runs
  it when the plugin version (`YAZAN_REWARDS_VERSION`) moves ahead. `dbDelta` applies only the diff, so
  new tables/columns are added in place without data loss. See [docs/UPGRADE.md](docs/UPGRADE.md).
- **Secure queries.** Raw SQL lives only in repositories (`src/**/*Repository.php`), each extending
  `Core\Database\AbstractRepository`. Every read uses `$wpdb->prepare()`; every write uses
  `$wpdb->insert()/update()` with explicit `%d/%s/%f` format arrays; only the trusted table name
  (from `Database::table()`) is ever interpolated; `LIKE` terms use `esc_like()`.
- **Performance.** Append-only ledgers with derived balances cached in user meta
  (`_yzrw_points_balance`, `_yzrw_credit_balance`, `_yzrw_tier`) for O(1) reads. Every table carries
  the indexes its hot queries need (per-user lookups, status filters, time ordering, idempotency
  keys). Money is `DECIMAL(19,4)`; points are `BIGINT`.

## Requested spec → actual table

| Requested table | Actual table | Notes |
|---|---|---|
| `wp_yazan_rewards_points_ledger` | `wp_yazan_rw_points_ledger` | Append-only. Uses a **signed `points`** column instead of separate `points_added`/`points_used` (a signed ledger is the canonical, race-safe design — sum = balance). `balance_after` is stored; `balance_before` is `balance_after − points` (derivable, not duplicated). `transaction_type` → `type`. `status`, `source`, `campaign_id`, `created_at` present as specified. |
| `wp_yazan_rules` | `wp_yazan_rw_rules` | `actions` (single JSON) is represented as `award_type` + `award_value` (typed columns the evaluator reads directly). `status` → `active` (tinyint) + a `starts_at`/`ends_at` window. `event`, `conditions`, `name`, `created_at` as specified. |
| `wp_yazan_campaigns` | `wp_yazan_rw_campaigns` | As specified (+ `multiplier`, `priority`, time window). |
| `wp_yazan_campaign_tasks` | `wp_yazan_rw_campaign_tasks` | **New — added.** Campaign activities. |
| `wp_yazan_social_accounts` | `wp_yazan_rw_social_accounts` | **New — added.** Connected accounts. |
| `wp_yazan_social_posts` | `wp_yazan_rw_social_actions` | Submitted content + share intents (`action_type` in {share, ugc}). |
| `wp_yazan_rewards` | `wp_yazan_rw_rewards` | Available rewards catalog. |
| `wp_yazan_achievements` | `wp_yazan_rw_achievements` (+ `wp_yazan_rw_user_achievements`) | Definitions + per-user progress. |
| `wp_yazan_referrals` | `wp_yazan_rw_referrals` | Referral relationships/funnel. |
| `wp_yazan_activity_logs` | `wp_yazan_rw_activity_logs` | **New — added.** Per-user activity feed. |
| `wp_yazan_fraud_checks` | `wp_yazan_rw_fraud_flags` | Fraud detection results. |

Additional supporting tables the engines rely on (not in the spec but required): `wallet_ledger`
(store credit), `redemptions`, `ambassadors`, `tiers`, `notifications`, `analytics_daily`.

## The three tables added for this spec

### `wp_yazan_rw_campaign_tasks` — campaign activities
`id`, `campaign_id`, `name`, `task_type`, `criteria`(JSON), `points_award`, `sort`, `active`,
`created_at` · **Indexes:** PK(`id`), KEY(`campaign_id`), KEY(`active`)
Repository: `src/Modules/Campaigns/CampaignTaskRepository.php`.

### `wp_yazan_rw_social_accounts` — connected social accounts
`id`, `user_id`, `platform`, `handle`, `profile_url`, `status`, `verified`, `meta`(JSON),
`connected_at`, `created_at` · **Indexes:** PK(`id`), **UNIQUE**(`user_id`,`platform`), KEY(`status`)
Repository: `src/Modules/Social/SocialAccountRepository.php`. Never stores raw OAuth secrets.

### `wp_yazan_rw_activity_logs` — per-user activity feed
`id`, `user_id`, `activity_type`, `description`, `object_type`, `object_id`, `points`, `meta`(JSON),
`created_at` · **Indexes:** PK(`id`), KEY(`user_id`), KEY(`activity_type`), KEY(`created_at`)
Repository: `src/Modules/Activity/ActivityRepository.php`. Populated by `ActivityLogger` (an event
subscriber) — a pure consumer that never touches balances. Exposed at REST `GET /activity`.

## Full table list (23)

| Table (`wp_yazan_rw_…`) | Owning module | Key indexes |
|---|---|---|
| `points_ledger` | Points | user_id · (source,source_id) · status · expires_at |
| `wallet_ledger` | Wallet | user_id · (source,source_id) · order_id |
| `rules` | Rules | event · active |
| `rewards` | Rewards | active |
| `redemptions` | Rewards | user_id · reward_id |
| `service_vouchers` | Rewards | user_id · status · type |
| `referrals` | Referral | referrer · referred · status |
| `referral_earnings` | Referral | referral_id · user_id |
| `ambassadors` | Ambassador | UNIQUE(user_id) · status |
| `campaigns` | Campaigns | active · starts_at · ends_at |
| `campaign_tasks` | Campaigns | campaign_id · active |
| `marketing_campaigns` | Campaigns | status · window_start · window_end |
| `campaign_participants` | Campaigns | (campaign_id,user_id) |
| `campaign_submissions` | Campaigns | campaign_id · user_id · status |
| `achievements` | Achievement | UNIQUE(akey) |
| `user_achievements` | Achievement | UNIQUE(user_id,achievement_id) · user_id |
| `tiers` | Achievement | UNIQUE(slug) |
| `social_actions` | Social | user_id · status · action_type |
| `social_accounts` | Social | UNIQUE(user_id,platform) · status |
| `fraud_flags` | AntiFraud | user_id · resolved · type |
| `notifications` | Notification | (user_id,channel) · status · queue |
| `analytics_daily` | Analytics | PK(stat_date,metric,dimension) · metric |
| `activity_logs` | Activity | user_id · activity_type · created_at |

The five tables added since the original 18-table spec are `service_vouchers` (service-reward
fulfilment), `referral_earnings` (per-referral ledger), and the marketing/UGC campaign trio
`marketing_campaigns` / `campaign_participants` / `campaign_submissions`.

## Verification

Activate → all **23** tables install (`dbDelta`), `yazan_rewards_db_version` = `1.11.0`. Fire a domain
event (e.g. `ORDER_REWARDED`) → an `activity_logs` row appears via `ActivityLogger`. Connect a social
account → one `social_accounts` row (UNIQUE per user+platform). The CLI verification suites under
`scratchpad/` exercise the ledgers, redemption, campaigns, analytics, notifications, and the public API.
