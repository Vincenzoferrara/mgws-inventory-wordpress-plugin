=== Warehouse Stock Manager for WooCommerce ===
Contributors: vincenzoferrara
Tags: woocommerce, inventory, stock, warehouse, pos
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-warehouse stock, shelf picking, movement ledger, purchase orders and inventory counts for WooCommerce.

== Description ==

MG Warehouse Stock keeps WooCommerce stock organised by **where** it is, not only by how much there is.

A product's quantity is spread across a tree of sites, warehouses, rooms, racks and shelves. Staff pick from a specific shelf, every movement is written to an append-only ledger with a reason code, and the quantity shown on the product is the sum of that tree. Nothing is overwritten, so a stock count can always be reconciled against what actually happened.

= What it adds =

* **Multi-site, multi-warehouse locations.** Sites and warehouses are real post types with a room/rack/shelf tree. Searchable, and editable from the admin screen or the REST API.
* **Movement ledger.** Every adjustment, receipt, pick, return and count correction is a row with a reason code, an author and a timestamp. Stock is never silently changed.
* **Reorder rules.** Per-product thresholds that surface suggested purchase quantities.
* **Suppliers, purchase orders and receiving.** Send a purchase order, receive it partially or in full, and the received quantities post to the ledger.
* **Inventory count sessions.** Open a count, enter what is physically on each shelf, and the differences become counted movements.
* **Accepted order status.** A dedicated `Accepted` status with a flow for staff to accept orders and pick the lines to fulfil.
* **Per-role capabilities.** Seven capabilities split into read, move, approve, manage suppliers, accept orders and administer the plugin, so a shop assistant can move stock without seeing purchasing.
* **REST API.** 34 endpoints under `mgws/v1` covering locations, stock, movements, purchasing, receiving, counts, the point of sale and loyalty cards, plus 5 short-form endpoints under `mgws` for health checks, stock reads and moves, order acceptance and barcode resolution. This is the interface a mobile or handheld front end uses.
* **High-Performance Order Storage.** Declared compatible, so the plugin works with WooCommerce's HPOS order tables.

= A note on WooCommerce stock reduction =

By default this plugin leaves WooCommerce's own stock reduction **alone**. If you want MGWS to be the single authority for stock, and you do not run other stock plugins, opt in:

`update_option( 'mgws_woocommerce_stock_authority', '1' );`

WooCommerce asks whether it may reduce stock once per **order**, not once per item, so switching this on stops stock reduction for every item in the order. That is correct when MGWS owns the ledger, and disruptive when it does not. It is off by default so that installing this plugin never changes how your store handles stock behind your back.

== Installation ==

1. Upload the `mg-warehouse-stock` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen.
2. Activate it. WooCommerce must be installed and active; WordPress will refuse activation otherwise.
3. Open **WooCommerce → Warehouse** to create your first site and warehouse.
4. Set a product's stock. The quantity on the product becomes the total of your location tree, and WooCommerce's own quantity is kept in sync with it.

== Frequently Asked Questions ==

= Does it work with High-Performance Order Storage? =

Yes. Compatibility is declared on every load, so WooCommerce will not flag the plugin as incompatible.

= Can different users see different parts of it? =

Yes. Seven capabilities cover reading stock, moving stock, approving purchases, managing suppliers, accepting orders, and administering the plugin. Roles are assigned on activation and can be adjusted per user.

= Where do the quantities come from? =

From the sum of the location tree. A product with 3 units on one shelf and 5 on another shows 8. The movement ledger is the record of how it got there.

= Can I run it alongside another stock plugin? =

Yes, and that is the default. The plugin only takes over WooCommerce's stock reduction if you explicitly opt in with the `mgws_woocommerce_stock_authority` option described above. If another plugin also adjusts quantities, MGWS's ledger will not see those movements.

= What happens when I delete the plugin? =

WordPress runs the uninstall routine, which removes all 18 of its tables, its options, its custom post types, its user meta and its capabilities. Your products and orders are untouched. This is destructive and cannot be undone, so export anything you want to keep first.

= Is there a mobile app? =

Yes: [MGWS Inventory](https://github.com/Vincenzoferrara/mgws-inventory), a free and open source app (GPL-3.0) for Android, Windows and Linux. With WooCommerce alone it manages products, orders, coupons, customers and the dashboard; with this plugin installed it also unlocks the point of sale, inventory, suppliers, loyalty cards, employees, print templates and custom reports.

The plugin exposes 34 REST endpoints under `mgws/v1` for the point of sale, loyalty cards, stock lookups, purchasing and counts, plus 5 short-form endpoints under `mgws`, so other front ends can use it too.

== Screenshots ==

1. Warehouse location tree with sites, warehouses, rooms, racks and shelves.
2. Product stock panel with default picking location and purchase cost.
3. Accepted order screen with availability, picking quantities and totals.
4. Product variation defaults for location and purchase cost.

== Changelog ==

= 2.4.0 =
* New report engine: /reports/query aggregates store receipts and online orders by measure, dimension and filters; /reports/catalog lists what can be asked.
* New capability mgws_reports_read, given to administrators and shop managers.
* New /dashboards routes: personal dashboards and dashboards shared with everyone or with managers only.
* New /print routes: the shop's label, sign and report templates, their categories and paper formats, shared by every device and managed by shop managers.
* Every user works in a site: with one site it applies to everyone, with more sites it must be assigned.
* New routes to read and choose the site in use and to create a site.
* New capability mgws_manage_employees: managing employees and sites no longer comes with stock movement rights.
* The inventory status route reports the installed plugin version.

= 2.3.0 =
* First public release of the multi-site, multi-warehouse stock manager.
* Location tree of sites, warehouses, rooms, racks and shelves.
* Append-only movement ledger with reason codes.
* Reorder rules, suppliers, purchase orders and receiving.
* Inventory count sessions with counted movements.
* Accepted order status and order acceptance flow.
* Seven per-role capabilities.
* 34 REST endpoints under `mgws/v1` and 5 short-form endpoints under `mgws`, covering the point of sale and loyalty cards.
* High-Performance Order Storage compatibility declared.
* Complete uninstall routine: tables, options, post types, user meta and capabilities.
* Translatable interface, English source with an Italian translation.
