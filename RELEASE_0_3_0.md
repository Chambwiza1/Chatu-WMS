# Chatu Warehouse 0.3.0: the ChatuERP core (core 1.1 and 1.5)

24 November 2026. Updates 0.2.0 and 0.2.1: copy the files over. Every page then goes to the update page; the administrator signs in and presses **Update the database** (`php spark migrate --all` does the same). Take a backup first.

**Affects:** everything underneath: who sees what, sign-in, people and roles, settings, the audit log, the scheduled job. What the warehouse screens do is unchanged.

## What's in it

- **Organisations.** One installation serves one company or many, and each organisation's data is kept apart:
  - every table carries its organisation;
  - a link can only point inside its organisation;
  - item codes, warehouse codes and document numbers are per organisation;
  - another organisation's record answers "not found".
- **The edition is chosen at install:** single company, or online service for many organisations. An updated 0.2 installation becomes a single company, with everything kept.
- **One login per person,** by username or e-mail. Someone in two organisations chooses one, and switches from the top of the page.
- **People and access, as in ChatuPOS 2.0:**
  - each organisation's own roles, to rename, change, copy or delete;
  - permissions given to one person directly;
  - one grid with Select, View, Create, Edit, Delete, Actions and Supervisor;
  - every warehouse (new ones included) or a list, per person.
- **Refusals say what is missing,** for example "Items, Create (item.create)".
- **Nobody locks the organisation out:** nobody changes their own access or a role they hold; the account owner cannot be switched off; the last person who can give access keeps it.
- **The platform console** (online service):
  - organisations and their counts, never their stock;
  - plans with limits on warehouses and people;
  - invoices and trials, paid online by mobile money (Lenco) or card (DPO);
  - Production and Fleet on, off or the organisation's choice;
  - the integrity check of every organisation;
  - the platform audit log;
  - one database update for every organisation.
- **Modules:** an organisation switches off Production or Fleet if it does not use them. A module with active recipes, or trips planned or on the road, stays on.
- **Messages pop up** on every page and stay until opened or closed, with a tone each device can mute.
- **Each organisation has its own audit chain.** On update, the 0.2 chain is checked and carried over.

## On update

- The company becomes organisation 1 and keeps its name, settings, data, documents and history.
- Everyone keeps exactly what they could do. A role for the whole company gives every warehouse; a role at a warehouse gives that warehouse.
- `item.manage`, `supplier.manage` and `customer.manage` became Select, View, Create, Edit and Delete of each list. User administration became People and Roles.
- Nobody is made account owner: the administrator gives access as before.

## Checks

- **Automated tests:** 52 pass (14 new):
  - organisations kept apart;
  - the database keys and the tenant guard;
  - roles, direct permissions and the Select column;
  - the lock-out rules and warehouses per person;
  - the platform console and the audit chains;
  - plans and limits, invoices and suspension, modules;
  - Lenco and DPO;
  - the scheduled job across organisations.
- **Update from 0.2.0:** a copy of the demo database, through the update page in a browser:
  - nobody lost or gained a permission, compared person by person;
  - the audit chains verify;
  - the stock check and the platform integrity check are clean.
- **Fresh install:** online service with the demo (two organisations).
- **Page walk:** 10 people (the platform owner, an organisation owner, a person in two organisations, managers, a storekeeper, clerks, an auditor), 783 pages; no errors and nothing from another organisation.
- **In a browser:**
  - a new organisation with a plan and Fleet off;
  - its owner adding a person and giving access on the grid;
  - the plan's warehouse limit;
  - a refusal naming the box;
  - the organisation chooser;
  - a message pop-up that stays until closed.
