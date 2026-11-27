# Chatu Warehouse

By Chatu Innovations. Support: info@chatuzm.com, +260 773818370 / +260 979913497, www.chatuzm.com.

A standalone warehouse management system for distributors, manufacturers and depots. Every unit of stock is in one place (warehouse, bin, batch, serial number, status), every change is in an append-only ledger, and stock is valued at FIFO cost. Built on the Chatu Assets platform: the same sign-in, duty-based roles, hash-chained audit log, installer and look.

Version 0.3.8 (signing in as someone, and support access by the organisation's leave; a sign-up form that sends a request; core 1.5.3: an invoice the product raises itself; "Pay by card" opens DPO's card page; forgotten password reset by e-mail; the ChatuERP core, verified: organisations, one login per person, the beta12 access model, the platform console, plans and billing). Installation and updating: see [INSTALL.md](INSTALL.md) (cPanel and Linux). How it works inside: [DESIGN.md](DESIGN.md).

## What it does

**Warehouses, zones and bins.** Any number of warehouses, depots, factories and stores. Each has six system bins (RECEIVING, STOCK, DISPATCH, QUARANTINE, DAMAGED, PRODUCTION) and as many storage and picking bins as you add, one at a time or a whole rack at once (A-01-01-1 to A-03-10-4). Bins can be blocked (nothing in, nothing picked) or switched off when empty.

**Items.** Goods, raw materials, finished goods, packaging and consumables, counted in whole units or to three decimals. Tracking by batch, by serial number, or both; batches can carry manufacture and expiry dates. Shelf life (sets the expiry of what you make), minimum days left before dispatch, picking order (earliest expiry first or oldest first), weight for vehicle loads, reorder level, selling price.

**Purchasing and receiving.** Purchase orders (draft, issued, partly received, received, closed). Goods received against an order or without one, with batch, expiry and serial numbers: accepted units go to a bin, held units to QUARANTINE for inspection, rejected units are only recorded. Opening stock is entered the same way. Put-away suggests a bin. Returns to suppliers leave at FIFO cost.

**Packs (units of measure).** Each item is kept in one base unit (bag, bottle, piece). Add packs to it: a bale of 10, a carton of 24, a pallet of 400, each with its own barcode if it has one, and mark the pack you usually buy or sell in. Purchase orders, receipts, sales orders and transfers can be entered in any pack; the system stores base units and the cost or price per base unit, and shows the pack on the document ("30 bag (3 bale)"). Scanning a carton's barcode finds the item.

**Foreign-currency purchases.** A purchase order can be priced in another currency (USD, ZAR …) at a rate, plus a landed-cost percentage for freight, duty and clearing. When the goods arrive the storekeeper can give the rate on the day. Stock is valued in kwacha: cost × rate × (1 + landed %). The order shows its total in the currency and the stock value in kwacha; the receipt keeps both.

**Moves and stock status.** Bin to bin, and between available, quarantine, damaged and on hold. Releasing stock back to available is a quality decision that needs approval rights. Batches can be held or recalled: they are then never picked, transferred or used.

**Transfers and goods in transit.** Stock is reserved at the source (earliest expiry first, never expired, held or blocked), dispatched into transit per batch and serial number with the cost it left with, and received at the destination in parts: good to a bin, damaged to DAMAGED, rejected to QUARANTINE. A final receipt writes off what never arrived, at its cost, with a note. Serials on the road are "in transit"; serials that never arrive are "lost".

**Sales and distribution.** Customer orders (prices for the invoicing export), shipments with a pick list, short picks, packing (packages and weight), dispatch at FIFO cost with the exact batches and serials that left, a printable delivery note, proof of delivery (name, note, photo of the signed note), failed deliveries (everything comes back at the same cost and the order can go out again) and customer returns (to quarantine by default). Customers can require a minimum shelf life. Invoicing stays in your accounting system: the dispatch report exports to CSV.

**Wave picking.** Choose several allocated shipments in one warehouse and pick them in one walk: the wave's pick list is sorted by bin, with the total to take from each bin, then the split by shipment. Confirm once; a short pick shortens only that shipment, and a shipment where nothing was found leaves the wave still allocated.

