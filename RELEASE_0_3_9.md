# Chatu Warehouse 0.3.9: who works where (core 1.2)

27 November 2026. Finishes **core 1.2** for Warehouse with B4, "who works where" (`claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`). Update 0.3.8 by extracting the zip over the folder. There is no database change.

## Who works where

**Set up > Who works where** (also from People and access) shows, for the people who are switched on:

- a card for each warehouse with how many people work there;
- a grid of every person against every warehouse, with their roles. A tick means it is one of their usual warehouses. People who work at **every warehouse** (the account owner, and anyone set to "every warehouse") are shown as such across the row;
- who works at **no** warehouse, so nobody is left out by mistake.

It needs **People, View** (`user.view`).

## Giving a warehouse its team

Open a warehouse from that page, or press **Team** on the warehouse's own page under Warehouses and bins.

- **Works here:** the people whose usual warehouses include it, with their roles. **Take off** removes it from their usual warehouses. Their other warehouses and roles stay.
- **Add people:** tick anyone else and press **Add to the team**.
- People at every warehouse are listed apart; change that on their own page.

The rules are those of a person's own page:

- it needs **People, Edit** (`user.update`);
- you cannot change your own access;
- only at a warehouse that is one of yours;
- the organisation always keeps someone who can change people's access.

What someone may do at a warehouse still comes from their roles and direct permissions. Each change is in the audit log as a change to that person's access. You are told if someone is left with no warehouse.

## Also

The left menu now marks only the page you are on. Before, "Administration" was marked too on the pages under it.

## Tested

69 acceptance tests pass. One is new: B4-1, who works where and a warehouse's team, with every refusal.

In Chrome, on a fresh online-service demo:

- the menu entry and the grid;
- every-warehouse people shown apart;
- a warehouse card opening its team;
- Steve added to Kitwe, shown at two warehouses, then taken off;
- a storekeeper refused, with the permission named.
