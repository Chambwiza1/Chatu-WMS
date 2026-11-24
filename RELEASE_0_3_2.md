# Chatu Warehouse 0.3.2: idle sign-out, message settings, a demo owner

24 November 2026. Updates 0.3.0 and 0.3.1: copy the files over. No database step.

**Affects:** signing out after idle time, Administration > Settings, the demo.

## Fixes

- **Idle sign-out works again with the offline look-up on.** The browser refreshes its stock snapshot in the background. Each refresh counted as activity, so a signed-in page left open never signed out. The snapshot refresh and the message check no longer count as activity (E1). Checked over HTTP: the session's last activity stays the same after both, and moves on after a page is opened.
- **Message pop-ups can be switched off for the organisation** (E1, E2): under Administration > Settings, "Messages pop up" and "Sound with them". Each device can still mute the sound.
- **The demo company has an account owner:** dora (Director / owner). Billing notices for Demo Distributors now reach someone. When the demo is loaded into an organisation that already has an owner (single-company install), that owner stays the only one.

## Checks

- **Automated tests:** 61 pass.
- **HTTP checks:** the idle check, plus the 22 earlier checks.
- **Page walk:** 12 people, no problems.
