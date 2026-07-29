=== Power Coupons for WooCommerce ===

Contributors: brainstormforce
Tags: discount rules, dynamic discounts, woocommerce discounts, woocommerce coupons, auto apply coupons
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress coupon plugin for WooCommerce that auto-applies discounts with flexible rules and dynamic cart incentives—no codes required.

== Description ==

Power Coupons is a WordPress coupon plugin built for WooCommerce stores that want discount rules, dynamic discounts, and auto-apply coupons—without turning the checkout into a "find the coupon field" scavenger hunt.

If you've ever run a promotion and still watched shoppers drop off at cart or checkout, it's often not because the deal wasn't good. It's because the coupon experience was clunky:

* The offer was buried in a banner.
* The code was forgotten, mistyped, or never entered.
* The cart didn't clearly explain what qualifies.
* Another discount tool conflicted, and nobody could tell why.

Power Coupons is designed to reduce that friction. Instead of relying on shoppers to enter codes, you can show eligible offers in-cart and apply discounts automatically when conditions are met. The result is a cleaner buying experience and promotions that are easier to manage (and easier to troubleshoot) over time.

[youtube https://www.youtube.com/watch?v=Uj6ZMyXoLug]

= Who is this for? =

* Store owners who want a WordPress coupon plugin that's easy to set up and easy to audit later.
* DTC and growing brands focused on increasing AOV with dynamic discounts and cart incentives.
* Retail teams running frequent promotions who need rule-based discounts that don't require codes.
* Agencies managing multiple WooCommerce sites that want consistent discount logic and a clean admin experience.

= What you can build with Power Coupons =

Use simple conditions (cart subtotal, products, categories, quantity, combinations) to trigger offers at the right time. For example:

* Auto-apply a discount when the cart subtotal crosses a threshold (great for "spend more" campaigns).
* Apply a category-based coupon rule (e.g., discount only for a seasonal collection).
* Run quantity-based deals (bulk discounts / quantity breaks) to increase basket size.
* Show relevant offers in the cart so shoppers see what they can unlock before checkout.

= How it works (quick overview) =

1. Create a discount rule and choose what triggers it (subtotal, products, categories, quantities, combinations).
2. Set what happens when the rule matches (auto-apply coupon / discount behavior).
3. Optional: exclude products or categories to protect margins.
4. Test with a real cart to confirm the discount applies and updates as the cart changes.
5. Launch your offer and let the cart do the work—no coupon codes required.

= How to auto-apply your first discount (quick start) =

* Install and activate Power Coupons.
* Go to WooCommerce > Coupons and create a new rule.
* Choose a simple condition (e.g., cart subtotal above a set amount).
* Save, then test by adding products to the cart until the condition is met.
* If the discount doesn't update immediately, exclude Cart and Checkout pages from caching and retest in an incognito window.

= Key features =

* **Auto-apply coupons (no codes needed):** discounts apply automatically when conditions are met.
* **Discount rules for WooCommerce:** build rule logic around cart total, products, categories, quantities, and combinations.
* **Dynamic discounts:** offers update in real time as the cart changes, so shoppers always see what they qualify for.
* **Clean admin workflow:** create and manage rules without an overwhelming "settings maze".
* **Clean shopper UI:** show only relevant offers in the cart to reduce friction and confusion.
* **Designed to stay lightweight:** helps reduce plugin overlap by keeping coupon rules and cart incentives in one place.

= Planned features (Roadmap) =

These features are on the roadmap and will appear in future updates:

* **WooCommerce BOGO offers:** Buy X Get Y, free gifts, and advanced BOGO rules.
* **Spend-more incentives:** "Spend more to unlock a reward" nudges and cart progress messaging.
* **Rewards & loyalty points:** earn points on purchases and redeem them for discounts.
* **Coupon analytics:** track coupon usage and promotion performance.

= Works well in typical WooCommerce setups =

Power Coupons is made for WooCommerce cart and checkout flows. Most stores combine discounts with themes, page builders, and common WooCommerce add-ons. As with any pricing/coupon system, if you run multiple "discount engines" at the same time, test carefully to avoid stacking surprises.

== Screenshots ==

1. Create an auto-apply WooCommerce coupon rule with cart conditions.
2. Discount rules by subtotal threshold (spend more to unlock a discount).
3. Category and product targeting (include/exclude logic).
4. In-cart offers shown clearly (no coupon code hunting).
5. Cart and Checkout Blocks view (modern WooCommerce checkout flow).
6. Settings screen for store-wide discount behavior and testing.

== Installation ==

1. Install the `Power Coupons` either via the WordPress plugin directory or by uploading `power-coupons.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to `WordPress Dashboard > Power Coupons > Settings` to configure the settings
4. Make sure to disable caching on your cart and checkout pages

== External services ==

This plugin uses external third-party services in specific situations. Below is a detailed disclosure of these services, when they are used, and what data is transmitted.

**Google Fonts**

= When it's used: =
Power Coupons loads the Figtree font family from Google Fonts CDN to enhance the visual appearance of the plugin's admin interface in the WordPress dashboard. This resource is loaded ONLY on the plugin's admin pages (Power Coupons settings and coupon edit screens) and is NOT loaded on the frontend of your website or visible to your customers.

= What data is transmitted: =
When an administrator accesses Power Coupons admin pages, a standard HTTP request is made to Google Fonts servers. This request may include:
- The URL of the admin page being accessed
- The administrator's IP address (standard for any HTTP request)
- Browser information (user agent)
- Timestamp of the request

= Service provider: =
- Service: Google Fonts
- Service URL: https://fonts.googleapis.com
- Privacy Policy: https://developers.google.com/fonts/faq/privacy
- Terms of Service: https://policies.google.com/terms

= Legal basis: =
The use of Google Fonts in the admin area is necessary for the proper presentation of the plugin interface to administrators. No personal data from your website visitors or customers is transmitted to Google Fonts, as this service is only used in the WordPress admin area.

= How to disable: =
If you prefer not to use Google Fonts for privacy or compliance reasons, you can disable it by adding this code to your theme's functions.php file:

`
add_action( 'admin_enqueue_scripts', function() {
    wp_dequeue_style( 'power-coupons-font' );
}, 99 );
`

Note: Disabling Google Fonts may slightly affect the visual appearance of the plugin's admin interface but will not affect functionality.

== Frequently Asked Questions ==

= Can I create discount rules for WooCommerce coupon codes? =
Yes. Power Coupons lets you create rule-based discounts that apply automatically when conditions are met.

= How do I set up dynamic discounts for WooCommerce? =
Create a rule using cart conditions like cart total, products, categories, quantities, or combinations. When the cart conditions match, the discount applies automatically.

= Why is my WooCommerce discount not applying? =
Common causes include: the cart does not meet the rule conditions, excluded products/categories, usage restrictions, or another coupon/discount already applied. Check the rule conditions and test with only one active rule to isolate the issue. If you have any questions or need assistance, please contact our [support team](https://cartflows.com/support).

= Can I create bulk discounts or quantity breaks? =
Yes. Use quantity-based rules to offer tiered discounts based on the number of items.

= Does Power Coupons work with WooCommerce Cart and Checkout Blocks? =
Power Coupons is built for WooCommerce cart and checkout flows. If your store uses Blocks and you notice coupon UI differences, update WooCommerce and Power Coupons to the latest versions and contact support with details.

= Will caching affect auto-apply discounts? =
Some caching setups can interfere with cart sessions. If discounts do not update correctly, exclude cart and checkout pages from caching and re-test.

== Contributors & Developers ==

Power Coupons is built and maintained by the team behind CartFlows and Brainstorm Force, creators of some of the most trusted WooCommerce and WordPress products.

We welcome feedback, ideas, and contributions to help make Power Coupons even better 🚀

== Development & Source Code ==

This plugin includes compiled JavaScript assets for production use.  
The original, human-readable source code for these files is included in this plugin.

React / JavaScript source code:
- Located in: `admin/assets/src/`
- Built files output to: `admin/assets/build/`

The files in `admin/assets/build/` are generated from the corresponding source files in `admin/assets/src/` using standard JavaScript build tools such as `wp-scripts`.

== Changelog ==

= 1.0.6 - Wednesday, 17th June 2026 =
* Improvement: Onboarding now keeps you on the Power Coupons dashboard after activating sibling plugins, instead of being redirected away.
* Fix: Onboarding final screen — fixed text alignments.
* Fix: "Get a license key here" link on the License settings tab now points to the correct URL.

= 1.0.5 - Tuesday, 2nd June 2026 =
* Improvement: Added the compatibility for future release of Power Coupons Pro.

= 1.0.4 - Friday, 1st May 2026 =
* Improvement: Loyalty Rewards admin now shows a banner and status pill when the master toggle is off, so the program isn't accidentally left disabled.
* Improvement: Loyalty Rewards landing page surfaces the available program types (Order Earning, Signup Bonus, Product Review) and notes that multiple campaigns can run simultaneously.
* Improvement: Loyalty Rewards and BOGO create/edit modals — field rules and start/end date scheduling hints are now visible inline instead of hidden in hover-only tooltips.
* Fix: Gift card email "Shop Now" button no longer renders broken in Outlook and other email clients.

= 1.0.3 - Tuesday, 14th April 2026 =
* New: Gift Cards — purchase, deliver, and redeem store-credit gift cards (Pro).
* New: Bulk edit for coupon slideout visibility with opt-in model.
* New: Added an onboarding wizard to provide a smooth onboarding experience and enable quick, guided setup.
* Fix: Improved code quality and static analysis compliance.

= 1.0.2 - Wednesday, 26th March 2026 =
* New: Added compatibility for Loyalty Rewards (Pro).
* New: Added compatibility for Cart Progress Bar (Pro).
* New: Added coupon display mode setting with modal layout support.
* New: Sub-tabbed settings for Loyalty Rewards and Text Customization.
* Improvement: Settings UI improvements with cascading disable toggles.
* Fix: Dropdown focus ring color consistency.
* Fix: HTML entity rendering in admin history notes.

= 1.0.1 - Monday, 16th March 2026 =
* New: Added integration with the WordPress Abilities API.
* New: Added a "Hide from Slideout" option for individual coupons.
* New: Added `power_coupons_available_coupons` filter to control which coupons are displayed.
* Fix: Fixed untranslated strings.
* Fix: Addressed accessibility issues identified in the WCAG 2.2 audit.
* Fix: Fixed alignment issues with the drawer trigger button.

= 1.0.0 - Monday, 2nd February 2026 =
* Initial release of Power Coupons.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Power Coupons.
