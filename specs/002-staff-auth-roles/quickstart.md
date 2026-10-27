# Quickstart Validation: Staff Authentication and Roles

**Feature**: 002-staff-auth-roles | **Date**: 2026-10-27

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details: [contracts/staff-auth.openapi.yaml](contracts/staff-auth.openapi.yaml);
fields and rules: [data-model.md](data-model.md). All data below is synthetic.

## Q1 — Install and migrate

```sh
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

Expected: the `users` and `personal_access_tokens` migrations show `Ran`; nothing else changed.

## Q2 — First administrator (US4, FR-025)

```sh
docker compose exec app php artisan staff:create-admin --name="Test Admin" --email=admin@clinic.test
```

Expected: the password is asked twice with hidden input; the command reports success. Running it
again with the same email fails with a clear message and creates nothing. `git grep -n "clinic.test"
-- database` finds nothing (no seeded credentials).

## Q3 — Sign in, profile, sign out (US1)

```sh
B=http://localhost:8080/api/v1
TOKEN=$(curl -s -X POST $B/auth/sign-in -H 'Content-Type: application/json' \
  -d '{"email":"Admin@Clinic.test ","password":"<the password>"}' | sed 's/.*"token":"\([^"]*\)".*/\1/')
curl -s $B/auth/me -H "Authorization: Bearer $TOKEN"
curl -s -o /dev/null -w '%{http_code}\n' -X POST $B/auth/sign-out -H "Authorization: Bearer $TOKEN"
curl -s -w '  %{http_code}\n' $B/auth/me -H "Authorization: Bearer $TOKEN"
```

Expected: sign-in works despite the mixed case and trailing space; `me` returns the profile with
`role: administrator` and no password; sign-out returns `204`; the last call returns `401`.

## Q4 — Generic failures and rate limiting (FR-006, FR-007)

```sh
for i in 1 2 3 4 5 6; do curl -s -X POST $B/auth/sign-in -H 'Content-Type: application/json' \
  -H 'Accept-Language: ar' -d '{"email":"nobody@clinic.test","password":"wrong-password-1"}'; echo; done
```

Expected: attempts 1–5 return `422` with the same Arabic `auth.failed` message as a wrong password
for a real account; attempt 6 returns the Arabic `auth.throttle` message with the seconds to wait.

## Q5 — Roles and staff management (US2, US3)

With an administrator token in `$TOKEN`:

```sh
curl -s -X POST $B/staff -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Test Reception","email":"reception@clinic.test","role":"receptionist","password":"reception-pass-1","password_confirmation":"reception-pass-1"}'
```

Expected: `201` with the new account. Then sign in as the receptionist and call `GET $B/staff`:
`403` with the forbidden message. As the administrator: deactivate the receptionist → their
existing token gets `401` and their sign-in fails with the generic message; reactivate → they can
sign in again. Deactivating the only administrator → `422` with the last-administrator message.

## Q6 — Automated tests

```sh
docker compose exec app composer test
```

Expected: exit `0`. The suite includes the role-by-action authorization matrix, token expiry after
8 idle hours (simulated time), deactivation and password-reset revocation, the last-administrator
rule, the `staff:create-admin` command, and en/ar messages for 401, 403, 422, and throttling.

## Q7 — Style and secrets

```sh
docker compose exec app vendor/bin/pint --test
git grep -nE "PASSWORD=.+" -- . ':!.env.example' ':!specs/'
```

Expected: Pint exits `0`; the grep finds nothing.
