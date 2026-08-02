# Yazan Standalone Product Dashboard

A WooCommerce-Products replacement that lives **outside `wp-admin`** at `https://<site>/dashboard`,
with its own login. It is not a second copy of your catalogue — every read and write goes through
WooCommerce CRUD, so the dashboard **is** WooCommerce.

---

## Quick start

```bash
# Build the SPA (only needed after changing anything in dashboard-app/src)
cd wp-content/plugins/yazan-core/dashboard-app
npm install
npm run build          # → ../assets/dashboard/app.js + app.css
```

Then open **`/dashboard`** and sign in with a WooCommerce **Shop Manager** or **Administrator**
account. If the URL 404s, visit `/wp-admin/` once (the idempotent installer flushes rewrite rules) or
re-save Settings → Permalinks.

`npm run dev` runs a standalone Vite dev server against a stubbed boot payload — useful for pure UI
work, but API calls need the real WordPress origin.

---

## Architecture

```
Browser ──► /dashboard         WP rewrite → PHP shell: #root + boot JSON (nonce, user) + app.js
              │
          React SPA (Vite + Tailwind)
              │  fetch, credentials:'same-origin', X-WP-Nonce
              ▼
        /wp-json/yazan/v1/*    REST controllers (capability check per route)
              │
              ▼
        WooCommerce CRUD       WC_Product / wc_get_products / wc_get_order — HPOS-safe
```

**Why cookie auth, not API keys.** The dashboard is same-origin, so the normal WordPress session
cookie authenticates it. WordPress only honours a cookie-authenticated REST request when it carries a
valid `wp_rest` nonce in `X-WP-Nonce` — that header *is* the CSRF protection, and it means **no API
key or token is ever stored in the browser**. Each route additionally runs `current_user_can()`.

---

## Files

```
yazan-core/
├── includes/dashboard/
│   ├── class-yazan-dashboard-boot.php     Requires the classes, registers every hook
│   ├── class-yazan-dashboard.php          /dashboard rewrite + standalone HTML shell
│   ├── class-yazan-dashboard-auth.php     Login/logout/me + shared permission callbacks
│   ├── class-yazan-dashboard-fields.php   Jewelry & authenticity field registry  ← the sync contract
│   ├── class-yazan-dashboard-audit.php    Audit log (who / what / when / IP / browser)
│   └── rest/
│       ├── class-yazan-rest-guard.php     Central default-deny authorization for the namespace
│       ├── class-yazan-rest-products.php  list / read / create / update / delete / bulk
│       ├── class-yazan-rest-media.php     upload / list / delete (real Media Library)
│       ├── class-yazan-rest-meta.php      reference data + dashboard stats
│       ├── class-yazan-rest-users.php     staff accounts: CRUD, suspend, reset, force-logout
│       ├── class-yazan-rest-roles.php     role CRUD + the grant editor's save path
│       └── class-yazan-rest-permissions.php   the catalog, grouped by module (read-only)
├── includes/rbac/                         Access control — see "Roles & permissions" below
│   ├── class-yazan-rbac-boot.php          Requires the classes, hooks, install/upgrade path
│   ├── class-yazan-rbac-schema.php        The four tables (dbDelta)
│   ├── class-yazan-permission-registry.php  THE CATALOG — source of truth, in code
│   ├── class-yazan-permissions.php        Resolution + three-tier cache
│   ├── class-yazan-roles.php              Role CRUD, grants, assignment, default seed
│   ├── class-yazan-users.php              Status, last login, phone, photo, sessions
│   ├── class-yazan-cap-projection.php     Yazan permissions → WP capabilities (additive only)
│   └── class-yazan-rbac-guard.php         Last-super-admin, self-protection, anti-escalation
├── assets/dashboard/                      Build output — COMMIT THIS (app.js, app.css, favicon.svg)
└── dashboard-app/                         React source (node_modules/ is gitignored)
    ├── vite.config.js                     Emits stable app.js / app.css into assets/dashboard/
    └── src/
        ├── api/          client.js (nonce + fetch), endpoints.js
        ├── context/      AuthContext, MetaContext, ToastContext
        ├── components/   ui.jsx, Layout.jsx, product/*, media/*
        └── pages/        Login, Products, ProductEditor, Misc (home/categories/attributes/inventory)
```

---

## REST API (`/wp-json/yazan/v1`)