**Barcode labels and scanning.** Code 128 labels on A4 sheets (3 × 8, the common 63.5 × 33.9 mm stock) or one per label on a label printer: items (with price and pack barcodes if you want), bins (a whole warehouse or zone at once), batches with expiry, and everything a goods receipt brought in (one per serial). Scan any label into Find, with a scanner or the phone camera: an item, a pack, a bin (opens what is in it), a batch, a serial or a document number.

**Production.** Recipes (what one batch uses and makes, plus labour and other costs). A run takes the materials from the PRODUCTION bin first and then storage (or exactly the batches you choose) at their real FIFO cost, makes a new dated batch costed at (materials + other cost) / quantity made, and records every raw-material batch used. Short material refuses the whole run. A run can be voided while its output is still in stock.

**Traceability.** From any batch: where it came from (supplier receipt or production run and its raw-material batches), what it was used to make, every customer who received it or anything made from it (with phone numbers, for a recall), and where the rest is now. Every serial number has its full history.

**Fleet and trips.** Vehicles (insurance, fitness, road tax, service by date and kilometres, odometer, capacity) and drivers (licence and expiry), with fuel, service and repair logs. A trip carries shipments and transfers from one warehouse; it is refused for expired papers or licences and for overloading. Departure at the gate dispatches everything on it; the trip closes when every delivery is recorded. Papers and services running out are flagged daily. Vehicles can be brought in from the Chatu Assets vehicle register (CSV): matched on registration, they get the asset number and VIN; new ones are added, retired ones skipped. Check the file first; nothing changes until you apply it.

**Offline look-up.** While you are signed in and online, the browser keeps a read-only copy of the items, packs, bins and stock of your warehouses (no costs). When the network drops, the app opens the offline look-up: scan or type an item, pack, bin, batch or serial and see what is where. Nothing can be posted offline. The copy is removed when you sign out.

**Your brand on paper.** Upload your logo, address and TPIN in Settings: they print on delivery notes and the heading of every printed page, next to the Chatu Warehouse mark.

**Adjustments and counts.** Adjustments (write-offs, found stock, corrections) with a reason and a second person's approval; the units being written off are held meanwhile. Cycle and full counts by zone, bin range or item, blind if you want, with a CSV count sheet; found stock is added on the count. Differences are posted against the system quantity at approval, by someone who did not count or submit. Small amounts can post automatically up to a limit you set.

**Reports.** Stock on hand (free, reserved, to put away, held, expired, damaged), stock by bin, expiry, reorder, movements, FIFO valuation including goods in transit, transit, dispatches for invoicing, losses and write-offs, production yield and cost, fleet use. All export to CSV.

**Organisations.** One installation serves one company (single company) or many (online service). Each organisation's data is kept apart down to the database keys; a person has one login and switches between the organisations they belong to. The platform owner adds organisations, sets their plans, limits and modules, and invoices them; they can pay online by mobile money (Lenco) or card (DPO).

**Control.** Each organisation keeps its own roles (copies of the ones below, to change as it likes) and can give a person extra permissions directly, all on one grid: Select, View, Create, Edit, Delete and the module's actions. A person works at every warehouse or at a list of them. Duties that must stay apart (administrator and approver, storekeeper and the accountant who approves their counts) cannot be combined. Nobody approves their own adjustment or count. Every action is in a hash-chained audit log, and a nightly integrity check proves that every bin agrees with the ledger, the FIFO layers agree with the stock, reservations add up, serial numbers are where the records say, and nobody edited the audit log.

## Roles

| Role | Does |
|---|---|
| Director / owner | Sees everything with values; approves adjustments and counts; holds and recalls batches |
| Warehouse manager | Runs a warehouse: receiving, moves, transfers, dispatch, counts and their approval, bins |
| Storekeeper | Receives, puts away, picks, packs, dispatches, counts; raises adjustments |
| Buyer | Purchase orders, suppliers, items |
| Sales and orders clerk | Customer orders, customers |
| Production supervisor | Recipes and production runs |
| Transport officer | Vehicles, drivers, trips |
| Accountant | Values and reports; approves adjustments and counts |
| Auditor | Sees everything, changes nothing |
| System administrator | Users, roles, settings and warehouse layout; cannot move or approve stock |

