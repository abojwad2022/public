# YAZAN Payment Bridge Lite v1.0

A lightweight, secure integration layer between WooCommerce's server-verified payment states and the
YAZAN digital ecosystem.

**It does not process payments.** The official gateways (WooPayments, WooCommerce PayPal Payments)
remain the only components that touch money. The Bridge never sees a card number, CVV, PIN, payment
credential or gateway secret, and it never trusts a browser redirect, frontend JavaScript, or
customer-submitted payment data. Payment confirmation depends solely on WooCommerce's server-side
verified state.

```
Customer → WooCommerce Checkout → Official Gateway → WooCommerce verified status
                                                          ↓
                                              YAZAN Payment Bridge Lite
                                                          ↓
                                                   YAZAN Services
```

---

## What it does

1. Listens to the six WooCommerce payment/refund hooks.
2. Records each transition in `wp_yazan_payment_events` with **database-enforced exactly-once**
   semantics.
3. Announces the event to the Ownership and Warranty seams, and to a generic `do_action` extension
   point.

---

## Requirements

- WordPress 6.5+
- WooCommerce 8.0+ (tested against 10.9) — **HPOS compatible**, declared via `FeaturesUtil`
- PHP 8.1+
- MySQL 8+ / MariaDB

Orders are only ever read through `wc_get_order()` and the WooCommerce CRUD API. The plugin never
touches `wp_posts` or `wp_postmeta` for order data.

Without WooCommerce — or on a version below the minimum — the plugin pauses and shows an admin
notice. It never deactivates itself and never fatals.

---

## Idempotency: the core guarantee

`woocommerce_payment_complete`, `woocommerce_order_status_processing` and
`woocommerce_order_status_completed` can all fire for the same order. All three map to the single
canonical event type `payment_completed`, and the events table carries:

```sql
UNIQUE KEY uq_order_event (order_id, event_type)
UNIQUE KEY uq_event_uuid  (event_uuid)
```

Duplicate suppression relies on **the INSERT failing** (MySQL error 1062), not on a SELECT-then-INSERT
sequence — concurrent webhooks, cron passes and admin status changes can race past any PHP check, but
not past the database. See `EventRepository::insert_unique()`.

Downstream integrations are additionally gated by a conditional UPDATE whose affected-row count acts
as a lock (`EventRepository::claim()`), so a retry — or two administrators clicking Retry at once —
still produces at most one downstream run.

---

## Event types

| Type | Trigger |
|---|---|
| `payment_completed` | First of `woocommerce_payment_complete` / `_status_processing` / `_status_completed` |
| `payment_failed` | `woocommerce_order_status_failed` |
| `payment_refunded` | `woocommerce_order_status_refunded`, or `woocommerce_order_refunded` when the cumulative refund covers the order total |
| `payment_partially_refunded` | `woocommerce_order_refunded` below the order total |

Full vs partial is decided in integer minor units, never by float comparison.

A **repeat partial refund** is not lost to the unique constraint: the existing row's amount is updated
to the cumulative refunded total and kept flagged for review.

## Integration statuses

`pending` → `processing` → `completed` | `failed` | `skipped`, plus `review` for partial refunds.

`skipped` means *no downstream system claimed the event* — which is the normal state until a YAZAN
ownership or warranty system is installed. It is deliberately distinct from `failed` (a subscriber ran
and threw) so the admin dashboard shows real faults instead of uniform red.

## Event source

Each event records who caused it: `gateway` (customer checkout or a gateway callback), `manual` (a
privileged user changing status inside wp-admin — legitimate for COD and bank transfer), or `system`
(cron / WP-CLI). Manual events **do** trigger integrations and are labelled "Manual" in the events
list and the logs.

---

## Product eligibility

A line item requires YAZAN services when **either** holds:

1. The product carries a YAZAN serial (`_yz_serial`) — the store's real identity scheme, written by
   `yazan-core` and read by the public `/verify-ring/` certificate lookup. When `yazan-core` is
   present its own `Yazan_Core_Verify::SERIAL_META` constant is used, so the two cannot drift apart.
2. The uppercased SKU matches the configured pattern, applied as a **strict anchored** regular
   expression — never a substring test. Default: `^YZ-[A-Z0-9]{2,10}-\d{1,8}$`.

Variations fall back to the parent product for both signals. An invalid pattern is rejected at save
time with a settings error and the previous value kept, so a bad regex can never break checkout.

---

## Extension points

