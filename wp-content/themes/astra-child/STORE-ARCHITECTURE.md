# Yazan — Store Architecture, Taxonomy & Navigation

Build reference for the redesigned luxury information architecture. Reflects the **live state** after
the 2026-07-21 taxonomy + navigation pass (see "Change log" at the bottom), plus the roadmap for the
dimensions not yet populated. Companion to `HANDOFF.md`.

---

## 0. Governing model

Three object types, three jobs — do **not** model every dimension as a category:

| Object | Job | Where |
|---|---|---|
| **Categories** (`product_cat`) | Browse spine — one primary path per product; URL + breadcrumb | The Stones · Rings · Collections |
| **Attributes** (`pa_*`) | Faceted filters (many-to-many) + PDP subject line | colour, style, shape, craft, engraving, rarity, wearer |
| **Tags** (`product_tag`) | Cross-cutting merchandising flags | `one-of-one`, colour source-tags, campaign tags |

---

## 1. Category spine (LIVE)

```
The Stones (#132)              /product-category/the-stones/          ← primary (colour = breadcrumb path)
├── Red Aqeeq        (12)      …/red-aqeeq/
├── Blue Aqeeq       (7)       …/blue-aqeeq/
├── Honey Aqeeq      (3)       …/honey-aqeeq/        (was 7 — 4 amber pieces moved to Orange)
├── Green Aqeeq      (5)       …/green-aqeeq/
├── Kabdi Aqeeq      (1)       …/liver-aqeeq/        (renamed from "Liver Aqeeq — Kabdi"; slug kept)
├── Lavender Aqeeq   (4)       …/lavender-aqeeq/
├── White Aqeeq      (2)       …/white-aqeeq/
├── Black Aqeeq      (2)       …/black-aqeeq/
├── Orange Aqeeq     (4)       …/orange-aqeeq/       ← NEW (filled from tag `orange`)
├── Multicolor Aqeeq (4)       …/multicolor-aqeeq/   ← NEW (filled from tags `banded` + `landscape`)
└── Yemeni Mocha     (0)       …/yemeni-mocha/       ⚠ emptied by the Multicolor move — retire or repurpose

Rings (#142)                   /product-category/rings/
├── Men's Rings     (22)       …/mens-rings/
└── Women's Rings   (22)       …/womens-rings/

Collections (#143)             /product-category/collections/
├── Signature Collection (15)  …/signature-collection/
├── Heritage Collection  (15)  …/heritage-collection/
└── Limited Editions     (14)  …/limited-editions/
```

**Primary category** (breadcrumb path) should be the stone-colour term. Set it per product in SureRank
so breadcrumbs read `Home › Store › The Stones › Red Aqeeq › [Product]` rather than a collection path.

**Open decision — Yemeni Mocha:** all 4 of its products were banded/landscape and moved to Multicolor,
so it is now empty. Either retire it, or repurpose it as a distinct warm-brown stone type and assign
real pieces. Left in place (empty categories are hidden from most surfaces) pending your call.

---

## 2. Attribute layer (`pa_*`)

| Attribute | Taxonomy | Status | Terms |
|---|---|---|---|
| Stone | `pa_stone` | **populated** (drives PDP subject + `pa_stone` sidebar facet) | Red/Blue/Green/Honey/Lavender/Liver/White/Black/Yemeni Mocha Aqeeq + Sulaimani/Yellow |
| Metal | `pa_metal` | **populated** (subject + facet) | Sterling Silver 925, Oxidized Silver, … |
| Wearer | `pa_wearer` | **populated + LIVE facet** — backfilled from Men's/Women's categories | Men (22), Women (22), Unisex |
| Design Style | `pa_style` | terms ready, **0 assigned** | Royal, Traditional Yemeni, Islamic Heritage, Minimal Luxury, Modern, Vintage, Handmade, Limited Edition |
| Stone Shape | `pa_shape` | terms ready, **0 assigned** | Oval, Round, Cushion, Freeform, Cabochon, Marquise, Emerald |
| Engraving | `pa_engraving` | **NEW**, 0 assigned | No Engraving, Arabic Calligraphy, Islamic Symbols, Custom Name Engraving, Handmade Engraving |
| Rarity | `pa_rarity` | terms ready, **0 assigned** | Standard, Rare, Very Rare, One of One |
| Colour | `pa_color` | terms ready, **0 assigned** (redundant with the colour category + `pa_stone`) | Red, Kabdi, Honey, Black, White, Green, Orange, Blue, Lavender, Multicolor |

