# YAZAN Social Rewards — Production Audit Report

**Plugin:** YAZAN Social Rewards & Ambassador Platform
**Version audited:** 1.11.0 → **1.11.1** (this release applies the fixes below)
**Scope:** 235 PHP files · 14 modules · 23 database tables · 25 REST controllers
**Method:** three independent read-only sweeps (security, performance/compatibility, architecture) reading every
repository, controller, template, admin shell, and the install/uninstall lifecycle, cross-checked by re-running
the plugin's four live verification suites (156 assertions, all green).

## Verdict

**Production-ready.** The production code paths — SQL, authorization, CSRF, input validation, output escaping,
secrets, and PHP/WordPress/WooCommerce compatibility — are **clean**. A short list of fixable items (one MEDIUM
security-hygiene item on dev-only scripts, two LOW items, and a set of admin-only performance optimizations) was
found and **fixed in 1.11.1**. No data-loss, financial-correctness, injection, or privilege-escalation defects
were found.

---

## 1. Security — CLEAN (production code)

| Check | Result |
|---|---|
| **SQL injection** | ✅ None. Every `$wpdb` call uses `$wpdb->prepare()` with `%s/%d/%f`; table names come from `Database::table()` (site prefix + hardcoded `yazan_rw_` + a constant — never request input). The one dynamic `IN()` (`NotificationRepository::mark_status`) builds placeholders from `array_fill()` over `array_map('intval',…)` and binds through `prepare()`. `AbstractRepository::build_where()` sanitizes columns to `[a-z0-9_]`, allow-lists operators, binds values. `LIKE` uses `$wpdb->esc_like()`. |
| **Permissions** | ✅ Zero `__return_true`. Every admin route → `require_cap( manage_yazan_rewards )`; customer routes → `require_customer()`; `/statistics` → `require_cap( view_yazan_rewards )`. No IDOR — every customer op resolves identity via `get_current_user_id()`; `mark_read()` is ownership-scoped. |
| **Nonces / CSRF** | ✅ No `wp_ajax_`/`admin_post_`/form handlers — all state changes go through REST, protected by the `wp_rest` nonce (`X-WP-Nonce`) enforced in every `permission_callback`. The one public entry (OAuth callback) uses a single-use `state` transient + per-IP rate limiting instead of a nonce (correct for a redirect callback). |
| **Validation / escaping** | ✅ Inputs sanitized (`absint`/`sanitize_key`/`sanitize_text_field`/`esc_url_raw` + enum allow-lists) with `wp_unslash` on superglobals; outputs escaped (`esc_html`/`esc_attr`/`esc_url`/`wp_kses_post`) in every template and admin shell. `Fingerprint` trusts `X-Forwarded-For` only when `YZRW_TRUST_PROXY` is defined. |
| **Secrets** | ✅ Social OAuth app credentials + the webhook signing secret are stored in **autoload-off, write-only** options exposed only as `last4`, overridable by `wp-config` constants. Customer OAuth tokens are **libsodium-encrypted** at rest (key from `YZRW_TOKEN_KEY`/WP salts, never in the DB). No hardcoded credentials anywhere. |

### Findings + fixes

- **S1 — MEDIUM — dev verification scripts were web-reachable.** `scratchpad/*.php` bootstrap WordPress and
  perform writes; if the plugin directory serves `.php`, they were reachable unauthenticated.
  **Fixed:** each script now aborts with `403` unless `PHP_SAPI === 'cli'`, and a `scratchpad/index.php` blocks
  directory listing. (Best practice: exclude `scratchpad/` from production build artifacts entirely.)
- **S2 — LOW — opt-in uninstall left two secret options behind.** `uninstall.php` did not delete
  `yazan_rewards_social_secrets` or `yazan_rewards_notification_webhook_secret` on a full purge.
  **Fixed:** both (plus the new `yazan_rewards_cron_ready` flag) are now in the delete loop.
- **S3 — LOW / informational — libsodium fallback.** If `sodium_crypto_secretbox` is unavailable, OAuth tokens
  degrade to a clearly-tagged base64 (`yzrw:raw:`) — recoverable at rest. Deliberate "degrade visibly" design.
  **Action (deploy note, not code):** ensure the host has the `sodium` extension (standard on PHP 7.2+) and
  define a dedicated `YZRW_TOKEN_KEY`. Documented in `docs/ADMIN-GUIDE.md`.

---

## 2. Performance & Scalability

Fan-out, rate limiting, and indexes are sound: the notification broadcaster chunks 100/async, the campaign
audience is capped at 5000 with a non-silent `audience_capped` action, points expiry batches at 500, and the
rate limiter is O(1). Hot columns are indexed. The issues were concentrated in the **admin analytics** path.

