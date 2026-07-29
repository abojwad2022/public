# Yazan Test Payment Gateways

**Development only. These gateways mark orders as PAID without taking any money.**

Four simulated WooCommerce gateways — Card, Apple Pay, Google Pay, PayPal — that let the full
purchase flow be exercised on `yazan.local`, where the real gateways cannot run (no HTTPS, no
connected WooPayments account, and Apple/Google Pay both require a verified public domain).

## Why a separate plugin

Because deleting one directory removes every trace. Nothing is added to the theme or to `yazan-core`,
so there is no path by which a simulated gateway reaches a live store as a side effect of shipping
something else.

## Two safeguards

1. **Environment gate.** `yazan_tg_allowed()` registers the gateways only when
   `wp_get_environment_type()` is `local` or `development`. `wp-config.php` sets
   `WP_ENVIRONMENT_TYPE = 'local'` here. On production the plugin loads but registers nothing — it
   cannot appear at checkout even if activated by mistake, and shows a notice saying so.
   A staging box that genuinely needs them must define `YAZAN_TG_FORCE` — deliberately awkward.
2. **Standing admin notice.** A non-dismissible error notice lists which simulated gateways are
   enabled, on every admin screen.

Every approved order also carries an order note reading *"SIMULATED payment via … No money was taken."*

## What it reproduces faithfully

The fidelity is in the **flow after approval**, not in the payment protocol. On approval the gateway
calls `WC_Order::payment_complete()` — the same entry point a real gateway uses — so all of this
behaves exactly as in production:

- order status → `processing`, transaction id stored
- stock reduction and stock holds
- "New order" and "Processing order" emails (captured by Mailpit locally)
- thank-you redirect (CartFlows Instant Checkout)
- `woocommerce_payment_complete`, which the **Yazan Payment Bridge** listens to

Verified end-to-end: a real checkout submission through simulated Apple Pay produced
`payment_completed` in `wp_yazan_payment_events` with the correct gateway id, transaction id, amount
and `source=gateway`.

## Settings

**WooCommerce → Settings → Payments**, one section per gateway. Each has:

| Setting | Purpose |
|---|---|
| Enable/Disable | off by default |
| Title / Description | what the customer sees at checkout |
| **Simulated outcome** | `Approve` or **`Decline`** — Decline sets the order to Failed and returns the customer to checkout with an error |

The Decline option exists so the failure path is testable: verified to raise `payment_failed` in the
Bridge ledger.

## Refunds

`supports` includes `refunds`, and `process_refund()` always approves, so refunds can be issued from
the order screen. Verified against the Bridge's classification:

| Action | Event recorded | Integration status |
|---|---|---|
| Refund part of the total | `payment_partially_refunded` | `review` |
| Refund the remainder | `payment_refunded` | `skipped` |

(`skipped` simply means no ownership/warranty subscriber has claimed the event yet — normal until
those systems exist.)

## Effect on the storefront payment marks

The theme's accepted-payment row (`astra-child/inc/payment-marks.php`) derives its marks from the
gateways WooCommerce actually offers. This plugin registers its ids through the theme's
`yazan_payment_gateway_marks` filter, so with it active the row becomes genuinely derived
(`apple-pay, google-pay, paypal, visa, mastercard, amex`) and the theme's pre-launch placeholder set
retires itself. Note `link` correctly disappears — there is no Link gateway.

## Removing it

Deactivate and delete the directory. Optionally drop the four
`woocommerce_yz_test_*_settings` options. Nothing else is left behind.
