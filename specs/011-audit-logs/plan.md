# Implementation Plan: Audit Logs

**Branch**: `011-audit-logs` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/011-audit-logs/spec.md`

## Summary

Add an `Audit` module with one append-only table and one search endpoint for administrators. Eight existing modules call a small `AuditTrail` service explicitly, inside the same transaction as the
change, so a change and its entry succeed or fail together. Entries hold field names, plus before and after values from a default-deny allow-list, so clinical text and patient details cannot enter
by mistake. Opening a patient or a medical record writes an entry first, and if it cannot be saved the record is not returned. Refused openings are recorded by one route middleware that cannot alter the
response. Search uses cursor paging, because each search adds an entry and would shift offset pages. No new dependencies. Evidence: [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. One new table, `audit_entries` ([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; Feature tests per story, including `AuditChanges`, and one coverage test across all 44 actions; the concurrency group re-run, since
transactions gain a write, with a new group using a real signed-in actor; earlier features' tests unchanged.

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: A search by patient or record in under 2 s with 5,000,000 entries (SC-002); no more than 100 ms added to a recorded action (SC-007)

**Constraints**: A change and its entry are atomic (FR-007); no clinical content, patient details, or credentials in entries (FR-006, FR-008, SC-003); refused responses unchanged (FR-005, SC-006); no way to
change or delete an entry (FR-014); administrators only (FR-013); synthetic data only

**Scale/Scope**: Single clinic, a few thousand visits a day, about 10 million entries a year (research R10). 1 endpoint, 1 module, 1 migration, 44 actions recorded across 8 modules

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | Endpoint behind `auth:sanctum`; administrators only, refused before validation; a default-deny allow-list for values; patients by internal id only; refusals and openings of medical records traced | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; one new migration, none edited; `/api/v1` via the module provider; no portal or frontend | ✅ |
| III | Repository pattern | `AuditEntryRepository` contract and implementation, bound in the provider, with `append` and `search` only; not generic | ✅ |
| IV | Thin controllers | `AuditEntryController`: Form Request → service → Resource. `RecordRefusedOpening` only reads the status and calls a service | ✅ |
| V | Services own workflows | Services call `AuditTrail` within transactions they already own; existence checks are in `EncounterRepository` | ✅ |
| VI | Form Requests/Resources/Policies | `SearchAuditEntriesRequest`; `AuditEntryResource`; `AuditEntryPolicy` registered with `Gate::policy` | ✅ |
| VII | en/ar localization | `audit::messages` and `audit::attributes` in both locales; the general error translated (R13); actions and outcomes are fixed identifiers | ✅ (D7 approved) |
| VIII | Tests & acceptance criteria | Coverage test across every action; atomicity; planted values; role matrix; unchanged refusals; both locales | ✅ |
| IX | Small tasks | See Implementation Outline; each module's calls form their own task, with its own test | ✅ |
| X | No invention | Actions trace to FR-003–FR-005 and existing routes; behavior of change detection, sign-in limits, and the command checked (R4, R6); no new dependency | ✅ (D1–D7 approved) |
| XI | Integrity & concurrency | Entries share each change's transaction; new shared locks traced and tested by `ConcurrentAuditTest` (R10); concurrency group re-run | ✅ |
| XII | No real data | Factories only; no patient identifiers beyond internal ids; performance runs on `clinic_testing` | ✅ |

The only departure is the polymorphic `subject_id` without a foreign key (Constitution, Technology & Security Constraints), justified in Complexity Tracking.

**Gate result (pre-research)**: PASS (D1–D7 approved 2026-11-05).

**Post-design re-check**: PASS, with one justified departure.

## Design

### Recording a change

```text
 client ──▶ controller ──▶ PatientService::update
                             DB::transaction
                               PatientRepository::update          fill + save
                               AuditTrail::recordChanges(action, model, subject, patientId)
                                 AuditChanges     names from getChanges(); values from the allow-list
                                 nothing changed? write nothing
                                 AuditEntryRepository::append
                             commit: both stand; any failure: neither
