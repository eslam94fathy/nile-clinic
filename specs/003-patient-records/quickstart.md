# Quickstart Validation: Patient Registration and Records

**Feature**: 003-patient-records | **Date**: 2026-10-28

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/patients.openapi.yaml](contracts/patients.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

Expected: the `create_patients_table` migration shows `Ran`; earlier migrations are unchanged.

## Q2 — Tokens for each role

Create a receptionist and a doctor with an administrator token (feature 002 quickstart Q2–Q5),
then sign in as each:

```sh
B=http://localhost:8080/api/v1
signin() { curl -s -X POST $B/auth/sign-in -H 'Content-Type: application/json' \
  -d "{\"email\":\"$1\",\"password\":\"$2\"}" | sed 's/.*"token":"\([^"]*\)".*/\1/'; }
RECEPTION=$(signin reception@clinic.test reception-pass-1)
DOCTOR=$(signin doctor@clinic.test doctor-pass-12)
```

## Q3 — Register (US1)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/patients -H "Authorization: Bearer $RECEPTION" \
  -H 'Content-Type: application/json' \
  -d '{"full_name":"  أحمد   محمود ","date_of_birth":"1985-02-03","sex":"male","phone":"010-0000 0001","national_id":"28502030100001"}'
```

Expected: `201`; `data.id` is the patient number; `full_name` is `أحمد محمود` (spaces cleaned);
`phone` is `01000000001`. Sending the same request again → `422` with an error on `national_id`.
A future `date_of_birth` or a 13-digit `national_id` → `422` naming those fields.

## Q4 — Search and view (US2)

```sh
curl -s "$B/patients?name=%D8%A7%D8%AD%D9%85%D8%AF" -H "Authorization: Bearer $RECEPTION"   # name=احمد
curl -s "$B/patients?phone=010%200000%200001" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/patients?national_id=28502030100001" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/patients?name=zzzz" -H "Authorization: Bearer $RECEPTION"
```

Expected: the first three each list the patient from Q3 (the name search finds `أحمد` from
`احمد`); each item has exactly `id`, `full_name`, `sex`, `date_of_birth`, `phone`. The last returns
an empty `data` array with `200`. `GET $B/patients/<id>` returns the full record including
`national_id`; an unknown id returns `404`.

## Q5 — Update (US3)

```sh
curl -s -X PATCH $B/patients/<id> -H "Authorization: Bearer $RECEPTION" \
  -H 'Content-Type: application/json' -H 'Accept-Language: ar' \
  -d '{"phone":"01000000009","national_id":null,"emergency_contact":{"name":"منى محمود","relationship":"sister","phone":"01000000010"}}'
```

Expected: `200`; new phone, `national_id` null, the emergency contact set, the same `id`.
`{"phone":"12"}` → `422` with an Arabic message naming the phone field.

## Q6 — Doctors are refused (FR-014)

```sh
for path in "/patients" "/patients/<id>"; do
  curl -s -w '  %{http_code}\n' "$B$path" -H "Authorization: Bearer $DOCTOR" -H 'Accept-Language: ar'; done
```

Expected: `403` with the Arabic forbidden message for both; a doctor `POST`/`PATCH` is also `403`
and changes nothing.

## Q7 — Search speed at 100,000 patients (SC-002)

Against the **test** database (`clinic_testing`) only, never the development data. There is no
`.env.testing`, so the database is chosen with an environment variable, which takes precedence
over `.env`:

```sh
T="docker compose exec -e DB_DATABASE=clinic_testing app"
$T php artisan migrate --force
$T php artisan tinker --execute "App\Modules\Patients\Models\Patient::factory()->count(100000)->create();"
$T php artisan tinker --execute '
  $s = app(App\Modules\Patients\Services\PatientService::class);
  foreach (["name" => "محمد", "phone" => "01000000001"] as $k => $v) {
    $t = microtime(true); $s->list([$k => $v], 15); printf("%s %.3fs\n", $k, microtime(true) - $t);
  }'
```

Expected: each search (list query plus page count) in under 2 seconds. Afterwards, empty the test
database again with `$T php artisan migrate:fresh --force` (affects `clinic_testing` only; the
test suite recreates its schema anyway).

## Q8 — Logs and style

```sh
docker compose exec app sh -c 'grep -cE "28502030100001|01000000001" storage/logs/*.log || true'
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
```

Expected: the grep prints `0` for every log file (no national ID or phone in logs); tests exit `0`
in both locales; Pint exits `0`.
