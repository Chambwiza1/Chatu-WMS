# Chatu Warehouse 0.6.0: search everything, "+", activity, the dashboard (core 1.6)

28 November 2026. **Core 1.6** for Warehouse: C7, C8, C10 and C12 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`, on Chatu Construction's model. Also a **security fix**: four database statements could read another organisation's records (section 5). Update 0.5.0 by extracting the zip over the folder. There is no database change.

## 1. Search everything from the top bar (C7)

A search box in the top bar, on every page. Press **/** to jump to it.

- Suggestions come as you type, grouped by kind. Arrows and Enter choose one.
- **Enter with nothing chosen** opens the page of an exact code: an item code or barcode (an item's or a pack's), a bin, a batch, a serial number or a document number. It works like Find on Home, so a scanner works here too. Otherwise Enter opens the results page.
- It finds:
  - items: by code, name, barcode or a pack's barcode;
  - warehouses and bins;
  - batches: by number or the supplier's number;
  - serial numbers;
  - customers and suppliers;
  - vehicles and drivers;
  - documents by their number: purchase orders, goods received, sales orders, shipments, pick waves, transfers, adjustments, counts, production runs and trips.
- **Only what you may open:** each group follows the same rules as its own list, by your duties and your warehouses. A Lusaka storekeeper never finds a Kitwe delivery. Customers need Customers, View. Vehicles appear only when Fleet is on.
- A % is searched as a % sign, not as "anything".

## 2. "+": add something new (C8)

A **+** button beside the search box lists what you may start. The list follows your duties and the organisation's modules:

- purchase order;
- goods received;
- sales order;
- transfer;
- adjustment;
- count;
- production run;
- trip;
- item;
- customer;
- supplier;
- vehicle.

## 3. Activity on each record (C10)

At the foot of a record's page, **Activity** (folded) shows who did what and when. It is read from the audit log, so nothing extra is stored. A change also lists the fields that changed ("name: Rice 5 kg bag → Rice 5 kg bag (white)"). Passwords, secrets and tokens are never shown.

It is on these pages:

- an item's stock page;
- purchase orders, goods received, sales orders, shipments and pick waves;
- transfers, adjustments and counts;
- production runs and trips;
- vehicles and drivers;
- customers and suppliers.

You see it only if you may see that kind of record. People with the audit log right get a link to the full log.

## 4. The dashboard (C12)

**Know > Dashboard**, for anyone who sees stock. It covers the last 7, 30 or 90 days, at all your warehouses or one of them.

**Figures:**

- stock value now;
- deliveries received;
- shipments dispatched;
- the share dispatched by the date the customer asked for;
- days from order to dispatch;
- the share of count lines that were exact;
- the value written off by adjustments and counts;
- transfers on the road;
- batches expiring within 30 days;
- items below their reorder level.

**Charts:**

- goods in and out day by day;
- stock value by warehouse;
- the items dispatched most.

Each figure opens its list or report. Money (stock value, write-offs, value by warehouse) shows only to people who may see costs.

## 5. Security fix: an OR that reached other organisations

Every statement on an organisation's table must name the organisation, and the query guard refuses one that doesn't. But a statement written as "the organisation **and** this **or** that" names it and still reads every organisation on the "or that" side. Four were found, and all four are fixed:

| Where | What could cross |
|---|---|
| Finding an item by code or barcode (Find, scanning, receiving and picking by barcode) | A barcode used by another organisation could find **its** item |
| Checking a new barcode is free | Another organisation's barcode or code could be called "already used" |
| Report > Trace, finding a batch by number | The supplier's batch number could find another organisation's batch |
| The integrity check of serial numbers | Another organisation's serial numbers could be reported as problems |

**Kept from coming back:**

- a new test, ORG-4, fails on the old code and passes now;
- another new test, SQL-1, reads every statement in the program and fails if any has an OR beside its organisation condition.

**The other products (not changed here):** I ran the same scan over ChatuPOS and ChatuPass. It found no such statement in their pages, but it flagged statements in their integrity checks that name no organisation at all. They pass the guard only because a join names `tenant_id`, so their own chats should check them. The core doc records them.

## Tested

81 acceptance tests pass. Six are new:

- C16-1: search by duty and warehouse;
- C16-2: "+" by duty and module;
- C16-3: activity, with what changed;
- C16-4: the dashboard, with money only for people who see costs;
- ORG-4: the cross-organisation fix;
- SQL-1: the scan.

**In Chrome**, on a fresh online-service demo:

- "/" and the suggestions, then arrow and Enter to an item;
- the item's activity;
- an exact code opening its page, and "Nothing found";
- the "+" menu;
- the dashboard for 30 days and for a week;
- no money for a storekeeper;
- search on a phone.

**The page walk:** seven people, about 600 pages, no errors. Signed in as transport, two pages answer "not allowed"; that is not new in this release.
