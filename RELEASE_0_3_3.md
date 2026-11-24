# Chatu Warehouse 0.3.3: platform console tidy-up

24 November 2026. Carries **core 1.1 and 1.5** of ChatuERP, as 0.3.2 did. It updates 0.3.x in place: extract the zip over the folder. There is no database change, so no update is needed.

## What changed

- **Platform console, choices side by side.** On an organisation's page (its modules) and on Platform > Plans (a plan's code, name, price and modules), the choices now sit side by side, three to a row on a wide screen, instead of each taking a full line. They still stack on a phone.
- **"Trial ends" only while on trial.** An organisation's page showed the trial's end date even after it became active. It now shows only while the organisation is on trial.

Both were found while updating the Warehouse manuals with new screenshots.

## Tests

61 pass (`php spark wms:test`). The console pages were checked in the browser.
