# Chatu Warehouse 0.2.1: one sign-in per account, /health, aligned forms (core 1.1)

23 November 2026. Updates 0.2.0: copy the files over and press **Update the database** under Administration (`php spark migrate --all` does the same). There is one step: one sign-in per account.

**Affects:** signing in, every form, uptime monitoring. This is core 1.1 of `claude/CHATUERP_CORE.md`.

## What's in it

- **One sign-in per account.**
  - Signing in on another device or browser signs the first one out. At its next page, the first one is told why.
  - The audit log records it.
  - **Administration, Settings: One sign-in per account** (1 = on, the default; 0 = off).
- **`/health` for an uptime monitor.** It answers 200 while the scheduled job has run in the last 15 minutes, otherwise 503.
- **The expired-page message:** "That page had expired, so nothing was saved", instead of CodeIgniter's bare error page.
- **Form fields line up** in rows.

## Checks

- **Automated tests:** 38 pass (1 new, CORE11-1).
- **Update from 0.2.0:** a demo install, files copied over, migration run.
- **Over HTTP:**
  - one sign-in per account, on and off;
  - the expired-page message;
  - `/health`.
- **Page walk:** 34 pages, 0 problems.
