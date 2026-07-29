# YAZAN Payment Bridge Lite v1.0 — Security Hardening Self-Audit

Every rule from the specification's H1–H7 section, with the file and line that implements it and how
it was verified. Line numbers are from the shipped v1.0.0 source.

Verification commands used throughout:

```bash
LOCAL_PHP="$HOME/AppData/Roaming/Local/lightning-services/php-8.3.17+1/bin/win64/php.exe"
LOCAL_PHP_INI="$HOME/AppData/Roaming/Local/run/O2BtUtET-/conf/php/php.ini"
"$LOCAL_PHP" -c "$LOCAL_PHP_INI" tests/run.php payments     # 66 assertions, all passing
```

Runtime: **PHP 8.3.17**, WordPress 7.0.2, WooCommerce 10.9.1 with HPOS enabled, MySQL 8.4.
The suite was run and passed on both PHP 8.2.29 (the environment's previous engine) and 8.3.17.

> Local reassigns the run-directory id and ports when it restarts. Re-derive `<id>` from
> `~/AppData/Roaming/Local/run/<id>/conf/php/php.ini` if these paths stop working. Note that
> `local-site.json` can lag behind the real engine — the authority is
> `run/<id>/conf/php/php-fpm.d/www.conf`.

---

## H1 — Database-level idempotency (no check-then-act)

| Requirement | Implementation | Verified |
|---|---|---|
| `UNIQUE KEY` on `(order_id, event_type)` | `src/Database/Installer.php:135` | `SHOW CREATE TABLE` confirms `UNIQUE KEY uq_order_event (order_id,event_type)` |
| `UNIQUE KEY` on `event_uuid` | `src/Database/Installer.php:136` | `SHOW CREATE TABLE` confirms `UNIQUE KEY uq_event_uuid (event_uuid)` |
| Duplicates detected by the constraint failing, not by a PHP lookup | `src/Events/EventRepository.php:53-104` — `insert_unique()` suppresses wpdb errors around the INSERT (`:78`), reads the driver error number (`:82`), and treats `1062` / `duplicate entry` as "already handled" (`:90`) | Test *"second insert is rejected by the unique constraint"*, *"exactly one payment_completed row exists"* |
| No SELECT-then-INSERT as the sole protection | There is **no** pre-INSERT existence check anywhere in the write path. `find_by_order_and_type()` (`:226`) is only called *after* a duplicate has already been rejected, to merge cumulative partial refunds | Code inspection + test *"a third qualifying hook still cannot create a duplicate"* |
| Same payment never creates duplicate ownership / certificate / warranty records | `EventRepository::claim()` (`src/Events/EventRepository.php:115-134`) is a conditional `UPDATE … WHERE integration_status IN ('pending','failed')` whose affected-row count is the lock; `IntegrationDispatcher::dispatch()` (`src/Integrations/IntegrationDispatcher.php:63-72`) refuses to run without it | Test *"two identical payment events produce exactly ONE integration run"* — a spy on `yazan_payment_bridge/ownership/create` fires exactly once across two `record()` calls |

**Live evidence.** Driving a real order through `payment_complete()`, then `processing`, then
`completed` produced **one** row:

```
1 event row(s) recorded after 3 qualifying hooks:
  #43  type=payment_completed  source=gateway  status=skipped  gateway=cod  txn=E2E-TXN-12345  amount=250.0000 USD
```

---

## H2 — Canonical trigger event (hook deduplication)

| Requirement | Implementation | Verified |
|---|---|---|
| One canonical `PaymentCompleted` event | `src/Events/EventTypes.php:28` (`PAYMENT_COMPLETED`), documented at `:18-24` | — |
| All three qualifying hooks map to it | `src/Payments/PaymentListener.php:97` (`woocommerce_payment_complete`), `:107` (`_status_processing`), `:117` (`_status_completed`) | Live E2E above |
| Later qualifying hooks must not re-trigger integrations | Enforced by the `(order_id, event_type)` UNIQUE key, since all three produce the same `event_type` | Test *"a third qualifying hook still cannot create a duplicate"* |
| Status-only events map to distinct types | `payment_failed`, `payment_refunded`, `payment_partially_refunded` — `src/Events/EventTypes.php:31,34,37` | — |

---

## H3 — Refund handling (full AND partial)

| Requirement | Implementation | Verified |
|---|---|---|
| Listen to `woocommerce_order_refunded` (partials) as well as `woocommerce_order_status_refunded` | `src/Payments/PaymentListener.php:78,84` (hook table), handlers at `:136` and `:158` | Live E2E produced both a `payment_partially_refunded` and a `payment_refunded` row |
| Record refund events with the refunded amount | `RefundClassifier::refunded_amount()` (`src/Payments/RefundClassifier.php:63`), passed through `PaymentEventService::record()` | Test *"the stored amount is the cumulative refunded total (25 + 15 = 40)"* |
| Full refund dispatches to Ownership + Warranty so downstream can revoke | `IntegrationDispatcher::on_full_refund()` (`src/Integrations/IntegrationDispatcher.php:192-205`) calls `revokeOwnership()` (`:197`) and `suspendWarranty()` (`:201`) | Tests *"a full refund notifies the ownership revoke seam"* / *"…the warranty suspend seam"* — driven through the **real** `wc_create_refund()` hook path, not a synthetic call |
| Seam methods exist | `OwnershipServiceInterface::revokeOwnership()`, `WarrantyServiceInterface::suspendWarranty()` | — |
| Partial refund records + sets status, does **not** auto-revoke | `IntegrationDispatcher::run()` returns `IntegrationStatus::REVIEW` for partials (`:135`) and never reaches the revoke path | Test *"a partial refund is flagged for manual review, never auto-revoked"* |
| Flagged in the admin for manual review | "Refunds awaiting review" card — `src/Admin/DashboardPage.php:69-74`; filterable status in the events list | Dashboard render verified |
| Full vs partial decided reliably | `RefundClassifier::to_minor_units()` (`src/Payments/RefundClassifier.php:73-80`) compares integer minor units — no float equality, no bcmath dependency | Tests *"a 25/100 refund is classified partial"*, *"refunding the remainder is classified full"* |

**Beyond the spec:** a repeat partial refund would be silently swallowed by the UNIQUE constraint. It
is instead merged into the existing row's cumulative amount — `PaymentEventService::on_duplicate()`
(`src/Payments/PaymentEventService.php:119-151`). Test: *"repeat partial refunds do not create a
second row"*.

---

## H4 — Manual status changes

| Requirement | Implementation | Verified |
|---|---|---|
| `source` field on the event | Column at `src/Database/Installer.php:123`; value object property `src/Events/Event.php:54` | `SHOW CREATE TABLE` |
| Derived from context (`gateway` / `manual` / `system`) | `Event::detect_source()` (`src/Events/Event.php:154-166`): cron/WP-CLI → `system`; a user with `edit_shop_orders` acting inside wp-admin → `manual` (`:162`); everything else → `gateway` | Tests *"a customer checking out is recorded as a gateway event, not manual"*, *"a gateway webhook with no logged-in user is a gateway event"* |
| Manual events still trigger integrations | No branch on `source` anywhere in `IntegrationDispatcher` | Code inspection |
| Visibly labelled "Manual" | `Event::source_label()` (`src/Events/Event.php:173-182`); Source column with a pill — `src/Admin/EventsPage.php:240`; included in log context | Events page render |

---

## H5 — Admin panel security

| Requirement | Implementation | Verified |
|---|---|---|
| Capability check at the top of every handler | `DashboardPage.php:39`, `EventsPage.php:49`, `SettingsPage.php:98`, `RetryController.php:66` — all before any work | `grep current_user_can` over `src/` returns exactly these plus the WooCommerce-notice guard |
| Nonce on every state-changing action | `RetryController::handle()` → `check_admin_referer( 'yazan_pb_retry_' . $event_id )` (`src/Admin/RetryController.php:75`); link built with `wp_nonce_url()` (`src/Admin/EventsPage.php:281`); settings use the Settings API's own nonce via `settings_fields()` (`src/Admin/SettingsPage.php:108`) | Retry URL confirmed to carry `_wpnonce` |
| All request params unslashed + sanitised | The plugin's only six superglobal reads: `EventsPage.php:79-82,101` and `RetryController.php:64` — every one is `wp_unslash()` + `sanitize_text_field()` / `sanitize_key()` / `absint()` | `grep '\$_\(POST\|GET\|REQUEST\)\['` returns exactly 6 lines, all wrapped |
| `$wpdb->prepare()` for every query with a variable | All SQL lives in `src/Events/EventRepository.php`. Every statement carrying a variable is prepared: `:120` (claim), `:214`, `:231`, `:268` (paginated list), `:289`, `:323`. The two unprepared statements (`:293`, `:311`) contain **no** variables — only the plugin-owned table name | `grep` audit of every `$wpdb->` call site |
| Search/filter/pagination queries prepared | `EventRepository::build_where()` (`:347-380`) whitelists `event_type` and `integration_status` against `EventTypes::all()` / `IntegrationStatus::all()`, `esc_like()`s the search term, binds every value; ORDER BY is a fixed literal; `LIMIT %d OFFSET %d` are bound (`:264-268`) | Test *"the events table survived the hostile filter values"* with `'; DROP TABLE wp_yazan_payment_events; --` as a filter value |
| Output escaping on every rendered value | `esc_html()` / `esc_attr()` / `esc_url()` throughout `src/Admin/` | Rendered the events page with a gateway of `stripe"><img src=x onerror=alert(2)>` — output is `stripe&quot;&gt;&lt;img src=x onerror=alert(2)&gt;`; no raw `<img`, no `<b>`, no attribute break-out |
| Settings registered with a sanitization callback | `register_setting( …, 'sanitize_callback' => [ $settings, 'sanitize' ] )` — `src/Admin/SettingsPage.php:57-67` | Verified live: a non-compiling pattern is rejected with `add_settings_error()` and the previous value kept |

**Access control verified live:** administrators hold all three capabilities; shop managers may read
but not manage; customers hold none and `EventsPage::render()` `wp_die()`s for them.

---

## H6 — Sensitive data in storage and logs

| Requirement | Implementation | Verified |
|---|---|---|
| `error_message` sanitised before storage | `EventRepository::finish()` (`src/Events/EventRepository.php:161`) routes through `Sanitizer::scrub_error()` | Test *"the stored error message has the card number redacted"* — a subscriber throwing `…card 4111 1111 1111 1111` stores a redacted message |
| Truncated to a fixed length | `Sanitizer::MAX_ERROR_LENGTH = 500` (`src/Support/Sanitizer.php:26`), applied at `:84`/`:87` | Test *"messages are truncated to the column width"* |
| Card-number and token patterns stripped | `src/Support/Sanitizer.php:59-71` — PAN, `sk_/pk_/rk_` keys, `tok_/card_/cus_/pi_/ch_/seti_` tokens, `Bearer`, labelled CVV, email | Tests for each |
| Never store raw gateway response bodies | Only `\Throwable::getMessage()` from our own catch blocks is stored (`IntegrationDispatcher.php:93`) — no gateway payload is ever read | Code inspection |
| No PII in logs at any level | `Logger::write()` (`src/Logging/Logger.php:93-125`) passes every context array through `Sanitizer::scrub_context()`, which drops anything outside an allow-list of identifiers and status codes (`src/Support/Sanitizer.php:34-47,96-114`) | **Live probe**: logged a context containing an email, a customer name, an address and a card number, then read the log file — all four absent; only `{"order_id":4242,"event_type":"payment_completed"}` written |
| Debug logging OFF by default | `config/default-settings.php` → `debug_logging => 0`; `Logger::debug()` returns early unless enabled (`src/Logging/Logger.php:45-50`) | Verified live: `debug_logging enabled: no (correct)`, and a `debug()` call while off wrote nothing |

Log line format actually produced:

```
2026-07-29T05:41:32+00:00 DEBUG Probe with PII in the context. {"order_id":4242,"event_type":"payment_completed"}
2026-07-29T05:41:32+00:00 ERROR Probe error with a card [redacted-pan]and [redacted-email] inline.
```

---

## H7 — Baseline hardening

| Requirement | Implementation | Verified |
|---|---|---|
| `defined( 'ABSPATH' ) || exit;` in every PHP file | Every file | Automated scan: **35 PHP files, 0 missing the guard** |
| WooCommerce active + minimum version, graceful deactivation with a notice, never fatal | `Plugin::woocommerce_supported()` (`src/Core/Plugin.php:259-267`, `MIN_WC_VERSION = '8.0'` at `:57`); `boot()` returns early and adds an `admin_notices` handler (`:225-228`); notice at `:274-291`. **`deactivate_plugins()` is never called** | Code inspection |
| `amount` is `DECIMAL(19,4)` | `src/Database/Installer.php:126` | `SHOW COLUMNS` → `decimal(19,4)`; test asserts it |
| `transaction_id` nullable | `src/Database/Installer.php:125`; `Event::from_order()` stores `null` for an empty id (`src/Events/Event.php:88`) | `SHOW COLUMNS` → `Null = YES`; test asserts it. Live COD order recorded `txn=E2E-TXN-12345`, and an order without one stores `NULL` |
| `class_exists()` / `function_exists()` guards before external classes | `ProductEligibilityService::serial_meta_key()` guards `Yazan_Core_Verify` (`src/Payments/ProductEligibilityService.php:48-54`); `Logger::write()` guards `wc_get_logger()` (`src/Logging/Logger.php:94`); HPOS declaration guards `FeaturesUtil` (`yazan-payment-bridge-lite.php:74`) | Test *"a product with a _yz_serial is eligible…"* passes with and without yazan-core resolution |
| Missing dependency = log + status, never a fatal | A missing downstream system yields `IntegrationStatus::SKIPPED` with a debug log, not a fatal; a *throwing* subscriber yields `FAILED` | Tests *"with no downstream system, the event is skipped rather than reported as a failure"* and *"a throwing subscriber does not escape record() into checkout"* |
| `uninstall.php` does not drop the table by default | `uninstall.php:53` reads `delete_data_on_uninstall`; `:56` returns early when unset. Default is `0` in `config/default-settings.php` | Code inspection |
| Opt-in "Delete all data on uninstall" setting, default OFF | `config/default-settings.php`; field at `src/Admin/SettingsPage.php:89` | Verified live in the settings dump |
| HPOS compatibility declared | `yazan-payment-bridge-lite.php:74` — `FeaturesUtil::declare_compatibility( 'custom_order_tables', YAZAN_PB_FILE, true )` | Site runs with HPOS enabled (`woocommerce_custom_orders_table_enabled = yes`); all order access is `wc_get_order()` / CRUD |
| No `$_POST`/`$_GET` without `wp_unslash()` + sanitising | See H5 | 6/6 reads compliant |

---

## Additional guarantees not required by H1–H7

- **Checkout can never break.** Every listener handler is wrapped in `try/catch (\Throwable)` —
  `PaymentListener::safely()` (`src/Payments/PaymentListener.php:185-191`) and `swallow()` (`:201-212`).
  Verified by the test *"a throwing subscriber does not escape record() into checkout"*.
- **Anchored SKU matching, never `strpos()`.** `ProductEligibilityService::sku_matches()`
  (`src/Payments/ProductEligibilityService.php:123-137`); `Settings::is_valid_pattern()`
  (`src/Settings/Settings.php:102-114`) rejects any pattern not anchored with `^`…`$` and any pattern
  that will not compile. Tests prove `XYZ-RING-1ABC` and `YZ-DEMO-12-EXTRA` do **not** match.
- **Regex round-trip through the Settings API is lossless.** `wp-admin/options.php` does not unslash
  array option values, so the sanitizer's `wp_unslash()` is required and correct — verified that
  `^YZ-[A-Z0-9]{2,10}-\d{1,8}$` survives a slashed POST intact and still matches `YZ-DEMO-12`.
- **No REST surface in v1.0.** `grep register_rest_route` over the plugin returns nothing. The
  namespace and route are reserved as constants only (`src/Core/Plugin.php:51,54`).
- **No regressions.** The full project suite (`tests/run.php`) reports 569 passed / 4 failed; the same
  4 failures occur with this plugin deactivated (pre-existing `yazan-core` `/yazan/v1/ai/*` and
  `/yazan/v1/wishlist` authz issues, unrelated to this work).

---

## Deliberate deviations from the specification

1. **`src/` rather than `includes/`** — matches the project's documented modern architecture
   (`yazan-social-rewards`). All specified class names and sub-folders are preserved.
2. ~~PHP 8.1 target, not 8.3~~ — **resolved.** The environment was upgraded to PHP 8.3.17, so the
   plugin declares `Requires PHP: 8.3` as the specification asked, and the suite passes on it.
   The source itself uses no 8.2- or 8.3-only construct (no `readonly` class, typed class constant,
   `#[\Override]`, `json_validate()`, or dynamic constant fetch — verified by scan, and all 35 files
   parse on 8.2.27 as well). It is therefore linted cleanly by the repo's `phpcs.xml`, whose
   `testVersion` stays at `8.1-` to keep protecting `yazan-core` and `yazan-social-rewards`, both of
   which still declare `Requires PHP: 8.1`. The plugin has been added to `phpcs.xml`'s `<file>` list.
3. **`integration_status` gains `skipped`** — the spec maps a missing dependency to `failed`. With no
   downstream YAZAN ownership or warranty system installed, *every* event would read `failed` and the
   dashboard would be uniformly red, hiding genuine faults. `skipped` means "no subscriber"; `failed`
   means "a subscriber ran and threw". H7's intent (never fatal, always recorded) is preserved.
4. **Repeat partial refunds merge instead of being dropped** — the UNIQUE constraint is unchanged; the
   existing row's amount is updated to the cumulative total so no refund is lost.
