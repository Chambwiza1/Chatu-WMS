# Chatu Warehouse 0.3.7: a sign-up form that sends a request (core 1.5.4)

27 November 2026. **Core 1.5.4**: sign-up by the customer, the same code in ChatuPass, ChatuQR-Menu, ChatuPOS and Chatu Warehouse. Update 0.3.6 by extracting the zip over the folder, then run the database update (`php spark migrate --all`, or the update button). Nothing is removed.

## What is new

The sign-in page has **New here? Ask for an account**, at `/signup`. Chatu Warehouse is set up with you, so the page does not create anything by itself: it sums up what the customer needs and sends you a request.

The customer gives the organisation's name, their own name, e-mail and phone, and answers:

- how many warehouses or stores, and how many people will sign in;
- about how many stock items;
- whether they make or pack products (production) and run their own delivery vehicles (fleet);
- what they use today, when they want to start, and anything else.

They get an e-mail thanking them. **You get an e-mail with the answers**, and the request waits under Platform > Sign-ups, with a count in the menu. No organisation and no sign-in is created. The page works even before e-mail is set up; the request is still listed.

## For the platform owner

- **Platform > Sign-ups** lists every sign-up with the answers, newest first, and filters by status.
- On a request: create the organisation with **New organisation**, then press **Done** (with a note if you like), or **Dismiss** with a reason.
- **Close or open the page** there at any time.
- The page exists only on the hosted service, never on a single-company installation.
- Five sign-ups an hour from one address; a hidden field catches robots.
- Sign-ups are in the platform audit log.

## Tested

66 acceptance tests pass, two of them new (SGR-1, SGR-2): the form is checked, the answers kept, the customer thanked and the platform owner told, nothing created; the owner marks a request done or dismisses it with a reason, once. In Chrome with a local mail catcher: the form sent, the e-mail to the owner, the count in the menu, and the request marked done.

Not tested live: e-mail through a real mail server (a local mail catcher was used).
