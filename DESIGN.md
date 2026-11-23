# Chatu Warehouse: design

Version 0.2.0, November 2026. Standalone application on the Chatu Assets 1.6.1 platform (CodeIgniter 4.6, PHP 8.1+, MariaDB 10.5+ / MySQL 8.0.16+). The warehouse logic comes from the ChatuPOS warehouse module (October 2026), rebuilt so that the warehouse ledger *is* the stock: there is no till and no separate stock total to keep in step, so the ChatuPOS "sync hook" is gone.

## 1. What came from where

| From Chatu Assets (kept, adapted) | From the ChatuPOS warehouse and production modules (rebuilt) | New |
|---|---|---|
| `Core\Db` (transactions, retries on deadlock, one-time operation tokens), `Core\Auth`, `Core\Audit` (hash chain), `Core\Settings`, `Workflow\Notify`, installer, test runner and kit, layout and stylesheet, CSP and auth filters | Bins and system bins, lots and serials (unique per item for all time), stock rows per bin/lot/serial/status, reservations, FIFO and FEFO picking, transfers with transit per lot and serial, partial receipt with damaged / rejected / missing, counts, adjustments, production with FIFO material cost, void rules | Purchasing, sales orders, fleet and trips, traceability across production, delivery notes and proof of delivery, standalone FIFO cost layers per warehouse |
| `Core\Access` rewritten: scopes are company-wide or one warehouse | | Reports, dashboard, nightly integrity check for the new model |

Removed from the Assets platform: assets, depreciation, approvals engine (asset requests), company setup wizard, hires and portal, impersonation. The Assets offline copy was replaced in 0.2 by a read-only offline look-up (section 9).

## 2. Code layout

`app/Libraries/Stock/` holds the ledger, valuation, reservations and lots. `app/Libraries/Docs/` holds one class per document (Purchasing, Receipts, SupplierReturns, Moves, Transfers, Sales, Shipments, Waves, Production, Adjustments, Counts). `app/Libraries/Fleet/` (with `AssetsImport`), `Labels/` (Code 128), `Setup/Upgrade` (database changes per release), `Master/` (items, parties, warehouses, numbering), `Reports/` (reports, dashboard, trace, reconcile), `Admin/` (users). Controllers are thin; every business rule and permission check is in a library, so the tests drive the same code as the screens.

## 3. Data model (`app/Database/sql/schema.sql`)

* **stock**: one row per (bin, item, lot, serial, status) with `qty` and `qty_allocated`. Unique key through generated `lot_key`/`serial_key` columns; CHECKs: 0 ≤ allocated ≤ qty, a serial row holds at most 1; a generated `serial_live` column is unique, so a serial number can be in stock in only one place. Empty rows are deleted.
* **movements**: append-only. Inflow has only a to-bin, outflow only a from-bin, a move both (and/or a status change). Each carries its document and the value of what left or arrived.
* **cost_layers / cost_entries**: FIFO layers per warehouse and item; each outflow records which layers it took.
* **allocations**: reservations of stock rows by shipments, transfers and pending adjustments; stage reserved or picked.
* **transit / transit_costs**: what is on a transfer, per lot and serial, and its cost in the order it left.
* Documents: purchase_orders, receipts, supplier_returns, moves, transfers, sales_orders, shipments (+ units actually shipped), customer_returns, recipes, production_runs (+ inputs per lot), adjustments, counts, trips and trip_stops, vehicles, drivers, vehicle_logs.

## 4. The rules that keep it right

