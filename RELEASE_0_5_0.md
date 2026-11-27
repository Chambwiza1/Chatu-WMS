# Chatu Warehouse 0.5.0: the outbox, with your own e-mail and WhatsApp (core 1.4)

27 November 2026. **Core 1.4** for Warehouse: E5 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`, under the rule agreed on 23 November: **an organisation's messages go out from its own address and number, not the platform's.** Update 0.4.0 by extracting the zip over the folder, then run the database update. It adds one table, the outbox. Nothing is removed.

## 1. Administration > Messages

A new page, for whoever keeps the settings (`admin.settings`). It is in the menu under Set up as **Messages sent**.

**E-mail:** your own sending address, sender name and reply-to, with your own mail server (SMTP: server, port, user, password and security).

- The password is kept encrypted and never shown again.
- In the audit log it shows only as `***`.

**WhatsApp:** your own WhatsApp Business number from Meta's set-up, or none. There is no Chatu number.

- You give the phone number id, a permanent access token and the app secret. The token and secret are kept encrypted.
- Messages use one approved template, `chatu_notice` (the same as ChatuPass), or your own. It has two parts: your name, and what happened.
- A number already used by another organisation is refused.
- The page shows the webhook address to give Meta. Delivery reports must be signed with your app secret, and a report only moves a message forward (sent, delivered, read).

**Send a test:** to an e-mail address or a phone number, sent at once, with the reason when it fails.

**Sent and waiting:** every message, filtered by status, channel and recipient.

- **Try again** works on one that failed (its text is kept for a week).
- **Cancel** works on one still waiting.

## 2. What goes out

- **Copies to your people.** Each message someone gets in Warehouse (an adjustment to approve, a transfer arriving) is copied to their e-mail, as before. It now comes from your own address.
- **Notices to customers (new).** When their goods leave the warehouse, and when they are delivered:
  - by e-mail to the customer's address;
  - by WhatsApp to their phone (a Zambian 0977 123456 becomes 260977123456).

  For example: "Order SO-2026-00003 has left our Lusaka warehouse: 1 package. Delivery note SH-2026-00003." On by default; switch it off under Customers on the same page. The shipment page lists what was sent and whether it arrived.

## 3. Before your own e-mail is set up

- **Online service:** e-mail to your people **waits** in the outbox. Its tries are not used up. Your administrators get an alert, "Messages waiting for your sender". Once the e-mail is saved, the waiting messages go out at the next run.
- **Customer notices** are only made on a channel you have set up, so a customer never gets "your goods left" days late.
- **Single-company edition:** nothing changes. The installation's mail settings count as your own, so messages go out through them as before.
- **The installation's own mailer** now sends only the platform's mail and account mail: password resets, plan, invoices and trial notices to the account owner, and the notice when support signs in.

## 4. How sending works

The same outbox as ChatuPass.

- The scheduled job sends what is due for each organisation.
- Each run claims its messages first, so two runs never send one twice.
- A temporary failure is tried again after 1, 5, 15 and 60 minutes (5 tries in all).
- A permanent one fails at once (not on WhatsApp, template missing, token expired).
- The text is kept encrypted, and wiped once sent or cancelled, or a week after it failed for good. Rows go after 12 months.
- For trying an installation out before Meta approves the template, the installation setting **WhatsApp: live or log** writes WhatsApp messages to the log instead (Platform settings, or Settings in the single-company edition). The webhook word is set there too.

## Tested

75 acceptance tests pass. Three are new:

- E5-1: e-mail waits for the organisation's own sender, with an alert, then goes out from its address. Plan and invoice messages stay with the installation's mailer, secrets are encrypted, and the single-company edition is unchanged.
- E5-2: retries, permanent failures, claims, Try again and Cancel.
- E5-3: customers told on dispatch and delivery by e-mail and WhatsApp. Signed delivery reports, never backwards, and the switch.

**In Chrome**, on a fresh online-service demo:

- the page;
- e-mail refused without a server, then saved, with the password kept and not shown;
- WhatsApp saved, and a test sent (log only);
- a shipment dispatched, with the two customer notices listed on it and sent by the scheduled job.

**The page walk:** five people, 500 pages, no errors. The update from 0.4.0 creates the outbox.

**Not tried:** a real mail server or Meta's live API, as this workspace has no network access to them. Send a test from Administration > Messages once your own server and number are in.
