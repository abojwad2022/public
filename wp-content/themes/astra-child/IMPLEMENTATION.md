# Yazan — Design Implementation Status

Tracks what the child theme **code** already implements from the two blueprints
(`تحليل موقع Comma Football (التصميم).md`, `تحليل الحركات والـ Animation.md`) and what still
needs to be done in the **Customizer / plugins / content** (your part — not code).

---

## ✅ Done in code (child theme)

| Blueprint area | Where |
|---|---|
| Color tokens (Part 13) | `assets/css/main.css` `:root` — `--yz-ink/ivory/agate/silver/sand/muted` |
| Type system + scale (Part 12) | `main.css` — Cormorant Garamond / Jost, tracked-uppercase labels |
| Brand fonts loaded | `inc/enqueue.php` (Google Fonts + preconnect) |
| Spacing, container, sharp buttons, hairlines (Part 14) | `main.css` |
| Menu hover (underline from left), sticky-header polish, dark footer (Parts 3, 11) | `main.css` |
| Motion tokens + reduced-motion guard (Motion Part 2) | `assets/css/motion.css` |
| Scroll reveals — one observer (Motion Part 7) | `motion.css` + `assets/js/motion.js` — use class `yz-reveal` |
| Hero entrance: image settle + masked line rise (Motion Part 4) | `motion.css` — use `yz-hero` markup |
| Image "curtain + settle" (Motion Part 6) | `motion.css` — attribute `data-img-reveal` |
| Header shrink + smart-hide (Motion Part 11) | `interactions.js` |
| Product card: badges, subject line, hover crossfade, slow zoom, sheen (Parts 7, 9) | `inc/woocommerce.php` + `woocommerce.css` |
| Product page: eyebrow, serial line, sticky desktop buy panel (Part 8) | `inc/woocommerce.php` + `woocommerce.css` |
| Sticky **mobile** Add-to-Cart bar (Part 15) | `inc/woocommerce.php` + `interactions.js` + `woocommerce.css` |
| Add-to-Cart feedback ("Adding…") (Motion Part 9) | `interactions.js` |
| Parallax scaffolding, front page only (Motion Part 8) | `inc/enqueue.php` + `assets/js/parallax.js` — attribute `data-parallax` |

### Authoring conventions (so the code lights up)
- **Reveal on scroll**: add CSS class `yz-reveal` to any block (Gutenberg → Advanced → Additional CSS class). For staggered rows, put the row in a `yz-grid` wrapper and `yz-reveal` on each child.
- **Hero**: give the hero section markup the classes from Motion Part 4 (`yz-hero`, `yz-hero__media`, headline lines wrapped `<span class="line"><span>…</span></span>`).
- **Product "subject line"** (e.g. `RED AQEEQ · SILVER 925`): create **global attributes** `Stone` (`pa_stone`) and `Metal` (`pa_metal`) and assign them to products.
- **Badges**: `One of One` → add product **tag** `one-of-one` (or `rare`). `Certified` + serial line → set a custom field `_yz_serial` on the product (e.g. `YZ-925-2026-00147`). `Limited — N left` → enable stock management with qty ≤ 3. `Offer` → put the product on sale.
- **Card hover second image**: add at least one **gallery** image to the product.
- **Parallax**: add attribute `data-parallax` to the brand-statement bg and craftsmanship images (front page only).

---

## ⏳ Your part — Customizer (no code)

Astra → Customize:
1. **Global → Colors**: map the 6 tokens (ink/ivory/agate/silver/sand/muted).
2. **Global → Typography**: Cormorant Garamond (headings) / Jost (body); scale from Part 12. Enable *local Google Fonts hosting* for speed + GDPR (then you can remove `yazan_enqueue_fonts` in `inc/enqueue.php`).
3. **Global → Container**: 1240px, no box shadow, no sidebar site-wide.
4. **Global → Buttons**: 0 radius (code already enforces, mirror it here).
5. **Header Builder**: 3-row layout (announcement / logo center / centered uppercase menu), sticky enabled.
6. **Footer Builder**: 4 columns incl. an **Authenticity** column (Verify a Ring · Certificate · Care). *(Ownership registration/transfer is intentionally NOT part of the system.)*
7. **WooCommerce → Shop**: 3 columns, remove the shop page title, product structure image/title/price.

## ⏳ Your part — Plugins (evaluate; not installed)
- Side-cart / drawer plugin (Part 10) — then re-ease its transitions to `--mo-*` tokens.
- Live search overlay (FiboSearch free) (Part 3).
- Filter plugin styled as chips (Part 9).
- Reviews: WooCommerce native, add a city field (avoid another heavy plugin).
- Currency switcher **only** if truly multi-currency.

## ⏳ Your part — Content & assets
- Name every ring; write stone stories.
- **Photography on one consistent background store-wide** — this outranks all CSS (Part 17 §7).
- Pages/menus: `Verify a Ring` (`/verify-ring/`), Our Story, FAQ; nav ordering from Part 4.

---

## ⚠️ Notes / honesty
- **YDOS (serials, /verify-ring/, certificates) does not exist yet.** The serial line and
  "Certified" badge appear **only** when you set `_yz_serial` on a product, so nothing is
  fabricated. Building the actual verification system is a separate project (custom plugin).
- **GSAP loads from a CDN** on the front page. If you want zero external requests, host GSAP
  locally in the theme and update the URLs in `inc/enqueue.php`.
- The child theme must be **activated** (Appearance → Themes → Yazan) for any of this to show.
