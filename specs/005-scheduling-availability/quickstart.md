# Quickstart Validation: Scheduling and Availability

**Feature**: 005-scheduling-availability | **Date**: 2026-10-28

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/scheduling.openapi.yaml](contracts/scheduling.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic. Dates are examples: pick dates in the
coming weeks and note their weekdays. All times are clinic time (Africa/Cairo).

## Q1 — Migrate and configure

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan tinker --execute "echo config('app.clinic_timezone');"
```

Expected: `create_doctor_schedules_table`, `create_schedule_periods_table`, and
`create_doctor_time_off_table` show `Ran`; earlier migrations unchanged; the time zone prints
`Africa/Cairo`.

## Q2 — Tokens and a doctor

Use an administrator, a receptionist, and a doctor with a profile (feature 004 quickstart Q2–Q4);
note the doctor id as `DOC`.

```sh
B=http://localhost:8080/api/v1
J='Content-Type: application/json'
signin() { curl -s -X POST $B/auth/sign-in -H "$J" -H 'Accept: application/json' \
  -d "{\"email\":\"$1\",\"password\":\"$2\"}" | sed 's/.*"token":"\([^"]*\)".*/\1/'; }
ADMIN=$(signin <admin email> <password>); RECEPTION=$(signin <reception email> <password>); DOCTOR=$(signin <doctor email> <password>)
```

## Q3 — Weekly schedule (US1)

```sh
curl -s -w '  %{http_code}\n' -X PUT $B/doctors/$DOC/schedule -H "Authorization: Bearer $ADMIN" -H "$J" \
  -d '{"appointment_minutes":20,"periods":[{"day":"sunday","start":"17:00","end":"21:00"},{"day":"sunday","start":"09:00","end":"10:00"},{"day":"tuesday","start":"10:00","end":"14:00"}]}'
curl -s $B/doctors/$DOC/schedule -H "Authorization: Bearer $RECEPTION"
```

Expected: `200`; `days` has seven entries `sunday`…`saturday`; Sunday's periods are
`09:00–10:00` then `17:00–21:00` (sorted); other days empty except Tuesday. Then:

- periods `09:00–13:00` and `13:00–15:00` on one day → `422` on `periods.1` (touching);
- a period `09:00–09:10` with 20-minute appointments → `422`;
- `appointment_minutes: 7` → `422`; `start: "09:03"` → `422`;
- after each `422`, `GET` still shows the schedule from the first request;
- the same `PUT` with `$RECEPTION` or `$DOCTOR` → `403`.

## Q4 — Slots (US2)

With `SUN` the next Sunday's date:

```sh
curl -s "$B/doctors/$DOC/slots?from=$SUN&to=$SUN" -H "Authorization: Bearer $RECEPTION"
```

Expected: `09:00–09:20`, `09:20–09:40`, `09:40–10:00`, then `17:00`, `17:20`, … `20:40–21:00`
(15 slots), in order. A range starting yesterday, longer than 31 days, or ending more than 90 days
ahead → `422`. Requesting today's slots shows only slots starting after the current clinic time.

## Q5 — Time off (US3)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/doctors/$DOC/time-off -H "Authorization: Bearer $ADMIN" -H "$J" \
  -d "{\"start_date\":\"$SUN\",\"end_date\":\"$SUN\",\"start_time\":\"09:20\",\"end_time\":\"09:40\",\"reason\":\"Synthetic meeting\"}"
curl -s "$B/doctors/$DOC/slots?from=$SUN&to=$SUN" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/doctors/$DOC/time-off?from=$SUN&to=$SUN" -H "Authorization: Bearer $RECEPTION"
```

Expected: `201`; the Sunday slots no longer include `09:20–09:40` (14 left); the list shows the
entry. A whole-day entry for the following Tuesday removes all of that day's slots.
`DELETE $B/doctors/$DOC/time-off/<id>` as administrator → `204` and the slot returns; as
receptionist → `403`. A `start_date` yesterday → `422`.

## Q6 — Own schedule and inactive doctor (US4, FR-009)

```sh
curl -s -w '  %{http_code}\n' $B/doctors/me/schedule -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' "$B/doctors/me/slots?from=$SUN&to=$SUN" -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' $B/doctors/me/schedule -H "Authorization: Bearer $RECEPTION"
```

Expected: `200` with the same data as Q3/Q4 for the doctor; `403` for the receptionist. Then
deactivate the doctor's staff account (feature 002): the slots request as receptionist returns
`200` with an empty `data`; reactivate it and the slots return.

## Q7 — Slot speed (SC-002)

Against the **test** database (`clinic_testing`) only:

```sh
T="docker compose exec -e DB_DATABASE=clinic_testing app"
$T php artisan migrate --force
$T php artisan tinker --execute '
  $docs = App\Modules\Doctors\Models\Doctor::factory()->count(500)->withSpecialties()->create();
  $docs->each(fn ($d) => App\Modules\Scheduling\Models\DoctorSchedule::factory()->weekdays()->create(["doctor_id" => $d->id]));
  $s = app(App\Modules\Scheduling\Services\SlotService::class);
  $from = now(config("app.clinic_timezone"))->addDay()->toDateString();
  $to = now(config("app.clinic_timezone"))->addDays(31)->toDateString();
  $t = microtime(true); $n = count($s->forDoctor($docs->last()->id, $from, $to));
  printf("%d slots %.3fs\n", $n, microtime(true) - $t);'
```

Expected: one doctor's 31-day slots in under 2 seconds. Afterwards
`$T php artisan migrate:fresh --force` (affects `clinic_testing` only).

## Q8 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
```

Expected: tests exit `0`, including the slot calculator unit tests and the message tests in both
locales; Pint exits `0`.
