# Chatu Warehouse 0.4.0: light or dark, your colours, alerts, and a menu that folds (core 1.3)

27 November 2026. **Core 1.3** for Warehouse: C1, C2, E1 and C6 of `claude/CHATUERP_GLOBAL_REQUIREMENTS_GAP.md`. Update 0.3.9 by extracting the zip over the folder, then run the database update (it adds one column, for light or dark). Nothing is removed.

## 1. Light or dark (C1)

The rule agreed for every product (core decision 1.3), and the same file as ChatuPass:

- **On your account:** My account > Light or dark: **Follow the device**, **Light** or **Dark**. It follows you to every device you sign in on. Everyone starts on "Follow the device".
- **On one device:** the button at the top (Auto, Light, Dark) changes it for that device only, for example a shared screen in a dark store. **Use my account's choice on this device** on My account hands the device back.
- The offline look-up follows the device.
- **Always as printed, whatever the theme:** labels and barcodes stay black on white so they scan, and printing is always light.

## 2. Your colours (C2)

Administration > Settings > **Colours**, for whoever keeps the settings (`admin.settings`):

- six ready palettes (Chatu green, Teal, Terracotta, Plum, Ocean, Charcoal), or **your own** main and highlight colour;
- the main colour colours buttons and links; the top bar and its dark shades are worked out from it; the highlight is the line under the top bar;
- **refused when it would not read:** the main colour must carry white text (4.5 to 1), and the highlight must stand out on the dark top bar (3 to 1). These are the same rules and palettes as ChatuPOS;
- warnings and errors keep their own colours, and printed documents stay black on white;
- every change is in the audit log. Everyone in the organisation sees it on their next page. Chatu green is the stylesheet as it always was.

## 3. Alerts pop up (E1)

What waits for a person, the same list as **Waiting for you** on Home, now pops up on every page beside the messages:

- adjustments and counts to approve;
- stock held for inspection;
- transfers on their way;
- lines to put away;
- orders to pick;
- purchase orders overdue;
- fleet papers and services due;
- expired and expiring batches;
- items to reorder;
- integrity problems.

**Who sees what:**

- each person sees only what their duties and their warehouses give them;
- an alert shows once per sign-in, and again when its number changes;
- **Open** goes to the list; **Put aside** hides it until the number changes; **×** closes it;
- overdue items (expired batches, papers past their date) sound a sharper tone.

**Settings:**

- the organisation can switch alerts off: Settings > **Alerts pop up** (default on). It is apart from **Messages pop up**;
- the sound follows **Sound with them**, and each device can still mute it;
- nobody gets alerts while signed in as someone else.

The pop-ups now scroll when there are many, instead of running off the screen.

## 4. The menu (C6)

- **Groups that fold:** Stock, In, Out, Make, Know, Lists, Set up. The group of the page you are on is open, and groups you open stay open from page to page.
- **Find in the menu:** type part of a name, with the words in any order ("order sales" finds Sales orders). Enter opens the first match, and Esc clears.
- **On a phone** the menu sits behind a **Menu** button, instead of a strip that scrolls sideways.

## Tested

72 acceptance tests pass. Three are new:

- C13-1: light or dark on the account;
- C13-2: colours, with every refusal;
- C13-3: alerts by duty and warehouse, and Put aside until the number changes.

**In Chrome**, on a fresh online-service demo, 24 checks:

- the menu: the group you are in, a group kept open, menu search, Enter;
- the alerts, and Put aside surviving a reload;
- the theme button for one device;
- the account's choice followed on a second device;
- "Follow the device" on a dark device;
- Ocean saved, with the buttons and the highlight line changing for another person;
- a too-light colour refused;
- the Menu button on a phone.

**The page walk in dark:** nine people, 741 pages, no errors.

**Not checked:** every screen in dark by eye. I looked at Home, Stock, Settings, People, the menu and the pop-ups. Tell me if a screen reads badly in dark.
