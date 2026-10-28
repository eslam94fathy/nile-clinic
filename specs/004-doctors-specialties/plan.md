# Implementation Plan: Doctors and Specialties

**Branch**: `004-doctors-specialties` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/004-doctors-specialties/spec.md`

## Summary

Add a `Doctors` module with three tables (`specialties`, `doctors`, `doctor_specialty`) and ten
endpoints. Administrators maintain the specialty catalog (add, rename, deactivate, reactivate) and
create and update doctor profiles, each linked to one staff account with the doctor role. All
active staff can list specialties, list and view doctors, and filter doctors by specialty, name,
or active status. A doctor can fetch their own profile. A doctor's name and active status are read
from the linked `users` row, never copied, so changes made through feature 002 take effect at once
(clarification Q2). Specialties always return both names (Q1). Only administrators can change
profiles (Q3). Name search reuses feature 003's Arabic normalization, moved to a shared helper and
applied in SQL at query time (D2). Unique names, accounts, and registration numbers are enforced by
database indexes, including when two requests arrive at once. No new dependencies. Evidence for
each choice is in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing `php:8.5.12-fpm-trixie` image, unchanged)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3 (both installed). No
new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. Three new tables
([data-model.md](data-model.md)); `users` read, not changed.

**Testing**: PHPUnit 12 via `composer test`, against `clinic_testing`; Feature tests per user
story; unit test for `ArabicName` (moved); Sanctum `actingAs` for role setup

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: Doctor lists in under 2 s with 500 doctors and 100 specialties (SC-002),
measured by quickstart Q7

**Constraints**: No account email, role, or user id in doctor responses (FR-015); synthetic data
only; no deletion of specialties or profiles

**Scale/Scope**: Single clinic, up to ~500 doctors and ~100 specialties. 10 endpoints, 1 module,
3 migrations, 1 shared helper moved out of `Patients`

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; class-level policies: reads for any active staff, changes for active administrators only, `/doctors/me` for active doctors; no patient data; account email/role/id never serialized (R10) | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; three new migrations, none edited; `/api/v1` via the module provider | ✅ |
| III | Repository pattern | `SpecialtyRepository` and `DoctorRepository` interfaces + Eloquent implementations bound in `DoctorsServiceProvider`; staff accounts read through the existing `StaffMemberRepository` contract (R3); no base repository | ✅ |
| IV | Thin controllers | `SpecialtyController`, `SpecialtyStatusController`, `DoctorController`: Form Request in → service → Resource out | ✅ |
| V | Services own workflows | `SpecialtyService` (add, rename, (de)activate, name race), `DoctorService` (create/update in a transaction, role and specialty rules, races) | ✅ |
| VI | Form Requests/Resources/Policies | `ListSpecialtiesRequest`, `StoreSpecialtyRequest`, `UpdateSpecialtyRequest`, `ListDoctorsRequest`, `StoreDoctorRequest`, `UpdateDoctorRequest`; `SpecialtyResource`, `DoctorSummaryResource`, `DoctorResource`; `SpecialtyPolicy`, `DoctorPolicy` | ✅ |
| VII | en/ar localization | `doctors::messages` and `doctors::attributes` in both locales; parity test covers module files; message tests in both locales; specialty names stored and returned in both languages with one response shape | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per user story, role × action matrix (SC-004), race tests (SC-003), inactive-doctor filter (SC-005), both locales (SC-006) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; no new dependency; D1–D3 approved | ✅ (D1–D3 approved) |
| XI | Integrity & concurrency | Profile + specialty rows written in one transaction; specialties share-locked while checked (R6); unique indexes on names, `user_id`, registration number, races mapped to `422` (R5, R7) | ✅ |
| XII | No real data | Factories with synthetic names; no seeder; no patient data involved | ✅ |

**Gate result (pre-research)**: PASS (D1–D3 approved 2026-10-28).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is
empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
     SpecialtyController / SpecialtyStatusController / DoctorController (thin)
                              │   Form Request: clean names, validate
                              │   └─ authorize() → SpecialtyPolicy / DoctorPolicy
                              ▼
           SpecialtyService            DoctorService ── DB::transaction
                │                         │    ├─ StaffMemberRepository (Staff module, findById)
                ▼                         ▼    ▼
     SpecialtyRepository        DoctorRepository, SpecialtyRepository::lockByIds
                │                         │
                └──────── Eloquent only here; name search via ArabicName (app/Support) ────────┘
```

### Endpoints ([contract](contracts/doctors.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| GET | `/specialties` | any active staff | FR-004, FR-004a |
| POST | `/specialties` | administrator | FR-001–FR-003 |
| PATCH | `/specialties/{specialty}` | administrator | FR-002, FR-003 |
| POST | `/specialties/{specialty}/deactivate` | administrator | FR-003, FR-008 |
| POST | `/specialties/{specialty}/reactivate` | administrator | FR-003 |
| GET | `/doctors` | any active staff | FR-013–FR-015 |
| POST | `/doctors` | administrator | FR-005–FR-010 |
| GET | `/doctors/me` | doctor | FR-017 |
| GET | `/doctors/{doctor}` | any active staff | FR-016 |
| PATCH | `/doctors/{doctor}` | administrator | FR-007–FR-010 |