```

### Recording an opening or a refusal

```text
 GET /patients/{id}, /doctors/me/encounters/{id}, /doctors/me/patients/{id}/encounters
   allowed   ──▶ Service::open / history ──▶ AuditTrail::record ──▶ return the record
                                               fails? a general 500; the record is not returned
   refused   ──▶ 403 or 404 built as today
                   ▼
                 RecordRefusedOpening (the two encounter routes only)
                   record exists? AuditTrail::recordRefusal
                   fails? report the error; the response goes out unchanged
```

### Endpoint ([contract](contracts/audit.openapi.yaml))

| Method | Path (`/api/v1`) | Parameters | Spec |
|--------|------------------|------------|------|
| GET | `/audit-entries` | `from`, `to`, `staff_id`, `patient_id`, `subject_type`, `subject_id`, `action`, `outcome`, `per_page`, `cursor` | US1–US3, FR-009–FR-012 |

Status codes: `200`, `401`, `403`, `422`.

### Module layout (new files)

```text
app/Modules/Audit/
├── Enums/{AuditAction,AuditActorType,AuditOutcome,AuditSubject}.php
├── Http/
│   ├── Controllers/AuditEntryController.php
│   ├── Requests/SearchAuditEntriesRequest.php        # policy first, then the filters
│   └── Resources/AuditEntryResource.php
├── Models/AuditEntry.php                             # created_at only; actor relation
├── Policies/AuditEntryPolicy.php                     # active administrator
├── Providers/AuditServiceProvider.php
├── Repositories/
│   ├── Contracts/AuditEntryRepository.php            # append, search
│   └── EloquentAuditEntryRepository.php
├── Services/
│   ├── AuditTrail.php                                # record, recordChanges, recordRefusal
│   └── AuditSearchService.php                        # search, then record the search
├── Support/AuditChanges.php                          # names, the allow-list, values
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_create_audit_entries_table.php
database/factories/AuditEntryFactory.php
```

### Changes to existing modules

Each gains the `AuditTrail` dependency and the calls listed in [data-model.md](data-model.md), with no change to rules or responses (FR-015).

| Module | Change |
|--------|--------|
| Staff | `AuthenticationService`, `StaffManagementService`: calls; new transactions (R3); failed sign-ins recorded before the existing exception |
| Patients | `PatientService`: calls; new transactions; new `open`, used by `PatientController::show` |
| Doctors | `DoctorService`, `SpecialtyService`: calls; new transactions; `specialty_ids` from the sync result |
| Scheduling | `ScheduleService`, `TimeOffService`: calls; new transactions; comparison so an unchanged schedule records nothing |
| Appointments | `BookingService`, `AppointmentService`: calls |
| Queues | `QueueService`, `DoctorQueueService`: calls; `undo` records before deleting |
| Encounters | `EncounterService`: calls; new `open`; `addAddendum` transaction; existence checks in the repository; `RecordRefusedOpening` on two routes; `EncounterController::show` uses `open` |
| Payments | `ChargeService`, `FeeService`: calls; `FeeService::set` transaction, and comparison for an unchanged fee |

Also changed: `bootstrap/providers.php`; `bootstrap/app.php` and `lang/{en,ar}/auth.php` (translated general error, R13); `docs/roadmap.md`; `README.md`; and `docs/architecture.md` (recording rule for future features, and
route-specific middleware in a module).

### Key rules

- **Atomic** (FR-007): `AuditTrail` never opens a transaction; each service records inside its own, and no call may catch and ignore a failure, except `recordRefusal`.
- **Default deny** (FR-006): values come only from the allow-list in `AuditChanges`; a new column keeps its name only until someone adds it deliberately.
- **Append only** (FR-014): the repository contract has no update or delete, and no route writes.
- **Nothing sensitive**: entries are built from names, allow-listed values, and ids, so no code path passes request text or models into `details`.
- **Own entry last** (FR-011): a search reads first and records after, so it does not list itself.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Feature/Localization/ServerErrorMessageTest` | R13: the general error in `en` and `ar` with debug off; the exception text never in the body; `422`, `401`, `403`, and `404` unchanged; debug output not replaced |
| `tests/Feature/Audit/AuditChangesTest` | Allowed values kept, others name-only, an unknown type keeps names only, ignored columns left out, encrypted columns give names with no ciphertext, creation gives `from: null`, nothing changed gives nothing |
| `tests/Feature/Audit/AuditTrailTest` | Actor, role, and address taken from the request; `system` with no address; an explicit actor; `recordRefusal` keeps going when saving fails |
| `tests/Feature/Audit/AuditEntriesTableTest` | Columns, indexes, foreign keys; removing a subject record leaves its entry |
| `tests/Feature/Audit/AuditActionCoverageTest` | SC-001: every `AuditAction` case, driven through its real endpoint, writes exactly one correct entry. Fails if a case has no scenario, so a new action cannot be left out |
| `tests/Feature/Audit/AuditAtomicityTest` | SC-004, US4: for a change in each module, a failing repository leaves the record unchanged and returns a general error; a rolled-back change leaves no entry; a refused or invalid change leaves none |
| `tests/Feature/Audit/AuditNoChangeTest` | Each update repeated with the same data writes no second entry; `discount.set` repeated still does; an idempotent deactivate writes none |
| `tests/Feature/Audit/AuditOpeningsTest` | US2: opening records `succeeded`; a failing repository returns no record; history paged and empty; listings write nothing; reports write nothing |
| `tests/Feature/Audit/AuditRefusedOpeningsTest` | US2, SC-006: other doctor `404` and receptionist or administrator `403` recorded as `refused`; unknown ids, and `422`, write nothing; bodies byte-identical to today's; a failing repository leaves the response unchanged |
| `tests/Feature/Audit/AuditSignInTest` | US3: success, wrong password, deactivated account, unknown email; attempts stopped by the limit write nothing; the password and unknown email stored nowhere |
| `tests/Feature/Audit/AuditPrivacyTest` | SC-003, FR-017: planted patient details, clinical text, reasons, passwords, and search text appear in no stored `details`, search response, or application log, across every action |
| `tests/Feature/Audit/AuditImmutabilityTest` | FR-014: no write route; only `GET` routes exist; the repository has only `append` and `search`; entries outlive their records; users and patients with entries cannot be deleted |
| `tests/Feature/Audit/ConcurrentAuditTest` | Constitution XI, R10: simultaneous processes with a real signed-in actor; no deadlock or lock timeout; each success has one entry, and each failure none |
| `tests/Feature/Audit/AuditSearchTest` | Each filter and combinations; `staff_id` as actor and as subject; clinic-day edges across daylight saving; newest first; cursor stable while entries are added; current name with the role then held; deactivated staff; removed records; unknown ids give an empty page; each search recorded with its filters |
| `tests/Feature/Audit/AuditCommandTest` | `staff:create-admin` records `system`, with no address |
| `tests/Feature/Audit/AuditValidationTest` | Malformed filters and cursors; `to` before `from`; `subject_type` and `subject_id` required together; `per_page` bounds |
| `tests/Feature/Audit/AuditAuthorizationTest` | SC-005: every role; no token `401`; deactivated `401`; non-administrators `403` before validation |
| `tests/Feature/Audit/AuditEntryPolicyTest` | Roles × ability; inactive actors refused |
| `tests/Feature/Audit/AuditMessagesTest` | SC-008: every new message in `en` and `ar`, with identical structure |

