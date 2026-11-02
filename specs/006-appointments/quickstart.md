# Quickstart Validation: Appointments

**Feature**: 006-appointments | **Date**: 2026-11-01

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/appointments.openapi.yaml](contracts/appointments.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic. Dates are examples: pick a Sunday and
a Tuesday in the coming weeks. All times are clinic time (Africa/Cairo).

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=appointments
```

Expected: `create_appointments_table` shows `Ran`; earlier migrations unchanged; eight routes
(six under `/appointments`, two under `/doctors/me/appointments`).

## Q2 — Tokens, a doctor, a schedule, two patients

Reuse feature 005 quickstart Q2 (`B`, `J`, `signin`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`) and
give the doctor `sunday 09:00–10:00` and `tuesday 10:00–11:00` with 20-minute appointments (005
Q3). Register two synthetic patients (feature 003 quickstart) and note their ids as `P1`, `P2`.
Set `SUN` and `TUE` to the coming dates.

## Q3 — Book (US1)

```sh
book() { curl -s -w '  %{http_code}\n' -X POST $B/appointments -H "Authorization: Bearer $1" -H "$J" \
  -d "{\"patient_id\":$2,\"doctor_id\":$DOC,\"date\":\"$3\",\"start\":\"$4\",\"note\":\"Synthetic first visit\"}"; }
book $RECEPTION $P1 $SUN 09:20
curl -s "$B/doctors/$DOC/slots?from=$SUN&to=$SUN" -H "Authorization: Bearer $RECEPTION"
```

Expected: `201` with `status: scheduled`, `end: 09:40`, the patient `{id, full_name, phone}`, and
the doctor; the slots list now has only `09:00` and `09:40`. Then:

- `book $RECEPTION $P2 $SUN 09:20` → `409`, `reason: slot_booked`;
- `book $RECEPTION $P1 $TUE 10:00` → `201` (same patient, other day);
- `book $RECEPTION $P2 $SUN 09:30` (off the grid), `08:00` (outside hours), yesterday → `422` on
  `start`;
- `patient_id: 999999` → `422` on `patient_id`;
- `book $DOCTOR $P2 $SUN 09:00` → `403`.

For `patient_busy`, give a second doctor Sunday hours and book `P1` with them at `09:20` → `409`,
`reason: patient_busy`.

## Q4 — Find and view (US2)

```sh
curl -s "$B/appointments?from=$SUN&to=$TUE&doctor_id=$DOC" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/appointments?from=$SUN&to=$TUE&patient_id=$P1&status=scheduled" -H "Authorization: Bearer $RECEPTION"
curl -s $B/appointments/<id> -H "Authorization: Bearer $RECEPTION"
```

Expected: appointments in date and time order with `outside_availability: false`; no `note`,
national ID, address, or emergency contact in lists; the single view adds `note`, `booked_by`,
and `booked_at`. A range longer than 92 days → `422`; an unknown id → `404`.

## Q5 — Outside availability (FR-018)

Record a whole-day time off for `SUN` (005 Q5), then:

```sh
curl -s "$B/appointments?from=$SUN&to=$SUN&outside_availability=1" -H "Authorization: Bearer $RECEPTION"
```

Expected: the time off was saved (`201`); the Sunday 09:20 appointment is listed with its times
unchanged and `outside_availability: true`. Remove the time off: it is no longer listed with
`outside_availability=1`. Change the doctor's appointment length to 30 minutes: the
appointment stays `false`, and Sunday slots skip any slot overlapping `09:20–09:40`.

## Q6 — Reschedule, cancel, no-show (US3, US4)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/appointments/<id>/reschedule -H "Authorization: Bearer $RECEPTION" -H "$J" -d "{\"date\":\"$TUE\",\"start\":\"10:20\"}"
curl -s -w '  %{http_code}\n' -X POST $B/appointments/<id>/cancel -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"reason":"Synthetic reason"}'
curl -s -w '  %{http_code}\n' -X POST $B/appointments/<id>/cancel -H "Authorization: Bearer $RECEPTION"
```

Expected: `200` with the new date and times and `rescheduled_by`/`rescheduled_at`; the Sunday
09:20 slot is offered again; cancel → `200`, `status: cancelled`, reason kept, slot offered again;
cancelling again → `422` (`not_changeable`). Marking a future appointment as no-show → `422`
(`not_started`); a past one is covered by the automated tests (they move the clock).

## Q7 — Doctor's own appointments (US5)

```sh
curl -s -w '  %{http_code}\n' "$B/doctors/me/appointments?from=$SUN&to=$TUE" -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' $B/doctors/me/appointments/<other doctor's id> -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' "$B/appointments?from=$SUN&to=$TUE" -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' $B/patients/$P1 -H "Authorization: Bearer $DOCTOR"
```

Expected: `200` with only this doctor's appointments and the patient as
`{id, full_name, sex, date_of_birth}`; `404`; `403`; `403` (Q1: no patient-record access).

## Q8 — Listing speed (SC-005)

Against the **test** database (`clinic_testing`) only:

```sh
T="docker compose exec -e DB_DATABASE=clinic_testing app"
$T php artisan migrate --force
$T php artisan tinker --execute '
  App\Modules\Appointments\Models\Appointment::factory()->count(100000)->create();
  $r = app(App\Modules\Appointments\Repositories\Contracts\AppointmentRepository::class);
  $a = App\Modules\Appointments\Models\Appointment::query()->inRandomOrder()->first();
  $t = microtime(true); $r->paginate(["from" => $a->date->toDateString(), "to" => $a->date->toDateString(), "doctor_id" => $a->doctor_id], 100);
  printf("doctor day %.3fs\n", microtime(true) - $t);
  $t = microtime(true); $r->paginate(["from" => "2026-01-01", "to" => "2026-04-01", "patient_id" => $a->patient_id], 100);
  printf("patient history %.3fs\n", microtime(true) - $t);'
```

Expected: both under 2 seconds. Afterwards `$T php artisan migrate:fresh --force`
(`clinic_testing` only). The exact factory states and filter keys follow the implementation.

## Q9 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app php artisan test --group=concurrency
docker compose exec app vendor/bin/pint --test
```

Expected: all tests exit `0`, including the concurrency group (exactly one booking per slot and
per patient time), feature 005's slot tests unchanged, and the message tests in both locales;
Pint exits `0`.
