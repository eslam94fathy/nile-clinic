# Implementation Plan: Patient Registration and Records

**Branch**: `003-patient-records` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-patient-records/spec.md`

## Summary

Add a `Patients` module with one table, `patients`, and four endpoints: register, list/search,
view, and update. Only active administrators and receptionists may use them; doctors are refused
every action (clarification Q1). The patient number is the record's id (D1). Name search matches
Arabic letter variants through a normalized `name_search` column, because the database collation
does not (verified, R3). Lists use a five-field summary resource, so national ID, address, and
emergency contact never appear in them. The national ID is optional and unique, enforced by a
unique index that also catches simultaneous registrations (R5). Database errors are logged
without bound values, so patient details never reach the log (D3). No new dependencies. Evidence
for each choice is in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing `php:8.5.12-fpm-trixie` image, unchanged)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3 (both installed). No
new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. One new table, `patients`
([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test`, against `clinic_testing`; Feature tests per user
story, a unit test for name normalization; Sanctum `actingAs` for role setup

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: Search results in under 2 s with 100,000 patients (SC-002), measured by
quickstart Q7

**Constraints**: Sensitive fields never in lists, errors, or logs (FR-013, FR-017); synthetic data
only; no deletion

**Scale/Scope**: Single clinic, up to ~100,000 patients. 4 endpoints, 1 module, 1 migration, 1
change to global exception reporting

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; `PatientPolicy` allows only active administrators and receptionists; doctors refused before any lookup (approved access rule = none, Q1); sensitive fields only in the single-record resource; query errors logged without values (R7) | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; one new migration, none edited; `/api/v1` via the module provider | ✅ |
| III | Repository pattern | `PatientRepository` interface + `EloquentPatientRepository`, bound in `PatientsServiceProvider`; plain-id route parameter loaded through it; no base repository | ✅ |
| IV | Thin controllers | `PatientController`: Form Request in → service → Resource out | ✅ |
| V | Services own workflows | `PatientService` (register, update, find, list; national-ID race handling) | ✅ |
| VI | Form Requests/Resources/Policies | `ListPatientsRequest`, `StorePatientRequest`, `UpdatePatientRequest`; `PatientResource`, `PatientSummaryResource`; `PatientPolicy` | ✅ |
| VII | en/ar localization | `patients::messages` and `patients::attributes` in both locales; parity test already covers module files; message tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per user story, role × action matrix (SC-005), list-shape test (SC-004), Arabic search cases (SC-003), race test (SC-006), both locales | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; no new dependency; D1–D3 approved | ✅ (D1–D3 approved) |
| XI | Integrity & concurrency | National-ID uniqueness enforced by the database, race mapped to `422` (R5); no multi-step writes needing a transaction | ✅ |
| XII | No real data | Factory with synthetic names (en and `ar_EG`); no seeder; no patient values in logs (R7) | ✅ |

**Gate result (pre-research)**: PASS (D1–D3 approved 2026-10-28). **Post-design re-check**: PASS.
No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
                  PatientController (thin) ── Form Request: normalize phone/name, validate
                              │                └─ authorize() → PatientPolicy (admin | receptionist)
                              ▼
                        PatientService   (find-or-404, national-ID race → 422)
                              │
                              ▼
                 PatientRepository → EloquentPatientRepository   (Eloquent only here)
                              │
                     Patient model: full_name mutator sets name_search (ArabicName::normalize)
