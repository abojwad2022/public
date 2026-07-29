# Yazan — Interchangeable Themes (Black ⇄ Burgundy)

A second, fully interchangeable visual identity — **Burgundy Luxury** (dark) —
alongside the existing **Black Luxury** (classic). Visitors switch instantly from
a floating switcher on the homepage; the choice persists everywhere.

> The classic theme is **not** replaced. Burgundy is additive and 100% scoped —
> with `data-yz-theme="black"` (the default) the site renders exactly as before.

---

## How it works (architecture)

```
<html data-yz-theme="black|burgundy">      ← set BEFORE first paint (no FOUC)
   │
   ├─ theme-tokens.css        both themes' design tokens (CSS variables)
   │      :root / [data-yz-theme="black"]      → legacy + semantic tokens (light)
   │      html[data-yz-theme="burgundy"]       → remapped tokens (dark)
   │
   ├─ main / motion / header / woocommerce / home.css   ← unchanged; read var(--yz-*)
   │
   └─ theme-burgundy.css      scoped override layer (html[data-yz-theme="burgundy"] …)
          fixes the light→dark inversions a token swap can't (text-on-dark, light
          literals, section grounds, header/footer, forms, cart, checkout, etc.)
```

**Why an override layer is needed.** The base system overloads two tokens:
`--yz-ink` and `--yz-ivory` are each used as *both* a surface colour *and* a text
colour. That is coherent for a light theme, but a light→dark flip needs surfaces
to go dark while text goes light — opposite directions for the same variable. So
Burgundy remaps the tokens **surface-correct** (every dark button/badge/bar/section
re-tints for free) and `theme-burgundy.css` corrects the places that used a token
as *text*. Adding a same-family recolour (e.g. another dark theme) may need only a
token block, no override sheet.

### No flash of the wrong theme (no-FOUC)

`inc/theme-switcher.php → yazan_theme_head_boot()` prints a tiny script at
`wp_head` **priority 0** — before any stylesheet is parsed. It reads the saved
theme from `localStorage` and stamps `data-yz-theme` on `<html>`. Because the
token CSS is render-blocking in `<head>` and the attribute is already set, the
correct theme paints on the **first frame**. Runs on every page → WooCommerce and
CartFlows checkout inherit the choice too.

### Smooth transition, no layout shift

On a user switch the JS adds `.yz-theme-animating` to `<html>` for ~480ms, which
enables a **400ms** transition on colour properties only
(`background/color/border/fill/box-shadow/outline`) — never layout properties, so
there is zero reflow. Initial page loads never animate. Honors
`prefers-reduced-motion`.

---

## Files

| File | Role |
|---|---|
| `assets/css/theme-tokens.css` | Tokens for **both** themes + shared design system (scale 50–950, type, shadows, radius, motion) + the switch-transition rule. |
| `assets/css/theme-burgundy.css` | Scoped Burgundy override layer (inert for Black). |
| `assets/css/theme-switcher.css` | The floating switcher widget (token-driven, works in both themes). |
| `assets/js/theme-switcher.js` | Switch controller: localStorage, instant apply, a11y (radiogroup + roving tabindex + Esc), cross-tab sync. |
| `inc/theme-switcher.php` | Theme **registry** (single source of truth), no-FOUC head boot, asset enqueue, switcher markup. |
| `assets/css/checkout.css` | Extended with a Burgundy block for CartFlows Instant Checkout (its enqueues are stripped, so it's themed inline by the `<html>` attribute). |
| `assets/tokens/burgundy.tokens.json` | W3C design-tokens export. |
| `assets/tokens/tailwind.burgundy.js` | Tailwind preset (both palettes + `theme-burgundy:` variant). |

Wiring: `inc/enqueue.php` loads `theme-tokens.css` first (render-blocking) and
`theme-burgundy.css` last (site-wide, wins the cascade). `functions.php` requires
`inc/theme-switcher.php`. Asset version bumped to **2.1.0**.

---

## Adding a third theme (no edits to existing code)

1. **Register it** — filter `yazan_themes` (e.g. in a small plugin or `functions.php`):
   ```php
   add_filter( 'yazan_themes', function ( $themes ) {
       $themes['emerald'] = array(
           'label'  => 'Emerald Luxury',
           'desc'   => 'Dark',
           'ground' => '#07160F',
           'accent' => '#1F7A54',
       );
       return $themes;
   } );
   ```
2. **Add its tokens** — a `html[data-yz-theme="emerald"]{ … }` block in `theme-tokens.css`.
3. **If it inverts light/dark**, add a scoped `theme-emerald.css` and enqueue it in
   `inc/enqueue.php` (mirror the burgundy line). A same-family recolour skips this.

The switcher UI, the boot allow-list, and the JS registry all read from
`yazan_themes()` automatically — no other changes.

---

## Notes / interactions

- **Existing Customizer "Obsidian/Maroon" switch** (`inc/theme-options.php`) is a
  separate, admin-wide axis that only tints `--yz-ink`. It's left intact. Burgundy's
  selectors are attribute-scoped and specific, so the per-visitor switch wins where
  they overlap. For a clean demo, keep the Customizer on **Obsidian**.
- **Footer background** is baked into the Elementor footer (#1165) as an inline
  per-section style. Burgundy overrides it with `!important` (external `!important`
  beats inline non-important) — see `theme-burgundy.css` §14.
- **Verification**: the site can't be screenshotted locally (see `HANDOFF.md`).
  Structure was verified against the token contracts; please open the homepage,
  toggle the switcher, then spot-check a shop archive, a product page, the cart
  drawer, and checkout in Burgundy and paste screenshots for pixel tuning.
