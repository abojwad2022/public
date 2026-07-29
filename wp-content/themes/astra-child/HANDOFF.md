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
  `C:/Users/Nebras/AppData/Roaming/Local/run/O2BtUtET-/conf/php/php.ini`
- **Run dir for THIS site:** `.../Local/run/O2BtUtET-/` (find it by grepping the run dirs' nginx
  `site.conf` for the `Local Sites/yazan/app/public` root).
- **MySQL:** host `127.0.0.1`, port **10029**, user `root`, pass `root`, db `local`
  (`local-site.json` lists a STALE 10017 — ignore it; read `run/<id>/conf/mysql/my.cnf`).
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
Caveats: hits nginx on :80 (not port-sensitive to Local restarts); scroll-reveal/hover animations
may not fire in a static shot (not necessarily a bug); for markup use curl-to-nginx above, for
pixels use this.

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
  power-coupons, sureforms, surerank, **elementor + header-footer-elementor** (header/footer are
  built in HFE/Elementor), woo-cart-abandonment-recovery, astra-sites (importer — can remove),
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
- **⚠️ REGRESSION spotted (not fixed — out of scope of the payment work): Google Fonts are back.**
  `/product/…` serves 2 `fonts.googleapis.com` links (`elementor-gf-roboto`, `elementor-gf-robotoslab`).
  The `elementor_google_font` option is now **unset** (it was set to `0`), so the zero-external-request
  invariant recorded below no longer holds. Re-set the option and re-check `yazan_dequeue_foreign_fonts()`.
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