Generated from the live route registry. The third column is the **Yazan permission slug** the route
requires — not a WordPress capability. Enforcement is central and default-deny
(`Yazan_REST_Guard` on `rest_request_before_callbacks`), with the same slug repeated on each route's
own `permission_callback`; both come from one argument to `Yazan_REST_Guard::args()`, so they cannot
drift apart. See [Roles & permissions](#roles--permissions-rbac).

Rows marked **public** are deliberately outside RBAC and keep their own `permission_callback`:
sign-in, the storefront chat widget, the token-authenticated backup download, and the customer
wishlist. `/customer/*`, `/campaign/*`, `/reward/*` and `/statistics` also live in this namespace but
belong to **yazan-social-rewards** — they are listed in `Yazan_REST_Guard::FOREIGN_PREFIXES`.

| Method | Route | Permission |
|---|---|---|
| GET | `/ai/analytics` | `ai.insights_view` |
| POST | `/ai/chat` | **public** — not RBAC-governed |
| GET | `/ai/chat-nonce` | **public** — not RBAC-governed |
| POST | `/ai/chat/handoff` | **public** — not RBAC-governed |
| POST | `/ai/core/test` | `ai.configure` |
| GET | `/ai/credentials` | `ai.configure` |
| POST | `/ai/credentials` | `ai.configure` |
| POST | `/ai/gallery/generate` | `ai.use` |
| POST | `/ai/gallery/plan` | `ai.use` |
| GET | `/ai/logs` | `ai.view_logs` |
| POST | `/ai/marketing` | `ai.use` |
| POST | `/ai/product` | `ai.use` |
| POST | `/ai/seo` | `ai.use` |
| GET | `/ai/settings` | `ai.configure` |
| POST · PUT/PATCH | `/ai/settings` | `ai.configure` |
| POST | `/ai/test` | `ai.configure` |
| POST | `/ai/test-all` | `ai.configure` |
| GET | `/attributes` | `attributes.view` |
| POST | `/attributes` | `attributes.create` |
| POST · PUT/PATCH | `/attributes/{id}` | `attributes.edit` |
| DELETE | `/attributes/{id}` | `attributes.delete` |
| GET | `/audit` | `audit.view` |
| POST | `/audit/purge` | `audit.purge` |
| POST | `/auth/login` | **public** — not RBAC-governed |
| POST | `/auth/logout` | `dashboard.access` |
| GET | `/auth/me` | `dashboard.access` |
| GET | `/backup` | `backup.view` |
| POST | `/backup` | `backup.create` |
| DELETE | `/backup/{id}` | `backup.delete` |
| POST | `/backup/{id}/download-token` | `backup.download` |
| POST | `/backup/{id}/restore` | `backup.restore` |
| GET | `/backup/download` | **public** — not RBAC-governed |
| GET | `/coupons` | `coupons.view` |
| POST | `/coupons` | `coupons.create` |
| GET | `/coupons/{id}` | `coupons.view` |
| POST · PUT/PATCH | `/coupons/{id}` | `coupons.edit` |
| DELETE | `/coupons/{id}` | `coupons.delete` |
| GET | `/customers` | `customers.view` |
| GET | `/customers/{id}` | `customers.view` |
| GET | `/emails` | `emails.view` |
| POST · PUT/PATCH | `/emails` | `emails.edit` |
| POST · PUT/PATCH | `/emails/{id}` | `emails.edit` |
| GET | `/gateways` | `gateways.view` |
| POST · PUT/PATCH | `/gateways` | `gateways.edit` |
| POST · PUT/PATCH | `/gateways/{id}` | `gateways.edit` |
| POST | `/inventory/bulk` | `inventory.edit` |
| GET | `/media` | `media.view` |
| POST | `/media` | `media.upload` |
| DELETE | `/media/{id}` | `media.delete` |
| GET | `/meta/taxonomies` | `dashboard.access` |
| GET | `/orders` | `orders.view` |
| POST | `/orders` | `orders.create` |
| GET | `/orders/{id}` | `orders.view` |
| POST · PUT/PATCH | `/orders/{id}` | `orders.edit` |
| POST · PUT/PATCH | `/orders/{id}/addresses` | `orders.addresses` |
| POST | `/orders/{id}/coupons` | `orders.coupons` |
| DELETE | `/orders/{id}/coupons` | `orders.coupons` |
| POST · PUT/PATCH | `/orders/{id}/items` | `orders.items` |
| GET | `/orders/{id}/notes` | `orders.view` |
| POST | `/orders/{id}/notes` | `orders.notes` |
| GET | `/orders/{id}/refunds` | `orders.view` |
| POST | `/orders/{id}/refunds` | `orders.refund` |
| DELETE | `/orders/{id}/refunds/{refund_id}` | `orders.refund_gateway` |
| GET | `/orders/alerts` | `orders.view` |
| POST | `/orders/bulk` | `orders.status` |
| GET | `/permissions` | `permissions.view` |
| GET | `/porting/export` | `porting.export` |
| POST | `/porting/import` | `porting.import` |
| GET | `/products` | `products.view` |
| POST | `/products` | `products.create` |
| GET | `/products/{id}` | `products.view` |
| POST · PUT/PATCH | `/products/{id}` | `products.edit` |
| DELETE | `/products/{id}` | `products.delete` |
| POST | `/products/{id}/duplicate` | `products.duplicate` |
| POST | `/products/{id}/restore` | `products.restore` |
| POST | `/products/bulk` | `products.bulk` |
| POST | `/products/bulk-edit` | `products.bulk` |
| POST | `/products/quick-edit` | `products.edit` |
| POST | `/products/trash/empty` | `products.delete` |
| GET | `/reports/sales` | `reports.view` |
| GET | `/reports/stock` | `inventory.view` |
| GET | `/roles` | `roles.view` |
| POST | `/roles` | `roles.create` |
| GET | `/roles/{id}` | `roles.view` |
| POST · PUT/PATCH | `/roles/{id}` | `roles.edit` |
| DELETE | `/roles/{id}` | `roles.delete` |
| POST | `/roles/{id}/duplicate` | `roles.create` |
| GET | `/roles/{id}/users` | `users.view` |
| GET | `/settings` | `settings.view` |
| POST · PUT/PATCH | `/settings` | `settings.edit` |
| GET | `/shipping/zones` | `shipping.view` |
| POST | `/shipping/zones` | `shipping.edit` |
| POST · PUT/PATCH | `/shipping/zones/{id}` | `shipping.edit` |
| DELETE | `/shipping/zones/{id}` | `shipping.edit` |
| POST | `/shipping/zones/{id}/methods` | `shipping.edit` |
| POST · PUT/PATCH | `/shipping/zones/{id}/methods/{instance}` | `shipping.edit` |
| DELETE | `/shipping/zones/{id}/methods/{instance}` | `shipping.edit` |
| GET | `/social-auth` | `settings.view` |
| POST · PUT/PATCH | `/social-auth` | `settings.edit` |
| GET | `/stats` | `dashboard.view` |
| GET | `/status` | `status.view` |
| POST | `/status/tools/{tool}` | `status.tools` |
| GET | `/tax` | `tax.view` |
| POST · PUT/PATCH | `/tax` | `tax.edit` |
| POST | `/tax/classes` | `tax.edit` |
| DELETE | `/tax/classes` | `tax.edit` |
| GET | `/tax/rates` | `tax.view` |
| POST | `/tax/rates` | `tax.edit` |
| POST · PUT/PATCH | `/tax/rates/{id}` | `tax.edit` |
| DELETE | `/tax/rates/{id}` | `tax.edit` |
| GET | `/terms/{taxonomy}` | `categories.view` |
| POST | `/terms/{taxonomy}` | `categories.create` |
| POST · PUT/PATCH | `/terms/{taxonomy}/{id}` | `categories.edit` |
| DELETE | `/terms/{taxonomy}/{id}` | `categories.delete` |
| GET | `/users` | `users.view` |
| POST | `/users` | `users.create` |
| GET | `/users/{id}` | `users.view` |
| POST · PUT/PATCH | `/users/{id}` | `users.edit` |
| DELETE | `/users/{id}` | `users.delete` |
| POST | `/users/{id}/activate` | `users.suspend` |
| GET | `/users/{id}/activity` | `users.view_activity` |
| POST | `/users/{id}/force-logout` | `users.force_logout` |
| POST | `/users/{id}/photo` | `users.edit` |
| POST | `/users/{id}/reset-password` | `users.reset_password` |
| POST | `/users/{id}/suspend` | `users.suspend` |
| GET | `/webhooks` | `webhooks.view` |
| POST | `/webhooks` | `webhooks.create` |
| POST · PUT/PATCH | `/webhooks/{id}` | `webhooks.edit` |
| DELETE | `/webhooks/{id}` | `webhooks.delete` |
| GET | `/wishlist` | **public** — not RBAC-governed |
| POST | `/wishlist/toggle` | **public** — not RBAC-governed |

---

## The sync contract (important)

`class-yazan-dashboard-fields.php` maps every Yazan field onto **storage the storefront already
reads**. Nothing is duplicated, which is why edits appear instantly on `/store/` and `/verify-ring/`.

| Dashboard field | Stored as |
|---|---|
| Agate Type / Origin / Color | attributes `pa_stone` · `pa_origin` · `pa_color` |
| Stone Weight / Shape | `pa_carat` · `pa_shape` |
| Silver Type / Purity | `pa_metal` · `pa_silver-purity` |
| Ring Size · Rarity | `pa_size` · `pa_rarity` |
| Collection | `collection` taxonomy |
| **Serial** | `_yz_serial` — the existing key `Yazan_Core_Verify` looks up |
| Silver Weight | `_yz_silver_weight` *(new)* |
| Craftsmanship | `_yz_craftsmanship` *(new)* |
| QR code · Certificate | `_yz_qr_code` · `_yz_certificate_id` *(new, attachment ids)* |
| Verification Status | `_yz_verification_status` *(new: draft / certified / retired)* |

Setting a **serial** + status **certified** is what makes a ring resolve at
`/verify-ring/{serial}/` — the dashboard is the authoring UI for the verification feature.

> Scope is authenticity only — stone, silver, craft. No ownership data is stored, by design.

### Adding a new jewelry field

- Backed by a WooCommerce attribute → add a row to `Yazan_Dashboard_Fields::attribute_fields()`.
  The editor, the `/meta/taxonomies` payload and saving all pick it up automatically.
- Plain value → add a `META_*` constant, register it in `register_meta()`, and handle it in
  `read()` + `stage_meta()`.

---

## Theming (light / dark)

A toggle sits in the top bar. The choice persists in `localStorage['yazan-dash-theme']`; with nothing
stored it follows the OS `prefers-color-scheme`, falling back to dark.

- **Source of truth:** the `--yz-*` custom properties in `src/styles.css`, redefined under
  `html[data-theme="light"]`. Every Tailwind colour token in `@theme` points at one of them, so
  components use plain semantic utilities and re-tint for free.
- **Semantic token names** — use these, not literal colours:

  | Utility | Meaning |
  |---|---|
  | `bg-canvas` · `bg-sunken` | app background · sidebar & input wells |
  | `bg-surface` · `bg-surface2` | cards · hover rows |
  | `border-edge` · `border-divider` | borders · subtle row rules |
  | `text-fg` · `text-muted` · `text-faint` | primary · secondary · tertiary text |
  | `agate` · `gold` · `ok` · `warn` · `danger` | accents (shifted per theme for contrast) |
  | `text-oncontrast` | text sitting on a gold/accent fill |

- **No flash of the wrong theme:** an inline script in `class-yazan-dashboard.php` stamps
  `<html data-theme>` *before* the stylesheet loads. `src/lib/theme.js` only ever changes it.
- Dark is a soft charcoal (`#1E2128`), deliberately not pure black.

## Security

- **AuthN** `wp_signon()` over the site's own cookie; generic error messages; per-IP rate limiting.
- **AuthZ** RBAC — see [Roles & permissions](#roles--permissions-rbac). Every route declares a
  permission slug and one filter enforces it, default-deny. A suspended account is refused on the
  `authenticate` filter, so no cookie is ever issued.
- **CSRF** `wp_rest` nonce required on all cookie-authenticated requests (enforced by core). The
  nonce expires after 12–24h and is bound to the login session, so `client.js` renews it once from
  `admin-ajax.php?action=rest-nonce` and replays the call when core answers
  `rest_cookie_invalid_nonce`; if no nonce can be minted, the session is gone and the app returns to
  the sign-in screen.
- **Input** per-field sanitisation (`sanitize_text_field`, `wc_format_decimal`, `absint`,
  `wp_kses_post`); unknown fields ignored; SKU uniqueness validated.
- **Uploads** `media_handle_upload()` with a MIME allow-list (images + PDF).
- **SQL** no raw queries for product data (WC CRUD only); audit table uses `$wpdb->insert()` /
  `prepare()`.
- **Audit** every create/update/delete/bulk/media/login lands in `wp_yazan_audit_log`, with the
  actor, IP and browser. Role and user changes record the exact permission diff.

---

## Status

| Phase | Scope | State |
|---|---|---|
| 0 | Route, shell, auth, field registry, audit table | **Done** |
| 1 | Products: list (search/filter/sort/bulk/paginate), full editor, media, jewelry + authenticity, variations | **Done** |
| 2 | **Categories · Attributes · Inventory** — full editing | **Done** |
| 3 | **Orders** — list, detail, status changes, bulk, notes | **Done** |
| 3b | **Customers** — list (search, role filter, sorting, pagination) + detail quick-view | **Done** |
| 4 | **Settings · activity log** | **Done** |
| 5 | **Users · Roles · Permissions (RBAC)** | **Done** |

---

## Roles & permissions (RBAC)

Added in 1.8.0. Four tables in `includes/rbac/`, ~150 permissions, and one filter that makes an
unprotected endpoint structurally impossible.

### The model

```
wp_yazan_roles              id, slug, name, is_super, is_system, sort, updated_at
wp_yazan_permissions        the catalog, MIRRORED from code (never authoritative)
wp_yazan_role_permissions   role_id  ⇄ permission_slug   (many-to-many)
wp_yazan_user_roles         user_id  ⇄ role_id           (many-to-many)
```

`Yazan_Permission_Registry::all()` in code is the **source of truth**; the table is a projection
re-synced on every `REGISTRY_VERSION` bump, so the catalog can be queried in SQL and travels inside
a database backup. The grants pivot stores the **slug**, not a foreign key: config is then portable
between environments, resolution needs no JOIN, and a catalog rewrite can never silently reassign a
grant to whatever row inherited an id.

A user's effective permissions are the **union** of their roles. There are deliberately no deny
rules — a deny primitive makes the union order-dependent, which is how "adding a role removed my
access" happens.

Permissions for modules this store has not built yet (`membership`, `banners`, `api_keys`,
`notifications`, `reviews`, `collections`, `pages`, `analytics`) are marked `planned`. They can be
granted now, render greyed in the Role Editor, and start working the day the module ships.

### Where each layer enforces

| Layer | Mechanism |
|---|---|
| REST (primary) | `Yazan_REST_Guard` on `rest_request_before_callbacks` — runs after route matching, so it holds the exact matched handler, and **before** the route's own `permission_callback`. An untagged handler is denied. |
| REST (defence in depth) | each handler also carries `Yazan_Dashboard_Auth::require_perm( $slug )`. |
| WooCommerce internals | `Yazan_Cap_Projection` — a **purely additive** `user_has_cap` filter mapping Yazan permissions onto the WP capabilities Woo actually checks. It never writes `false` and never modifies a role, so it cannot take access away. |
| SPA routes | `<Protected perm="…">` in `App.jsx`. |
| Buttons & menus | `<Can perm="…">`, plus `perm:` on every `NAV` entry in `Layout.jsx` (the ⌘K palette inherits the same filter). |

**Adding a route:** use `Yazan_REST_Guard::args( $methods, $callback, 'module.action' )`. That one
call emits the callback, the `permission_callback` and the guard tag together.
`GET /status` reports any handler carrying neither a permission nor an explicit public marker.

**Why `rest_request_before_callbacks` and not `rest_pre_dispatch`:** the latter fires *before*
`match_request_to_handler()`, so `get_route()` is still the raw path and there is no handler —
enforcing there would mean maintaining a second, drifting copy of the routing table as regexes.

### `Yazan_REST_Guard::MODE`

Ships as `'report'`: an **untagged** route is allowed but logged to the audit table and counted in
`GET /status`. Flip to `'enforce'` once `status.route_guard.untagged` has read `0` for a day.
A route that IS tagged and denied is refused in both modes — the mode only decides what happens to
a handler nobody labelled. `/users`, `/roles` and `/permissions` are always hard-enforced.

### Safety rails (`Yazan_RBAC_Guard`)

- **Last super admin** — delete, demote, suspend and role-change each assert one remains, inside
  `GET_LOCK('yazan_rbac_write')` so two operators cannot both pass the check.
- **Self-protection** — you cannot delete, suspend, or change the roles of your own account.
- **No escalation** — you can only grant permissions you hold, and only edit a role whose *current*
  set is also within yours. Without that second clause a Manager could edit (or strip) the Super
  Admin role and lock their own boss out.
- **Suspension** bites in three places: the `authenticate` filter, session destruction, and a
  per-request check in the guard.
- **Password reset** defaults to WordPress's own emailed link. Setting one directly is refused on
  your own account, because `wp_set_password()` destroys every session you hold. Minimum length is
  `Yazan_REST_Users::MIN_PASSWORD` (8) — enforced server-side on both write paths; the UI only
  displays the number.
- **Profile photos are optional.** A new account's photo is held in the browser and uploaded
  immediately *after* the account is created, so abandoning the form cannot leave an orphan
  attachment in the media library. With no photo, the UI draws a local `UserRound` icon rather than
  falling back to Gravatar. ⚠️ Note for anyone touching this: the REST payload's `avatar` field is
  **never empty** (`get_avatar_url()` always returns a Gravatar URL), so "has a photo" must be read
  from **`avatar_id`**. Clearing is `PUT /users/{id}` with `avatar_id: 0`.

### WordPress role vs Yazan role

A **WordPress `administrator` is always treated as a Yazan super admin** — that is the lockout
backstop, and it means ticking Yazan role boxes on such an account changes nothing. The user editor
therefore exposes a **WordPress role** control (permission `users.wp_role`) directly above the Yazan
roles, so an operator can move a staff account down to Subscriber and have its Yazan roles actually
take effect. Four rules apply:

- Only a Yazan **super admin** may change a WordPress role at all — this is site-level, not
  store-level, authorization.
- Only an account that **is** a WordPress administrator may grant `administrator`. Without this a
  Yazan-only super admin could mint a full WordPress administrator from the dashboard — exactly the
  escalation path `Yazan_Cap_Projection` avoids by mapping `users.*` to no capabilities at all.
- You cannot change **your own** WordPress role.
- Demoting the **last administrator who can still sign in** is refused (`yazan_last_wp_admin`).
  Suspended administrators do not count, because the `authenticate` filter blocks them at
  wp-login.php too, not only at `/dashboard`.

Recorded separately as `user.wp_role_change`, never buried inside a generic `user.update`.

### Install / upgrade

`Yazan_RBAC_Boot::maybe_install()` runs on `init` priority 1 against one autoloaded option, because
`Yazan_Core_Installer` only fires on `admin_init` and this store can be operated entirely from
`/dashboard`. It creates the tables, syncs the catalog, seeds eight roles **only into an empty
table** (never re-seeds), and backfills once: every `administrator` → `super-admin`, every
`shop_manager` → `manager`. Before install the whole subsystem is inert, so deploying it changes
nothing until the installer has run.

A real WordPress administrator is **always** treated as a super admin, read straight from
`WP_User::$roles` — never through a capability API, which would recurse inside `user_has_cap`. That
is the lockout backstop.

### Performance

`Yazan_Permissions::for_user()` costs two indexed queries on a cold cache and nothing thereafter:
a static memo, `wp_cache`, and a **usermeta snapshot** that survives across requests. The snapshot
is the tier actually carrying the load, because this site has no persistent object-cache drop-in.

The cache **version is part of the key**, not the value, so a stale entry is unreachable rather than
merely wrong — there is no delete to forget. Any RBAC write bumps the version, and
`YAZAN_CORE_VERSION` is folded in so a deploy that changes the catalog cannot serve an old snapshot.

---

### Beyond the phases — coupons, reports, order corrections, catalogue speed-ups

**Coupons** (`pages/Coupons.jsx`) — full WooCommerce coupon management across the same three tabs as
wp-admin: General, Usage restrictions, Usage limits. Codes are normalised with
`wc_format_coupon_code()` and checked for uniqueness — two coupons sharing a code would make the
applied discount non-deterministic. A percentage over 100 and a negative amount are both refused.

**Reports** (`pages/Reports.jsx`) — net revenue, orders, items sold, average order and refunds for a
chosen range, a per-day bar chart, the top 10 best sellers, plus out-of-stock and low-stock lists.

Built from `wc_get_orders()` and the real order line items rather than the WooCommerce Analytics
lookup tables, which can lag or sit un-synced on a store whose orders were imported or edited outside
the normal flow. Revenue is **net** — refunds are subtracted — and only `processing`, `completed` and
`on-hold` are counted. The chart is dependency-free (scaled `div`s), so no charting library is
shipped for one view.

**Order corrections** — billing/shipping addresses stay editable *after* payment, because a corrected
delivery address is routine and moves no money; totals are only recalculated when the country/state
changes, since that can move the tax rate. Coupons, by contrast, may only be added or removed while
the order `is_editable()` — discounting a paid order would drop the total below the captured amount.

**Duplicate & quick edit** — duplication uses WooCommerce's own `WC_Admin_Duplicate_Product`, so
variations and attributes come along, then strips the copy's **identity**: status forced to draft, SKU
cleared, and `_yz_serial` / QR / certificate removed. Copying the serial would leave two products
claiming the same certificate at `/verify-ring/`. Quick edit patches name, SKU, prices, status and
stock inline; a duplicate SKU or blank name is reported as `skipped` rather than silently applied.

### Store configuration — tax, shipping, payments, emails, webhooks, tools

All reachable from **Settings**, each as its own tab.

**Tax** — store tax options plus the rate tables per tax class (country, state, postcode, city, rate,
priority, shipping), and tax-class create/delete. Rates go through the `WC_Tax` API rather than raw
SQL so WooCommerce's caches and the `wc_tax_rate_locations` rows stay consistent.

**Shipping** — zones with country/continent regions, method add/remove/enable, and each method's own
settings. Method settings are filtered against the method's **own declared form fields**, so a caller
can only write keys the method actually defines. The "Rest of the world" fallback zone (id 0) is
protected from deletion.

**Payments** — enable/disable, display order, and the customer-facing title/description/instructions.

> **Credentials are deliberately out of scope.** API keys are long-lived bearer secrets; exposing
> them through a second REST surface widens the blast radius of any dashboard compromise, and each
> gateway validates its keys through flows (OAuth handshakes, webhook registration) a generic form
> would silently break. The response payload is asserted in tests to contain no secret-shaped field.
> A gateway that is not yet connected (`needs_setup`) also cannot be switched on from here — it would
> appear at checkout and fail on the customer.

**Emails** — global sender/branding plus each of the 18 transactional emails (enabled, recipient,
subject, heading, extra content, format). Every recipient address is validated individually.

**Import / Export** — CSV for products, orders and customers.

| | Export | Import |
|---|---|---|
| Products | ✅ incl. serial, verification status, silver weight, craftsmanship | ✅ dry-run required |
| Customers | ✅ incl. billing, order count, lifetime spend | ✅ dry-run required |
| Orders | ✅ incl. line items, ring serials, coupons | ❌ **refused by design** |

Order import is refused because it would fabricate financial records — payments, refunds and gateway
transaction ids cannot be meaningfully re-created from a spreadsheet.

Customer import has two non-negotiable rules: passwords are never taken from a file (new accounts get
a random one and the person uses "forgot password"), and **the role is pinned to `customer`** — a CSV
containing `role=administrator` cannot escalate anyone. Both are asserted in tests.

**Webhooks** — full CRUD over `WC_Webhook`. Delivery URLs are restricted to http/https, topics come
from an allow-list, and the signing **secret is write-only**: it can be set or rotated but is never
returned in a response, since echoing it would let anyone reading one API response forge deliveries.

**System status** — environment report (WordPress/WooCommerce/PHP/MySQL/server/theme, HPOS, SSL,
writability), active plugins, store counts, and WooCommerce's maintenance tools.

Tools are executed by delegating to `WC_REST_System_Status_Tools_Controller::execute_tool()`, so the
dashboard runs the identical routine wp-admin does rather than re-implementing any of it. Genuinely
destructive tools (`reset_roles`, `delete_taxes`, `clear_sessions`, …) are on an explicit deny-list
and return 403 — a mis-click there is unrecoverable.

### Backup & restore — full-site (Settings → Backup, and wp-admin → Tools → Yazan Backup)

A dependency-free backup subsystem lives in `includes/backup/`:

- **`class-yazan-backup-engine.php`** — the shared, UI-agnostic engine. It writes a pure-PHP `$wpdb`
  SQL dump (every table, batched INSERTs) and zips the whole `wp-content` tree with `ZipArchive` — no
  `mysqldump`/`exec` and no third-party library. The archive layout is `yazan-backup.json` (manifest)
  + `database.sql` + `wp-content/…`. A `db`-scope backup omits the file tree.
- **`rest/class-yazan-rest-backup.php`** — the dashboard REST surface (`/backup*`, above).
- **`class-yazan-backup-admin.php`** — the **Tools → Yazan Backup** wp-admin screen. Same engine, same
  archives, so a backup made in either place restores in either place.

The frontend is the **Backup** tab in `Settings.jsx` (`pages/settings/BackupPanel.jsx` + `backupApi`).

Design decisions worth knowing:

- **Scope** is DB + full `wp-content` (or DB-only). WordPress core is *not* included — restore targets
  the same install. `node_modules`, `.git`, `cache` and the backups directory itself are pruned.
- **Storage** is `wp-content/uploads/yazan-backups/`, each archive named with a 32-char random token
  and served only through an authenticated endpoint (the token is the real protection — the
  `.htaccess` guard is inert on nginx). Sidecar `.json` files hold metadata so listing never opens a
  zip. Optional retention keeps only the newest *N*.
- **Large-file download** is a two-step token flow: mint a single-use token with the nonce, then the
  browser navigates to a plain URL so a ~300 MB archive streams from disk instead of through a Blob.
- **Creation is crash-safe**: the zip is built to a `.tmp` name and atomically renamed only on
  success, with `ignore_user_abort(true)`, so a proxy timeout can't leave a corrupt archive listed.
- **Restore** requires `manage_options` + a typed `RESTORE` confirmation, extracts `wp-content/*` over
  the live tree (with a path-traversal guard) and replays the dump statement-by-statement (split on a
  sentinel line, so semicolons in data never mis-parse). It **auto-takes a DB-only safety snapshot
  first**, so a mistaken restore can itself be rolled back.
- **wp-admin restore** works from the stored list for any size (no upload); the upload-restore form is
  bounded by `upload_max_filesize` (~300 MB here) — larger files are dropped into the backups folder
  and restored from the list.
- Verified end-to-end: DB round-trip preserves quotes/semicolons/backslashes/Arabic and NULL; a full
  backup was 283 MB / 20,404 files / 83 tables in ~2 min with the exclusion guards holding.

### Phase 4 — what's covered

**Settings** (`pages/Settings.jsx`) — tabbed editor over Store, Inventory, Order alerts and Invoice
printing, with dirty-field markers and a single save.

The security-critical piece is `Yazan_REST_Settings::registry()`: a **strict allow-list** of option
names, each with a declared type. A request can only write an option that appears there, and every
value is coerced by its type (selects validated against their choices, numbers clamped to min/max,
booleans stored as WooCommerce's `yes`/`no`, emails validated). There is no code path that lets a
caller name an arbitrary option — verified by tests that attempt to write `admin_email` and
`siteurl` and confirm both are refused **and left unchanged**, including inside a payload that also
contains a legitimate field.

**Activity log** (`pages/ActivityLog.jsx`) — the audit trail with search, action/type/date filters,
deep links back to the product or order, and pagination.

The log is **append-only by design**: entries can never be edited or deleted individually through the
API, only aged out in bulk via `/audit/purge` (administrators only) — and the purge records itself,
so the trail always explains its own gaps. All filtering goes through `$wpdb->prepare()`; only the
table name is interpolated, from the trusted prefix.

### Phase 2 — what's covered

**Categories** (`pages/Categories.jsx`) — one screen for product categories, collections and tags.
Create / rename / re-slug / re-parent / delete, plus a category image. Hierarchy is rendered as an
indented tree.

**Attributes** (`pages/Attributes.jsx`) — create and edit global `pa_*` attribute definitions, and
manage each one's terms inline (add, rename, delete, with usage counts).

**Inventory** (`pages/Inventory.jsx`) — spreadsheet-style editing. Change stock across many rows,
then save once; only touched rows are sent, and the server's response re-syncs the table so what you
see always matches what WooCommerce stored. Dirty rows are highlighted and can be discarded.

Guard rails enforced server-side (the UI mirrors them, the API re-checks them):

| Rule | Behaviour |
|---|---|
| Taxonomy allow-list | Only product taxonomies + `pa_*` are reachable; anything else → 400 |
| Default product category | Cannot be deleted → 409 |
| Category loops | Cannot be its own parent, nor moved under its own descendant → 400 |
| Jewelry attributes | `pa_stone`, `pa_size`, … cannot be deleted → 409 (the editor and the verification certificate read them) |
| Attribute slug | Immutable after creation — renaming would unlink every product; max 28 chars |
| Deleting a term | Reports how many products used it, and how many children were promoted |
| Managed-stock rows | Setting a status moves the quantity too, so WooCommerce doesn't discard it |
| Variable products | Reported as `skipped: variable_parent` — their stock lives on the variations |

### Products list — parity with wp-admin

The products screen is a deliberate reproduction of WooCommerce's own list table, not a
loose equivalent. What that buys, and the traps behind each piece:

- **Status views with counts** (All / Published / Drafts / Pending / Private / Trash). Counts
  come from `wp_count_posts()` and are **not** filter-aware — same as wp-admin, where the tabs
  describe the catalogue rather than the current search.
- **Trash is a first-class view.** Before this existed the dashboard could trash a product but
  never show it again: `index()` accepted only the four live statuses and no restore route
  existed, even though `products.restore` was already in the permission catalogue. On this
  install that had already stranded **32 products**. Restore goes through `wp_untrash_post()`,
  so a draft comes back a draft rather than silently going live.
- **Search covers SKU, GTIN and a bare product id**, via
  `WC_Data_Store::load('product')->search_products()` — the same call the wp-admin list uses.
  ⚠️ A no-match search must return early: an empty `post__in` is *ignored* by `WP_Query`, so
  short-circuiting is what stops "no results" becoming "every product".
- **Bulk Edit** (`POST /products/bulk-edit`) reproduces wp-admin's panel, including the
  percentage arithmetic of `WC_Admin_Post_Types::set_new_price()` down to its quirks: an unset
  sale price uses the regular price as its base, results clamp at 0 and round to the store's
  price precision, and any price change clears a scheduled sale. Prices are skipped for
  variable products, whose prices live on their variations.
- **Quick Edit** carries wp-admin's full field set. Every value it needs already travels with
  the row, so the panel opens without a request — the same reason WooCommerce embeds a hidden
  `<div>` per row.
- **Permissions are re-checked per field, not per route.** `products.bulk` only grants access
  to the mechanism; `products.change_price` / `products.change_stock` decide what actually
  lands, and blocked fields are dropped and **reported back** (`blocked` in the response) so
  the operator is told rather than watching an edit quietly revert.
- Screen Options (visible columns, rows per page) persist in `localStorage`, not user meta —
  a display preference is not worth a REST route and an RBAC surface. Per browser, like the
  theme toggle.

### CSV porting takes TWO keys, not one

WordPress treats `import` and `export` as **site-wide** capabilities — "may move bulk data in
and out of this site" — and core grants them to administrators only
(`wp-admin/includes/schema.php`). WooCommerce therefore requires two keys before either CSV
screen will open, and widens the site-wide one to Shop Manager on install:

```php
current_user_can( 'edit_products' ) && current_user_can( 'export' )   // class-wc-admin-exporters.php:58
current_user_can( 'edit_products' ) && current_user_can( 'import' )   // class-wc-admin-importers.php:62
```

`porting.export` / `porting.import` are our site-wide key; `products.export` /
`products.import`, `orders.view` and `customers.view` are the subject-scoped one. **Both are
required**, enforced by `Yazan_REST_Porting::require_subject()` inside the handler — the
central guard takes a single slug, so the second check lives with the code that reads `type`.

⚠️ **Why this is not cosmetic.** `/porting/export` and `/porting/import` dispatch on a `type`
body parameter across products, orders **and customers**. With one gate, a role granted
`porting.export` so it could export the *catalogue* could export the *customer list* —
personal data — by changing that one parameter. The seeded **Accountant** role was in exactly
that state. It now exports orders and customers (which it is entitled to) but not products,
which matches what WooCommerce would allow a view-only-products role to do: nothing.

An unrecognised or omitted `type` falls through to the products path, so it is gated **as
products** rather than waved through — otherwise omitting the parameter would be the bypass.

### Orders — what's covered

List (search / status / date-range filters, sortable by order, date, total, bulk status changes,
pagination), full detail (line items with SKU **and the ring's `_yz_serial`**, totals, coupons,
billing/shipping, payment), status changes, and order notes (private or emailed to the customer).

Status changes go through `WC_Order::update_status()`, **not** `set_status()`, so WooCommerce's
normal side effects still fire — customer emails, stock handling, and this plugin's own
`Yazan_Core_Notifications` / `Yazan_Core_Auto_Print` features behave exactly as they do in wp-admin.

### Money operations — the rules they enforce

Order creation, line-item editing and refunds live in `class-yazan-rest-order-write.php` and are
deliberately conservative. The UI hides what isn't allowed; **the server re-checks all of it.**

**Creating orders** (`POST /orders`) — requires at least one resolvable product, otherwise 400 (and
an order that ends up with zero valid items is deleted rather than left as a shell). Orders are
built as `pending` and only moved to the requested status at the end, so WooCommerce's side effects
fire on a complete order. Every one is tagged `created_via = yazan-dashboard`.

**Editing line items** (`PUT /orders/{id}/items`) — only while `WC_Order::is_editable()` is true
(pending / on-hold), exactly the wp-admin rule. Editing a paid order returns **409**, because the
order total would no longer match the amount actually captured. Changing a quantity preserves the
item's **agreed unit price** rather than re-pricing from today's catalogue.

**Refunds** (`POST /orders/{id}/refunds`) — two distinct kinds:

| | Manual (default) | Gateway (`refund_payment: true`) |
|---|---|---|
| Moves money | No — records the refund only | **Yes, irreversibly** |
| Requires | `edit_shop_orders` | `edit_shop_orders` **+ `manage_woocommerce`** + gateway support |
| UI | single confirm | two confirms, red button, explicit warning |

Both validate `amount > 0` and `amount <= get_remaining_refund_amount()` (compared rounded, to avoid
float edge cases), and both can restock. A gateway refund on a method that can't do it (e.g. Cash on
Delivery) is refused with 400 **before** anything is recorded. `DELETE .../refunds/{id}` removes the
refund *record* only — money already sent through a gateway is not reversed, and the UI says so.

Every create / item change / refund / refund-deletion is written to `wp_yazan_audit_log` with its
amount.

> Note: `manage_woocommerce` is held by Shop Manager and Administrator, so in practice the extra
> gateway-refund capability excludes custom roles that were granted `edit_shop_orders` alone.

### Known notes

- ~~`Yazan_Core_Verify::collection_name()` reads `product_cat` instead of the `collection`
  taxonomy.~~ **Fixed.** It now reads the `collection` taxonomy first (what the dashboard writes) and
  only falls back to the legacy `product_cat` slugs when no collection term is assigned. Previously a
  ring carrying a legacy `signature-collection` category displayed *that* on its public certificate
  even when its real collection was Heritage — i.e. the certificate showed the wrong collection.
- Bulk stock actions move the **quantity** on stock-managed products (WooCommerce derives status from
  quantity on save), so "mark out of stock" sets qty `0`, and "mark in stock" restores `1` if the
  quantity was `0`.
- Rebuild (`npm run build`) after any `src/` change — the shell serves the committed bundle and
  cache-busts on `filemtime`.
