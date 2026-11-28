# Chatu Warehouse 0.7.0: one search rule, type-ahead pickers, bulk edit (core 1.7)

28 November 2026. **Core 1.7** for Warehouse: D1, D2 and D4 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`, on ChatuPOS's model. D5, imports that check every row (all or nothing, with a template), Warehouse already had. Update 0.6.0 by extracting the zip over the folder. There is no database change.

## 1. One search rule for every list (D1)

Every search box in Warehouse now follows the same rule:

- **Words in any order:** "bag rice" finds "Rice 5 kg bag".
- **Part of a code:** "ICE5" finds RICE5.
- **Every word must be found**, each in any of the list's columns: "rice sugar" finds nothing.
- **% and _ are letters**, not "anything".
- **An exact barcode** finds its item as a whole. On the items list, a pack's barcode does too.

This applies to:

- the items list (code, name, description);
- customers and suppliers (code, name, contact, phone, e-mail);
- sales orders (number, customer, customer's reference);
- batches and serial numbers;
- stock;
- quarantine;
- the stock picker on a new adjustment (also an exact bin code);
- the on-hand and movements reports.

One shared function does it: `Core\Find`.

## 2. Type-ahead pickers (D2)

The item box on every line editor now lists matching items as you type. That covers purchase orders, goods received, sales orders, transfers, adjustments, counts and recipes.

- It matches words in any order, part of a code, an item's barcode and a pack's barcode.
- **Arrows and Enter** or a tap pick one. The cursor then moves on to the unit, so a line goes in without the mouse.
- **A scanner never opens the list.** Keys faster than anyone types, ending in Enter, take an exact code or barcode straight away.
- **Enter in an item box never sends the form** by mistake.

The browser's own list, which only matched from the start of a name and showed every item, is gone.

The customer and supplier lists on sales orders, purchase orders and goods received get a box above them. It narrows the list as you type, and picks the customer or supplier when one is left.

## 3. Bulk edit on screen (D4)

On the items list, tick items, then open **Change the ticked items**. You can change:

- the category;
- switched on or off (off needs Items, Delete);
- reorder at, and reorder quantity;
- the selling price: set to, up or down by a %, or up or down by an amount;
- the minimum days of shelf life on receipt.

A field left empty keeps each item's own value.

- Every item goes through the same checks as its own page, and each change is in the audit log (and on the item's Activity).
- **All or nothing:** if one item is refused (a price below nothing, say), none changes, and the message names the item.
- At most 500 items at a time. It needs Items, Edit.

## Tested

83 acceptance tests pass. Two are new:

- D1: words in any order, part of a code, every word needed, % and _ as letters, an exact barcode;
- D4: price up 10% and reorder levels on two items, each audited; nothing to change; all or nothing; the refusals.

**In Chrome**, on a fresh demo, 13 checks:

- the items list found with words in any order;
- the picker on a new purchase order: listing as you type, arrow and Enter, the cursor moving on;
- a scanned code taken without the list, and not sending the form;
- the supplier list narrowed to one;
- bulk edit of two items, with the count, the change and the list updated.

**The page walk:** six people, about 530 pages, no errors.
