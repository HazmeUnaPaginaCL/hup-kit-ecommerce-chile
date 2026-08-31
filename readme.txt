=== HUP - Kit Ecommerce Chile ===
Contributors: hazmeunapagina
Tags: chile, woocommerce, comunas, rut, checkout
Requires at least: 6.0
Tested up to: 7.0
Requires Plugins: woocommerce
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Chilean toolkit for WooCommerce: region/comuna selector, RUT validation, shipping zones, checkout field editor and company billing.

== Description ==

**HUP - Kit Ecommerce Chile** brings together, in a single plugin, the essentials a Chilean WooCommerce store needs:

* **Dynamic region and comuna selector** for Chile (updated 2025)
* **Comuna management:** enable only the comunas you ship to, per region
* **Shipping zones:** group comunas and assign real shipping costs
* **Checkout field editor:** reorder, enable/disable and customize fields (drag & drop)
* **RUT/DNI field** with automatic validation (Modulo 11 algorithm)
* **Company billing module:** optional company fields when the customer needs an invoice
* **HPOS compatible** (High Performance Order Storage, WooCommerce 8.2+)

= Main features =

**Dynamic comuna selector**

- Up-to-date database with all Chilean regions and comunas (2025)
- Cascading selector: first the region, then its available comunas
- Support for custom sectors per region

**Smart shipping zones**

- Create zones by grouping comunas from one or several regions
- Shipping cost: free, fixed amount or cash on delivery
- Optional "free shipping from" threshold
- Integrates automatically with the WooCommerce cart

**Checkout field editor**

- Drag and reorder fields
- Enable/disable fields as needed
- Customize labels, placeholders, default value, CSS class and width
- Add custom fields (text, number, textarea, select, radio, checkbox)
- Choose whether each custom field appears in order emails and in the order details
- RUT field with automatic validation

**Company billing module**

- "Do you need an invoice?" checkbox at checkout
- Company fields that appear only when the customer requests them
- Company RUT with validation
- The customer can copy their main details with one click

= Built for Chile =

Created by Chileans for Chilean stores, designed specifically for the local market:

- Chilean naming (Region/Comuna instead of State/City)
- Correct RUT format and validation
- Up-to-date territorial division
- Optimized for domestic shipping within Chile

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin from the "Plugins" menu in WordPress.
3. Go to WooCommerce > HUP - Kit Ecommerce Chile.
4. Enable the modules you need.
5. Configure your comunas and shipping zones.

== Frequently Asked Questions ==

= Is it HPOS compatible? =

Yes. The plugin declares full compatibility with HPOS (High Performance Order Storage) from WooCommerce 8.2+.

= Can I add custom sectors? =

Yes. In the Comunas section you can add custom sectors per region that work as additional comunas.

= Is the RUT validated automatically? =

Yes. It uses the Modulo 11 algorithm to validate Chilean RUTs. It accepts formats with or without dots and hyphen.

= Does it work with my theme? =

Yes. The plugin integrates directly with WooCommerce and is compatible with any theme that follows WooCommerce standards.

= Does it work with the block-based Checkout? =

Not yet. This version supports the classic (shortcode) Checkout only, and declares that incompatibility so WooCommerce can warn you. Block Checkout support is planned for a future release.

== Screenshots ==

1. Admin panel
2. Comuna management per region
3. Checkout field editor
4. Company billing module
5. Shipping zones with costs

== Changelog ==

= 1.1.1 =
* Fixed: orders with shipping could fail at checkout with "No shipping method has been selected" when the comuna was sent in a field the plugin did not read in that context.

= 1.1.0 =
* Fixed: a critical error could appear when deleting the plugin. Uninstalling now completes cleanly even if WooCommerce is not fully loaded.
* Fixed: the plugin no longer triggers a fatal error when WooCommerce is inactive; it shows an admin notice instead.
* Fixed: the shipping cost could stay outdated when changing comuna quickly at checkout.
* Added Isla de Pascua (Easter Island) to the Valparaíso region; it was missing from the comuna list.
* Improved: all styles are now loaded through enqueued stylesheets, following WordPress.org best practices.
* Added an extension API: hooks and filters that let companion add-ons register modules, admin tabs, checkout field types and shipping cost types without modifying the plugin.
* Added a section on the Home screen highlighting the optional Pro version.

= 1.0.0 =
* Initial public release.
* Dynamic region and comuna selector for Chile (2025 territorial division).
* Comuna management per region with enable/disable and custom sectors.
* Shipping zones with configurable costs (free, fixed, cash on delivery) and a "free shipping from" threshold.
* Checkout field editor with drag & drop and custom field types (text, number, textarea, select, radio, checkbox).
* Per-field control over visibility in order emails and order details.
* RUT field with automatic validation (Modulo 11), both client and server side.
* Company billing module with conditional fields and company RUT validation.
* HPOS compatible. Classic Checkout only.

== Upgrade Notice ==

= 1.1.1 =
Fixes orders being rejected at checkout with "No shipping method has been selected". Recommended update.

= 1.1.0 =
Fixes a critical error that could appear when removing the plugin and prevents fatal errors when WooCommerce is inactive. Recommended update.

= 1.0.0 =
Initial public release.
