# Implementation Plan: Medical Encounters

**Branch**: `008-medical-encounters` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/008-medical-encounters/spec.md`

## Summary

Add an `Encounters` module with two tables (`encounters`, `encounter_addenda`) and seven endpoints,
all for doctors under `/doctors/me`: list own visits with their record status, start a record, open
one, save a draft, finalize, add an addendum, and list a patient's history. Each record belongs to one
queue entry from feature 007, so booked patients and walk-ins work the same way. Clinical content is
encrypted in the database and returned only when one record is opened; listing queries never select
it. The access rule lives in one policy, and anything a doctor may not read returns the same 404 as
something that does not exist. A one-line PHP setting stops stack traces from logging the start of
string arguments. No new dependencies, and features 001–007 keep their behavior. Evidence:
[research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image; one `app.ini` line added, research R6)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. Two new tables; `queue_entries`, `patients`,
`doctors`, `users`, and `specialties` are read, not changed ([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; Feature tests per user story with
`travelTo` in the clinic zone; a `concurrency` group with parallel PHP processes (feature 006 R9);
feature 007's tests unchanged

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: A doctor's visit list or a patient's history in under 2 s with 500 doctors and
500,000 records (SC-005)

**Constraints**: One record per visit and no change to finalized content, also under concurrency
(FR-002, FR-008); no clinical content in listings, errors, or logs (FR-018, FR-021); all-or-nothing
changes (FR-019); clinic wall-clock "today" (FR-023); synthetic data only

**Scale/Scope**: Single clinic, ~500 doctors, a few thousand visits a day. 7 endpoints, 1 module, 2
migrations; 1 new method in `QueueRepository`; 1 line in `app.ini`

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; `EncounterPolicy` holds the approved access rule (spec Q2); reception and administrators get 403 before any lookup; refused records return the same 404 as unknown ones; clinical content encrypted, absent from listings, errors, and logs | ✅ (D1, D4 approved) |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; two new migrations, none edited; `/api/v1` via the module provider; no patient portal; image version unchanged | ✅ |
| III | Repository pattern | `EncounterRepository` (+ Eloquent) with purpose-named methods; `QueueRepository` gains `patientOnList`; no generic repository | ✅ |
| IV | Thin controllers | `OwnVisitController`, `EncounterController`, `PatientEncounterController`: Form Request or Gate → service → Resource | ✅ |
| V | Services own workflows | `EncounterService` owns transactions, locks, and status and completeness rules; repositories only query | ✅ |
| VI | Form Requests/Resources/Policies | `ListOwnVisitsRequest`, `SaveEncounterRequest`, `AddAddendumRequest`, `ListPatientEncountersRequest`; `VisitResource`, `EncounterResource`, `EncounterHistoryItemResource`, `AddendumResource`; `EncounterPolicy` | ✅ |
| VII | en/ar localization | `encounters::messages` and `encounters::attributes` in both locales; statuses are fixed identifiers; clinical text returned as written; message tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per story; concurrency group (SC-004); role × endpoint matrix (SC-003); planted-text tests (SC-002); both locales (SC-006) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; framework behavior and sizes verified (R4, R6); no new dependency; D1–D4 approved | ✅ (D1–D4 approved) |
| XI | Integrity & concurrency | One transaction per change; queue entry locked for start, encounter locked for save and finalize; unique index as backstop; real parallel-process tests | ✅ |
| XII | No real data | Factories only; concurrency script refuses any database but `clinic_testing`; stack trace arguments off; logs carry internal ids only | ✅ (D4 approved) |

**Gate result (pre-research)**: PASS (D1–D4 approved 2026-11-05).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   OwnVisit / Encounter / PatientEncounter controllers (thin)
                              │   1 class-level `access` (doctors only: else 403)
                              │   2 validation (never repeats input)
                              │   3 find, then `view` policy (else the same 404)
                              │   4 `write` policy on changes (non-author: 403)
                              ▼
   EncounterService ─ DB::transaction
     start     lock queue entry ─▶ check owner, status, no record ─▶ insert
     save      lock encounter   ─▶ must be draft ─▶ replace content
     finalize  lock encounter   ─▶ must be draft and complete ─▶ finalized_at
     addendum  must be finalized ─▶ insert
```

### Endpoints ([contract](contracts/encounters.openapi.yaml))

All are for doctors; administrators and receptionists get 403.

| Method | Path (`/api/v1`) | Spec |
|--------|------------------|------|
| GET | `/doctors/me/visits?from&to&patient_id&record_status&page&per_page` | FR-016, FR-018 |
| POST | `/doctors/me/visits/{visit}/encounter` | FR-001, FR-002 |
| GET | `/doctors/me/encounters/{encounter}` | FR-009, FR-012, FR-013 |
| PUT | `/doctors/me/encounters/{encounter}` | FR-003, FR-004, FR-006, FR-008 |
| POST | `/doctors/me/encounters/{encounter}/finalize` | FR-007, FR-008 |
| POST | `/doctors/me/encounters/{encounter}/addenda` | FR-011 |
| GET | `/doctors/me/patients/{patient}/encounters?page&per_page` | FR-012, FR-013, FR-017 |