Status codes: `201` created, `401` unauthenticated, `403` forbidden, `404` unknown specialty or
doctor (or no own profile), `422` validation (including taken names, account already linked, not
an active doctor, deactivated specialty, empty `PATCH`).

### Module layout (new files)

```text
app/Modules/Doctors/
├── Exceptions/{Specialty,Doctor}NotFoundException.php   # render 404 with doctors::messages; not reported
├── Http/
│   ├── Controllers/SpecialtyController.php              # index, store, update
│   ├── Controllers/SpecialtyStatusController.php        # deactivate, reactivate
│   ├── Controllers/DoctorController.php                 # index, store, show, me, update
│   ├── Requests/{ListSpecialties,StoreSpecialty,UpdateSpecialty}Request.php
│   ├── Requests/{ListDoctors,StoreDoctor,UpdateDoctor}Request.php
│   └── Resources/{Specialty,DoctorSummary,Doctor}Resource.php
├── Models/{Specialty,Doctor}.php                        # name/title mutators (ArabicName::clean), relations, Doctor::isActive()
├── Policies/{Specialty,Doctor}Policy.php
├── Providers/DoctorsServiceProvider.php                 # bindings, routes, policies, translations
├── Repositories/
│   ├── Contracts/SpecialtyRepository.php                # all(?isActive), findById, lockByIds, create, update, nameTaken
│   ├── Contracts/DoctorRepository.php                   # findById, findByUserId, paginate(filters, perPage), create, update, syncSpecialties, registrationNumberTaken
│   ├── EloquentSpecialtyRepository.php
│   └── EloquentDoctorRepository.php
├── Services/{Specialty,Doctor}Service.php
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
app/Support/ArabicName.php                              # moved from app/Modules/Patients/Support (D2)
database/migrations/<date>_create_{specialties,doctors,doctor_specialty}_table.php
database/factories/{Specialty,Doctor}Factory.php
```

Changed existing files: `bootstrap/providers.php` (register `DoctorsServiceProvider`);
`app/Modules/Patients/Models/Patient.php` and `…/Repositories/EloquentPatientRepository.php`
(import `App\Support\ArabicName`); `tests/Unit/Patients/ArabicNameTest.php` →
`tests/Unit/Support/ArabicNameTest.php`; `docs/architecture.md` (one line: shared helpers live in
`app/Support/`); `docs/roadmap.md` (004 status); `README.md` (a short "Doctors and specialties"
note linking the contract, as for features 002 and 003).

### Key rules

- **Authorization** (R9): class-level abilities checked in each Form Request's `authorize()`, and
  with `Gate::authorize()` in the controller for actions without a body (`show`, `me`,
  `deactivate`, `reactivate`), as the Staff and Patients controllers do.
- **Names**: `Specialty` mutators store `name_en`/`name_ar` through `ArabicName::clean()`;
  `Doctor` does the same for `title`. Unique indexes plus `Rule::unique` (ignoring self on
  rename/update).
- **Races** (R5, R7): services catch `UniqueConstraintViolationException`, ask the repository
  which value is taken (`nameTaken`, `findByUserId`, `registrationNumberTaken`), and throw a
  `422` on that field.
- **Profile create/update** (R6, R7): inside `DB::transaction`: load the account
  (`StaffMemberRepository::findById`) and require active + doctor role (create only);
  `lockByIds` the requested specialties and require each active or already assigned; write the
  profile; `syncSpecialties`.
- **Active doctor** (R2): `Doctor::isActive()` from the loaded user; the `is_active` filter joins
  `users` on the same two conditions.
- **Doctor name search** (R4): `EloquentDoctorRepository` builds the SQL normalization from
  `ArabicName::VARIANTS` and escapes `LIKE` wildcards in the normalized term.