- **P1 — HIGH — analytics campaigns N+1 (admin-only).** `AnalyticsMetrics::overview()` looped every campaign
  calling `CampaignAnalytics::for_campaign()`, and `SubmissionRepository::stats()` ran **5 `COUNT(*)` queries**
  per campaign — uncached, and `AnalyticsController::export()` re-ran the whole `overview()`.
  **Fixed:** `stats()` is now **one `GROUP BY status` query**, and `overview()` caches its full payload in a
  per-range transient (`yzrw_analytics_overview_{days}`, 15 min, `$fresh` bypass) — reusing the pattern already
  applied to the order aggregate. A dashboard reload and its CSV export now cost ~one transient read.
- **P2 — MEDIUM — uncached `count_users()` + liability scans.** Same root cause; **fixed** by the `overview()`
  cache above (the whole payload, including `count_users()` and `LiabilityCalculator::snapshot()`, is cached).
- **P3 — MEDIUM — per-request Action Scheduler lookups on `init`.** Six recurring schedulers called
  `as_has_scheduled_action()` (a DB query each) on every request.
  **Fixed:** a `yazan_rewards_cron_ready` autoloaded flag is set once the `init` scheduling pass completes;
  each `ensure_scheduled()` short-circuits when it is set. The flag is cleared on settings change, migration,
  and deactivation so scheduling always re-evaluates when it must. Steady-state requests now perform **zero**
  scheduler DB lookups.
- **P4 — LOW — unbounded order fetch in rule context.** `ContextBuilder::purchase_count()` fetched **all** of a
  customer's order IDs to count them. **Fixed:** uses `wc_get_orders( paginate => true )->total` (a COUNT, no
  row materialization).

**Residual (acceptable):** retention/CLV in the order aggregate are all-time (range-independent) and cached
30 min — a documented reporting nuance, not a correctness bug.

---

## 3. Compatibility — CLEAN

- **PHP 8.1 / 8.2:** 100% `declare(strict_types=1)`, constructor property promotion, typed properties. **No**
  dynamic/undeclared properties (no 8.2 deprecation), no `each()`/`create_function()`/`mysql_*`. Requires PHP 8.1.
- **WordPress 6.5+:** i18n via `load_plugin_textdomain` on `init`; Composer-optional PSR-4 autoloader with a
  self-contained fallback; no deprecated core APIs.
- **WooCommerce 8.0+ / HPOS:** `FeaturesUtil::declare_compatibility` for `custom_order_tables` + `cart_checkout_blocks`.
  All order **writes** go through `wc_get_order()`/`wc_get_orders()` CRUD. The single raw order-table read (the
  analytics aggregate) is READ-only and gated by `OrderUtil::custom_orders_table_usage_is_enabled()` with a
  post/meta fallback — HPOS-safe.

---

## 4. Architecture — SOLID

Namespaced PSR-4 OOP with a DI `Container`, a dependency-ordered `ModuleRegistry` (14 modules), an `EventBus`
(engines communicate via domain events + interfaces, never direct calls), a declarative `HookLoader`, typed
`Settings`, and clean install/upgrade/uninstall lifecycles. **24 `apply_filters` extension points** + **4
`yazan_register_*()`** developer functions + a pluggable reward-provider seam make it extensible without core
edits. Two public REST namespaces (`yazan-rewards/v1` internal, `yazan/v1` public/stable). Append-only financial
ledgers with cached balances; uninstall protects data by default (`delete_data_on_uninstall = false`).

- **A1 — docs were stale/missing.** `STRUCTURE.md` (said 12 engines/15 tables) and `DATABASE.md` (18 tables /
  schema 1.1.0) were out of date, and there was no README or install/admin/developer/upgrade/backup guide.
  **Fixed:** both refreshed to the live counts (14 modules, 23 tables, schema 1.11.0) and the full doc set
  generated (`README.md`, `docs/INSTALLATION.md`, `docs/ADMIN-GUIDE.md`, `docs/DEVELOPER.md`,
  `docs/UPGRADE.md`, `docs/BACKUP.md`).

---

## Production-readiness checklist

- [x] No SQL injection / all queries prepared
- [x] All write endpoints capability- + nonce-gated; no IDOR
- [x] Inputs validated & sanitized; outputs escaped
- [x] Secrets write-only / encrypted; none in code
- [x] PHP 8.1/8.2, WP 6.5+, WC 8.0+ / HPOS compatible
- [x] Background jobs bounded (chunked/async/capped) and correctly scheduled on `init`
- [x] Data protected on uninstall by default; opt-in purge is complete
- [x] Dev scripts cannot run over the web (CLI-guarded)
- [x] Docs current (counts, versions, extension points)
- [ ] **Deploy-time:** confirm `sodium` extension present; define `YZRW_TOKEN_KEY` and (if used) `YZRW_TRUST_PROXY`
- [ ] **Deploy-time:** verify Action Scheduler runs (WooCommerce provides it); schedule DB backups (see `docs/BACKUP.md`)

*Generated as part of the 1.11.1 production audit. All fixes verified: `php -l` clean across changed files;
the four live suites pass (37/49/35/35); dashboard `overview()` transient-cached (0.04 ms cached hit); all
recurring jobs scheduled; `scratchpad/*.php` returns 403 over HTTP; front-end healthy (200).*
