=== HUP - Kit Ecommerce Chile ===
Contributors: hazmeunapagina
Tags: chile, woocommerce, comunas, rut, checkout
Requires at least: 6.0
Tested up to: 7.1
Requires Plugins: woocommerce
Requires PHP: 8.0
Stable tag: 1.3.1
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

= Can I manage missing product images in bulk? =

The optional Pro add-on provides a grid for assigning product images with drag and drop and setting default images for products without a main image.

== Screenshots ==

1. Admin panel
2. Comuna management per region
3. Checkout field editor
4. Company billing module
5. Shipping zones with costs

== Changelog ==

= 1.3.1 =
* Fixed: customers can choose their comuna in My account > Addresses.
* Fixed: the company comuna is no longer cleared when the checkout updates.
* Fixed: comunas load with the page, so stores behind a proxy are not rate limited.
* Fixed: an invalid RUT is now detected before the order is sent, and its field is focused.
* Fixed: required custom fields show a single error.
* Fixed: with recent WooCommerce versions, a required phone was shown as optional.
* Fixed: "Probar una comuna" uses the real shipping cost, including add-on cost types, and negative costs are not accepted.
* Improved: the checkout keeps the saved comuna of returning customers, and their invoice details are prefilled.
* Tested with WooCommerce 11.3.
* Admin: the Home screen now highlights the Product Images module from the optional Pro add-on. When the add-on registers the module, its switch appears in the module list.

= 1.3.0 =
* Fixed: when a comuna belongs to several shipping zones (for example one per courier), the checkout now offers all of them instead of only the last saved zone. Rates are listed from cheapest to most expensive.
* Improved: "Probar una comuna" lists every shipping option the customer will see.
* Improved: the zone editor shows every other zone that already includes a comuna.
* Admin: the settings link in the plugins list now reads "Configuración", like every HUP plugin.
* Admin: card footer buttons wrap on small screens.

= 1.2.0 =
* New: redesigned settings screen using the design system shared by all HUP plugins, with a light side menu, a link for each section, cleaner cards and a new Guide section.
* New: "Probar una comuna" tool in the Despacho section. It simulates the shipping cost for any comuna and checks the WooCommerce settings it depends on: shipping zone, shipping method, shipping countries and classic checkout.
* Improved: shipping zones are listed in a table with their cost and coverage instead of the full list of comunas. The zone editor opens in a dialog and shows which comunas already belong to another zone.
* Improved: comuna search ignores accents ("nunoa" finds "Ñuñoa").
* Fixed: editing a zone could leave comunas with an apostrophe (such as O'Higgins) unchecked.
* Fixed: field labels are now escaped in the checkout field editor.
* Tested with WordPress 7.1.

= 1.1.2 =
* Fixed: the checkout could show "There are no shipping options available" even with zones configured. WooCommerce kept reusing shipping rates cached in the customer's session. The plugin now refreshes them when modules, zones, comunas or checkout fields change and after each update, and the selected comuna is part of the shipping package, so changing it always recalculates.
* Fixed: with the checkout field editor active, custom checkout templates (page builders) could send an empty country, which placed the customer in the "Rest of the world" zone. Chile is now shown as the only country and enforced on the server.
* Fixed: "Cash on delivery" zones were labeled "Free shipping" at checkout. They now read "Por pagar al recibir".
* Changed: Chilean regions now use WooCommerce's standard codes (CL-AT, CL-RM…). Saved settings are migrated automatically and existing orders and customers keep displaying correctly. Region-based WooCommerce shipping zones and courier plugins that rely on these codes now work.
* Fixed: the shipping method title can now be edited from the WooCommerce shipping zone.
* Improved: WooCommerce admin screens (store address, shipping zones, order editing) list every region even when the region filter is on.

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

= 1.3.0 =
Comunas in several shipping zones now show every option at checkout. Recommended update.

= 1.2.0 =
New settings design and a tool to test shipping for any comuna.

= 1.1.2 =
Fixes stores where the checkout showed no shipping options. Regions now use WooCommerce's standard codes; saved settings are migrated automatically. Recommended update.

= 1.1.1 =
Fixes orders being rejected at checkout with "No shipping method has been selected". Recommended update.

= 1.1.0 =
Fixes a critical error that could appear when removing the plugin and prevents fatal errors when WooCommerce is inactive. Recommended update.

= 1.0.0 =
Initial public release.
