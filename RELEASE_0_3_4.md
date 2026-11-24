# Chatu Warehouse 0.3.4: forgotten password, reset by e-mail

24 November 2026. Carries **core 1.1 and 1.5**, and the new shared requirement **B12** (core 1.5.1 of `claude/CHATUERP_CORE.md`). It updates 0.3.3 in place: extract the zip over the folder, then press **Update the database**. There is one step: the table of password links.

## New: "Forgotten your password?"

1. The sign-in page has a new link, **Forgotten your password?**
2. The person gives their username or e-mail address.
3. A link is e-mailed to the address on their account. It works once, for 60 minutes.
4. The link opens a page to choose a new password (at least 10 characters, with letters and numbers).
5. They sign in with the new password. A second e-mail tells them the password was changed.

## The rules

- **The answer is always the same,** whether or not the account exists, so nobody can use the page to find out who has an account.
- **Only the newest link works.** Asking again retires the earlier links.
- **Limits:** at most 3 links an hour for one account, and 10 requests an hour from one address.
- **The link itself is never kept,** only its fingerprint (SHA-256).
- **A new password clears the lock** after too many wrong passwords.
- **Open sign-ins are ended.** Anything signed in with the old password is signed out at its next page. This relies on "One sign-in per account" being on (the default).
- **No link is sent** to an account that is switched off, or that has no e-mail address. An administrator sets those passwords, as before.
- **The audit log** records each request (sent or refused, and why) and each password set with a link.

## What it needs

- **A sender address.** The link is account mail, so it goes out through the installation's own mailer: the sender address and SMTP details already used for staff e-mails. Without a sender address, the page tells people to ask their administrator instead.
- **E-mail addresses on people's accounts.** Check them under People before telling staff about the link.

## Checks

- **Automated tests:** 63 pass. Two are new:
  - RST-1: who gets a link, the limits, and that the link is not kept.
  - RST-2: the link sets the password once, with the rules, the lock, open sign-ins, old links and the audit log.
- **Update from 0.3.3:** a demo install, files copied over, update run.
- **End to end over HTTP,** with a local mail catcher on the real SMTP path:
  - an unknown name and a real account get the same page;
  - the e-mail arrives and its link opens the form;
  - mismatched and weak passwords are refused;
  - a good one is saved, and the old session is signed out;
  - the old password fails and the new one works;
  - a used link and a made-up link are refused.
- **Not checked here:** delivery through a real mail server to a real inbox. This workspace has none. Send yourself a link on the live server before telling staff.
