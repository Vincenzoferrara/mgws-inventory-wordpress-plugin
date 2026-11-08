=== Warehouse Stock Manager for WooCommerce ===
Contributors: vincenzoferrara
Tags: woocommerce, inventory, stock, warehouse, pos
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 2.14.1
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
* **Per-role capabilities.** Twenty-seven capabilities split into read, move, approve, manage suppliers, accept orders, read and manage costs, manage employees and reports, administer the plugin, and one per advanced module (lots, transfers, price lists, approval rules, returns, mass reset, scheduled counts, kits, reservations, shipments, barcodes, supplier prices, documents, payments), so a shop assistant can move stock without seeing purchasing or margins.
* **Advanced stock modules.** Lots (origin and expiry as labels, no per-lot cost), stateful transfers between warehouses, price lists, approval thresholds, returns/RMA, scheduled cycle counts, reservations, shipments with tracking, and a mass stock reset that still posts to the ledger.
* **Back-office records.** Product kits and their components, documents (DDT, invoices, credit notes) with their status and PDF path, supplier price history kept apart from the retail price, EAN-13/UPC-A/EAN-8/Code 128 barcodes per product, and partial payments with an aging report.
* **Costs and stock value.** A declared cost per product or variation, kept as an append-only history, and a stock value per shelf derived from the weighted average of the real loads. A declaration can revalue the stock in hand at the declared cost, and a missing-cost list names the items whose stock has no value. Costs are projected one way to WooCommerce's Cost of Goods Sold field when that feature is enabled; the plugin values stock either way. Money is stored as integer minor units, and a missing cost stays missing instead of becoming a zero margin.
* **REST API.** 179 endpoints under `mgws/v1` covering locations, stock, movements, costs, purchasing, receiving, counts, the point of sale, reports, loyalty cards, permission sets, lots, transfers, price lists, approval rules, returns, kits, documents, supplier prices, barcodes, payments, shipments, reservations and scheduled counts, plus 5 short-form endpoints under `mgws` for health checks, stock reads and moves, order acceptance and barcode resolution. This is the interface a mobile or handheld front end uses.
* **High-Performance Order Storage.** Declared compatible, so the plugin works with WooCommerce's HPOS order tables.

= How WooCommerce stock stays correct =

The plugin never takes authority over WooCommerce's stock. Every movement it
records is applied to WooCommerce as a delta with
`wc_update_product_stock()`, so a concurrent sale is added or subtracted
instead of being overwritten, and orders, refunds and cancellations continue to
be handled by WooCommerce itself.

When the two sides drift anyway — a direct database write, a request that died
halfway, a manual edit in WooCommerce — a listener and a nightly sweep record
the difference as a movement instead of rewriting the levels in place. Surplus
that no shelf claims lands in a per-site **Unassigned** warehouse, which is
hidden from the warehouse pickers, so an unexplained difference stays visible
and traceable instead of disappearing.

== Installation ==

1. Upload the `mg-warehouse-stock` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen.
2. Activate it. WooCommerce must be installed and active; WordPress will refuse activation otherwise.
3. Open **WooCommerce → Warehouse** to create your first site and warehouse.
4. Set a product's stock. The quantity on the product becomes the total of your location tree, and every movement is applied to WooCommerce as a delta, so WooCommerce's quantity follows the ledger without ever being overwritten.

== Frequently Asked Questions ==

= Does it work with High-Performance Order Storage? =

Yes. Compatibility is declared on every load, so WooCommerce will not flag the plugin as incompatible.

= Can different users see different parts of it? =

Yes. Separate capabilities cover reading stock, moving stock, approving purchases, managing suppliers, accepting orders, costs, reports, administration and each advanced module. Roles are assigned on activation, can be adjusted per user, and permission sets can bundle any capability of the catalog.

= Where do the quantities come from? =

From the sum of the location tree. A product with 3 units on one shelf and 5 on another shows 8. The movement ledger is the record of how it got there.

= Can I run it alongside another stock plugin? =

The plugin does not take over WooCommerce's stock reduction, so it does not
conflict with plugins that adjust quantities: WooCommerce remains the owner of
the total and MGWS applies its own movements as deltas. If another plugin
changes quantities, MGWS's ledger will not see those movements until the next
reconciliation.

= What happens when I delete the plugin? =

WordPress runs the uninstall routine, which removes 37 of its tables, its options, its custom post types, its user meta and its capabilities. Your products and orders are untouched. This is destructive and cannot be undone, so export anything you want to keep first.

The fiscal records are kept on purpose: the tables mg_pos_receipts, mg_pos_receipt_lines, mg_pos_closings and mg_documents hold receipts, documents and shift closings, which are fiscal records a shop must keep. Drop them yourself if you no longer need them.

= Is there a mobile app? =

