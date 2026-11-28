# Chatu Warehouse 0.9.2: the plan changes when the invoice is paid; credit for unused annual time

28 November 2026. **Core 1.5.6**, the same in all six ChatuERP products. It updates 0.9.1 in place. Database: one step (press **Update the database**, or run the update command): two columns on invoices.

The owner's two decisions on core 1.5.5 (clients choose their plan and ask for their invoice):

1. **The plan changes on payment, not on the invoice.**
2. **Pro-rata credit only on annual plans and above, when the plan changes.**

## 1. The plan changes when the invoice is paid

- Choosing another plan from Billing raises the invoice at the new plan's price. The organisation **stays on its present plan** until that invoice is paid.
- When it is paid (online through Lenco or DPO, or recorded by you in the console), the plan changes in the same step.
- Until then Billing says: "Your plan changes to *plan* when invoice *number* is paid", and the invoice carries the note "The plan changes to *plan* when this is paid."
- Cancelling the unpaid invoice from the console leaves the plan as it was. Nothing has to be undone.
- A payment is never refused over a limit. If the organisation grew past the new plan between asking and paying, the plan still changes. The audit log says it is over.

## 2. Credit for time left, on invoices of 12 months or more

When an organisation **changes plan** and still has time left on a **paid invoice of 12 months or more** (12 or 24 months):

- The unused time is worked out **by the day** and taken off the new invoice.
- The **new period starts today**, not after the old one, because the old time has been credited.
- When the new invoice is paid, the organisation is paid until the end of the new period.

**Example.** A year of Plan A at 200 a month is 2,400.00. Half way through, the organisation moves to Plan B at 350 a month for a year: 4,200.00 less about 1,200.00 unused = about 3,000.00, for a year from today.

**No credit when:**

- the time left is on an invoice of **1, 3 or 6 months**: the new invoice is at full price and follows the paid period, as before;
- the organisation asks for **the same plan again**: that is a renewal, and it follows the paid period.

**A credit larger than the new invoice** (moving down from an expensive year): the invoice comes to 0.00, is settled at once, and the plan changes at once. **The rest of the credit is not refunded or carried forward.** See "To decide" below.

## What did not change

Who sees Billing, the owner's invoice at once, an administrator's or accountant's request waiting for the owner, one unpaid invoice or request at a time, and the check that a plan is big enough at the time of asking.

Invoices you issue from the console work as before: they are for the plan the organisation is on, with no credit.

## Checks

- **Automated tests:** 93 pass. The plan tests are changed for rule 1, and a new one (PR-5) covers the credit: none from 6 months; the whole year on the first day; by the day half way; none for the same plan; taken off on a change, with the new period from today; paid-until moved to the new period; a credit larger than the invoice.
- **In Chrome** (ChatuPOS and Chatu Construction, hosted, demo data): the accountant asks for a year of a bigger plan; the owner approves; the invoice shows 18,000.00 and the note; the organisation is still on its old plan and Billing says the change is waiting; the platform owner records the payment; the plan changes. Then a move down with a full year unused: credit, 0.00 to pay, settled, plan changed.
- **Update from 0.9.1:** the sign-in page opens and the two columns are there.

## To decide

- **The rest of a credit larger than the new invoice.** Now it is lost (no refund). The other ways: keep it as a balance against later invoices, or lengthen the new period to use it up. Say which you want.
- **"Annual and above"** is read as the length of the paid invoice (12 or 24 months), since plans are priced by the month. Say if you meant something else.
