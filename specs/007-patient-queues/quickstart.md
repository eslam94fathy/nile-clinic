# Quickstart Validation: Patient Queues

**Feature**: 007-patient-queues | **Date**: 2026-11-02

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/queues.openapi.yaml](contracts/queues.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic. Queues are for the clinic's **today**
(Africa/Cairo), so run this on a day the doctor works, or give the doctor hours for today's weekday
first.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=queue
docker compose exec app php artisan route:list --path=check-in
```

Expected: the queue tables and the appointments status change show `Ran`; earlier migrations
unchanged; routes for walk-ins, the clinic list, leave, undo, a doctor's list, the three
`/doctors/me/queue…` routes, and `POST /appointments/{appointment}/check-in`.

## Q2 — Tokens, a doctor working today, appointments

Reuse feature 006 quickstart Q2 (`B`, `J`, `signin`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`, two
patients `P1`, `P2`) and register a third synthetic patient `P3`. Give the doctor hours covering the
rest of today (005 Q3) with 20-minute appointments, set `TODAY` to the clinic's date, and book `P1`
at the first free slot and `P2` at the second (006 Q3); note the appointment ids `A1`, `A2`.

## Q3 — Check in (US1)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/appointments/$A2/check-in -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' -X POST $B/appointments/$A1/check-in -H "Authorization: Bearer $RECEPTION"
curl -s $B/appointments/$A1 -H "Authorization: Bearer $RECEPTION"
```

Expected: `201` each; `A2` gets queue number 1, `A1` number 2, both `waiting`, `walk_in: false`;
`A1` shows `status: checked_in`. Then:

- check in `A1` again → `422` (`not_checkable`);
- `POST $B/appointments/$A1/cancel` → `422` (`not_changeable`); the slot is still not offered by
  `/doctors/$DOC/slots`;
- check in an appointment for tomorrow → `422`;
- check in as `$DOCTOR` → `403`.

## Q4 — Walk-in and lists (US2, US4)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/queue/walk-ins -H "Authorization: Bearer $RECEPTION" -H "$J" -d "{\"patient_id\":$P3,\"doctor_id\":$DOC}"
curl -s $B/doctors/$DOC/queue -H "Authorization: Bearer $RECEPTION"
curl -s "$B/queue?status=waiting" -H "Authorization: Bearer $RECEPTION"
```

Expected: walk-in `201`, number 3, `walk_in: true`, `appointment: null`; the doctor's slots are
unchanged. The doctor's list is in queue order: `A1` (earlier appointment) before `A2`, and the
walk-in placed by its arrival time; no note or sensitive patient fields. The same walk-in again →
`422` (`already_in_queue`); `$B/doctors/999999/queue` → `404`.

## Q5 — Call next and finish (US3)

```sh
curl -s -w '  %{http_code}\n' $B/doctors/me/queue -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/queue/call-next -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/queue/call-next -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/queue/finish -H "Authorization: Bearer $DOCTOR"
curl -s $B/appointments/$A1 -H "Authorization: Bearer $RECEPTION"
```

Expected: own list with the patient as `{id, full_name, sex, date_of_birth}`; the first call returns
`A1`'s entry as `with_doctor`; the second call → `422` (`visit_in_progress`); finish → `200`,
`seen`; `A1` shows `status: completed`. `call-next` as `$RECEPTION` → `403`.

## Q6 — Leave and undo (US5)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/queue/<walk-in entry id>/leave -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' -X DELETE $B/queue/<A2 entry id> -H "Authorization: Bearer $RECEPTION"
curl -s $B/appointments/$A2 -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/queue/call-next -H "Authorization: Bearer $DOCTOR"
```

Expected: leave `200`, `left`; undo `204`; `A2` is `scheduled` again; call next → `422`
(`nobody_waiting`). Checking `A2` in again gives queue number 4 (numbers are not reused).

## Q7 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app php artisan test --group=concurrency
docker compose exec app vendor/bin/pint --test
```

Expected: all tests exit `0`, including feature 006's tests unchanged, the queue concurrency tests
(numbers 1…N without duplicates or gaps; exactly one patient called), and the message tests in both
locales; Pint exits `0`.