## Not in this version

Invoicing and payments (by design: export the dispatch report to the accounting system), a link to ChatuPOS shops (kept fully separate for now), and route optimisation. Offline use is look-up only: receiving, moving and dispatching need the network, so that two people cannot sell or move the same unit.

## Changes in 0.3.8

- **Core 1.2:** an administrator signs in as one of their people, with a reason, for an hour at most; Chatu support signs in only while the organisation has opened Support access. Every save is listed. Notes: `RELEASE_0_3_8.md`.

## Changes in 0.3.7

- **Core 1.5.4:** "New here? Ask for an account" on the sign-in page: a form that sums up what the customer needs and sends the platform owner a request (Platform > Sign-ups). Notes: `RELEASE_0_3_7.md`.

## Changes in 0.3.6

- **Core 1.5.3:** an invoice the product raises itself (`Billing::issue` with `$system`); updates ask the database which columns exist. No screen changes. Notes: `RELEASE_0_3_6.md`.

## Changes in 0.3.5

- **Pay by card** on an invoice now opens DPO's card page, through a step page of our own; **Go to the card page** on a card payment still waiting. Notes: `RELEASE_0_3_5.md`.

## Changes in 0.3.4

- **Forgotten your password?** on the sign-in page: a link e-mailed to the address on the account, good once for 60 minutes. Notes: `RELEASE_0_3_4.md`.

## Changes in 0.3.3

- Platform console: an organisation's module and plan choices sit side by side instead of one per line.
- "Trial ends" shows on an organisation's page only while it is on trial.

Notes: `RELEASE_0_3_3.md`.

## Changes in 0.3.2

- Idle sign-out is no longer held off by the offline look-up's background refresh.
- Message pop-ups and their sound can be switched off for the organisation (Administration, Settings).
- The demo company's director, dora, is its account owner.

Notes: `RELEASE_0_3_2.md`.

## Changes in 0.3.1

- Opening a record's page reads the messages about it.
- A copied role keeps the duties kept apart.
- Another organisation's warehouses, items, suppliers and customers answer "not found", also when changed.
- Platform pages answer "not found" to everyone but the platform owner.
- Your own page no longer offers switching yourself off.

Notes: `RELEASE_0_3_1.md`.

## Changes in 0.3.0

- Organisations: every table keyed by organisation, with links that cannot cross; codes and document numbers per organisation.
- Editions chosen at install: single company or online service; an updated 0.2 installation becomes a single company.
- The platform console: organisations, plans with limits on warehouses and people, invoices, trials, module rules (Production, Fleet), online payment, the integrity check of every organisation, one update for all of them.
- People and access as in ChatuPOS 2.0: own roles, direct permissions, one grid with a Select column, warehouses per person; refusals name the module and the box; nobody changes their own access.
- New messages pop up and stay until opened or closed.

Notes: `RELEASE_0_3_0.md`.

## Changes in 0.2.1

- One sign-in per account, with a setting to switch it off (Administration, Settings).
- `/health` for an uptime monitor.
- An expired form goes back with a plain message instead of the error page.
- Form fields line up in rows.

Notes: `RELEASE_0_2_1.md`.

## Changes in 0.2.0

- Packs and pack barcodes on items; documents entered in packs.
- Purchase orders and receipts in other currencies, with exchange rate and landed cost.
- Wave picking.
- Barcode labels for items, packs, bins, batches, serials and receipts; scanning bins and packs into Find; camera scanning on the home page.
- Vehicles from the Chatu Assets vehicle register; VIN and asset number on vehicles.
- Offline look-up page.
- Chatu Innovations brand throughout; your own logo, address and TPIN on printed documents.
- Administration shows when the database needs updating after new files are copied, with a button to do it.