`TranslationParityTest` checks the new language files. Earlier features' tests must keep passing unchanged, which is SC-006 and FR-015's main evidence.

## Implementation Outline (input for `/speckit-tasks`)

1. Baseline: run the suite.
2. Foundation: migration and table test; enums; `AuditEntry` and factory; repository; `AuditChanges` and its test; `AuditTrail`; provider and registration; the translated general error.
3. US1, changes and finding them: search endpoint, policy, request, resource; then calls in Patients and Payments, with atomicity, no-change, and search tests.
4. US1, remaining modules, each its own task: Doctors, Scheduling, Appointments, Queues, then Encounter changes. The concurrency group runs after each module that has locks.
5. US2: `open` methods; `RecordRefusedOpening`; opening and refusal tests.
6. US3: Staff module calls; sign-in; command; sign-in tests; staff filter; then the concurrency test with a real actor, once every module records.
7. US4 and polish: search recording; coverage test across all 44 actions; privacy, authorization, and message tests; architecture, README, and roadmap; Pint; SC-002 and SC-007 measurement; quickstart; verification
   record. Postman collection update as a separate follow-up.

## Approved Decisions (2026-11-05)

All seven were approved by the user on 2026-11-05. D7 was added after `/speckit-analyze`.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | URL | **One endpoint, `GET /audit-entries`, with filters** — R1 | Nested routes per record, which leave removed records with no parent |
| D2 | How entries get written | **Explicit `AuditTrail` calls in services, inside their transactions** — R2 | Observers or middleware, which miss upserts and reads, or run after commit and so break FR-007 |
| D3 | Existing services | **Add `DB::transaction` to the 16 methods that write without one** — R3 | Writing entries outside a transaction, which could lose an entry or keep one for a failed change |
| D4 | Refused openings | **One middleware on the two encounter routes, reading the response status** — R7 | Branches in the controller and Form Request, which are three places, and one would change a `403` to a `422` |
| D5 | Paging | **Cursor paging, without a total; the only list in the API that differs** — R9 | Offset paging, where each search shifts the pages and a count runs on a growing table |
| D6 | Record reference | **`subject_type` + `subject_id` with no foreign key** — R10 | Foreign keys, which cannot span nine tables and would block or lose entries when records are removed |
| D7 | General error | **Translate the message of every unexpected `500` on the API, with debug off** — R13 | Leaving it in English, which breaks Constitution VII now that the spec promises this response; a separate feature first, which would hold this one up |

