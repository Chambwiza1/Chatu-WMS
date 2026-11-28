# Chatu Warehouse 0.9.1: clients choose their plan and ask for their invoice

28 November 2026. **Core 1.5.5**, the same in all six ChatuERP products. It updates 0.9.0 in place. Database: one step (press **Update the database**, or run the update command): a new table for the requests.

Asked for by the owner on 28 November: Billing for company owners and accountants; clients choose a plan from inside their account and ask for an invoice; the owner's is direct, an administrator's or accountant's waits for the owner.

## Who sees Billing

- **The account owner**, always.
- **People with "Plan and billing"** (the right `billing.manage`). The **Accountant** role gets it with this update, in every organisation.
- **People with Settings**, as before.

Everyone else gets "not allowed", and does not see Billing in the menu.

## Choosing a plan and asking for an invoice

Billing has a new part, **Choose a plan and ask for an invoice**: the plans on offer with their price a month and their limits, and the number of months to invoice (1, 3, 6, 12 or 24).

- **The account owner** presses **Issue my invoice**. The plan changes straight away and the invoice is issued at once, ready to pay online or by bank.
- **An administrator or accountant** presses **Ask for the invoice**. Nothing changes yet. The account owner gets a message, and Billing shows the request with **Approve and issue the invoice** and **Reject** (with a reason). The person who asked is told the outcome, and can take the request back while it waits.

The platform owners get a message: "*organisation* chose *plan* and raised invoice *number*".

## The rules

1. **Plans on offer** are the active plans with a price. Until you add plans (Platform > Plans), clients see "No plans are on offer yet".
2. **A plan too small is refused**, with the numbers: "You have 4 people; Starter allows 3."
3. **One thing at a time.** No new request while one waits, or while an invoice is unpaid ("Invoice INV-… is not paid yet. Pay it first, or ask Chatu Innovations to cancel it").
4. **The plan changes when the invoice is issued**, not when it is paid. An upgrade is usable at once; an unpaid invoice goes overdue and suspends as any other does.
5. **The invoice runs from the day after the paid-until date**, as invoices from the console do. There is no part-month credit when a plan changes in the middle of a paid period.
6. **Only the account owner approves or rejects.** An administrator cannot approve a request, their own or anyone's.
7. **A suspended organisation can still do all of this**, so it can choose a plan and pay.

## Checks

- **Automated tests:** 92 pass, 4 of them new (PR-1 to PR-4): who may use Billing; the owner's invoice at once; a request waiting, then approved; rejected with a reason, cancelled, and closed to another organisation.
- **In Chrome** (ChatuPOS and Chatu Construction, hosted edition with demo data): someone without the right is refused; the accountant asks and sees the request waiting; the owner approves and lands on the invoice (3 months of Standard = 1,800.00); no second request while it is unpaid; the owner's own invoice at once; no security-policy block.
- **Update from 0.9.0** and **a fresh install** from the built zip: the sign-in page opens, the new table is there, and the right is on the roles named above.

## Decide when you see it

- Rule 4 (plan changes when the invoice is issued) and rule 5 (no part-month credit) are my choices; say if you want either the other way.
- Months offered are 1, 3, 6, 12 and 24, with no discount for longer periods.
