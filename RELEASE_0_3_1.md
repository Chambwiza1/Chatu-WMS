# Chatu Warehouse 0.3.1: core points verified, five fixes

24 November 2026. Updates 0.3.0: copy the files over. No database step: the version is updated on the first page.

**Affects:** messages, roles, people's pages, links to another organisation's records, the platform pages.

Every agreed ChatuERP core point carried by Warehouse was checked in four ways: automated tests, HTTP checks, the browser, and a page walk. The results are in `warehouse/VERIFICATION_0_3_1.md` in the project. The checks found five faults, fixed here.

## Fixes

- **E2: opening a record's page now marks the messages about it as read.** List pages still mark nothing as read. 0.3.0 had the rule in the library, but no page called it.
- **A copied role keeps the template it came from.** Before this, copying a role (for example, Accountant to "Finance clerk") escaped the duties kept apart (segregation of duties): a storekeeper could hold the copy. The same fault is in ChatuPOS's `Admin\Roles::copy`.
- **Another organisation's warehouse, item, supplier or customer now answers "not found",** also when you change it. Before, a warehouse page answered "not one of yours", and a change could stop at a code check first.
- **A platform page opened by someone who isn't the platform owner** now answers "not found". Before, it failed with an error page, because the layout expected the warehouse switcher.
- **A person's own page** no longer offers "Switch off in this organisation" or "New password". Both were refused anyway; the password is changed under My account. The account owner's page no longer offers "Switch off" either.

## Checks

- **Automated tests:** 61 pass (9 new: messages, shared logins, settings layers, duties kept apart, roles, invoices, trials, plan modules, the integrity rules).
- **HTTP checks:** 22 pass.
- **Browser:** 41 checks pass, including:
  - the web installer;
  - one update for every organisation;
  - plans, invoices and modules;
  - the role grid;
  - the pop-up rules.
- **Page walk:** 12 people, 993 pages, no problems.