```

### Endpoints ([contract](contracts/patients.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| GET | `/patients` | administrator, receptionist | FR-011–FR-013 |
| POST | `/patients` | administrator, receptionist | FR-001–FR-006, FR-009 |
| GET | `/patients/{patient}` | administrator, receptionist | FR-013 |
| PATCH | `/patients/{patient}` | administrator, receptionist | FR-010 |

Status codes: `201` registered, `401` unauthenticated, `403` forbidden (including every doctor
request), `404` unknown patient number, `422` validation (including a repeated national ID).

### Module layout (new files)

```text
app/Modules/Patients/
├── Enums/Sex.php
├── Exceptions/PatientNotFoundException.php        # renders 404, patients::messages.not_found; not reported
├── Http/
│   ├── Controllers/PatientController.php          # index, store, show, update
│   ├── Requests/{ListPatients,StorePatient,UpdatePatient}Request.php
│   └── Resources/{Patient,PatientSummary}Resource.php
├── Models/Patient.php                             # Sex cast, date cast, full_name mutator, emergency-contact accessors
├── Policies/PatientPolicy.php
├── Providers/PatientsServiceProvider.php          # binding, routes, policy, translations
├── Repositories/
│   ├── Contracts/PatientRepository.php            # findById, create, update, paginate(filters, perPage)
│   └── EloquentPatientRepository.php
├── Services/PatientService.php
├── Support/ArabicName.php                         # normalize() — the one definition (R3)
├── Support/PhoneNumber.php                        # normalize() and the validation pattern (R4)
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_create_patients_table.php
database/factories/PatientFactory.php
```

Changed existing files: `bootstrap/providers.php` (register `PatientsServiceProvider`),
`bootstrap/app.php` (report `QueryException` without bindings, D3), `docs/roadmap.md` (003 status),
and `README.md` (a short "Patients" note after "Signing in", linking the contract, as that section
does for feature 002).

### Key rules

- **Authorization** (R10): class-level abilities against `Patient::class`, checked in each Form
  Request's `authorize()` and, for `show`, `Gate::authorize('view', Patient::class)` in the
  controller, as `StaffMemberController::show` does.
- **Normalization**: Form Requests clean `phone`, `emergency_contact.phone`, and the `phone`
  filter with `PhoneNumber::normalize()` in `prepareForValidation()`; the model mutator cleans
  `full_name` and sets `name_search`. The repository normalizes the `name` filter with
  `ArabicName::normalize()` and escapes `LIKE` wildcards.
- **Emergency contact** (R9): accepted and returned as one nested object; the service maps it to
  and from the three columns; `null` clears all three.
- **National-ID race** (R5): `PatientService` wraps create and update in a guard that turns
  `UniqueConstraintViolationException` into a `422` on `national_id`, as
  `StaffManagementService::guardUniqueEmail()` does.
- **Never logged or listed**: sensitive fields appear only in `PatientResource`; `name_search` is
  never serialized; `QueryException` logs only placeholder SQL (D3).

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Patients/ArabicNameTest` | alef, taa marbuta, yaa variants; case; space collapsing; Latin names unchanged except case |
| `tests/Feature/Patients/RegisterPatientTest` | required fields only; all fields; each validation rule and boundary (DOB today / tomorrow / 130 years; phone 8 and 15 digits; national ID 13/14 digits and first digit); repeated national ID; race via the service with validation bypassed (SC-006) |
| `tests/Feature/Patients/SearchPatientsTest` | name contains, Arabic variants both directions (SC-003), phone shared by several patients, national ID, id, AND of filters, empty result, paging and `per_page` limit, `LIKE` wildcards in the term matched literally, list items have exactly five keys (SC-004) |
| `tests/Feature/Patients/ShowPatientTest` | full record fields; `404` for unknown id |
| `tests/Feature/Patients/UpdatePatientTest` | partial update; clearing `national_id`, `address`, `emergency_contact`; national ID of another patient refused; own national ID kept; `id` unchanged; empty body refused |
| `tests/Feature/Patients/PatientAuthorizationTest` | data provider: doctor × 4 actions → `403`, nothing changed; administrator and receptionist allowed; no token → `401`; deactivated receptionist → `401` (SC-005) |
| `tests/Feature/Patients/PatientMessagesTest` | `404`, `422` (field names), `403` in `en` and `ar` with identical structure |
| `tests/Feature/Patients/QueryErrorLoggingTest` | a forced `QueryException` is logged without bound values (D3) |

`TranslationParityTest` (feature 001) already checks the new module language files.

## Implementation Outline (input for `/speckit-tasks`)

1. Migration, `Sex` enum, `ArabicName` (with unit test), `PhoneNumber`, `Patient` model,
   `PatientFactory`.
2. Module skeleton: provider (binding, routes, policy, translations), registered in
   `bootstrap/providers.php`; `patients::messages` and `patients::attributes` in en/ar.
3. `PatientPolicy` and the authorization test.
4. US1: repository `create`/`findById`, `PatientService::register`, `StorePatientRequest`,
   `PatientResource`, `POST /patients`, register tests.
5. US2: repository `paginate`, `PatientService::list`/`find`, `ListPatientsRequest`,
   `PatientSummaryResource`, `GET /patients` and `GET /patients/{patient}`, search and show tests.
6. US3: repository `update`, `PatientService::update`, `UpdatePatientRequest`, `PATCH`, update
   tests.
7. D3: `QueryException` reporting without bindings, and its test.
8. Messages test in both locales.
9. Polish: roadmap and README updates, Pint, quickstart run (including Q7 timing), verification
   record.

## Approved Decisions (2026-10-28)

All three were approved by the user on 2026-10-28.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Patient number | **The record's auto-increment id**, returned as `id`; unique, system-assigned, never reused — R2 | A separate formatted `patient_number` column (for example `P-000123`) |
| D2 | Search parameters | **Separate filters** `name`, `phone`, `national_id`, `id`, combined with AND — R8 | One free-text `q` matched against every field |
| D3 | Patient data in logs | **Change global reporting** in `bootstrap/app.php` so `QueryException` is logged with placeholder SQL only, no bound values — R7 | Keep the framework default, which logs the values (names, phones, national IDs) on a database error |

## Risks, Assumptions, and Rollback

- **Contains-search scans** `name_search` (R3). Measured in Q7; if it ever exceeds the target, a
  `FULLTEXT` ngram index is the next step, as a new migration.
- **D3 affects every module**: database errors from staff endpoints are also logged without
  values. That is intended; the SQL text and error code still identify the failure.
- **Phone matching is exact** after removing separators; `010…` and `+2010…` are different values
  (R4). Recorded as a possible later improvement, not in this spec.
- **Rollback**: `php artisan migrate:rollback` for the one migration (destructive for patient data;
  opt-in only), remove the provider line, and revert the commit range.

## Project Structure

### Documentation (this feature)

```text
specs/003-patient-records/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── patients.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── System/                                     # existing (health)
├── Staff/                                      # existing (auth, roles); Role enum read by PatientPolicy
└── Patients/                                   # new (see Module layout above)
bootstrap/app.php  bootstrap/providers.php      # changed
database/migrations/  database/factories/PatientFactory.php
tests/Feature/Patients/  tests/Unit/Patients/   # new tests listed above
```

**Structure Decision**: The existing modular monolith. One new domain module, `Patients`,
following `docs/architecture.md`; no new top-level directories.

## Complexity Tracking

No Constitution violations; nothing to justify.
