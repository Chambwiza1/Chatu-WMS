# Chatu Warehouse 0.8.0: late work chased, then escalated (core 1.8)

28 November 2026. **Core 1.8** for Warehouse: E4 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`. Web Push (E3), the other part of core 1.8, was agreed for Construction and Assets only; Warehouse's pop-ups (core 1.1 and 1.3) already reach people who are signed in. Update 0.7.0 by extracting the zip over the folder, then run the database update. It adds one table. Nothing is removed.

## What is chased

| What | Late from | Who acts |
|---|---|---|
| An adjustment waiting for approval | when it was raised | approvers at the warehouse (never the one who raised it) |
| A count waiting for approval | when it was submitted | approvers at the warehouse (never the one who submitted it) |
| A transfer on the road, not received | when it left | receivers at the warehouse it goes to |
| A sales order past the date the customer asked | that date | pick, pack and dispatch at the warehouse |
| A purchase order past its expected date | that date | purchase orders, at the warehouse |
| A shipment dispatched with no delivery recorded | when it left | pick, pack and dispatch at the warehouse |

## How

- **Working days, the organisation's own.** New settings:
  - **Working days**: 1 = Monday … 7 = Sunday; default Monday to Friday;
  - **Public holidays**: dates;
  - **Chase late work after**: default 2 working days.
- **After that many working days**, the people who should act get a message: "Waiting, 3 working days: Adjustment … (LSK)".
- **After twice as many**, it is **escalated to their managers**: people who hold the same duty at every warehouse, or the account owner if nobody does. They get a message: "Overdue, 4 working days: …".
- Messages pop up (core 1.1), and are copied to e-mail through the organisation's own sender (core 1.4).
- **Each level is sent once per item.** The daily run sends what is due, and stops as soon as the work is done.

## Where to see it

- **Know > Late and overdue:** everything late at your warehouses: what it is, its number, since when, how many working days, and who has been told (reminded, or managers).
- **The dashboard** has a new figure, **Late and overdue**.

## Tested

85 acceptance tests pass. Two are new:

- E4-1: working days, with Saturdays and a public holiday;
- E4-2: an adjustment waiting. Not late on the day. Reminded after two working days to the Lusaka approvers, never to the one who raised it nor to Kitwe. Each level only once. Escalated to managers at every warehouse later. Gone from the list once approved.

**On a fresh demo, with documents set back in time:**

- the daily run told 8 people;
- Late and overdue lists them with who was told;
- the reminder shows in Messages;
- the dashboard figure shows;
- a wrong working day is refused in Settings.

**The page walk:** four people, about 390 pages, no errors. The update from 0.7.0 creates the table.