Yes: [MGWS Inventory](https://github.com/Vincenzoferrara/mgws-inventory), a free and open source app (GPL-3.0) for Android, Windows and Linux. With WooCommerce alone it manages products, orders, coupons, customers and the dashboard; with this plugin installed it also unlocks the point of sale, inventory, suppliers, loyalty cards, employees, print templates and custom reports.

The plugin exposes 178 REST endpoints under `mgws/v1` for the point of sale, loyalty cards, stock lookups, costs, purchasing, receiving, counts, reports, divergence checks, permission sets, lots, transfers, price lists, approval rules, returns, kits, documents, supplier prices, barcodes, payments, shipments, reservations and scheduled counts, plus 5 short-form endpoints under `mgws`, so other front ends can use it too.

== Screenshots ==

1. Warehouse location tree with sites, warehouses, rooms, racks and shelves.
2. Product stock panel with default picking location and purchase cost.
3. Accepted order screen with availability, picking quantities and totals.
4. Product variation defaults for location and purchase cost.

== Changelog ==

= 2.14.1 =
* POS: new POST /pos/voids records the items scanned at the till and then removed (line deleted, quantity lowered, cart emptied) as `void` rows of the movement ledger. They never reach the customer's receipt and do not change stock.
* POS: the receipt's `received_amount` is the cash handed over (`totals.importo_ricevuto`), not the change. Older apps get `total + change`.
* Stock: the locations of GET /inventory/stock/product/{id} (and the other inventory record routes) also carry `warehouse_name`, so the app can name warehouses it does not list, like the system "Unassigned" one.
* Security: the back-office modules of 2.14.0 (shipments, reservations, payments, kits, documents, barcodes, supplier prices, scheduled counts, mass reset) let anyone with `mgws_stock_read` write. Reading still needs `mgws_stock_read`; writing now needs the module's own capability (or `manage_options`) and an assigned site.
* The nine module capabilities are in the permission-set catalog (30 entries), so a set can grant them.
* Kits: component deductions (order accept, POS checkout, `POST /inventory/kits/deduct`) now move the components on WooCommerce too. Before, the nightly reconciliation put the pieces back into Unassigned. Order accept reports `kit_issues[]`.
* Kits: deduct/restore check the caller's site; `ref_order_id` must be a real order and needs `mgws_order_accept`; a restore needs the order and never gives back more than it took.
* Mass reset: `target_qty` is required, `dry_run` previews the change, the Unassigned warehouse is refused, WooCommerce failures are reported in `woo_failed[]`, and the route checks `mgws_stock_reset_manage`.
* Documents: only a draft can be deleted; an issued document never goes back to draft and `cancelled` is final.
* Barcodes saved by MGWS are resolved when scanning, and one code can belong to one item only.
* Employees: POST/PATCH /employees can create the employee's WordPress user (`create_wp_user`, `username`, optional `permission_set_id` and `send_invite`). The account gets only the set's role, never a capability the author lacks, and a random password the employee replaces from the WordPress email. New GET /employees/linkable-users to link an existing user by name instead of by ID. One WordPress user can be linked to one employee only.
* Employees: every new employee is also a WordPress user (400 `mgws_account_required` otherwise). The login email is the new `work_email`; the personal `email` stays on the record. The username comes from the work email (`username` is optional). Editing a linked employee keeps the user's names and login email in step, never on an account holding capabilities the editor lacks.
* Employees: new record fields `work_email`, contract (`contract_type`, `contract_level`, `contract_start`, `contract_end`, `contract_minutes_week`) and the usual week `weekly_schedule`. Database schema 21.
* Employees: new shifts (`mg_employee_shifts`) under /employees/{id}/shifts, with overlap checks across midnight, copy of the usual week onto a period (`/shifts/generate`) and planned hours against the contract week by week.
* Employees: new documents (`mg_employee_documents`) under /employees/{id}/documents: PDF, JPEG, PNG or WebP up to 10 MB, type read from the content, stored outside the media library in a closed folder with random names, read back only through the REST route.

= 2.14.0 =
* New product kits: `mg_kits` names a bundle and `mg_kit_components` lists the pieces with their quantities. A kit is a definition, not a stock writer: selling one is the caller's job, because the quantity WooCommerce owns is the quantity of the kit product.
* New GET/POST /inventory/kits, GET/DELETE /inventory/kits/{id}, POST /inventory/kits/{id}/components and DELETE /inventory/kits/components/{id}.
* New documents (DDT, invoices, credit notes): `mg_documents` stores the type, the number, the reference, the status (`draft`/`issued`/`sent`/`cancelled`), the issue date and the PDF path. The row is the record; rendering and delivery stay with the caller.
* New GET/POST /inventory/documents, GET/DELETE /inventory/documents/{id} and POST /inventory/documents/{id}/status.
* New POST /inventory/purchase-orders/{id}/send: records the purchase order as a sent document and emails the supplier when the supplier has an address. The PDF attachment is not generated by the plugin yet.
* New product barcodes: `mg_product_barcodes` keeps one EAN-13, UPC-A, EAN-8 or Code 128 per product/variation, so a scanned code resolves to the item without a meta lookup.
* New POST /inventory/product-barcodes, GET/DELETE /inventory/product-barcodes/{product_id}.
* New partial payments and aging: `mg_payments` records an amount against a reference, and `GET /inventory/payments/aging` buckets the outstanding amounts into `0-30`, `31-60`, `61-90` and `90+` days.
* New GET/POST /inventory/payments, DELETE /inventory/payments/{id} and GET /inventory/payments/aging.
* New shipments and tracking: `mg_shipments` is generic over `reference_type`/`reference_id` and moves through `pending`/`shipped`/`delivered`/`cancelled`. A shipment is a trail, not a stock movement.
* New GET/POST /inventory/shipments, GET/DELETE /inventory/shipments/{id} and POST /inventory/shipments/{id}/status.
* New reservations: `mg_reservations` holds a quantity for a reference until it is released or consumed. A reservation never changes a level: it records intent, and consuming it is the caller's decision.
* New GET/POST /inventory/reservations, POST /inventory/reservations/{id}/release, /consume and DELETE /inventory/reservations/{id}.
* New scheduled cycle counts: `mg_count_schedules` stores a warehouse and a frequency, and a daily cron at 04:00 opens a draft count session for every due schedule, then moves the schedule forward. A failed write leaves the schedule due so the next run retries it.
* New GET/POST /inventory/count-schedules, PATCH/DELETE /inventory/count-schedules/{id} and POST /inventory/count-schedules/run-due.
* New supplier price history: `mg_supplier_price_history` is append-only and separate from the WooCommerce price, so the price paid to a supplier is a record, not a product field.
* New GET/POST /inventory/supplier-prices.
* New POST /inventory/stock/reset: resets every level of a warehouse (or a chosen subset of products) to a target quantity, recording an adjust movement per level and mirroring the net change to WooCommerce.
* The database schema is version 20; nine tables were added and the cron hooks are cleared on deactivation and uninstall.

= 2.13.2 =
* New POST /inventory/stock/reset: resets every level of a warehouse (or a chosen subset of products) to a target quantity, recording an adjust movement per level and mirroring the net change to WooCommerce.

= 2.13.1 =
* The capability catalog now lists the five module capabilities added in 2.8.0-2.13.0: `mgws_lots_manage`, `mgws_transfers_manage`, `mgws_price_lists_manage`, `mgws_approval_rules_manage`, `mgws_returns_manage` (21 entries in total). A permission set can finally grant them.

= 2.13.0 =
* New returns (RMA): `mg_returns` holds the header and `mg_return_lines` the items. States are `requested` -> `approved` -> `received` -> `refunded`, or `rejected` from requested/approved. A return is a trail, not a stock writer: `received` changes the state only, no stock row is written, because the total of a return/refund belongs to WooCommerce.
* New GET/POST /returns, GET/DELETE /returns/{id}, POST /returns/{id}/lines, DELETE /returns/{id}/lines/{line_id}, POST /returns/{id}/approve, /receive, /reject and /close.
* New capability `mgws_returns_manage` (18 in total).

= 2.12.0 =
* New notification templates: `mg_notification_templates` stores a code + channel + subject + body with `{key}` placeholders, compiled by `render()`. The plugin compiles but does not send; delivery is the caller's job. Channels: email, push, sms, in_app.
* New GET/POST /notification-templates, PATCH/DELETE /notification-templates/{id}, POST /notification-templates/render.
* New capability `mgws_notification_templates_manage` (18 in total).
* Note: the notification templates and preferences of 2.12.0/2.13.0 were later removed; their capabilities are revoked on upgrade.

= 2.11.0 =
* New approval rules: `mg_approval_rules` stores a threshold above which a document type requires a capability. Includes a stateless check (`POST /approval-rules/check`) that returns whether approval is required and which capability. The rule only says "approval required"; the approval workflow (who, when, notification) is a separate concern.
* New GET/POST /approval-rules, PATCH/DELETE /approval-rules/{id}, POST /approval-rules/check.
* New capability `mgws_approval_rules_manage` (17 in total).

= 2.10.0 =
* New B2B price lists: `mg_price_lists` and `mg_price_list_items` store a named set of per-item prices, optionally scoped to a customer or a supplier, with a validity window and a minimum quantity per tier. A price list is not the WooCommerce retail price (`_price`); it is a separate price consulted by the B2B portal and the app.
* New GET/POST /price-lists, GET/PATCH/DELETE /price-lists/{id}, POST /price-lists/{id}/items and DELETE /price-lists/{id}/items/{itemId}.
* New capability `mgws_price_lists_manage` (16 in total).

= 2.9.0 =
* New stateful stock transfers: `mg_stock_transfers` and `mg_stock_transfer_lines` track a transfer as draft -> sent -> received (or cancelled). `sent` takes the quantity out of the source warehouse, `received` puts it into the destination, `cancelled` from `sent` compensates the source. Each transition writes its own movement rows (`source_type: stock_transfer`). The instant transfer route is untouched.
* New GET/POST /transfers, GET /transfers/{id}, POST /transfers/{id}/lines, POST /transfers/{id}/send, /receive, /cancel and DELETE /transfers/{id}/lines/{lineId}.
* New capability `mgws_transfers_manage` (15 in total).

= 2.8.0 =
* New stock lots: `mg_stock_lots` and `mg_stock_lots_levels` group pieces of the same item by origin and expiry date. A lot carries the supplier, the lot code, the expiry and the receipt date; its quantity per location is tracked separately. A lot is a label, not a movement: it creates no stock movement, changes no level quantity and carries no cost (the WAC stays the cost authority).
* New GET/POST /lots, PATCH/DELETE /lots/{id} and POST /lots/{id}/levels to manage lots and their quantity per location.
* New capability `mgws_lots_manage` (14 in total).

= 2.7.0 =
* New minimal roles: permission sets stored as templates in `mg_permission_sets` and applied as ordinary WordPress roles (`mgws_{slug}`). Two new capabilities `mgws_roles_read` and `mgws_roles_manage` (13 in total).
* New GET/POST /permission-sets and PATCH/DELETE /permission-sets/{id}: create, edit and delete named capability bundles, with an allow-list catalog, no escalation, immutability of sets in use and a flush of the WordPress roles object.
* PATCH /users/{id}/permissions now refuses to remove the last administrator (409 mgws_last_admin) and returns role_labels so the app shows the display name instead of the technical role key.
* Seed: two default sets (Warehouse operator, Cashier) created once on upgrade; they are normal roles and can be deleted when unused.

= 2.6.0 =
* New receipt snapshot of cost: POS receipts store the cost of every line, in the declaration's minor units, with the derived unit cost; an unreadable value leaves the line open instead of guessing.
* New report measures: cost, margin, margin_rate and cost_coverage over the sales of the period, with the declared coverage; revenue_share as the ABC revenue share; stock measures (qty_on_hand, stock_value, unvalued_qty, stock_cost_coverage, sold_qty, rotation, days_without_sale) from the current warehouse ledger.
* New source `stock` for reports, with product, variation, category, brand, site and warehouse dimensions and the matching filters.
* New GET /inventory/stock/divergences compares the WooCommerce stock total with the ledger per item, without correcting anything; correction stays a separate decision.

= 2.5.0 =
* POST /inventory/costs accepts revalue_stock: a declaration can also set the value of the stock in hand to the declared cost, without writing a movement.
* New GET /inventory/costs/missing lists the items holding stock whose value is unknown.

= 2.4.0 =
* New report engine: /reports/query aggregates store receipts and online orders by measure, dimension and filters; /reports/catalog lists what can be asked.
* New capability mgws_reports_read, given to administrators and shop managers.
* New /dashboards routes: personal dashboards and dashboards shared with everyone or with managers only.
* New /print routes: the shop's label, sign and report templates, their categories and paper formats, shared by every device and managed by shop managers.
* Every user works in a site: with one site it applies to everyone, with more sites it must be assigned.
* New routes to read and choose the site in use and to create a site.
* New capability mgws_manage_employees: managing employees and sites no longer comes with stock movement rights.
* New /inventory/costs routes: declared cost per product or variation, the backfill of existing purchase documents, and the stock value coverage report. Stock values are now written by every movement.
* New capabilities mgws_costs_read and mgws_costs_manage, given to administrators only until the role templates land.
* The inventory status route reports the installed plugin version.
* The loyalty stats route also reports the total points ever issued and how many cards are in each tier.
* Requires WordPress 6.2 or later: database queries pass table names through the %i identifier placeholder.

= 2.3.0 =
* First public release of the multi-site, multi-warehouse stock manager.
* Location tree of sites, warehouses, rooms, racks and shelves.
* Append-only movement ledger with reason codes.
* Reorder rules, suppliers, purchase orders and receiving.
* Inventory count sessions with counted movements.
* Accepted order status and order acceptance flow.
* Seven per-role capabilities.
* 35 REST endpoints under `mgws/v1` and 5 short-form endpoints under `mgws`, covering the point of sale and loyalty cards.
* High-Performance Order Storage compatibility declared.
* Complete uninstall routine: tables, options, post types, user meta and capabilities.
* Translatable interface, English source with an Italian translation.