- **No N+1**: lists eager-load `user` and `specialties`.
- **Empty `PATCH`**: `422` with the error key `fields` and `doctors::messages.nothing_to_update`,
  as feature 003 does.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Support/ArabicNameTest` | moved unchanged from `tests/Unit/Patients` |
| `tests/Feature/Doctors/SpecialtyCatalogTest` | add (cleaned names, `201`, active); duplicate per language ignoring case and spaces, including a deactivated one; rename (self allowed, other's name refused); length rules; deactivate/reactivate idempotent; list both names, `is_active` filter, exactly four keys; `404`; race via the service with validation bypassed (SC-003) |
| `tests/Feature/Doctors/CreateDoctorTest` | profile created with name from account; not-doctor role, deactivated account, unknown account, second profile for one account → `422 user_id`; registration number taken → `422`; no/unknown/deactivated specialty → `422 specialty_ids`; races for `user_id` and registration number (SC-003); no `email`/`user_id` in body |
| `tests/Feature/Doctors/UpdateDoctorTest` | title, registration number (set, clear, other's refused, own kept), specialty set replaced; deactivated specialty kept but not newly added; empty set refused; empty body refused; account unchanged |
| `tests/Feature/Doctors/BrowseDoctorsTest` | specialty filter; name search (case, spaces, Arabic variants both ways, `LIKE` wildcards literal); `is_active` filter for deactivated account and changed role, and restored (SC-005); list items exactly five keys; paging and `per_page` limit; show adds `registration_number`; `404`; `/doctors/me` with and without a profile |
| `tests/Feature/Doctors/DoctorsAuthorizationTest` | data provider: every role × every endpoint (receptionist and doctor refused all changes, including the doctor's own profile; `/doctors/me` refused for non-doctors); no token → `401`; deactivated account → `401` (SC-004) |
| `tests/Feature/Doctors/DoctorsPolicyTest` | 3 roles × each ability; inactive actors refused |
| `tests/Feature/Doctors/DoctorsMessagesTest` | `404`, `422` (field names and custom messages), `403` in `en` and `ar` with identical structure (SC-006) |

`TranslationParityTest` (feature 001) already checks the new module language files.

## Implementation Outline (input for `/speckit-tasks`)

1. D2 prerequisite: move `ArabicName` to `app/Support/`, make `VARIANTS` public, update the two
   Patients imports and move its unit test; full test suite still green.
2. Migrations, `Specialty` and `Doctor` models, factories.
3. Module skeleton: provider (bindings, routes, policies, translations), registered in
   `bootstrap/providers.php`; `doctors::messages` and `doctors::attributes` in en/ar.
4. `SpecialtyPolicy`, `DoctorPolicy`, and the policy test.
5. US1: specialty repository, `SpecialtyService`, requests, `SpecialtyResource`, five specialty
   endpoints, catalog tests.
6. US2: doctor repository (create, update, sync, find), `DoctorService` create/update,
   `StoreDoctorRequest`/`UpdateDoctorRequest`, `DoctorResource`, `POST`/`PATCH /doctors`, create
   and update tests.
7. US3: doctor `paginate` with filters and name search, `ListDoctorsRequest`,
   `DoctorSummaryResource`, `GET /doctors`, `/doctors/{doctor}`, `/doctors/me`, browse tests.
8. Authorization matrix test and messages test in both locales.
9. Polish: architecture note, roadmap and README updates, Pint, quickstart run (including Q7
   timing), verification record.

## Approved Decisions (2026-10-28)

All three were approved by the user on 2026-10-28.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Module boundaries | **One `Doctors` module** holding specialties and doctor profiles, two repositories and two services — R1 | Two modules, `Specialties` and `Doctors` |
| D2 | Arabic-aware doctor name search | **Move `ArabicName` to `app/Support/`** (shared by Patients and Doctors) and **normalize `users.name` in SQL at query time**; no schema change to `users` — R4 | A `name_search` column on `users` maintained by the Staff module; or importing the helper from the Patients module |
| D3 | Doctor id | **Own auto-increment id on `doctors`**, with a unique `user_id`; URLs and later features use the doctor id — R2 | Use the staff account id as the doctor's primary key |

## Risks, Assumptions, and Rollback

- **Query-time normalization scans** doctors joined to users (R4). Fine at 500 doctors (Q7); if
  the clinic grows far beyond that, a normalized column becomes a new migration.
- **The `ArabicName` move touches feature 003 code.** Behaviour is unchanged and covered by the
  existing Patients search tests and the moved unit test; step 1 runs the full suite before any
  Doctors code is added.
- **Cross-module reads**: Doctors depends on Staff's `User` model and `StaffMemberRepository`
  contract. Staff does not depend on Doctors; feature 002 behaviour is unchanged (Q2).
- **Rollback**: `php artisan migrate:rollback --step=3` for the three migrations (destructive for
  specialty and doctor data; opt-in only), remove the provider line, and revert the commit range.

## Project Structure

### Documentation (this feature)

```text
specs/004-doctors-specialties/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── doctors.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── System/                                     # existing (health)
├── Staff/                                      # existing; User, Role, StaffMemberRepository read by Doctors
├── Patients/                                   # existing; ArabicName import updated (D2)
└── Doctors/                                    # new (see Module layout above)
app/Support/ArabicName.php                      # moved (D2)
bootstrap/providers.php                         # changed
database/migrations/  database/factories/{Specialty,Doctor}Factory.php
tests/Feature/Doctors/  tests/Unit/Support/     # new and moved tests listed above
```

**Structure Decision**: The existing modular monolith. One new domain module, `Doctors`,
following `docs/architecture.md`, plus `app/Support/` for the one helper now shared by two
modules.

## Complexity Tracking

No Constitution violations; nothing to justify.
