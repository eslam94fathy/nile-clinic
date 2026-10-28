# Verification Results: Patient Registration and Records

**Feature**: 003-patient-records | **Date**: 2026-10-28 | **Task**: T039, T040

Results observed while running [quickstart.md](quickstart.md) Q1–Q8 on the development stack
(project `nile-clinic`, `http://localhost:8080`). Failures are reported as observed
(Constitution VIII). All accounts and patients used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `003-phase-6-polish`.
- Development database `clinic`; tests and Q7 run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate | `migrate:status` | `2026_10_28_080411_create_patients_table` `Ran` (batch 3, applied in Phase 2); earlier migrations unchanged | Pass |
| Q2 Tokens for each role | created `qs-reception@clinic.test` (receptionist) and `qs-doctor@clinic.test` (doctor) with `tinker`; signed in both | Two tokens issued | Pass |
| Q3 Register | `POST /patients` with `"  أحمد   محمود "`, phone `010-0000 0001`, national ID `28502030100001`; same again; future DOB with a 13-digit ID | `201`, `id` 1, phone `01000000001`. **The name came back as `???? ?????`** (see below). Repeat: `422` "The national ID has already been taken."; invalid: `422` on `date_of_birth` and `national_id` | Pass after a client-side fix (see below) |
| Q4 Search and view | `?name=احمد` (URL-encoded), `?phone=010 0000 0001`, `?national_id=…`, `?name=zzzz`, `GET /patients/1`, `GET /patients/999999` | First three each list patient 1 with exactly `id`, `full_name`, `sex`, `date_of_birth`, `phone` (the name search found `أحمد` from `احمد`); `zzzz` → `200` with `data: []`; show → full record with `national_id`; unknown → `404 {"message":"No patient was found."}` | Pass |
| Q5 Update | `PATCH /patients/1` (`Accept-Language: ar`) with new phone, `national_id: null`, emergency contact; then `{"phone":"12"}` | `200`: phone `01000000009`, `national_id` null, contact `منى محمود`/`sister`/`01000000010`, same `id`. Bad phone: `422` "صيغة حقل رقم الهاتف غير صحيحة." (names the field in Arabic) | Pass |
| Q6 Doctors refused | doctor `GET /patients`, `GET /patients/1` (`ar`), `POST`, `PATCH` | All `403`; Arabic body "غير مسموح لك بتنفيذ هذا الإجراء."; patient 1's phone and the patient count unchanged after the doctor's `PATCH` | Pass |
| Q7 Search speed at 100,000 | 100,000 factory patients in `clinic_testing`; `PatientService::list()` timed (second run of each) | `name=محمد` 81 matches 352 ms; `name=احمد` 157 matches 181 ms; `name=an` 12,274 matches 35 ms; exact phone 2 ms; `name=zzzz` 0 matches 34 ms. All under 2 s (SC-002) | Pass (see notes) |
| Q8 Logs and style | `grep -cE "28502030100001\|01000000001\|01000000009" storage/logs/*.log`; `composer test`; `pint --test` | `laravel.log: 0`; `294 passed (879 assertions)`; Pint exit 0 (126 files) | Pass |

## Client-side problem found in Q3: Arabic sent as `?`

- **Observed**: the registered name came back as `???? ?????`; the stored bytes were `3f3f3f3f…`
  (literal `?`), so the server stored exactly what it received.
- **Cause**: Git Bash on Windows passes non-ASCII command-line arguments to the native `curl.exe`
  through the ANSI code page, so `-d '{"full_name":"أحمد …"}'` reached the API as question marks.
  The shell itself is UTF-8 (`printf 'أ' | od` → `d8 a3`). Not an application defect.
- **Workaround used**: JSON bodies containing Arabic were written to a UTF-8 file and sent with
  `--data-binary @file`; Arabic query values were URL-encoded (as the quickstart already does).
  Patient 1's name was corrected through `PATCH` (records are never deleted); afterwards it was
  stored as `أحمد محمود` with `name_search` starting with plain `ا`.
- The quickstart was **not** edited; on Windows Git Bash, send Arabic request bodies from a UTF-8
  file.

## Notes on Q7

- The rows were inserted with factory `make()` and batched `insert`s of 1,000 (47 s) instead of
  `factory()->count(100000)->create()`, only for speed; the model's mutator still filled
  `name_search`. The database name was checked to be `clinic_testing` inside each command.
- The timings are inside the application (list query plus page count), not full HTTP requests.
  The leading-wildcard name search scans the table as expected (research R3); the slowest case
  was 352 ms.
- Afterwards the `patients` table in `clinic_testing` was truncated instead of running the
  quickstart's `migrate:fresh` (narrower; the test suite recreates the schema anyway).

## State left on the development stack

- Accounts: `qs-reception@clinic.test` (receptionist) and `qs-doctor@clinic.test` (doctor), both
  active, password `quickstart-pass-1`, synthetic. Remove or replace them as needed.
- Patient 1 (`أحمد محمود`, synthetic) in the local `clinic` database.

## Not verified

- Two registrations with the same national ID at exactly the same moment over HTTP. The database
  path is covered by `RegisterPatientTest` (service called with validation bypassed against an
  existing row), but PHPUnit sends one request at a time.
- HTTP response time at 100,000 patients (only the application-level timing above).
