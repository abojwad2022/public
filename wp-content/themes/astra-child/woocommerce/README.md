# WooCommerce template overrides — Yazan

This `woocommerce/` folder is where the child theme overrides WooCommerce templates. When a file
here mirrors the path of a template inside `wp-content/plugins/woocommerce/templates/`, WooCommerce
loads **your** copy instead of its own.

## Golden rule: prefer hooks over template copies

**~90% of customizations should be done with hooks/filters in `inc/woocommerce.php`, NOT by copying
a template here.** Copied templates are frozen at the version you copied them — when WooCommerce
updates its template, your copy goes stale and can break the store or miss new features. Only copy a
template when a hook genuinely cannot reach the change.

## How to add an override (correctly)

1. Find the source template in `wp-content/plugins/woocommerce/templates/…`
   (e.g. `single-product/add-to-cart/variable.php`).
2. Copy it here preserving the **same relative path**, dropping the leading `templates/`:
   `themes/astra-child/woocommerce/single-product/add-to-cart/variable.php`.
3. Edit only what you need. **Keep the `@version` header** so you can diff against future WooCommerce
   releases.
4. Verify under **WooCommerce → Status → Templates**. WooCommerce flags any override whose version is
   behind core — update yours when it does.

## Folder map (mirror of WooCommerce's `templates/`)

```
woocommerce/
├── archive-product.php              # shop / product archive wrapper (rarely needed)
├── content-product.php              # a single card in the loop (prefer loop hooks instead)
├── loop/                            # loop parts: price, rating, add-to-cart button, pagination
├── single-product/                  # product page parts…
│   ├── add-to-cart/                 #   simple.php, variable.php, quantity, etc.
│   ├── tabs/                        #   description / additional-info / reviews tabs
│   └── …                            #   title, price, meta, product-image, related, etc.
├── cart/                            # cart.php, mini-cart.php, cart-totals, cross-sells
├── checkout/                        # form-checkout, review-order, payment, thankyou
├── global/                          # quantity-input, form-login, breadcrumb, sale-flash
├── myaccount/                       # dashboard, orders, my-address, form-edit-account
└── emails/                          # transactional email templates
```

The subfolders present here are scaffolding. Add a template only when you actually override it.

## What Yazan already does WITHOUT overrides (see ../inc/woocommerce.php)

- Product-card subject line, badges, hover second image, `.yz-card` class — via loop hooks.
- Product-page eyebrow, serial line, sticky mobile add-to-cart bar — via
  `woocommerce_single_product_summary` + `wp_footer` hooks.
- Removed Astra/native sale flash, forced no-sidebar shop — via filters.

Reach for a real override here only for structural changes hooks can't express (e.g. re-ordering
the variable-product add-to-cart markup, or a bespoke cart layout).

## HPOS / blocks note

- Keep any order-related code HPOS-safe (use `wc_get_order()` — never `get_post()`).
- The store may use the **Cart/Checkout Blocks**; classic `cart/` and `checkout/` template overrides
  only affect the **shortcode** cart/checkout. Confirm which is active before overriding those.