**Facet wiring** lives in `inc/taxonomy.php` (`yazan_extra_shop_facets`): each of style/shape/engraving/
rarity/wearer is added to the shop sidebar **only once its taxonomy has assigned terms**, so the sidebar
never shows an empty facet. Today the sidebar shows **Stone · Metal · Wearer · Price**. Assign style /
shape / engraving / rarity to products and those facets appear automatically — no code change.

**To make the remaining facets real:** batch-assign per product in the WooCommerce product editor
(Attributes tab), or extend the setup script. Shape/style/engraving are **not derivable** from existing
data, so they need editorial input — do not auto-fill them.

---

## 3. Collections system

Durable collections = categories (above). Dynamic collections = tag/attribute archives:

| Collection | Mechanism | Positioning |
|---|---|---|
| New Arrivals | date query (last 30 days) | "The newest stones to reach Yazan." |
| Signature | category | "The rings that define the house." |
| Heritage | category | "Craft passed hand to hand." |
| Limited Editions | category (also the interim "Rare Aqeeq" target) | "When they're gone, they're gone." |
| Rare Stones | `pa_rarity` archive (once populated) | "Stones the earth made only once." |
| Handmade Masterpieces | `pa_style = Handmade` | "Made entirely by hand, one at a time." |
| Gift | tag `gift` | "A ring that arrives as a story." |
| One of One | tag `one-of-one` (LIVE, drives the badge) | "This one is yours. There is no second." |

---

## 4. Navigation (LIVE — "Main Menu" #53, rendered by HFE on the `primary` location)

Top level: **Home · Shop ▾ · Collections ▾ · Rare Aqeeq · About YAZAN · Authentication · Contact**

**Shop ▾** = 4-column mega (`yz-mega` class → styled by `assets/css/megamenu.css`):

| Aqeeq Types | Collections | Rings | Services |
|---|---|---|---|
| Red Aqeeq | Signature Collection | Men's Rings | Ring Authentication |
| Honey Aqeeq | Heritage Collection | Women's Rings | Digital Certificate |
| Kabdi Aqeeq | Limited Editions | All Rings → | Warranty |
| Green Aqeeq | | | Custom Orders |
| Orange Aqeeq | | | |
| Multicolor Aqeeq | | | |

- All Aqeeq/Collection/Ring links are **real category archives** (`get_term_link`), not placeholders.
- **Collections ▾** = simple dropdown (`yz-mega--simple`): Signature · Heritage · Limited Editions.
- **Rare Aqeeq** → Limited Editions archive (closest real "rare" grouping until `pa_rarity` is populated;
  then repoint to `/product-category/…` or a `pa_rarity` archive).
