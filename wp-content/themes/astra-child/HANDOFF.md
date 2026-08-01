# Yazan — Session Handoff (read this first)

Written for the next Claude session (e.g. Claude Code in VS Code). It captures hard-won,
**verified** facts about this environment, the project reality, the design direction on record,
what was built, known issues, and gotchas that will otherwise cost you hours. Everything below was
confirmed against the live site — not assumed.

---

## 1. The mission (design direction ON RECORD)

The user wants to **reverse-engineer the STRUCTURE / layout / rhythm / motion of
`https://commafootball.com/`** (a Shopify football-merch store) and recreate that *feel* in this
WordPress store — **with 100% placeholder content, no copyrighted assets**.

**Key decision the user confirmed:** do **NOT** delete the existing jewelry catalog or create
football products. Instead **apply commafootball's structure/rhythm/motion on top of the existing
Yemeni-agate (aqeeq) catalog**. This keeps the store coherent while achieving the structural-clone
goal. A literal football clone was explicitly rejected because it conflicts with the real catalog.

**Card typography decision:** "balanced" — adopt the reference's **spacing / hierarchy / rhythm**
but **keep the brand's serif display face** for card titles (size-tuned), not the reference's
compact uppercase sans.

Reference analysis already exists (Arabic) in the project root:
`وثائق مرجعية/تحليل موقع Comma Football (التصميم).md` (17 parts) and
`.../تحليل الحركات والـ Animation.md` (14 parts). `IMPLEMENTATION.md` (next to this file) tracks the
code layer vs the Customizer/plugin/content layers.

---

## 2. Local dev — CLI access (THE most valuable section)

The site runs under **Local by Flywheel**. Neither `php` nor `wp` (WP-CLI) is on PATH, and the DB
is not on `localhost:3306`. Here is the working recipe.

### Paths & ports (⚠️ Local reassigns these on restart — re-derive if they stop working)
- **PHP binary:** `C:/Users/Nebras/AppData/Roaming/Local/lightning-services/php-8.3.17+1/bin/win64/php.exe`
- **Site php.ini (loads all extensions + sets the right DB port):**
  `C:/Users/Nebras/AppData/Roaming/Local/run/ZxDGXcWIP/conf/php/php.ini`
- **Run dir for THIS site:** `.../Local/run/ZxDGXcWIP/` — was `O2BtUtET-`, reassigned 2026-07-31.
  Re-derive by grepping each run dir's conf for `Local Sites/yazan`; **`yazan2` is a different
  site** with its own run dir (currently `veQcwIpwv`, still on PHP 8.2.30), so match the exact name.
- **MySQL:** host `127.0.0.1`, port **10028**, user `root`, pass `root`, db `local`
  (was 10029; `local-site.json` lists a STALE 10017 — ignore it. Authoritative source is
  `mysqli.default_port` in `run/<id>/conf/php/php.ini`).
- **Webroot:** `C:/Users/Nebras/Local Sites/yazan/app/public`
- **Site URL:** `http://yazan.local` · **Shop page:** `/store/`

### Run any WordPress/WooCommerce code via CLI (no WP-CLI needed)
Always pass `-c <site php.ini>` — it loads mysqli/gd/openssl AND sets `mysqli.default_port=10029`,
so wp-config's `DB_HOST='localhost'` connects correctly. Without it you get
`mysqli_connect(): undefined` or `MySQL server has gone away`.

```bash
PHP="/c/Users/Nebras/AppData/Roaming/Local/lightning-services/php-8.3.17+1/bin/win64/php.exe"
INI="/c/Users/Nebras/AppData/Roaming/Local/run/O2BtUtET-/conf/php/php.ini"
# bootstrap.php: define('WP_USE_THEMES',false); require '.../app/public/wp-load.php'; ...WC APIs...
"$PHP" -c "$INI" bootstrap.php 2>&1 | grep -v imagick   # (imagick warning is harmless)
```
Use `wc_get_product()` / `wc_get_order()` / `WP_Query` — this is the WP-CLI-equivalent the user
approved (no `wp-cli.phar` download required).

### GROUND TRUTH for rendered output = curl to nginx (NOT CLI template rendering)
⚠️ **Gotcha that cost this session real time:** rendering a card with
`wc_get_template_part('content','product')` in a bare CLI bootstrap does **NOT** reflect what the
real page outputs, because **Astra "Modern shop style"** rewrites the loop at request time. A CLI
render made it look like there were duplicate add-to-cart buttons; there weren't. **Always verify
against the real nginx response:**
```bash
curl -s -k -L --resolve yazan.local:80:127.0.0.1 "http://yazan.local/store/" -o out.html
# then grep/parse out.html for classes/counts
```
(Write curl output and any PHP-read temp files to a Windows-visible path such as the scratchpad —
Git-Bash `/tmp` is not visible to the Windows PHP binary.)

### Screenshots are self-serve — do NOT ask the user to paste one
The in-app browser tools can't reach `yazan.local` (they block reads on local/private origins, and
no Claude-in-Chrome extension is connected). **But Claude can still see the site directly** with
headless Chrome that maps `yazan.local`→`127.0.0.1` and ignores the local cert, then Read the PNG.

Helper (from project root):
```bash
bash app/public/wp-content/themes/astra-child/tools/shot.sh /store/            # desktop 1440px
bash app/public/wp-content/themes/astra-child/tools/shot.sh / mobile           # 390px mobile
bash app/public/wp-content/themes/astra-child/tools/shot.sh product/the-adeni-ember desktop
```
It prints the PNG path — Read it to see the page. Raw form if needed:
```bash
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu \
  --window-size=1440,2200 --host-resolver-rules="MAP yazan.local 127.0.0.1" \
  --ignore-certificate-errors --screenshot="C:/path/out.png" "http://yazan.local/store/"
```

