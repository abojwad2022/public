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
│   ├── class-yazan-dashboard-audit.php    Audit log (the one custom table)
│   └── rest/
│       ├── class-yazan-rest-products.php  list / read / create / update / delete / bulk
│       ├── class-yazan-rest-media.php     upload / list / delete (real Media Library)
│       └── class-yazan-rest-meta.php      reference data + dashboard stats
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

| Method | Route | Capability |
|---|---|---|
| POST | `/auth/login` | public (rate-limited: 6 failures / 15 min per IP) |
| POST | `/auth/logout` · GET `/auth/me` | `edit_products` |
| GET | `/products` — `search, category, stock_status, type, status, orderby, order, page, per_page` | `edit_products` |
| GET · POST | `/products` · `/products/{id}` | `edit_products` |
| PUT/PATCH | `/products/{id}` | `edit_products` |
| DELETE | `/products/{id}?force=1` | `delete_products` |
| POST | `/products/bulk` — `trash, delete, publish, draft, set_instock, set_outofstock` | `edit_products` (+`delete_products` for the destructive two) |
| GET · POST | `/media` · DELETE `/media/{id}` | `upload_files` |
| GET | `/meta/taxonomies` · `/stats` | `edit_products` |
| GET | `/orders` — `search, status, customer_id, date_from, date_to, orderby, order, page, per_page` | `edit_shop_orders` |
| GET | `/orders/{id}` | `edit_shop_orders` |
| PUT/PATCH | `/orders/{id}` — `status`, `customer_note` | `edit_shop_orders` |
| POST | `/orders/bulk` — `status`, `ids` | `edit_shop_orders` |
| GET · POST | `/orders/{id}/notes` — `note`, `customer_note` | `edit_shop_orders` |
| POST | `/orders` — create; `line_items`, `billing`, `shipping`, `shipping_lines`, `status` | `edit_shop_orders` |
| PUT/PATCH | `/orders/{id}/items` — `items[]`, `add[]`, `remove[]` | `edit_shop_orders` (+ order must be editable) |
| GET · POST | `/orders/{id}/refunds` — `amount`, `reason`, `restock`, `refund_payment` | `edit_shop_orders` (+ `manage_woocommerce` for gateway) |
| DELETE | `/orders/{id}/refunds/{refund_id}` | `manage_woocommerce` |
| GET · POST | `/coupons` · `/coupons/{id}` (GET/PUT/DELETE) | `edit_shop_coupons` / `delete_shop_coupons` |
| GET | `/reports/sales` — `days` or `date_from`+`date_to` | `view_woocommerce_reports` |
| GET | `/reports/stock` | `edit_products` |
| PUT/PATCH | `/orders/{id}/addresses` — `billing`, `shipping` | `edit_shop_orders` |
| POST · DELETE | `/orders/{id}/coupons` — `code` | `edit_shop_orders` (+ order editable) |
| POST | `/products/{id}/duplicate` | `edit_products` |
| POST | `/products/quick-edit` — `items[]` | `edit_products` |
| GET · PUT/PATCH | `/tax` · `/tax/classes` · `/tax/rates` (+ `/{id}`) | `manage_woocommerce` |
| GET · POST · PUT · DELETE | `/shipping/zones` (+ `/{id}`, `/{id}/methods`, `/{id}/methods/{instance}`) | `manage_woocommerce` |
| GET · PUT | `/gateways` · `/gateways/{id}` — enable/order/copy only, never credentials | `manage_woocommerce` |
| GET · PUT | `/emails` · `/emails/{id}` | `manage_woocommerce` |
| GET | `/porting/export` — `type=products\|orders\|customers` | `manage_woocommerce` |
| POST | `/porting/import` — `type`, `csv`, `dry_run`, `create_missing` | `manage_woocommerce` |
| GET · POST · PUT · DELETE | `/webhooks` · `/webhooks/{id}` | `manage_woocommerce` |
| GET · POST | `/backup` — list+env / create (`scope=full\|db`, `keep`) | `manage_options` |
| DELETE | `/backup/{id}` | `manage_options` |
| POST | `/backup/{id}/restore` — `confirm=RESTORE`, `safety` | `manage_options` |
| POST | `/backup/{id}/download-token` → single-use token | `manage_options` |
| GET | `/backup/download?token=` — streams the archive | token (single-use, 120s) |
| GET | `/status` | `manage_woocommerce` |
| POST | `/status/tools/{tool}` | `manage_options` |
| GET · PUT/PATCH | `/settings` — read schema+values / write whitelisted options | `manage_woocommerce` |
| GET | `/audit` — `search, action_filter, object_type, user_id, object_id, date_from, date_to, page, per_page` | `manage_woocommerce` |
| POST | `/audit/purge` — `days` | `manage_options` |
| GET · POST | `/terms/{taxonomy}` — list / create | `manage_product_terms` |
| PUT/PATCH · DELETE | `/terms/{taxonomy}/{id}` | `manage_product_terms` |
| GET | `/attributes` — definitions + their terms | `edit_products` |
| POST · PUT/PATCH · DELETE | `/attributes` · `/attributes/{id}` | `manage_woocommerce` |
| POST | `/inventory/bulk` — `items[]` of `{id, manage_stock, stock_quantity, stock_status}` | `edit_products` |
| GET | `/customers` — `search, role, orderby (name·email·registered), order, stats, page, per_page` | `edit_shop_orders` |
| GET | `/customers/{id}` — adds billing/shipping, order count, lifetime spend, last order | `edit_shop_orders` |

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
- **AuthZ** `current_user_can()` on every route — native WooCommerce roles, no custom roles.
- **CSRF** `wp_rest` nonce required on all cookie-authenticated requests (enforced by core).
- **Input** per-field sanitisation (`sanitize_text_field`, `wc_format_decimal`, `absint`,
  `wp_kses_post`); unknown fields ignored; SKU uniqueness validated.
- **Uploads** `media_handle_upload()` with a MIME allow-list (images + PDF).
- **SQL** no raw queries for product data (WC CRUD only); audit table uses `$wpdb->insert()` /
  `prepare()`.
- **Audit** every create/update/delete/bulk/media/login lands in `wp_yazan_audit_log`.

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