Status codes: `200`, `201` started record or addendum, `401`, `403` (not a doctor; a non-author
changing a record they may read), `404` (unknown, may not be read, no profile), `422` validation
(`visit_not_started`, `already_started`, `not_editable`, `required_to_finalize`, `not_finalized`).

### Module layout (new files)

```text
app/Modules/Encounters/
├── Enums/EncounterStatus.php                      # draft, finalized
├── Exceptions/
│   ├── EncounterNotFoundException.php             # 404; also a false `view`; not reported
│   ├── HistoryNotFoundException.php               # 404; not reported
│   └── VisitNotFoundException.php                 # 404; not reported
├── Http/
│   ├── Controllers/{OwnVisit,Encounter,PatientEncounter}Controller.php
│   ├── Requests/{ListOwnVisits,SaveEncounter,AddAddendum,ListPatientEncounters}Request.php
│   └── Resources/{Visit,Encounter,EncounterHistoryItem,Addendum}Resource.php
├── Models/{Encounter,EncounterAddendum}.php       # encrypted casts, $hidden
├── Policies/EncounterPolicy.php                   # access, view, write
├── Providers/EncountersServiceProvider.php
├── Repositories/
│   ├── Contracts/EncounterRepository.php          # find, lockById, create, update, existsForVisit,
│   │                                              # addAddendum, paginateVisits, paginateHistory
│   └── EloquentEncounterRepository.php
├── Services/EncounterService.php                  # listVisits, start, find, save, finalize,
│                                                  # addAddendum, history
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_create_encounters_table.php
database/migrations/<date>_create_encounter_addenda_table.php
database/factories/{Encounter,EncounterAddendum}Factory.php
tests/Support/encounter-action.php                 # child process for the concurrency test
```

Changed existing files:

- `app/Modules/Queues/Repositories/Contracts/QueueRepository.php` and `EloquentQueueRepository.php`
  (`patientOnList`)
- `docker/php/conf.d/app.ini` (`zend.exception_ignore_args = On`; rebuild needed)
- `bootstrap/providers.php` (register `EncountersServiceProvider`)
- `docs/roadmap.md`, `README.md` (short "Medical encounters" section; `APP_KEY` backup note)

### Key rules

- **Access** (R4): one policy. Own record → allow. Another doctor's draft → 404. Another doctor's
  finalized record → allow only if the patient is on this doctor's list today as `waiting`,
  `with_doctor`, or `seen`.
- **History scope** (R5): on the list, all finalized records; otherwise own finalized records, and 404
  when there are none.
- **Locks first** (R3): no decision to change is made from a plain read.
- **Clinical content** (R6, R7): encrypted casts, `MEDIUMTEXT`, `$hidden`; listing queries select no
  clinical column.
- **Save replaces** (R8): every key must be sent, so a missing field cannot erase content.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Feature/Encounters/StartEncounterTest` | with doctor and seen start; waiting, left → 422; another doctor's visit → 404; second start → 422; booked and walk-in alike; later day allowed; finish without a record still works; no status changes; no profile 404 |
| `tests/Feature/Encounters/SaveEncounterTest` | content replaced; partial draft allowed; missing key → 422 and unchanged; limits at the boundary in Arabic and four-byte text; line without medication → 422; 21 lines → 422; last save wins |
| `tests/Feature/Encounters/FinalizeEncounterTest` | complete → finalized with time; each missing field named, including `prescriptions.N.*`; no lines allowed; save and re-finalize → 422 and content identical; visit status unchanged |
| `tests/Feature/Encounters/OwnVisitListTest` | own visits only; status filter for `none`, `draft`, `finalized`; patient filter; 92-day limit; order; paging; waiting and left excluded; no clinical content |
| `tests/Feature/Encounters/EncounterAccessTest` | own draft and finalized readable; another's draft → 404; another's finalized readable for waiting, with doctor, and seen today; not for left, undone, yesterday's list, or a stale with-doctor entry; identical 404 body for refused and unknown; non-author write → 403 |
| `tests/Feature/Encounters/PatientHistoryTest` | on list: all finalized from any doctor, newest first; off list: own only, none → 404; unknown → same 404; on list with none → empty; drafts excluded; item fields only; no clinical content |
| `tests/Feature/Encounters/AddendumTest` | author adds; content unchanged; oldest first; visible to permitted readers; draft → 422; empty and 10,001 characters → 422; non-author 403 or 404; no change or delete route exists |
| `tests/Feature/Encounters/EncounterPrivacyTest` | SC-002: planted text absent from every listing, validation error, and the log after a forced failure; raw database values are ciphertext; `zend.exception_ignore_args` is on; `$hidden` keeps clinical attributes out of `toArray` |
| `tests/Feature/Encounters/EncounterPersistenceTest` | unique visit index; foreign keys `RESTRICT`; history index exists; worst-case four-byte text and 20 full lines round-trip |
| `tests/Feature/Encounters/ConcurrentEncounterTest` `#[Group('concurrency')]` | 8 parallel starts → one record, the rest 422; saves against a finalize → finalized content never changes; parallel addenda all kept |
| `tests/Feature/Encounters/EncountersAuthorizationTest` | every role × every endpoint; no token 401; deactivated account 401; administrators and receptionists 403 before lookup |
| `tests/Feature/Encounters/EncountersPolicyTest` | roles × abilities; inactive actors refused; `view` and `write` for each record state |
| `tests/Feature/Encounters/EncountersMessagesTest` | every new message in `en` and `ar` with identical structure |

