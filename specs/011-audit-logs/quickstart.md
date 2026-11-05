# Quickstart Validation: Audit Logs

**Feature**: 011-audit-logs | **Date**: 2026-11-05

Run from the repository root with the development stack up (see `README.md`). Set `B` from the port the stack really publishes (`docker compose port web 80`), since another service may hold `APP_PORT`. Commands are POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/audit.openapi.yaml](contracts/audit.openapi.yaml); actions and rules: [data-model.md](data-model.md). All data below is synthetic.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=audit-entries
```

Expected: the `create_audit_entries_table` migration shows `Ran`; earlier migrations are unchanged; only `GET` is listed.

## Q2 — Setup

Reuse the setup from feature 009 quickstart Q2–Q4 (`B`, `J`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`, patient `P1`), with `C1` a charge that has at least `50.00` unpaid. A development database may hold earlier entries, so read the newest entries or filter by a new record.

Search shorthand:

```sh
audit() { curl -s -w '  %{http_code}\n' "$B/audit-entries?$1" -H "Authorization: Bearer $ADMIN"; }
```

## Q3 — Who changed a record (US1)

```sh
curl -s -X PUT $B/charges/$C1/discount -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"50.00","reason":"Synthetic reason PLANTED-REASON"}'
curl -s -X PATCH $B/patients/$P1 -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"phone":"01000000099"}'
curl -s -X PATCH $B/patients/$P1 -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"phone":"01000000099"}'
audit "subject_type=charge&subject_id=$C1&action=discount.set"
audit "subject_type=patient&subject_id=$P1&action=patient.updated"
```

Expected: `200`. The discount entry names the administrator, with `discount` from and to, and has `discount_reason` in `fields` but no `PLANTED-REASON`. The patient shows **one** `patient.updated`, not two, since the second update changed nothing; `phone` is in `fields` and the number appears nowhere. The second update still returned the whole record, so it adds one `patient.opened`.

## Q4 — Refused and failed changes

```sh
curl -s -o /dev/null -w '%{http_code}\n' -X PUT $B/charges/$C1/discount -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"10.00","reason":"Synthetic"}'
curl -s -o /dev/null -w '%{http_code}\n' -X PUT $B/charges/$C1/discount -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"99999.00","reason":"Synthetic"}'
audit "subject_type=charge&subject_id=$C1&action=discount.set"
```

Expected: `403`, `422`, and the same single discount entry as in Q3.

## Q5 — Who opened a record (US2)

Use the doctor's finalized record `E1` from feature 008's quickstart, on patient `P1`, and a second doctor's token `DOCTOR2` with no access to it.

```sh
curl -s -o /dev/null -w '%{http_code}\n' $B/patients/$P1 -H "Authorization: Bearer $RECEPTION"
curl -s -o /dev/null -w '%{http_code}\n' $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR"
curl -s -o /dev/null -w '%{http_code}\n' $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR2"
curl -s -o /dev/null -w '%{http_code}\n' $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $RECEPTION"
curl -s -o /dev/null -w '%{http_code}\n' $B/doctors/me/encounters/999999 -H "Authorization: Bearer $DOCTOR2"
audit "patient_id=$P1&action=encounter.opened"
audit "patient_id=$P1&action=patient.opened"
```

Expected: `200`, `200`, `404`, `403`, `404`. `encounter.opened` shows one `succeeded` and two `refused`; nothing is recorded for `999999`. No clinical text appears in any response.

## Q6 — A staff member's activity (US3)

```sh
curl -s -o /dev/null -w '%{http_code}\n' -X POST $B/auth/sign-in -H "$J" -d '{"email":"<reception email>","password":"wrong-PLANTED-PASSWORD"}'
curl -s -o /dev/null -w '%{http_code}\n' -X POST $B/auth/sign-in -H "$J" -d '{"email":"nobody-planted@example.test","password":"wrong-PLANTED-PASSWORD"}'
audit "staff_id=<reception id>&action=auth.sign_in&outcome=refused"
audit "action=auth.sign_in&outcome=refused"
```

Expected: `422` twice; both attempts have `refused` entries, the first naming the receptionist and the second with `actor.type` `unknown`. Neither `PLANTED` value appears, nor does the `nobody-planted` email. Then repeat the wrong sign-in past the limit: the attempts stopped by the limit add no entries.

## Q7 — Cursor and searches

```sh
audit "per_page=2"
# Request the same URL again with the returned meta.next_cursor as cursor.
audit "action=audit.searched&per_page=1"
```

Expected: the second page continues exactly where the first ended, with nothing repeated, although searches added new entries. `audit.searched` shows the filters used.

## Q8 — Access and languages

```sh
curl -s -o /dev/null -w '%{http_code}\n' $B/audit-entries -H "Authorization: Bearer $RECEPTION"
curl -s -o /dev/null -w '%{http_code}\n' "$B/audit-entries?from=bad" -H "Authorization: Bearer $DOCTOR"
curl -s -o /dev/null -w '%{http_code}\n' $B/audit-entries
curl -s -o /dev/null -w '%{http_code}\n' "$B/audit-entries?from=2026-11-05&to=2026-11-01" -H "Authorization: Bearer $ADMIN"
curl -s -H 'Accept-Language: ar' "$B/audit-entries?from=bad" -H "Authorization: Bearer $ADMIN"
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE $B/audit-entries/1 -H "Authorization: Bearer $ADMIN"
```

Expected: `403`, `403`, `401`, `422`, an Arabic message with the same structure, and `404` or `405` for the delete. The first-administrator command is checked by `AuditCommandTest`, not here, so that no extra administrator is created on a development database.

## Q9 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app php artisan test --group=concurrency
docker compose exec app ./vendor/bin/pint --test
postman spec lint specs/011-audit-logs/contracts/audit.openapi.yaml --issueType=syntax
```

Expected: every test passes, including all earlier features' tests unchanged. Pint reports `PASS`, and the contract reports no issues.

## Q10 — Performance (SC-002, SC-007)

Run on `clinic_testing` only with 5,000,000 synthetic entries, removed afterwards. Measure `search` three times each by patient, by record, by staff member, with no filter, with `outcome=refused` alone, and with a date range alone; and time patient updates with and without the entry.

Expected: under 2 s for each search, and under 100 ms added to the action. A miss is recorded as observed.
