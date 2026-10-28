# Quickstart Validation: Doctors and Specialties

**Feature**: 004-doctors-specialties | **Date**: 2026-10-28

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/doctors.openapi.yaml](contracts/doctors.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

Expected: `create_specialties_table`, `create_doctors_table`, and `create_doctor_specialty_table`
show `Ran`; earlier migrations are unchanged.

## Q2 — Tokens for each role

Create a receptionist and a doctor account with an administrator token (feature 002 quickstart
Q2–Q5), note the doctor's staff id as `DOCTOR_USER_ID`, then sign in as each:

```sh
B=http://localhost:8080/api/v1
signin() { curl -s -X POST $B/auth/sign-in -H 'Content-Type: application/json' \
  -d "{\"email\":\"$1\",\"password\":\"$2\"}" | sed 's/.*"token":"\([^"]*\)".*/\1/'; }
ADMIN=$(signin admin@clinic.test admin-pass-123)
RECEPTION=$(signin reception@clinic.test reception-pass-1)
DOCTOR=$(signin doctor@clinic.test doctor-pass-12)
```

## Q3 — Specialty catalog (US1)

```sh
J='Content-Type: application/json'
curl -s -w '  %{http_code}\n' -X POST $B/specialties -H "Authorization: Bearer $ADMIN" -H "$J" \
  -d '{"name_en":"  Cardiology ","name_ar":"أمراض القلب"}'
curl -s -w '  %{http_code}\n' -X POST $B/specialties -H "Authorization: Bearer $ADMIN" -H "$J" \
  -d '{"name_en":"Pediatrics","name_ar":"طب الأطفال"}'
curl -s -w '  %{http_code}\n' -X POST $B/specialties -H "Authorization: Bearer $ADMIN" -H "$J" \
  -H 'Accept-Language: ar' -d '{"name_en":"cardiology","name_ar":"قلب"}'
curl -s "$B/specialties?is_active=1" -H "Authorization: Bearer $RECEPTION"
```

Expected: `201`, `201` (`name_en` stored as `Cardiology`); the third is `422` with an Arabic
message on `name_en`. The list shows both specialties, each with exactly `id`, `name_en`,
`name_ar`, `is_active`. `POST $B/specialties/<id>/deactivate` then `…/reactivate` as the
administrator return `200` with `is_active` false then true; the same calls with `$RECEPTION` or
`$DOCTOR` return `403`.

## Q4 — Doctor profile (US2)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/doctors -H "Authorization: Bearer $ADMIN" -H "$J" \
  -d "{\"user_id\":$DOCTOR_USER_ID,\"title\":\"Consultant\",\"registration_number\":\"A12345\",\"specialty_ids\":[<cardiology id>,<pediatrics id>]}"
```

Expected: `201`; `data.name` is the account's name; two specialties; `registration_number`
`A12345`; no `email` or `user_id` in the body. Repeating it → `422` on `user_id`. Using the
receptionist's staff id → `422` on `user_id`. Then deactivate Pediatrics and
`PATCH $B/doctors/<id>` with `{"specialty_ids":[<cardiology id>,<pediatrics id>]}` → `200`
(kept); a new doctor sent the deactivated Pediatrics → `422` on `specialty_ids`;
`{"specialty_ids":[]}` → `422`. The same `PATCH` with `$DOCTOR` → `403`.

## Q5 — Browse doctors (US3)

```sh
curl -s "$B/doctors?specialty_id=<cardiology id>" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/doctors?name=<part of the doctor's name, in a different alef/yaa spelling>" -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' $B/doctors/me -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' $B/doctors/me -H "Authorization: Bearer $RECEPTION"
```

Expected: the doctor from Q4 in both lists, each item with exactly `id`, `name`, `title`,
`is_active`, `specialties`; `/doctors/me` → `200` for the doctor and `403` for the receptionist.
`GET $B/doctors/<id>` adds `registration_number`; an unknown id → `404`.

## Q6 — Inactive doctors (FR-012)

Deactivate the doctor's staff account (feature 002), then:

```sh
curl -s "$B/doctors?is_active=1" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/doctors?is_active=0" -H "Authorization: Bearer $RECEPTION"
```

Expected: the doctor is absent from the first list and present in the second with
`is_active: false`. Reactivate the account: the doctor is active again with the same profile.

## Q7 — Listing speed at 500 doctors and 100 specialties (SC-002)

Against the **test** database (`clinic_testing`) only, never the development data:

```sh
T="docker compose exec -e DB_DATABASE=clinic_testing app"
$T php artisan migrate --force
$T php artisan tinker --execute '
  $sp = App\Modules\Doctors\Models\Specialty::factory()->count(100)->create();
  App\Modules\Doctors\Models\Doctor::factory()->count(500)->create()
    ->each(fn ($d) => $d->specialties()->attach($sp->random(2)->pluck("id")));'
$T php artisan tinker --execute '
  $s = app(App\Modules\Doctors\Services\DoctorService::class);
  $id = App\Modules\Doctors\Models\Specialty::query()->value("id");
  foreach ([["specialty_id" => $id], ["name" => "محمد"], ["is_active" => true]] as $f) {
    $t = microtime(true); $s->list($f, 15); printf("%s %.3fs\n", key($f), microtime(true) - $t);
  }'
```

Expected: each list in under 2 seconds. Afterwards, `$T php artisan migrate:fresh --force`
(affects `clinic_testing` only).

## Q8 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
```

Expected: tests exit `0`, including the existing Patients tests after the `ArabicName` move (D2)
and the message tests in both locales; Pint exits `0`.