#### ⚠️ NEVER judge mobile from a raw `--window-size=390,844` shot
`chrome --headless --window-size=390,844` does **not** emulate a phone. It renders in **desktop**
mode at a narrow window, and desktop Chrome **ignores `<meta name="viewport">`** — so the page lays
out as a squeezed desktop page and looks clipped at the right edge even when a real phone renders
it perfectly. **This exact artefact was mistaken for a real "mobile overflow bug" on 2026-07-31**
and was partly chased before CDP proved `scrollWidth === clientWidth === 390` — no overflow had
ever existed. If you take one thing from this section, take this.

`shot.sh … mobile` now routes through **`tools/shot-cdp.mjs`**, which drives the DevTools protocol
(`Emulation.setDeviceMetricsOverride{ mobile: true }` + an iPhone UA + touch emulation) — the only
way to set the mobile flag. It also **prints the measurement**, so overflow is read, not guessed:

```
$ bash tools/shot.sh /store/ mobile
C:/…/store_mobile.png
viewport 390px · scrollWidth 390px · no horizontal overflow
```

Verified 2026-08-01: `/`, `/store/`, `/cart/`, `/checkout/`, `/my-account/` all report **no
horizontal overflow** at 390px. Needs Node 18+ for global `fetch`/`WebSocket` (machine has 24).

Other caveats: hits nginx on :80 (not port-sensitive to Local restarts); scroll-reveal/hover
animations may not fire in a static shot (not necessarily a bug); for markup use curl-to-nginx
above, for pixels use this.

---

## 3. Project reality (verified against the DB/live site)

- **WordPress 7.0.2 · WooCommerce 10.9.1 · PHP 8.3.17 · MySQL 8.4.0.**
- **Active theme:** `Yazan (Astra Child)` — already activated.
- **44 published products already exist**, all `simple`, **all with featured image + gallery +
  attributes**, 5 on sale. Names like "The Sa'dah Carnelian", SKUs `YZ-DEMO-##`. They are good demo
  content — **do not recreate products; the earlier "create products via WP-CLI" task is moot.**
- **18 product categories** (Red/Blue/Green/Honey/… Aqeeq, Rings, Heritage/Signature Collection…).
- **Global attributes exist**, incl. `pa_stone`, `pa_metal`, `pa_size`, `pa_origin`, `pa_carat`,
  `pa_color`, `pa_rarity`, `pa_shape`, `pa_style`, `pa_silver-purity`, `pa_condition`.