- **Services** links → the new **Authentication** page (`/authentication/`, id #7951) with `#certificate`
  / `#warranty` anchors; **Custom Orders** → `/contact-us/`.
- **Authentication** (top level, `yz-nav-accent`) → `/authentication/`. Content is serial + certificate +
  public verification + warranty + custom orders — **no ownership** (per the recorded decision). When the
  `yazan-core` verify endpoint (`/verify-ring/`) goes live, point "Digital Certificate" there.

**Mega-menu structure contract** (how `megamenu.css` reads the WP menu):
`yz-mega` top item → 2nd-level items become **column headings** (serif, hairline underline) → 3rd-level
items render **inline** beneath each heading. So: build columns as 2nd-level menu items, links as their
children. The CSS already supports 4 columns; a 5th "featured image" column would need a small addition.

**Header shell** (announcement bar, sticky/compress, logo, mobile off-canvas) is built in **HFE
(Elementor)** + behaviour in `inc/header.php` / `assets/{css,js}/header.*`. The nav *content* is the WP
menu above; the nav *chrome* is the mega-menu CSS. Rebuild the menu via `scratchpad/menu.php`.

---

## 5. URL & breadcrumb reference

- Product: `/product/{slug}/` · Category: `/product-category/{parent}/{child}/` · Tag: `/product-tag/{slug}/`
- Shop root: `/store/` (WooCommerce shop page = "Store" #38). Note two stray `/shop/` + `/shop-2/` pages exist.
- Breadcrumb target: `Home › Store › The Stones › {Colour} › {Product}` (set primary category in SureRank).

---

## 6. Reversibility / scripts

One-off setup scripts + backups live in the session scratchpad:
- `taxonomy.php --commit` — rename/create categories, extend attributes, backfill `pa_wearer`.
  Backup: `backup-taxonomy.json` (every product's cats/tags/wearer before the run).
- `menu.php --commit` — rebuild Main Menu #53 + create the Authentication page.
  Backup: `backup-menu.json` (all 18 previous menu items).
- Re-run either without `--commit` for a dry run. To revert, restore memberships/items from the JSON backups.

Durable in-theme wiring: `inc/taxonomy.php` (facets). Everything else is data (terms, menu, page).

---

## 7. Change log — 2026-07-21

1. Renamed `Liver Aqeeq — Kabdi` → **Kabdi Aqeeq** (slug `liver-aqeeq` kept — no URL break).
2. Created **Orange Aqeeq** (4, from tag `orange`, moved out of Honey) + **Multicolor Aqeeq**
   (4, from `banded`+`landscape`, moved out of Yemeni Mocha → Mocha now empty).
3. Extended `pa_style` / `pa_color` / `pa_rarity` term vocab to the luxury spec; created `pa_engraving`
   + `pa_wearer`; backfilled `pa_wearer` (Men 22 / Women 22) as a **live sidebar facet**.
3b. Aligned the `pa_stone` attribute (PDP subject + Stone facet) to the categories: renamed its
   `Liver Aqeeq`→`Kabdi Aqeeq`, added `Orange Aqeeq`/`Multicolor Aqeeq`, moved the 8 re-homed
   products' `pa_stone` to match — so the Stone filter no longer contradicts the browse tree.
   Backup: `scratchpad/backup-pa_stone.json` (`align-stone.php`).
4. Rebuilt the header nav to the redesigned structure (4-col Shop mega + real links); created the
   on-brand **Authentication** page (#7951).
5. Built out **/authentication/** into a full luxury page: hero → 5 proof pillars (anchors
   `#serial/#certificate/#verify/#warranty/#custom`) → 3-step "how verification works" → closing CTA.
   Files: `template-parts/pages/authentication.php` (markup, rendered via `the_content`),
   `assets/css/authentication.css` (scoped, page-only enqueue), `inc/page-authentication.php` (loader).
   Astra page meta set to Full-Width / no-title / no-sidebar (`auth-meta.php`). CTAs point to
   `/contact-us/` via the `yazan_auth_verify_url` / `yazan_auth_custom_url` filters — repoint the first
   to `/verify-ring/` once that endpoint ships. DB block content kept as graceful fallback.
6. Added `inc/taxonomy.php` (facet wiring) + `inc/page-authentication.php` — the child-theme code changes.
7. Built the **public ring-verification system** (`/verify-ring/`, page #7984):
   - **Logic in yazan-core** (`includes/class-yazan-core-verify.php`, plugin v1.2.0): `Yazan_Core_Verify::lookup($serial)`
     resolves a product by its `_yz_serial` meta and returns certificate data (stone, colour, silver,
     origin, shape, weight, collection, certified year, one-of-one) — **authenticity only, no owner data**.
     Pretty route `/verify-ring/{serial}/` via rewrite + `yz_serial` query var; page auto-provisioned by
     the installer.
   - **Presentation in the theme**: `template-parts/pages/verify-ring.php` (form + certificate card /
     not-found / idle states), `assets/css/verify.css`, `inc/page-verify-ring.php` (loader). Degrades
     gracefully if the plugin is inactive.
   - **Integrations**: PDP "Verify" link deep-links to each ring's `/verify-ring/{serial}/`; the
     Authentication page CTA points here (`yazan_auth_verify_url`). All 44 products carry serials.

### Next
- Decide Yemeni Mocha's fate (retire / repurpose).
- Assign `pa_style` / `pa_shape` / `pa_engraving` / `pa_rarity` per product to activate those facets.
- Set primary category (colour) per product in SureRank for clean breadcrumbs.
- Optionally repoint the "Digital Certificate" mega-menu link to `/verify-ring/`.
- Screenshot the open mega menu + the certificate card (can't screenshot locally) to fine-tune.
```