`TranslationParityTest` checks the new language files. Feature 007's tests must keep passing unchanged.

## Implementation Outline (input for `/speckit-tasks`)

1. Baseline: run the suite; `zend.exception_ignore_args` in `app.ini`, rebuild, and a test for it.
2. Schema: two migrations, models with encrypted casts and `$hidden`, factories, `EncounterStatus`;
   persistence test.
3. Module skeleton: provider, routes, translations, exceptions, `EncounterPolicy` with
   `QueueRepository::patientOnList`; policy test.
4. US1: `EncounterRepository`, `EncounterService::start` and `save`, `find`, start, save, and open routes.
5. US2: finalize with completeness rules.
6. US3: visit list.
7. US4: open under the access rule; patient history.
8. US5: addenda.
9. Concurrency: child script and `ConcurrentEncounterTest`.
10. Polish: privacy tests, authorization matrix, messages test, SC-005 measurement, README and roadmap,
    Pint, quickstart, verification record. Postman collection update as a separate follow-up.

## Approved Decisions (2026-11-05)

All four were approved by the user on 2026-11-05.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Protecting clinical content at rest | **Laravel's built-in encrypted casts on every clinical column** (`MEDIUMTEXT`). Losing `APP_KEY` loses the content, and SQL cannot search or group it — R6 | Plain columns, readable by anyone with database access or a dump |
| D2 | Storing prescription lines | **One encrypted JSON column on the record**; lines are structured in the API — R7 | A child table, which adds a model and rewrites on each save, with no query benefit under encryption |
| D3 | URLs | **Everything under `/doctors/me/…`**, including `/doctors/me/patients/{patient}/encounters` — R1 | `/encounters/{id}` and `/patients/{id}/encounters`, which read as staff routes |
| D4 | Stack trace arguments | **`zend.exception_ignore_args = On` for the whole app**, verified to remove the 15-character argument leak; needs an image rebuild — R6 | Marking parameters `#[\SensitiveParameter]`, which depends on never missing one |

## Risks, Assumptions, and Rollback

- **`APP_KEY` becomes critical** (D1): today it protects sessions and cookies, which can be reissued.
  With encryption, losing it destroys medical records, and changing it without `APP_PREVIOUS_KEYS` makes
  them unreadable. Back it up separately before production. The README gains this warning.
- **Feature 007 is not merged**: this plan is based on `007-phase-8-polish`. Merge it first, or branch
  008 from its tip.
- **Access depends on feature 007's list**: a change to how queue entries work could change who can
  read records. `EncounterAccessTest` fails if that happens.
- **Deactivated authors**: their drafts stay unreadable, and their finalized records can't be corrected,
  until reactivation. This is accepted in the spec; handing records over is out of scope.
- **Legal review**: Retention and protection requirements are still open items for Egyptian
  health-data review before production (Constitution I).
- **Rollback**: roll back the two migrations (destroys clinical data; opt-in only), remove the provider
  line, and revert the commit range. `patientOnList` and the `app.ini` line are harmless if left in.

## Project Structure

### Documentation (this feature)

```text
specs/008-medical-encounters/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── encounters.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Encounters/                                 # new (see Module layout above)
├── Queues/                                     # QueueRepository::patientOnList
├── Patients/                                   # read only (patient summary)
└── Doctors/                                    # read only (DoctorRepository, DoctorService::findOwn)
bootstrap/providers.php                         # changed
docker/php/conf.d/app.ini                       # changed
database/migrations/  database/factories/
tests/Feature/Encounters/  tests/Support/encounter-action.php
```

**Structure Decision**: The existing modular monolith. One new domain module, `Encounters`, following
`docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
