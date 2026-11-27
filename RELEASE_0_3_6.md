# Chatu Warehouse 0.3.6: core 1.5.3

27 November 2026. **Core 1.5.3**, a small change to the shared ChatuERP core, first made in ChatuPass 0.8.0 and now the same in ChatuPOS, ChatuQR-Menu, Chatu Warehouse and ChatuPass. It updates 0.3.5 in place: extract the zip over the folder. There is no database step, and no screen changes.

## What changed

1. **An invoice the product raises itself.** `Billing::issue()` takes one more argument, `$system`. When it is true, the invoice is issued with no platform user signed in, for a purchase an organisation makes by itself (ChatuPass uses it for the one-event pass). Chatu Warehouse does not use it yet. Left out, it is false and nothing changes: only the platform owner issues invoices.
2. **Updates ask the database which columns exist.** The update helper asked CodeIgniter whether a column was there, and CodeIgniter answers from a list it remembers per connection. After one update step added a column, a later step in the same run could get a stale answer. It now asks the database itself, as ChatuPOS has done since beta19.
3. **A test that failed by chance is fixed.** The forgotten-password test (RST-2) built a wrong link by changing the first character to "x". When the real link already began with "x", the "wrong" link was the right one and the test failed, about once in 60 runs. The product was not at fault.

## The shared files

`Platform\Billing`, `Platform\Pay` and `Platform\Modules` are again identical, byte for byte, in ChatuPOS, ChatuQR-Menu, Chatu Warehouse and ChatuPass.

## Tested

64 acceptance tests pass, one of them new. A new test (C15-6) checks that an organisation's own user is still refused an ordinary invoice, and that an invoice raised by the product is issued and recorded against them. The test fails when the new argument is ignored.

Not tested live: Lenco and DPO (their servers cannot be reached from the build workspace).
