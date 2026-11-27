# Chatu Warehouse 0.3.8: signing in as someone, and support access (core 1.2)

27 November 2026. **Core 1.2**, the same code as ChatuPass 0.11.0. Update 0.3.7 by extracting the zip over the folder, then run the database update. Nothing is removed, and nobody loses access.

## 1. An administrator signs in as one of their people

On a person's page under People and access: **Sign in as …**, with a required reason.

- For helping someone or seeing exactly what they see. It lasts **at most an hour**.
- A red banner shows on every page, with **Stop and go back**.
- What is saved is saved as that person. Every page that saved something is **listed** against the sign-in.
- **Never** as yourself, the account owner, or anyone who can change people or roles.
- Refused meanwhile: passwords (theirs and anyone's), payment keys, support access, and reading their messages away.
- The person can still sign in themselves; neither ends the other.
- It is a new permission, **People > Sign in as a person** (`user.signin_as`), in the Supervisor column. On update it goes to every role and person that may change roles (the System administrator and the like), and to nobody else.

## 2. Chatu support signs in, only when the organisation allows it

**Closed by default.** Nobody from Chatu can sign in to an organisation or see its records.

- The organisation opens it under **Set up > Support access**: 4 hours, 24 hours, 3 days or 7 days. It closes by itself, or with **Close it now**. This needs the right to change roles.
- While open, the platform owner sees **Sign in as the account owner** on the organisation's page in the console, with a required reason.
- The account owner is **told at once**, with the reason.
- It is recorded in **both** audit logs: the platform's and the organisation's.
- The same banner, the same list of what was saved, the same refusals. Support cannot extend its own access.
- If the organisation closes access, a support sign-in **ends at its next page**.

## 3. What the organisation sees

Set up > Support access lists every sign-in as someone: who, as whom, the reason, when it ended and how, and how many pages saved something. The number opens the list of those pages. What changed is in the audit log, under the name of the person signed in as.

## Tested

68 acceptance tests pass. Two are new (C12-2 an administrator signs in as; C12-3 support access).

In Chrome, from a fresh demo install: sign in as a member of staff, the banner, People refused, a password change refused, the person signs in herself without ending it, Stop returns, the history. Then support: refused while closed, opened for 4 hours by the organisation, signed in as the account owner, the console out of reach meanwhile, extending access refused, closed by the organisation and support out at the next page.

Update from 0.3.7 checked: each organisation's own administrator role gets the new permission.
