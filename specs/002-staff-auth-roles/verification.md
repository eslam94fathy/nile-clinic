# Verification Results: Staff Authentication and Roles

**Feature**: 002-staff-auth-roles | **Date**: 2026-10-27 | **Task**: T053, T054

Results observed while running [quickstart.md](quickstart.md) Q1–Q7 on the development stack
(project `nile-clinic`, `http://localhost:8080`). Failures are reported as observed
(Constitution VIII). All accounts used are synthetic (`@clinic.test`).

## Environment

- Windows 11 Pro, Docker 29.6.2, Compose v5.3.1, Git Bash; branch `002-phase-8-polish`.
- Development database `clinic`; tests run against `clinic_testing`.
- The interactive `staff:create-admin` password prompts were answered over stdin
  (`docker compose exec -T -e SHELL_INTERACTIVE=1 … <<< "<password>\n<password>\n"`).

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Install and migrate | `composer install`; `migrate`; `migrate:status` | `Nothing to migrate` (applied in Phase 2); `create_users_table` and `create_personal_access_tokens_table` both `Ran` | Pass |
| Q2 First administrator | `staff:create-admin --name="Test Admin" --email=admin@clinic.test` (twice) | First run: password asked twice, `Administrator admin@clinic.test created.`, exit 0, one active administrator. Second run: `The email has already been taken.`, exit 1, still one account | Pass |
| Q2 No seeded credentials | `git grep -n "clinic.test" -- database` | **Not as written**: matches `database/factories/UserFactory.php` (the synthetic `@clinic.test` email pattern for tests). Intent checked separately: `database/seeders/DatabaseSeeder.php` is empty and the only password in `database/` is the factory's synthetic test password | Pass with caveat (see below) |
| Q3 Sign in, profile, sign out | sign-in with `"Admin@Clinic.test "`; `GET /auth/me`; `POST /auth/sign-out`; `GET /auth/me` again | Sign-in worked despite case and trailing space; profile `role: administrator`, no `password`; sign-out `204`. **First run: the last call returned `500 Route [login] not defined.`** instead of `401` | **Fail → fixed** (see below); rerun: `401 {"message":"You are not signed in."}` — Pass |
| Q4 Generic failures and rate limit | 6 × sign-in `nobody@clinic.test` with `Accept-Language: ar`; one wrong password for the real account | Attempts 1–5: `422` Arabic `auth.failed`; attempt 6: `422` Arabic `auth.throttle` with `55` seconds; wrong password for the real account: the identical Arabic `auth.failed` body | Pass |
| Q5 Roles and staff management | create receptionist; receptionist `GET /staff`; deactivate; old token; sign-in; reactivate; sign-in; deactivate the only administrator | `201` with the account; receptionist `403` "You are not allowed to perform this action."; deactivate `200`; old token `401`; sign-in while inactive `422` generic failure; reactivate `200`; sign-in works; deactivating the only administrator `422` "This change would leave the clinic without an active administrator." | Pass |
| Q6 Automated tests | `composer test` | `173 passed (556 assertions)`, exit 0 | Pass |
| Q7 Style and secrets | `vendor/bin/pint --test`; `git grep -nE "PASSWORD=.+" -- . ':!.env.example' ':!specs/'` | Pint: 93 files, exit 0; grep: no matches (exit 1) | Pass |

## Failure found and fixed: 500 instead of 401 (Q3)

- **Observed**: `GET /api/v1/auth/me` with a revoked token from plain `curl` (no
  `Accept: application/json`) returned `500` with `Route [login] not defined.` and a stack trace.
- **Cause**: for requests that do not expect JSON, Laravel's `Authenticate` middleware calls its
  default guest redirect `route('login')`; this API has no such route, so the redirect failed
  before the translated 401 renderer ran. Every automated test used `getJson()`, which always
  sends the JSON `Accept` header, so none caught it.
- **Fix** (commit `572ebfa`): `$middleware->redirectGuestsTo(null)` in `bootstrap/app.php`. A new
  `AuthErrorsTest` case (`Accept: text/html` and `*/*`, in `en` and `ar`) failed with the 500 before
  the fix and passes now. Live check after the fix: `401 application/json` with the Arabic message
  for no `Accept`, `text/html`, and `application/json`. Recorded in research R5.

## Caveat: Q2's no-credentials check

The command `git grep -n "clinic.test" -- database` matches the test factory, whose synthetic
emails end in `@clinic.test` by design (Constitution XII). That is test data, not seeded
credentials. The check's intent, no seeded or default accounts, holds: there are no seeders
with content and no fixed account credentials. The quickstart step should check for seeders
rather than the domain; it was **not** edited here.

## State left on the development stack

- Accounts: `admin@clinic.test` (administrator, password `quickstart-admin-pass-1`) and
  `reception@clinic.test` (receptionist, password `reception-pass-1`), both active, both
  synthetic. Remove or replace them as needed; they exist only in the local `clinic` database.
- Rate-limit counters from Q4 expire after 60 seconds.

## Not verified

- Concurrent last-administrator requests (two administrators deactivating each other at the same
  moment) are protected by the row lock (research R8) but were not exercised; PHPUnit runs one
  request at a time.
- The 8-hour idle expiry was verified only in automated tests with simulated time
  (`TokenExpiryTest`), not by waiting 8 hours on the stack.
