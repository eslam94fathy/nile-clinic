# Quickstart Validation: Medical Encounters

**Feature**: 008-medical-encounters | **Date**: 2026-11-05

Run from the repository root with the development stack up (see `README.md`). Commands are
POSIX-shell syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/encounters.openapi.yaml](contracts/encounters.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic. Do not enter real patient details.

## Q1 — Rebuild and migrate

```sh
docker compose build app
docker compose up -d app
docker compose exec app php -r "echo ini_get('zend.exception_ignore_args'), PHP_EOL;"
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=doctors/me
```

Expected: the setting prints `1`; the two encounter migrations show `Ran`; earlier migrations
unchanged; the seven routes in the contract are listed.

## Q2 — Two doctors, one patient, one visit

Reuse feature 007 quickstart Q2 (`B`, `J`, `signin`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`, patients
`P1`, `P2`), and create a second doctor with a profile, with token `DOCTOR2` and id `DOC2`. Then:

1. Add `P1` as a walk-in for `DOC` (007 Q4), and note the entry id as `V1`.
2. Before calling them, try to start the record (Q3 below): expected `422` (`visit_not_started`).
3. Call next as `$DOCTOR` (007 Q5).

## Q3 — Start and save a draft (US1)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/visits/$V1/encounter -H "Authorization: Bearer $DOCTOR"
curl -s -w '  %{http_code}\n' -X PUT $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR" -H "$J" \
  -d '{"chief_complaint":"SYNTHETIC-PLANTED-TEXT","examination_findings":null,"diagnosis":null,"treatment_plan":null,"prescriptions":[{"medication":"Synthetic med"}]}'
curl -s -w '  %{http_code}\n' $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR"
```

Set `E1` from the first response's `data.id`. Expected: `201` draft; `200` with the saved content;
`200` with the same content. Then:

- start the same visit again → `422` (`already_started`);
- `PUT` without `diagnosis` → `422`, and the draft is unchanged;
- start as `$DOCTOR2` → `404`; open `E1` as `$DOCTOR2` → `404` (another doctor's draft);
- any of these as `$RECEPTION` or `$ADMIN` → `403`.

## Q4 — Finalize (US2)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/encounters/$E1/finalize -H "Authorization: Bearer $DOCTOR"
```

Expected: `422` with errors on `diagnosis`, `prescriptions.0.dosage`, `prescriptions.0.frequency`, and
`prescriptions.0.duration`. `PUT` those fields with values, then finalize again → `200`, `finalized`,
with `finalized_at`. Saving or finalizing again → `422` (`not_editable`). The visit's status has not
changed: finish it with `POST $B/doctors/me/queue/finish` as usual.

## Q5 — Visit list (US3)

```sh
TODAY=$(TZ=Africa/Cairo date +%F)
curl -s "$B/doctors/me/visits?from=$TODAY&to=$TODAY&record_status=finalized" -H "Authorization: Bearer $DOCTOR"
```

Expected: `V1` with `record_status: finalized`, its `encounter_id`, and the patient summary; the
planted text does not appear. A range over 92 days → `422`.

## Q6 — Reading another doctor's record (US4)

As `$DOCTOR2`, with `P1` not on their list:

```sh
curl -s -w '  %{http_code}\n' $B/doctors/me/patients/$P1/encounters -H "Authorization: Bearer $DOCTOR2"
curl -s -w '  %{http_code}\n' $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR2"
curl -s -w '  %{http_code}\n' $B/doctors/me/encounters/999999 -H "Authorization: Bearer $DOCTOR2"
```

Expected: `404` each, and the last two bodies are identical. Add `P1` as a walk-in for `DOC2` (entry
`V2`), then repeat:

- the history is `200`, listing `E1` with its date, the doctor's name, and specialties, and no clinical
  content;
- `E1` is `200` with its clinical content;
- `PUT` on `E1` as `$DOCTOR2` → `403`.

Mark `V2` as left as `$RECEPTION` (007 Q6), and both reads return `404` again.

## Q7 — Addendum (US5)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/doctors/me/encounters/$E1/addenda -H "Authorization: Bearer $DOCTOR" -H "$J" -d '{"text":"Synthetic correction"}'
curl -s $B/doctors/me/encounters/$E1 -H "Authorization: Bearer $DOCTOR"
```

Expected: `201`; the record shows the addendum and its content is unchanged. Empty `text` → `422`.

## Q8 — Nothing readable outside the API

```sh
docker compose exec app php artisan tinker --execute="echo substr(DB::table('encounters')->orderByDesc('id')->value('chief_complaint'), 0, 20), PHP_EOL;"
docker compose exec app sh -c "grep -c SYNTHETIC-PLANTED-TEXT storage/logs/*.log"
```

Expected: the database value is ciphertext and not the planted text; the log count is `0` for each
file (`grep` exits non-zero when nothing matches).

## Q9 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app php artisan test --group=concurrency
docker compose exec app vendor/bin/pint --test
```

Expected: all tests exit `0`, including feature 007's tests unchanged, the encounter concurrency tests
(one record per visit; finalized content never changes), the planted-text tests, and the message tests
in both locales; Pint exits `0`.