`Stock\Ledger` is the only code that changes stock. `add()` (inflow) writes the row, a movement and a cost layer; `remove()` (outflow) checks free quantity (or consumes the caller's reservation), writes the movement and consumes FIFO layers; `move()` changes bin and/or status and carries a reservation along (picking).

Invariants, checked after every automated test and every night (`Reports\Reconcile`):

1. every stock row = the sum of movements into it less movements out of it;
2. stock per warehouse and item = quantity left in its cost layers;
3. `qty_allocated` on each row = its active reservations;
4. a serial is "in stock" exactly when a stock row holds it, and "in transit" exactly when a transfer carries it;
5. transit units = transit cost units on every transfer line;
6. the audit log chain is unbroken.

**Valuation vs picking.** Physical picking follows FEFO or FIFO by batch; the cost consumed is always the oldest layer. A production void takes back its own output layer first, so voiding restores the exact values.

**Availability.** Free = qty − reserved, over rows that are available, in an active pickable bin, of an active batch, not expired, and (for shipments) with the longer of the item's and the customer's minimum shelf life left. RECEIVING, DISPATCH, QUARANTINE, DAMAGED and PRODUCTION are not pickable; production draws on PRODUCTION, storage and picking bins.

## 5. Concurrency

Every operation runs in one transaction (`Db::atomic`, retried on deadlock or lock timeout) with an optional one-time token, so a double submit is done once. Lock order everywhere: document row, then `stock_locks` rows of the items involved (sorted by warehouse and item), then stock rows, then cost layers. The per-item lock serialises everything that touches an item in a warehouse, so reservations cannot oversell.

## 6. Security

Server-side permission and warehouse checks on every action and page; refusals are logged. CSRF on every form; strict Content-Security-Policy (no inline script or style). All SQL bound. Proof-of-delivery uploads are checked by extension, MIME type and size, stored outside `public/` under random names and served through a permission check. Costs and values only with `stock.view_cost`. Segregation: the administrator cannot hold approval roles; nobody approves their own adjustment; nobody who counted or submitted a count approves it; releasing quarantined stock needs approval rights.

## 7. Decisions taken (please confirm)

| Question | Default chosen |
|---|---|
| Valuation | FIFO per warehouse; transfers carry their layers' cost |
| Picking | FEFO per warehouse by default, FIFO selectable per warehouse and per item |
| Expired, held or recalled batches | never picked, transferred or used in production |
| Over-receipt against a purchase order | 0 % (setting) |
| Rejected goods at receipt | not taken in; recorded on the receipt |
| Damaged / rejected units on a transfer | received into DAMAGED / QUARANTINE |
| Missing units on a transfer | written off at the cost they left with on the final receipt; note required |
| Failed delivery | everything back to STOCK (or quarantine / damaged, as chosen) at the cost it left with |
| Customer return | to QUARANTINE by default, at the cost it left with |
| Adjustments and counts | always a second person unless an automatic limit is set |
| Count difference basis | system quantity at approval |
| Production output | a new batch per run; cost = (FIFO materials + other cost) / quantity made |
| Invoicing | not in the system; dispatches export to CSV with prices |
| ChatuPOS link | none in this version |
| Packs | stock always in the base unit; a pack is an entry convenience stored on the line |
| Foreign currency | stock valued in the home currency at cost × rate × (1 + landed %); the PO rate unless the actual rate is given at receipt; one currency per order |
| Offline | look-up only; nothing is posted offline |

## 8. Release 0.2

**Packs (`item_units`).** Per item: name, factor (> 1; whole for items counted in whole units), optional barcode (unique across items and packs), default for buying and for selling. `Items::convertLine()` turns a line entered in a pack into base units (quantity × factor, cost or price ÷ factor) and keeps `pack_unit` and `pack_factor` on po_lines, receipt_lines, so_lines and transfer_lines for display. Nothing downstream (ledger, reservations, FIFO) knows about packs. `Items::byCode()` finds an item by code, item barcode or pack barcode; Find, the line editors and the offline look-up all use it.

**Foreign currency (`Master\Fx`).** purchase_orders and receipts carry `currency` ('' = home, setting `currency_code`), `fx_rate` (home per one unit) and `landed_pct`. Receipt line costs are entered in the order's currency; the receipt stores `unit_cost_fc` and the home `unit_cost` = fc × rate × (1 + landed / 100), which is what the ledger and cost layers see. Opening stock is always in the home currency.

**Waves (`pick_waves`, `shipments.wave_id`).** A wave groups 2 to 60 allocated shipments of one warehouse; a shipment is in at most one open wave. The pick list is the reserved allocations of its shipments ordered by bin sort and code. Confirming locks the wave and calls `Shipments::pick()` for each shipment in the same transaction, so a wave is picked entirely or not at all; a shipment where every line was found empty leaves the wave and stays allocated.

**Labels (`Labels\Code128`).** Code 128 drawn as SVG (set B, set C for runs of four or more digits, modulo-103 check). Sheets are plain HTML pages sized in millimetres for A4 3 × 8 label stock or a 60 × 30 mm label printer. Decoding was checked with an independent reader (zxing) on the printed PDF.

**Chatu Assets vehicles (`Fleet\AssetsImport`).** Reads the Assets "Vehicle register" CSV (columns Asset, Registration, Description, Make and model, VIN (chassis), State, Insured until), matches on registration with spaces and punctuation ignored, and plans link / add / skip before anything is written. Only `vehicles.asset_code` and `vehicles.vin` (and empty make, model and insurance date) are set: no live connection between the systems.

**Offline look-up.** `GET /offline/snapshot` returns items, packs, bins and stock (no costs) for the warehouses where the user has `stock.view`. `app.js` refreshes it into IndexedDB every 15 minutes while signed in and clears it at sign-out or when the user has no stock rights. `public/sw.js` caches only the look (CSS, script, fonts, logo) and `offline.html`; signed-in pages are never cached, and a page that cannot load opens `offline.html`, which answers from the saved copy. The cache name carries the version, so a new release replaces it.

**Upgrading.** `Setup\Upgrade::v020()` adds each table and column only if missing; migration `Release020` calls it, so `php spark migrate --all` or the Administration button (which lists `Upgrade::pending()`) can run more than once. A fresh install runs all migrations.

**Branding.** Chatu Innovations palette (forest #0b3a1b, lime #7cf25e), Bricolage Grotesque and Figtree served locally, the stacked logo on sign-in and the wide logo in every footer and printed page heading. The client's logo (PNG, JPG or WEBP up to 1 MB, checked as an image, stored under `writable/uploads/brand`), address and TPIN print on delivery notes and printed pages.

## 9. Tested

`php spark wms:test --db chatuwms_test`: 37 acceptance tests (receiving and moves 8, transfers 5, sales and distribution 6, production and traceability 4, fleet, adjustments and counts 6, release 0.2 7, reconciliation self-test 1), each from a fresh demo company and each ending with the integrity check. Before release: every page was opened as each of the 14 demo and installed users (1,239 pages, no errors, no broken markup); the 0.1 flows and new 0.2 flows were driven through the real forms in a browser (a USD purchase order entered in bales and received at the rate on the day, a wave confirmed with a short pick, scanning a bin and a pack barcode into Find, item and receipt labels, the Chatu Assets vehicle import, the offline look-up with the network cut, and sign-out clearing the saved copy), ending with the integrity check: everything agrees. A 0.1 database was upgraded with `php spark migrate --all`, and a second run changed nothing.


## Core 1.1 (23 November 2026)

Shared with every ChatuERP product; see `claude/CHATUERP_CORE.md` in the project.

- **One sign-in per account** (`Auth::sessionCurrent`, `storedKey`, `newKey`).
  - Each sign-in writes a new `users.session_key` and keeps it in the session (`skey`).
  - A session whose key is no longer the account's is ended at its next page, with a message.
  - The setting `single_session` (on by default) switches the rule off.
  - Before the database update has added the column, every session counts as current.
  - A replaced sign-in is logged as `auth.session_replaced`.
- **An expired form** (`App\Filters\CsrfFilter`, which wraps CodeIgniter's CSRF filter):
  - on a page, it goes back with "That page had expired, so nothing was saved", or to sign-in when the session has ended;
  - in the background, it answers 403 JSON `{ok:false, error, expired:true}`;
  - it reads the session itself rather than `Auth`, because it runs before the organisation is known.
- **`/health`** (`Cron::health`, no sign-in): 200 while the scheduled job is recent, otherwise 503, with the job's age and the version.
- **Form rows** (`.row2`, `.row3`): each field spans three subgrid rows, so labels, boxes and hints line up across the row.
