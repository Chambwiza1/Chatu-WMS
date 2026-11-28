# Chatu Warehouse 0.9.0: Excel exports and saved filters (core 1.9)

28 November 2026. **Core 1.9** for Warehouse: D6 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`. Update 0.8.0 by extracting the zip over the folder, then run the database update. It adds one table. Nothing is removed.

## Excel beside every CSV

Every page that offers **Download CSV** now also offers **Download Excel** (.xlsx):

- every report (Know > Reports);
- the audit log;
- the count sheet of a stock count.

What the workbook does:

- **Numbers are numbers**, so they add up in Excel.
- **Codes with a leading zero stay as typed** (0012 is not turned into 12).
- **The header row is bold and stays in view**, with a filter on it.
- **Columns are as wide as their contents.**
- **Text that looks like a formula is kept as text, never run.**

The rules are the same as for CSV: it needs the **Export data** permission, and each download is written in the audit log ("report.export", with the format and the number of rows). The workbook is built by the product itself and needs no extra software.

## Saved filters

On **Items, Stock, Batches and serials, Customers and suppliers, Sales orders, every report and the audit log**:

1. Set some filters.
2. Choose **Save these filters** and give them a name.
3. They show above the list as **Saved filters**. One click applies them again; the one in use is highlighted.

Saved filters work like this:

- **They are yours.** Others do not see them, and only you can remove them.
- **An administrator can share a filter with a role**, for example "Storekeeper". Everyone holding that role in the organisation sees it, marked with the role's name. Only an administrator removes a shared filter.
- **Saving the same name again replaces it.**
- **Exports and page numbers are never saved, only the filters.**
- **Another organisation never sees them**, even someone in both organisations who holds the same role there.

## Tested

88 acceptance tests pass. Three are new:

- **D6-1:** the workbook opens as a zip with all its parts and well-formed XML. Numbers are numbers, a leading-zero code is text, formula-looking text is text, and special characters are escaped. The header stays in view with a filter.
- **D6-2:** a filter is its owner's own. Exports and paging are dropped. The same name replaces it. A filter with nothing set is refused. A storekeeper cannot share. Someone else cannot see or remove it.
- **D6-3:** shared with Storekeeper, the storekeeper sees it with the role's name and a buyer does not. A storekeeper of the other organisation does not see it. That organisation's owner cannot share with this organisation's role or remove the filter. An administrator removes it.

**In the browser, on a fresh demo:**

- the accountant downloads the stock-on-hand report and the audit log as Excel;
- the file opens in Excel's format: checked with openpyxl and LibreOffice, with bold headers, the frozen row and the filter;
- the owner saves "Rice" on Stock and shares it with Storekeeper;
- the storekeeper sees it, cannot remove it, and one click applies it;
- the storekeeper has no Excel button, and the Excel address is refused (403);
- a buyer does not see the filter;
- the owner removes it;
- on a phone, the save form folds under the filters.

**The page walk:** seven people, no errors. It found one fault that was fixed before release: the platform audit log (where no organisation is chosen) failed with the new panel. **The update from 0.8.0** creates the table.
