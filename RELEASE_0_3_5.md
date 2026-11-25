# Chatu Warehouse 0.3.5: "Pay by card" opens DPO's card page

25 November 2026. A fix to **core 1.5** (online payment), the same in all six ChatuERP products. It updates 0.3.4 in place: extract the zip over the folder. There is no database step.

## What was wrong

Pressing **Pay by card** on an invoice created the payment, but the card page never opened. The invoice then showed "DPO, Pending" with nowhere to type a card number.

The cause was our own security policy. It lets a form send people only to our own site. "Pay by card" is a form, and its answer tried to pass the browser straight on to DPO's page, so Chrome and Edge blocked the move without saying anything. Mobile money through Lenco was not affected, because it never leaves our site.

## What changed

1. **A step page of our own.** "Pay by card" now goes to a short page on our site ("Taking you to DPO's secure page"), which passes the browser on after a second. It has a **Continue to payment** button in case the browser waits.
2. **"Go to the card page"** next to a card payment that is still waiting on the invoice, so a person who closed the tab can get back to it without starting again.
3. **The step page passes on only a waiting card payment of ours.** A made-up, paid, failed or expired reference goes back to the invoice with "That payment is no longer waiting."

The security policy itself is unchanged: forms still post only to our own site.

## Card payments that are already waiting

Payments started before this update show "Go to the card page" too. The link works for as long as DPO still holds that payment open; if DPO's page says it has expired, start a new one. After 2 days we mark a waiting payment expired.

## Setting up DPO for testing

Under **Platform > Billing settings** (hosted service) give:

- **DPO company token:** the Company Token of the test account.
- **DPO service type:** the number DPO gives for the service sold (its "Product Service ID" or "Service Type").
- **DPO payment page:** leave as it is unless DPO gives another address. Some DPO papers show `payv3.php?ID=` in place of `payv2.php?ID=`; if the card page does not load, change it there.

## Checks

- **Automated tests:** 63 pass. The billing test now also checks the step page: a waiting card payment is passed on; a made-up reference, something that is not a reference, and a settled payment are not.
- **In Chrome,** against a stand-in for DPO (run in Chatu Construction; the payment code is the same): Pay by card reaches the card page with no security-policy block; "Go to the card page" works from the invoice; after paying, the person is back on the invoice with "Paid. Thank you." and the invoice is paid through DPO.
- **Update from 0.3.4** and **a fresh install** from the built zip: the step page answers and the sign-in page opens.
- **Not checked against DPO itself:** DPO's servers cannot be reached from the build workspace, so the last step (DPO's real card page, with test cards) is for the first test on the hosted site.