- **Active plugins:** woocommerce, woocommerce-payments, **cartflows** (owns checkout),
  **modern-cart** (owns the cart drawer — style it, don't rebuild), **variation-swatches-woo**,
  power-coupons, sureforms, surerank, **elementor + header-footer-elementor** (⚠️ HFE owns the
  **FOOTER ONLY** — `ehf-footer`. The **HEADER is Astra's own Header Builder** (`ast-hfb-header`);
  there is no `ehf-header` and no `.elementor-location-header` in the output. This line used to say
  both were HFE and that cost a debugging session — so header layout lives in Astra Customizer
  options, NOT in `_elementor_data`, and Astra's `astra_get_option_{$option}` filters work on it),
  woo-cart-abandonment-recovery, astra-sites (importer — can remove),
  and a custom **`yazan-core`** plugin that already exists.
- **Permalinks:** `/%postname%/`.

### Astra "Modern shop style" is ON — and it already gives us the reference card behaviour
The real shop card renders as:
```
<li class="… product-type-simple yz-card">
  <div class="astra-shop-thumbnail-wrap"> <a …>[badges][img][alt img][yz-subject]</a> </div>
  <div class="astra-shop-summary-wrap">  <a><h2 title></a> [category] [price] </div>
</li>
```
So Astra **already** splits media vs body and shows an **on-image hover add-to-cart trigger**
(`Astra_Woocommerce::add_modern_triggers_on_image`, hooked to `woocommerce_after_shop_loop_item` @5;
class source in `astra/inc/compatibility/woocommerce/class-astra-woocommerce.php`). That IS the
commafootball quick-add pattern. **Lean into it and STYLE it — do not remove it** (removing it
deletes the only add-to-cart in modern style). No template override is needed for the split.

---

## 4. What was built this session (all in `astra-child/`, verified live via nginx)

Theme asset version bumped to **1.8.1** (`YAZAN_VERSION` in `functions.php`) to bust cache.

1. **Live promo countdown in the announcement bar** (reference's signature timer; was missing).
   - `inc/header.php`: `yazan_announcement_deadline()` (filterable — fixed date via
     `yazan_announcement_deadline`, or a rolling window via `yazan_announcement_window`, default
     48h so it's always live) + a countdown layout branch in `yazan_announcement_bar()`.
   - `assets/css/header.css`: `.yz-announce--promo`, `.yz-cd__seg/__num/__unit` (tabular figures,
     hidden until `.is-ready` to avoid a 00:00 flash).
   - `assets/js/header.js`: `initCountdown()` — ticks each second, auto-rolls when it hits zero.
2. **Product-card typography/rhythm toward the reference** (CSS only, `assets/css/woocommerce.css`):
   compact tracked-uppercase `.yz-subject`, serif title tuned to 17px/500, price 14px/400 with
   reference-like vertical gaps. (Card structure/hooks live in `inc/woocommerce.php`.)

Nothing outside `astra-child/` was touched. No products, no plugins, no parent theme. All reversible.

---

## 5. Known issues / next steps

- **⚠️ THE SITE IS ON `http://localhost:10029` RIGHT NOW, on purpose (2026-08-01).**
  `wp-config.php` defines `WP_HOME`/`WP_SITEURL` as the loopback host because **Google refuses any
  OAuth redirect URI that is neither HTTPS nor a loopback address** — `yazan.local` is rejected, and
  a tunnel is the only alternative. Comment those two lines out to go back to `yazan.local`; nothing
  else needs undoing.
  - **`yazan.local` still works** — `Yazan_Canonical_Host` (`yazan-core/includes/class-yazan-canonical-host.php`,
    `template_redirect` @5) 302s any front-end request on `yazan.local` to whichever host `WP_HOME`
    names, and back again when the constants are commented out. GET/HEAD only, known hosts only
    (`yazan.local` · `localhost` · `127.0.0.1`), and it never touches `^yazan-auth/`, which must
    answer on the host registered with the provider.
  - **Why the guard had to exist:** opening `/dashboard/` on the *other* host gives a **blank black
    screen with nothing in the console**. The bundle is a `<script type="module">`, and a module from
    a different origin than the page is refused outright. WordPress does not rescue this —
    `redirect_canonical()` fixes a wrong *port* but deliberately leaves a wrong *host* alone. Same
    trap silently breaks any `fetch(admin_url())` with `credentials: 'same-origin'` (sign-in card,
    concierge chat). Half an hour was lost to this before the cause was found; the guard closes it.
  - **⚠️ The port is Local's and CHANGES on restart.** Re-derive from
    `~/AppData/Roaming/Local/run/<id>/conf/nginx/site.conf` (`listen 127.0.0.1:PORT`) and update BOTH
    wp-config lines AND the redirect URI in the Google console. Current run dir `ZxDGXcWIP`,
    **HTTP 10029**, **MySQL 10028** — CLAUDE.md still says 10029 for MySQL, which is wrong.
  - Registered redirect URI must be exactly `http://localhost:10029/yazan-auth/google/callback/`.
    Google credentials are **configured** (encrypted `yazan_social_auth_credentials` option, not
    wp-config constants); `Yazan_Social_Auth::is_enabled()` returns true and the Google tile renders
    on the sign-in card. Apple is not configured and cannot be — it refuses loopback outright and
    needs the tunnel described in `social-auth/README.md`.

- **✅ BUILT (2026-08-01) — Sign-in / create-account card (`inc/signin-card.php`).** One component
  on two surfaces: the signed-out `/my-account/` page and a dialog the header account icon opens on
  every other page. Shopify-style two steps — email → password *or* register (email + first name +
  password; the username is generated from the address). AJAX, so nobody leaves the page they were
  shopping.
  - **`yazan_signin_card()` is the whole thing.** `woocommerce/myaccount/form-login.php` is now a
    four-line placement, and `wp_footer` puts the same call inside `.yz-signin-modal`. They cannot
    drift apart.
  - **Works with JavaScript off.** The card renders two ordinary WooCommerce forms (core field
    names, core nonces, every core `do_action`) that POST to `/my-account/`; an inline script folds
    them into the stepped flow before first paint (same technique as `inc/shop-filters.php`).
    `woocommerce_new_customer_data` carries the first name through core's own handler.
  - **⚠️ Namespace:** it is `signin`, *not* `auth` — `inc/page-authentication.php` already owns
    `yazan_auth_*` and `.yz-auth*` for the `/authentication/` provenance page. A first draft using
    `auth` fatal-errored on `Cannot redeclare yazan_auth_enqueue()`. Keep them apart.
  - **⚠️ Known trade-off:** the lookup step reveals whether an address has an account here — the
    price of any email-first flow (Shopify, Etsy and Amazon all pay it). Mitigated by a 30/15min
    per-IP lookup throttle, a 12/15min attempt throttle, a honeypot, and one generic
    "Incorrect email address or password" for every sign-in failure. `add_filter(
    'yazan_signin_two_step', '__return_false' )` collapses it to a single form if that is ever
    unacceptable.
  - Provider buttons come from yazan-core (`Yazan_Social_Auth_UI::buttons_html( '', 'icons' )`,
    added this session together with the `yazan_social_auth_enqueue_css` filter so the stylesheet
    can load off the account page). They render only when Google/Apple credentials are configured —
    which they are not yet, so the card currently shows no "or" rule at all.
  - `assets/css/my-account.css` lost its whole logged-out block (~190 lines), and its generic input
    styling is now scoped to `.logged-in` + `form.lost_reset_password` so it cannot overwrite the
    card's field metrics (the 16px font size that stops iOS zooming on focus).

- **✅ BUILT (yazan-core v1.8.0, 2026-08-01) — Users · Roles · Permissions (RBAC) in `/dashboard`.**
  Full documentation in **`plugins/yazan-core/DASHBOARD.md` → "Roles & permissions (RBAC)"**. Nothing
  in `astra-child/` changed; this is entirely a yazan-core feature.
  - **⚠️ A WordPress `administrator` bypasses every Yazan permission** — deliberate, it is the
    lockout backstop. So ticking Yazan roles on such an account does nothing until you demote it.
    The user editor has a **WordPress role** control for exactly that (permission `users.wp_role`,
    super admins only, refuses demoting the last administrator or changing your own).
  - **149 permissions / 34 modules**, defined in code (`includes/rbac/class-yazan-permission-registry.php`)
    and mirrored into `wp_yazan_permissions`. Four tables; the two pivots are real many-to-many.
    Eight roles seeded: Super Admin, Admin, Manager, Sales, Inventory, Customer Service, Accountant,
    Marketing.
  - **Every `yazan/v1` route is gated** by a central default-deny filter
    (`Yazan_REST_Guard` on `rest_request_before_callbacks`). Verified: 238 handlers tagged,
    14 deliberately public, **0 untagged**, checkable any time from `GET /status`.
  - **`Yazan_REST_Guard::MODE` currently ships as `'report'`** — an untagged route is allowed but
    logged. ⚠️ **Next step: flip it to `'enforce'`** once `/status` has reported `untagged: 0` for a
    day. That is the one deliberate piece of unfinished hardening.
  - **Nothing was taken away from anyone.** The WP-capability bridge is an *additive* `user_has_cap`
    filter — it only ever ORs capabilities in, never removes them, and never rewrites a WP role. All
    8 existing administrators were backfilled to `super-admin` and are unaffected.
  - **⚠️ `yazan/v1` is a SHARED namespace.** `yazan-social-rewards` publishes its customer-facing
    routes (`/customer/*`, `/campaign/*`, `/reward/*`, `/statistics`) into it. They are listed in
    `Yazan_REST_Guard::FOREIGN_PREFIXES` and left to their own permission callbacks — if that plugin
    adds a route, it will surface in `/status` as untagged and needs adding to that list.
  - **🐛 Fixed a long-standing bug while testing this:** `Yazan_Dashboard_Audit::query()` ran the
    action filter through `sanitize_key()`, which **strips the dot** — so filtering the Activity Log
    for `product.create` searched for `productcreate` and matched nothing. Every action name is
    dotted, so that filter had never worked for any action. Now uses `clean_action()`, the same
    normaliser the write path uses.
  - Audit log gained a **`user_agent`** column (schema v2, added by `dbDelta`) plus `user_id` and
    `action` indexes. `Yazan_Core_Privacy` blanks the UA on user deletion and now **deletes**
    (not anonymises) `wp_yazan_user_roles` rows.
  - Verified end-to-end with 34 assertions through `rest_do_request()` — a Sales role gets 200 on
    orders/customers/products and **403 `yazan_forbidden`** on settings/users/roles/backup/audit/
    reports and `DELETE /products/{id}`; suspension returns `yazan_suspended`; logged-out returns
    401. Screenshots confirmed the sidebar, ⌘K palette and action buttons all shrink to match.

- **✅ BUILT (yazan-core v1.7.0) — one-tap sign-in with Google & Apple.** New module at
  `plugins/yazan-core/includes/social-auth/` (8 classes + `assets/css/social-auth.css`). Tap →
  provider account picker → signed in, back where they were. No registration form, no password, no
  confirmation screen. Full setup + troubleshooting in that folder's **`README.md`**.
  - **Server-side OAuth redirect, deliberately NOT Google's JS SDK.** Google Identity Services
    would give an in-page popup but needs `accounts.google.com/gsi/client` on the login page, which
    would end the site's zero-external-requests position. Both provider marks are inline SVG, so
    the buttons add **zero** requests. Decision taken with the user on 2026-07-31.
  - **Ships inert.** With no wp-config constants defined, no buttons render and `/my-account/` is
    byte-for-byte unchanged — verified by curl before/after.
  - Attaches via `woocommerce_login_form_start` / `woocommerce_register_form_start`, two of the
    hooks `woocommerce/myaccount/form-login.php` already preserved, so it also appears on
    CartFlows' checkout login automatically. **No existing template was edited.**
  - Linking by verified email only (`email_verified` must be true) — that single rule is what stops
    an unverified address reaching an existing customer's orders. Subject id, not email, is the
    durable identity, so a changed Google address does not fork the account.
  - Fires `wp_login`, so the rewards plugin's `LoginObserver` streak logic keeps working. Uses
    `wc_create_new_customer()` and `wc_set_customer_auth_cookie()` rather than hand-rolled
    equivalents.
  - Basket survives sign-in via `Yazan_Social_Auth_Cart` (stash before redirect, merge after,
    adding only lines the cart does not already hold).
  - **Tests:** `tests/run.php social` — 75 assertions, all passing. Full suite is **660/660**.
  - **⚠️ NOT yet verified end-to-end** — needs real credentials and a public HTTPS tunnel, since
    neither provider accepts `yazan.local`. Untested: the live provider round trip, Apple's
    cross-site `form_post` + `SameSite=None` cookie, Apple's first-auth name blob, and the basket
    merge across a real sign-in. Apple additionally needs a paid Developer Program membership.
- **✅ FIXED (v2.8.0) — `/my-account/` two-column layout + header account icon.** Six fixes, all
  verified by self-screenshot at desktop / 900px / mobile.
  - **The empty-quadrant bug was Astra's float clearfix meeting CSS grid.** Astra ships
    `.woocommerce .col2-set::before,::after{content:" ";display:table}`
    (`woocommerce-layout-grid.min.css`). A grid container's own pseudo-elements are **grid items**,
    so `#customer_login` had FOUR items in a 2-column grid: `::before` took cell 1, Sign In took
    cell 2, Register wrapped to row 2, `::after` filled the rest. `clear:both` is a no-op on a grid
    item. Fix = `content: none` on those two pseudo-elements (`my-account.css`). **Remember this
    whenever you turn a WooCommerce `.col2-set` / `.u-columns` wrapper into a grid or flex parent.**
  - **Mobile overflow** was `1fr` (= `minmax(auto,1fr)`, cannot shrink below min-content) plus up to
    88px of card padding → `minmax(0, 1fr)`, the idiom the signed-in grid already used.
  - **Breakpoint 860/861 → 991/992.** The old pair was the only one of its kind in the child theme
    (convention is 767 / 991-993) and left the 861–921px band rendering two padded columns inside
    an already-narrowed Elementor container — the worst overflow case.
  - **The "floating" account icon**: `header.css` gave `.ast-header-account` a 56px
    `line-height/min-height !important` but — unlike its three cluster siblings — never gave it
    `display:flex; align-items:center`, so an 18px inline-flex SVG sat baseline-aligned in a 56px
    line box and rode high. Added the flex centring + `line-height: 1`.
  - **Crowding guard**: Astra's `grid-template-columns: auto auto` + `flex-wrap: nowrap` with no
    `1fr`/`min-width:0` let the row overflow right, pushing the last child (the account icon) off
    screen ≈≤1280px. Header stays full-bleed by design; the nav now absorbs the squeeze.
  - **Account icon now points at `/my-account/`**, not `wp-login.php`. Astra exposes no filter on
    that link and its WooCommerce account type is gated behind Astra **Pro**, so this uses the
    generic `astra_get_option_{$option}` value filter (`inc/header.php`) — a supported hook, no
    parent-theme edit.
- **✅ FIXED (v2.8.0) — theme switcher was invisible below 921px.** `theme-switcher.js` moved it
  into `.site-header-primary-section-right`, but `querySelector` returns the **desktop** header's
  cluster, which is `display:none` on mobile — so the control vanished instead of falling back to
  its floating button. Now guarded on actual visibility, with a rAF-throttled `resize` listener
  that moves it back and forth across the breakpoint. **This exposed a latent collision:** the
  floating switcher and the concierge launcher are both pinned bottom-inline-start, and the chat
  widget's `z-index: 99990` buried the switcher's `1000`. They are now stacked vertically.
- **✅ CHANGED (v2.8.0) — `/my-account/` is now ONE card, not two columns.** The user asked why the
  page still showed a full manual registration form when the whole promise is "one tap, no
  registration form" — a fair objection. Now: social buttons → "or use your email" → sign-in
  fields, all in a single 520px card, with registration folded behind a native `<details>`
  ("New to Yazan? Create an account") underneath. `<details>` deliberately, not JS: it opens with
  JavaScript off and is keyboard/AT-addressable for free. It is re-opened **server-side** when
  `$_POST['register']` is set, so a failed registration does not collapse the form and hide the
  error. Every WooCommerce hook, nonce and field name is preserved, and the
  `.u-columns/.u-column1/.u-column2` class names are kept even though nothing is a column now,
  because plugins target them.
  - `Yazan_Social_Auth_UI` now renders the provider buttons **once per page**, not once per form —
    with both forms on one screen the old per-form guard produced two identical button pairs.
- **✅ FIXED (v2.8.0) — stale cache keys.** `my-account.css`, `theme-switcher.css` and
  `theme-switcher.js` were enqueued with the bare `YAZAN_VERSION`, so edits to them shipped behind
  an old cache key and appeared to do nothing. All three now use `yazan_asset_ver()` (filemtime),
  like everything in `inc/enqueue.php`. **If a CSS change ever seems not to apply, check this first.**
- **⚠️ Note on the test suite count.** The authz suite enumerates live routes, so its total moves
  with them: it read **156 routes / 660 assertions** earlier on 2026-07-31 and **169 / 706** later
  the same day. Verified NOT caused by the theme work — stashing every file from that round left
  the count identical — and stable across repeated runs. Treat "0 failed" as the signal, not the
  total.

- **✅ RESOLVED (v1.8.2) — `yz-subject` placement.** Re-hooked `yazan_loop_subject_line` from
  `woocommerce_shop_loop_item_title` @5 onto Astra's `astra_woo_shop_title_before`, which fires
  INSIDE `.astra-shop-summary-wrap` just before the title. Verified via curl + user screenshot: the
  subject now sits above the title in the card body (eyebrow→title rhythm).
- **✅ DONE (v1.8.3) — Astra modern hover add-to-cart restyled** into the reference's full-width bar
  that rises from the bottom of the media (`.ast-on-card-button` → `translateY(100%)→0` on
  `:hover`/`:focus-within`, ink `rgba(20,18,16,.92)` bg, ivory tracked-uppercase label + small bag
  glyph). Note: the trigger `<a>` is a SIBLING of the image link inside `.astra-shop-thumbnail-wrap`
  (nested `<a>` is invalid), so the WRAP is the positioning/clip context. Resting (hidden) state
  confirmed by screenshot; **the reveal/hover state still needs a hover screenshot to fine-tune**.
- **✅ FIXED (v1.8.4) — `.yz-badge--certified` legibility.** It used a transparent fill + near-black
  text, so over dark product photos it vanished to an empty bordered box. Now solid ink fill + ivory
  text + silver hairline (stays distinct from the fill-only badges).
- **✅ FIXED (v1.8.5) — single-product content collision.** `.summary` was `position: sticky`, but
  WooCommerce renders gallery + summary + tabs + related as siblings of one flex `div.product`, so the
  pinned buy-panel travelled down over the full-width Description tabs (ATC + product_meta landed on
  top of the Description text). Removed the desktop sticky (can't bound it to the gallery row without a
  template wrapper). Mobile sticky ATC bar still covers conversion. See `assets/css/woocommerce.css`.
- **✅ FIXED (v1.8.6) — mega-menu opened on empty page hover (all pages).** In `megamenu.css` the
  hidden `.sub-menu` used `opacity:0; visibility:hidden`, but the mega panel's 3rd-level submenu
  re-asserts `visibility:visible` (to show inline). A descendant's `visibility:visible` overrides an
  ancestor's `hidden`, so the transparent panel — sitting absolutely at top:100%/left:0 over the
  page's top-left — stayed hit-testable; hovering that dead zone opened the menu. Fix: `pointer-events:
  none` on the hidden panel / `auto` on the open panel (`:hover`/`:focus-within`/`.yz-open`).
- **✅ DONE (v1.8.7) — shop filter chips.** `yazan_stone_filter_chips()` on `woocommerce_before_shop_loop`
  @5 (inc/woocommerce.php) renders a stone chip row above the grid on shop + product taxonomy archives:
  "All Stones" + each `the-stones` child category (ordered by count) linking to the existing archives,
  active chip marked. Sharp-cornered hairline chips (`.yz-chips`/`.yz-chip` in woocommerce.css), scroll
  rail on mobile. Plugin-free/no-AJAX. Verified active state on /store/ and /product-category/…/.
- **✅ DONE (v1.8.8) — footer content + dark style.** User chose "rewrite Elementor data via code".
  Footer is HFE Elementor template #1165 (4 icon-list columns of DEMO content: Women Jeans / Men Shirts /
  Google Play). Rewrote `_elementor_data` by widget id → Shop by Stone / Collections / The House / Yazan
  (brand text; placeholder+playstore images removed) with only VERIFIED-real links (category archives,
  /store/, /about/, /contact-us/, /my-account/, /cart/ — note: Our Story/Verify-a-Ring/FAQ pages do NOT
  exist). **Backup:** postmeta `_yz_elementor_backup` on 1165 + `scratchpad/footer_1165_backup.json`.
  Dark bg (#141210) set on sections 012b4cf+cc45d63 IN the Elementor data (CSS can't override per-section
  bg); ivory text/links via main.css §6 (`.elementor-location-footer`). Edit scripts in scratchpad.
- **✅ DONE — footer text legibility (v1.9.0).** Dark footer text was near-invisible. Real root cause:
  the child-theme footer rules were scoped to `.elementor-location-footer` — an Elementor **Pro** class
  that does NOT exist here (this site uses the **HFE free** plugin, which wraps the footer in
  `.footer-width-fixer` → `.elementor-1165`). So the selector matched nothing. Fixed by re-scoping main.css
  §6 to `.yazan .footer-width-fixer …` with `!important` on colours (Elementor's kit/global text colour
  otherwise outranks a plain rule). Verified `.footer-width-fixer` wraps the footer content in the DOM.
- **✅ DONE — homepage section order (front-page.php).** Reordered to match reference Part 6: hero →
  collections → bestsellers → story → **trust → collection-stories** → reviews → newsletter (authenticity
  band now precedes the parallax collection-stories). PHP template change — no asset version bump needed.
- **✅ DONE — motion pass (v1.9.1).** Audit found the motion system already faithful to the reference
  (tokens, reveals, masked hero lines, curtain image reveals, mobile shortening, reduced-motion). Only
  gap: shop/archive product cards had NO entrance reveal. Fixed by adding `yz-reveal` to the loop
  `post_class` filter (`yazan_loop_item_class`) + a first-row stagger for `ul.products` in motion.css
  (mirrors the `.yz-grid` cap-at-4 pattern). All 16 cards verified carrying `yz-reveal`.
- **✅ DONE — free-shipping model + progress bar (DB config, not code).** User chose the threshold
  nudge. Configured (via WC + modern-cart option APIs, scripts in scratchpad):
  • WooCommerce shipping zone **"Worldwide"** (zone id 1, all 6 continents) with **Free Express Shipping
    over $500** (`free_shipping` iid 2, requires=min_amount, min_amount=500) + **Express flat rate $20**
    (`flat_rate` iid 1). The plugin reads the threshold from this zone's free_shipping method (falls back
    to zone_id 1 for address-less drawer carts).
  • modern-cart bar ENABLED: `moderncart_setting['enable_free_shipping_bar']=true`; copy in
    `moderncart_cart` (`free_shipping_bar_text` = "You're {amount} away from complimentary express
    shipping", `free_shipping_success_text` = "Complimentary express shipping unlocked"). NOTE settings
    are FLAT arrays under wp_options `moderncart_setting` (main) + `moderncart_cart` (texts); keys survive
    only if present in the plugin defaults (`array_intersect_key`).
  • Announcement bar copy reframed in inc/header.php ("…on qualifying orders · ends in") to kill the
    "complimentary for all" conflict. To change the threshold: edit the free_shipping method's min_amount.
- **🚧 IN PROGRESS — cart drawer (modern-cart) styling (v1.9.3).** Conservative on-brand BASELINE added
  to woocommerce.css (Part 10 section). modern-cart uses a clean `moderncart-*` namespace; key classes:
  `.moderncart-panel` (sliding panel), `.moderncart-slide-out-header/-title/-close`, `-footer`,
  `.moderncart-free-shipping-progress-bar > .moderncart-progress-bar`, `.moderncart-cart-item-*`,
  `a.checkout-button`. Styled: ivory panel, serif title, agate progress fill, hairline dividers, ink
  checkout CTA. **Written BLIND (drawer is JS-injected, can't curl/screenshot it) — NEEDS a screenshot
  of the OPEN drawer to tune spacing/shades + finish Part 10 (subtotal row, secure-checkout microcopy,
  upsells).** Ask the user to add a product and open the cart.
- **✅ DONE (v2.0.0) — remaining candidate batch ("انجز الكل"):**
  • **Arabic fonts self-hosted** — Amiri + Cairo (arabic + latin subsets) in assets/fonts/ via
    assets/css/fonts-ar.css; RTL-only enqueue points local. Full zero-external now holds under RTL too.
  • **Single-product gallery** — on-brand skin in woocommerce.css (Part 8): minimal ink zoom/lightbox
    trigger, hairline thumbnails with muted→active (`.flex-active` ink border) states. Layout kept so
    WooCommerce flexslider is untouched. (Products are simple → no size chips; that item is N/A.)
  • **Cart drawer upsells** — recommendation classes styled (`moderncart-slide-out-recommendations-header`,
    `moderncart-add-to-cart` = ink-outline-fills-on-hover, serif recommended names). Only render when the
    store has upsells configured.
  • **CartFlows checkout re-brand** — the active checkout is CartFlows **Instant Checkout** (classic form,
    NOT Blocks); the theme's normal stylesheets DON'T load there — only inline `checkout.css` (via
    `yazan_checkout_inline_css()` on wp_head@100) applies. CartFlows themes the form through CSS vars on
    `.wcf-embed-checkout-form` whose defaults are BLUE (#0084d6); overrode `--wcf-btn-bg-color` /
    `--wcf-gcp-primary-color` / heading/field/accent vars → ink + agate. Verified our override lands after
    CartFlows' declaration (later source order wins). Deeper form layout is left to CartFlows' design.
  • All four are baselines verified structurally/headlessly; the three visual ones (gallery, drawer upsells,
    checkout) still benefit from a screenshot pass to fine-tune spacing/shades.
- **✅ FIXED — persistent coupon error blocking checkout.** Every page (incl. checkout) showed
  "coupon 'cart10' … cannot be used in conjunction" + "issues with the items in your cart". Cause:
  **power-coupons was auto-applying `WELCOME15` ($15) on every cart load** (`_power_coupon_auto_apply=yes`
  on coupon #7605), while `cart10` ($10) sat stuck in 3 WC sessions, and a stale individual-use conflict
  notice was cached in the session. Fix (DB, no code): set `_power_coupon_auto_apply=no` on all coupons;
  cleared `applied_coupons`/`coupon_*_totals` + dropped `wc_notices` from all `wp_woocommerce_sessions`
  rows (kept cart items). Verified a fresh simulated cart auto-applies nothing and raises no errors. Both
  coupons still EXIST and work if entered manually — only the silent auto-discount was removed (right for a
  luxury store). If a coupon ever "sticks" again, check power-coupons auto-apply + clear session coupons.
- **✅ DONE (v2.7.0) — accepted-payment marks (Apple Pay · Google Pay · PayPal · card · Link).**
  New module `inc/payment-marks.php` is the single source of truth; `yazan_payment_strip()` in
  inc/woocommerce.php is now a thin caller (it previously rendered plain TEXT pills while its docblock
  claimed "pure inline SVG" — now true). Marks are inline monochrome SVG on `currentColor`, so zero
  external requests still holds and both token sets read correctly.
  • **The row is gateway-derived, not hardcoded** — `yazan_payment_marks_from_gateways()` maps live
    gateway ids (`woocommerce_payments`, `ppcp-gateway`, `ppcp-credit-card-gateway`, …) plus the
    WooPayments express sub-options to marks, so it can never advertise a method the store can't take.
    Today that yields `[]` (only COD/bacs/cheque are enabled), so the filterable pre-launch set stands
    in. Kill it for launch with `add_filter( 'yazan_payment_marks_prelaunch', '__return_false' )`;
    verified that collapses the row to nothing. Final override: `yazan_payment_marks` (unknown slugs
    dropped, canonical order enforced).
  • **Placements:** product (`woocommerce_after_add_to_cart_form`), cart (`woocommerce_after_cart_totals`),
    checkout (`woocommerce_review_order_after_submit`), and `[yazan_payment_marks]` for the Elementor/HFE
    footer — shortcode ON PURPOSE, so the footer needs no second `_elementor_data` rewrite.
  • ⚠️ **Checkout gotcha (cost real time):** the same rules that work everywhere else collapsed the row
    into a VERTICAL STACK on the CartFlows Instant Checkout. Source order does NOT save you there —
    `wc-blocks-style-css` loads AFTER `yazan_checkout_inline_css()`'s inline block. Fix: the layout
    declarations in checkout.css (`display`/`flex-direction`/`align-items`/`flex-wrap`/`width`/`float`)
    carry `!important`; colours deliberately do not. Verified by screenshot on the real checkout.
  • ⚠️ **Before launch:** these are on-brand monochrome stand-ins. Apple/Google/PayPal/the card networks
    each require their OWN official mark artwork under their brand guidelines — swap the SVG bodies in
    `yazan_payment_mark_glyph()` (one function, one file).
- **✅ DONE — simulated payment gateways for local testing (new plugin `yazan-test-gateways`).**
  Real gateways can't run here (no HTTPS, no WooPayments account, Apple/Google Pay need a verified
  public domain), so four simulated WooCommerce gateways now cover Card / Apple Pay / Google Pay /
  PayPal. **They mark orders PAID without taking money** — see the plugin's README.
  • **Deliberately its OWN plugin, not yazan-core**: deleting one directory removes every trace, so a
    fake gateway can never ride along into production with something else.
  • **Guard:** registers only when `wp_get_environment_type()` is local/development (wp-config sets
    `local`). On production it loads but registers nothing. Override needs `YAZAN_TG_FORCE`.
    Plus a non-dismissible admin notice + an order note on every simulated order.
  • **Verified end-to-end** via a real checkout POST: order → `processing`, txn id stored, stock
    reduced, emails sent, and the **Payment Bridge wrote `payment_completed`** with `source=gateway`
    and the right gateway id. Refunds verified too: partial → `payment_partially_refunded`/`review`,
    remainder → `payment_refunded`. Decline outcome verified → `payment_failed`.
    All verification orders/events were deleted and stock restored afterwards.
  • **Knock-on:** `yazan_payment_marks_from_gateways()` in inc/payment-marks.php was refactored from a
    hardcoded switch to a **filterable map** (`yazan_payment_gateway_marks`), which the plugin hooks.
    Result: the storefront marks row is now genuinely DERIVED — and `link` correctly disappears,
    because no Link gateway exists. The pre-launch placeholder retired itself automatically.
- **✅ DONE — "A new order has arrived." now fires in the Yazan dashboard, not just wp-admin.**
  **Root cause:** `Yazan_Core_Notifications::enqueue_admin_alert()` hooks `admin_enqueue_scripts`, but
  `/dashboard` is NOT a wp-admin screen — `Yazan_Dashboard::maybe_render()` prints a standalone
  document on `template_redirect` and exits. It never calls `wp_head()`/`wp_footer()`, so **nothing
  enqueued can reach it** — not the script, not jQuery, not Heartbeat. Hence polling, not Heartbeat.
  • **Server:** extracted `Yazan_Core_Notifications::orders_since()` — one query now shared by the
    wp-admin Heartbeat handler AND the new `GET yazan/v1/orders/alerts` route, so the two surfaces
    can't drift. Route lives in class-yazan-rest-orders.php behind that file's existing
    `edit_shop_orders` permission callback. Omitting `since` = SEED mode (returns latest, count 0) so
    opening the dashboard doesn't announce the whole order history.
  • **Client:** `context/OrderAlertsContext.jsx` polls every 30s (matches the wp-admin heartbeat
    interval), skips while `document.hidden`, catches up on `visibilitychange`. `lib/chime.js` ports
    the WebAudio beep + adds `ctx.resume()` and a localStorage mute flag.
  • **ToastContext extended ADDITIVELY** — `{persist, key, action, icon}`. `persist` skips the
    auto-dismiss timer; `key` makes a repeat alert UPDATE the live toast instead of stacking. The
    plain `success/error/info(message)` signature is untouched (30+ call sites).
  • ⚠️ **Gotcha:** `ToastProvider` is mounted ABOVE `<BrowserRouter>` in App.jsx, so a `<Link>` inside
    the toast throws. The action is a **callback** the caller supplies; `OrderAlertsProvider` sits
    inside the router and passes its own `navigate('/orders')`.
  • Header **bell + unread badge** in Layout.jsx (click → /orders + clear; right-click → mute chime).
  • **Verified end-to-end**: endpoint 401s unauthenticated; two orders placed through the simulated
    gateways produced one toast that updated 1 → "2 new orders have arrived." with the badge tracking,
    and it never auto-dismissed. Verification orders deleted, stock restored, session token destroyed.
  • ⚠️ **`window.Notification` (OS popup) needs a secure context** — it will not fire on
    `http://yazan.local`. The in-app toast + bell are the reliable path; desktop popups start working
    on their own once the site is HTTPS.
  • **Screenshotting an authed dashboard**: headless Chrome can't be handed a cookie by flag. Mint a
    `wp_generate_auth_cookie()` + session-bound `wp_rest` nonce in PHP (seed `$_COOKIE` BEFORE calling
    `wp_create_nonce`, or the nonce is computed against an empty token and 403s), launch Chrome with
    `--remote-debugging-port`, then drive `Network.setCookie` + `Page.captureScreenshot` over CDP from
    a small node script (node 24 has a global `WebSocket`). Scripts kept in the scratchpad.
- **✅ FIXED (v2.8.0) — the Google Fonts regression, and made un-regressable.** It was worse than
  first recorded: not just `/product/…` but **every page**, and there was a third source nobody had
  spotted — **CartFlows** loading `Lato:700` on the checkout.
  - `elementor_google_font` was not merely reset to a wrong value, it was **deleted**, and Elementor
    treats absent as enabled. A DB value can be wiped again by the next update, so it is now
    enforced in code: `add_filter( 'pre_option_elementor_google_font', … )` returning `'0'`
    (`inc/enqueue.php`) short-circuits `get_option()` before it reads the row. **Consequence to
    know: the Elementor settings screen will now look like it saves and change nothing — remove
    that filter if a Google font is ever genuinely wanted.**
  - `cartflows-google-fonts` added to `yazan_dequeue_foreign_fonts()`, which is the only thing that
    catches it (the option filter does not).
  - Verified 0 `fonts.googleapis.com` / `fonts.gstatic.com` references on `/`, `/store/`,
    `/my-account/`, `/cart/` and `/checkout/`. The only remaining off-site string sitewide is the
    `gmpg.org` XFN profile link, which is metadata — no request is made.
- Cart drawer = style modern-cart, don't rebuild. Checkout = CartFlows.
- **✅ DONE — GSAP hosted locally.** GSAP 3.12.5 + ScrollTrigger moved from cdnjs to
  `assets/js/vendor/` (inc/enqueue.php now enqueues `$js.'vendor/gsap.min.js'` / `ScrollTrigger.min.js`).
  Front page verified: 0 cdnjs refs, both files HTTP 200. Parallax behaviour unchanged (same version).
- **✅ DONE — TRUE zero external requests.** Verified 0 googleapis/gstatic + 0 cdnjs across front/shop/
  product. Steps: (1) brand fonts **Cormorant Garamond + Jost self-hosted** — variable woff2 (latin +
  latin-ext) in `assets/fonts/`, `@font-face` in `assets/css/fonts.css`, enqueue points local, preconnect
  removed. (2) `yazan_dequeue_foreign_fonts()` (inc/enqueue.php @100) dequeues Astra's **Lato** +
  Elementor's **Roboto/Roboto Slab** (never used — `body.yazan{font-family:Jost}` wins over Astra's Lato).
  (3) Elementor Google Fonts disabled at option level: **`elementor_google_font=0`** (DB, cache cleared).
  (4) `yazan_strip_google_font_hints()` filters `wp_resource_hints` to drop the leftover Google preconnect
  hints. Only `gmpg.org` remains = `rel="profile"` head metadata (fetches nothing). To re-add a Google
  font later, self-host it the same way rather than reintroducing the CDN. **Arabic fonts (Amiri/Cairo)
  still load from Google but only under is_rtl() (not active) — self-host when RTL goes live.**

## 6. Working rules (from CLAUDE.md — still apply)
Child theme only; never edit Astra/Woo/core. Hooks & filters, not file patches. Sanitize/escape/
nonce/`$wpdb->prepare`. HPOS-safe (`wc_get_*`). Guard every PHP file with the ABSPATH check.
Enqueue via `wp_enqueue_*`. Verify markup against **curl-to-nginx**, and for anything visual take
your own screenshot with **`tools/shot.sh`** (headless Chrome) — never ask the user to paste one.