The Bridge stores **no ownership data**. Yazan's authenticity model is scoped to the certificate and
explicitly excludes an ownership register, so the connectors are seams: they announce, and a
downstream system decides what to record.

### Generic — fired exactly once per recorded event

```php
add_action( 'yazan_payment_bridge/event/payment_completed', function ( $event ) {
    // $event is a Yazan\PaymentBridge\Events\Event value object.
} );
```

Also available: `…/event/payment_failed`, `…/event/payment_refunded`,
`…/event/payment_partially_refunded`.

### Ownership seam

```php
// Claim the event so the Bridge records "completed" instead of "skipped".
add_filter( 'yazan_payment_bridge/ownership/handled_create', '__return_true' );

// Do the work.
add_action( 'yazan_payment_bridge/ownership/create', function ( $event, $items, $reason ) {
    // $items: [ [ product_id, parent_id, serial, sku, quantity ], … ]
}, 10, 3 );
```

Full refunds fire `yazan_payment_bridge/ownership/revoke` (claim via `…/handled_revoke`).
`yazan_payment_bridge/ownership/exists` answers `verifyOwnership()`.

### Warranty seam

Identical shape: `…/warranty/create`, `…/warranty/suspend`, filters `…/warranty/handled_create`,
`…/warranty/handled_suspend`, and `…/warranty/status` for `getWarrantyStatus()`.

### Other

- `yazan_payment_bridge/eligible_items` — filter the resolved line items.
- `yazan_payment_bridge/booted` — after hooks are registered.
- `yazan_payment_bridge/migrated` — after a schema install/upgrade.

A downstream system that implements `OwnershipServiceInterface` / `WarrantyServiceInterface` and
subscribes to these hooks needs no change to the Bridge.

---

## Admin

**YAZAN → Payment Bridge** (dashboard), **Payment Events** (searchable, filterable, paginated list
with a nonce-protected Retry action), and **Settings**.

Capabilities, granted to Administrator on activation (`view` also to Shop Manager):

| Capability | Grants |
|---|---|
| `yazan_payment_view` | Read the dashboard and events list |
| `yazan_payment_manage` | Change settings |
| `yazan_payment_retry` | Re-run an integration |

Every handler checks its capability first, verifies a nonce on every state-changing action, unslashes
and sanitises every request value, uses `$wpdb->prepare()` for every query containing a variable, and
escapes every rendered value. Gateway names, transaction ids and error messages are treated as
untrusted output.

## Settings

| Setting | Default |
|---|---|
| Enable ownership integration | On |
| Enable warranty integration | On |
| Eligible SKU pattern | `^YZ-[A-Z0-9]{2,10}-\d{1,8}$` |
| Enable debug logging | **Off** |
| Delete all data on uninstall | **Off** |

## Logging

WooCommerce logger (**WooCommerce → Status → Logs**), source `yazan-payment-bridge`, levels
debug/info/warning/error.

**No personal data at any level.** Log context is filtered through an allow-list of identifiers and
status codes (`Sanitizer::scrub_context()`), and every message is scrubbed for card numbers, gateway
keys, tokens, bearer credentials and email addresses (`Sanitizer::scrub_error()`) before it is written
or stored. Debug is off by default.

## Uninstall

`uninstall.php` always removes the three capabilities. It **keeps the payment-event ledger** unless
"Delete all data on uninstall" was explicitly enabled — payment history may be required for
accounting or legal retention.

---

## Tests

The suite lives with the project's other regression suites:

```bash
"$LOCAL_PHP" -c "$LOCAL_PHP_INI" tests/run.php payments
```

66 assertions covering the unique constraints, duplicate suppression, the claim lock, the
"two identical payment events produce exactly one integration run" guarantee, retry safety, refund
classification including repeat partials, eligibility anchoring, error scrubbing, capability gating,
and hostile admin input.

---

## Not in v1.0 (reserved for v1.2)

- **REST API** — the namespace `yazan/v1` and route `/payment-events` are reserved as constants only
  (`Plugin::REST_NS`, `Plugin::REST_ROUTE`). Nothing is registered. Note that `yazan/v1` is already in
  use by `yazan-core` and `yazan-social-rewards`; v1.2 must not assume it owns the namespace. When
  implemented it must require authentication and a real `permission_callback` — never `__return_true`.
- Rewards connector (will consume the same `payment_completed` event)
- Advanced retry system, Action Scheduler queue, external services, multi-store support