## Risks, Assumptions, and Rollback

- **Blast radius**: Eight modules change, though each by a few lines. Earlier features' tests are the guard, and each module is a separate task.
- **New failure mode**: An action can now fail if its entry cannot be saved. This was chosen in the spec, and the trail shares the clinic's database, so it should coincide with other failures.
- **Shared error message**: The translated general error applies to every module (D7). Only its text changes, not the status.
- **New locks**: Update-only transactions now take shared locks on the user and patient rows (R10). `ConcurrentAuditTest` tests this, and a deadlock stops the work.
- **Growth**: About 10 million entries a year, with no retention yet. Legal review should set a period before production. SC-002 has not been measured yet; a miss will be reported, not hidden.
- **Timing**: A refused request does one more insert when a record exists, which could in principle reveal existence (R8). Accepted as small against network noise.
- **Proxies**: Behind a load balancer, addresses are wrong until trusted proxies are configured (R11), and the per-address sign-in limit has the same problem. Noted for deployment.
- **Future features**: A new sensitive action needs its own call. The coverage test catches an added enum case with no scenario, but not a new action nobody added to the enum, so `docs/architecture.md` gains the rule.
- **Legal**: Retention, and whether network addresses count as personal data, need legal review before production (spec, Assumptions).
- **Rollback**: Remove the provider line, the module, and the calls by reverting the commit range, then roll back the migration **only with explicit approval**, because that deletes the trail. The added transactions are safe to keep.

## Project Structure

### Documentation (this feature)

```text
specs/011-audit-logs/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── audit.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Audit/                                      # new (see Module layout above)
└── Staff/ Patients/ Doctors/ Scheduling/
    Appointments/ Queues/ Encounters/ Payments/ # services gain recording calls
bootstrap/providers.php  bootstrap/app.php      # changed
lang/{en,ar}/auth.php                           # changed
database/migrations/  database/factories/
tests/Feature/Audit/  tests/Feature/Localization/ServerErrorMessageTest.php  tests/Support/audit-action.php
```

**Structure Decision**: The existing modular monolith. One new domain module, `Audit`, following `docs/architecture.md`.

## Complexity Tracking

| Departure | Why it is needed | Simpler alternative rejected because |
|-----------|------------------|--------------------------------------|
| `audit_entries.subject_id` has no foreign key (Constitution, Technology & Security Constraints) | Entries must outlive removed records (FR-014), and the column refers to nine record types | A nullable foreign key per type adds nine columns and still loses the reference when a row such as an undone check-in is deleted |
