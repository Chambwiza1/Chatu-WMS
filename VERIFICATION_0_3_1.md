# Chatu Warehouse 0.3.1: verification of the ChatuERP core points

24 November 2026. This checks every agreed core point that Warehouse now carries, against the definitions in `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md` and `claude/CHATUERP_CORE.md`.

The checks ran on Chatu Warehouse 0.3.1 (repo Chambwiza1/Chatu-WMS, commit 3589758), on MariaDB 10.11 and PHP 8.3, in four ways:

- **T: automated tests.** `php spark wms:test`: 61 pass. Each test starts from a fresh demo and ends with the stock check of every organisation.
- **H: HTTP checks** against the running application, online-service demo with three organisations: 22 pass.
- **B: browser checks** in Chromium: 41 pass.
- **W: page walk:** 12 people, from the platform owner to a storekeeper limited to one warehouse, 993 pages. No errors, and nothing from another organisation showed.

The update from 0.2.0 was also run on a copy of the 0.2.0 demo database, through the update page. Every person's permissions were compared before and after: nobody lost or gained one. The audit chains and the stock check were clean afterwards.

## Result

All agreed points that Warehouse carries pass. The checks found five faults, all fixed in 0.3.1:

1. Opening a record's page did not mark the messages about it as read (E2).
2. A copied role escaped the duties kept apart (B6). **The same fault is in ChatuPOS** `Admin\Roles::copy`.
3. Another organisation's warehouse answered "not one of yours" instead of "not found". A change to another organisation's item could stop at a code check first.
4. Platform pages failed with an error page for people who aren't the platform owner, instead of answering 404.
5. A person's own page offered "Switch off" and "New password". The server refused both; the buttons are now gone. **ChatuPOS's people page has the same buttons.**

## Point by point

### Core decisions

| Point | Result | Evidence |
|---|---|---|
| 1.1 tenant model: `tenant_id`, `(tenant_id, id)`, composite keys, query guard, "not found" | Pass | T ORG-1, ORG-2, ORG-3. H ISO-1 to ISO-5: another organisation's item, stock page and warehouse answer 404, and so does a change to its item or a switch into an organisation you're not in. W: no cross-organisation data. |
| 1.2 one login per person, memberships, choose and switch | Pass | T USR-1: an existing login joins by e-mail; the name and password stay the person's own; switched off in one organisation, still in the other. B: the chooser, and the switcher in the header. |
| 1.5 shared billing files | Pass | `Billing`, `Pay` and `Modules` are byte-identical to ChatuPOS beta27; only `Product` differs. |
| Settings in two layers | Pass | T SET-1. |

### A. Tenancy, platform and commercial

| Point | Result | Evidence |
|---|---|---|
| A1 edition at install | Pass | B A1a to A1f: the web installer, single company. The owner signs in by e-mail and has no platform or billing menu; the console answers 404. The online-service edition was installed from the command line. |
| A2 platform console, status with reason, suspended = read-only | Pass | T PLT-1. H A2a and A2b: the console is 404 for others. H A2c to A2e: suspended shows the banner, a save is refused, Billing still opens. |
| A3 plans with limits | Pass | T C15-1: limits checked when a plan is chosen, and when a warehouse or a person is added. B: the third warehouse is refused on a two-warehouse plan. The single-company edition has no limits. |
| A4 invoices and the daily check | Pass | T C15-2 and A4: 1 to 24 months from paid-until, numbered INV-YYYYMM-NNNN, void only with a reason and only unpaid, overdue then suspended, paying reopens. B: issued and paid from the console; the owner sees it under Billing. |
| A5 trials | Pass | T A5: warned once three days before, then suspended with the data kept, then extended. B: the trial banner with the days left. |
| A6 (a) Lenco and DPO for Chatu's invoices | Pass | T C15-4 and C15-5. H A6a to A6d, the live webhook: a wrong signature gets 401; a signed call settles the invoice once ("already settled" the second time); an unknown organisation gets 404. |
| A6 (b) the organisation's own keys | n/a | Warehouse takes no payments of its own. |
| A7 3-state modules (Production, Fleet) | Pass | T C15-3 and A7: the platform rule wins, a plan sets the rules, the organisation switches what's left to it, the single-company edition counts only its own switch. B A7a to A7d: Production off leaves the menu and the grid, its page answers 404, and it comes back on. |
| A8 in use stays on | Pass | T C15-3. H A8 and A8b: refused while there is an active recipe, or a trip on the road. |
| A9 one update for every organisation | Pass | B A9a to A9g: a manager and an account owner are held on "being updated", with background calls 503. The platform owner updates every organisation at once, and both demos work again. The single-company update was run from 0.2.0. |
| A11 an audit chain per organisation | Pass | T AUD-1: a changed record breaks only its own chain. On update, the 0.2 chain is checked and sealed again. |
| A12 nightly integrity check | Pass | T A12: two owners, a role from another organisation and a platform code in a role are each found. T JOB-1: the scheduled job checks every organisation. |
| A13 `/health` | Pass | H: 503 before the scheduled job ran, 200 after. |

### B. People, access and sign-in

| Point | Result | Evidence |
|---|---|---|
| B1 own roles, direct permissions, one grid | Pass | T ACC-1 and B1. B B1a to B1e: add, tick a whole row, save, copy, delete. B: role ticks show greyed on a person's page. |
| B2 Select column | Pass | T ACC-1: `canSelect` from any of Select to Delete; Items has a Select box. The update gives Select to those whose duty picks from a list (checked person by person). |
| B3 refusals name the module and box | Pass | T ACC-1: "Items, Create (item.create)". B B3: "People, View (user.view)". |
| B5 no lock-out, no change to your own access | Pass | T ACC-2: not your own access, not a role you hold, not yourself off, the owner never off, the last person who can give access keeps it. B B5a: no such buttons on your own page. |
| B6 duties kept apart | Pass (after fix 2) | T B6: refused in enforce mode (a copied role too), allowed and listed in report mode. |
| B7 one sign-in per account, with its setting | Pass | T CORE11-1. H B7: the first session ends and is told why; the newer one goes on. |
| B11 the expired page | Pass | H B11: a page gets "That page had expired"; a background call gets 403 JSON. |

### C and E

| Point | Result | Evidence |
|---|---|---|
| C4 aligned form rows | Pass | B: the boxes in each row line up. |
| E2 pop-ups that stay | Pass (after fix 1) | T E2. B E2a to E2l, on a page and across sign-ins: the newest three, "2 more unread", nothing read by showing them, they stay on the next page, Close all hides without reading until the next sign-in, a list page reads nothing, a record page reads its own, × and Open each read only one, the tone mutes on this device. |

The 45-second check for messages does not count as activity for the idle sign-out. This was checked in the code (`AuthFilter`), not by waiting out the timer.

## Not in Warehouse yet: later core versions

These agreed points are in the plan, not in this release. Warehouse takes each core version with the other products from now on:

- B4 who works where;
- B9 "sign in as";
- C1, C2 dark mode and colours (1.3);
- C6 grouped menu with search;
- C7, C8, C10, C11, C12 search, "+", activity, galleries, dashboards;
- D1, D2, D4 to D6 search rules, pickers, bulk edit, imports, .xlsx;
- E1 pop-up alerts;
- E3 Web Push and E4 escalation;
- E5 the organisation's own outbox;
- F2, F3 offline sync, installable app.

## Notes

- Demo Distributors (the demo's first organisation) has no account owner, so billing notices for it go to the platform owner only. Organisations made from the console always have an owner.
- Fixes 2 and 5 are in shared code that came from ChatuPOS. Carry them to POS with its next release.
