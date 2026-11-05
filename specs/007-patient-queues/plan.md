# Implementation Plan: Patient Queues

**Branch**: `007-patient-queues` | **Date**: 2026-11-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/007-patient-queues/spec.md`

## Summary

Add a `Queues` module with two tables (`queue_days`, `queue_entries`) and nine endpoints: six for
administrators and receptionists (check in an appointment, add a walk-in, the clinic's list for
today, one doctor's list, mark as left, undo a check-in) and three for doctors (own list, call next,
finish). Feature 006's appointments gain the states `checked_in` and `completed`, which keep holding
their slot (one migration changes the generated column behind the 006 unique index; verified on
MySQL 9.7.4). Every change to one doctor's queue on one day locks a per-doctor-per-day row first,
which serializes check-ins and calls and hands out queue numbers 1, 2, 3… that are never reused;
unique indexes are the database backstop. Waiting lists are ordered by appointment time, with
walk-ins placed by arrival time (spec Q2). No new dependencies. Evidence: [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. Two new tables; `appointments.scheduled_flag`
expression changed by a new migration ([data-model.md](data-model.md)); `patients`, `doctors`,
`users`, and the Scheduling tables are referenced or read, not changed.

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; Feature tests per user story
with `travelTo` in the clinic zone; a `concurrency` group with parallel PHP processes (feature 006
R9); feature 006's tests unchanged

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: A doctor's or the clinic's list for today in under 2 s with 500 doctors and
5,000 entries in the day (SC-004)

**Constraints**: No duplicate check-ins, no duplicate or reused queue numbers, one patient per
doctor and one doctor per patient at a time, also under concurrency (FR-003, FR-004, FR-011);
all-or-nothing changes (FR-016); clinic wall-clock "today" (FR-018); synthetic data only

**Scale/Scope**: Single clinic, ~500 doctors, a few thousand arrivals a day. 9 endpoints, 1 module,
3 migrations; small changes in Appointments (statuses, slot holding)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; class-level `QueueEntryPolicy` (reception: admin + receptionist; own: doctor); doctors act only on their own list and never send an entry id; lists carry only the patient summary; no `/patients` access for doctors | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; three new migrations, none edited; `/api/v1` via the module provider; no waiting-room display or patient portal | ✅ |
| III | Repository pattern | `QueueRepository` (+ Eloquent) for entries and queue days; `AppointmentRepository` gains purpose-named methods for check-in and completion; no generic repository | ✅ |
| IV | Thin controllers | `CheckInController`, `QueueController`, `OwnQueueController`: Form Request / Gate → service → Resource | ✅ |
| V | Services own workflows | `QueueService` (check-in, walk-in, leave, undo, lists) and `DoctorQueueService` (own list, call next, finish) own transactions and locks; repositories only query | ✅ |
| VI | Form Requests/Resources/Policies | `AddWalkInRequest`, `ListQueueRequest`; `QueueEntryResource`, `OwnQueueEntryResource`; `QueueEntryPolicy` | ✅ |
| VII | en/ar localization | `queues::messages` and `queues::attributes` in both locales; statuses and conflict reasons are fixed identifiers; message tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per story; concurrency group (SC-002); role × endpoint matrix (SC-005); both locales (SC-006); 006 regression for slot holding | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; MySQL behavior verified (R2); no new dependency; D1–D4 approved | ✅ (D1–D4 approved) |
| XI | Integrity & concurrency | One transaction per change with `attempts: 3`; queue-day row locked before any change; fixed lock order (queue day → appointment → patient); unique indexes as backstops; checked-in and completed appointments keep holding their slot; real parallel-process tests | ✅ |
| XII | No real data | Factories only; the concurrency script refuses any database but `clinic_testing`; no patient data in logs or exceptions | ✅ |

**Gate result (pre-research)**: PASS (D1–D4 approved 2026-11-02).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   CheckIn / Queue / OwnQueue controllers (thin)
                              │   Form Request or Gate → QueueEntryPolicy (class-level)
                              ▼
   QueueService / DoctorQueueService ─ DB::transaction(attempts: 3)
     1 lock queue day        ─▶ QueueRepository::lockDay(doctorId, today)   (no-op upsert + FOR UPDATE)
     2 lock appointment      ─▶ AppointmentRepository::lockById            (check-in, undo, finish)
     3 lock patient          ─▶ PatientRepository::lockById                (call next, walk-in)
     4 check + write         ─▶ QueueRepository, AppointmentRepository::update
```

### Endpoints ([contract](contracts/queues.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| POST | `/appointments/{appointment}/check-in` | admin, receptionist | FR-001–FR-005 |
| POST | `/queue/walk-ins` | admin, receptionist | FR-006 |
| GET | `/queue?status&page&per_page` | admin, receptionist | FR-007, FR-009 |
| GET | `/doctors/{doctor}/queue` | admin, receptionist | FR-007, FR-008 |
| POST | `/queue/{entry}/leave` | admin, receptionist | FR-014 |
| DELETE | `/queue/{entry}` | admin, receptionist | FR-014 |
| GET | `/doctors/me/queue` | doctor | FR-010 |
| POST | `/doctors/me/queue/call-next` | doctor | FR-011 |
| POST | `/doctors/me/queue/finish` | doctor | FR-012 |

Status codes: `200`, `201` created entry, `204` undo, `401`, `403`, `404` (unknown appointment,
entry, or doctor; no own profile), `409` backstop conflicts (`already_checked_in`,
`patient_with_another_doctor`), `422` validation (`not_checkable`, `already_in_queue`,
`not_changeable`, `visit_in_progress`, `nobody_waiting`, `no_current_visit`, unknown patient or
doctor).

### Module layout (new files)

```text
app/Modules/Queues/
├── Enums/QueueStatus.php                          # waiting, with_doctor, seen, left
├── Exceptions/
│   ├── QueueEntryNotFoundException.php            # 404; not reported
│   └── QueueConflictException.php                 # 409 {message, reason}; not reported
├── Http/
│   ├── Controllers/{CheckIn,Queue,OwnQueue}Controller.php
│   ├── Requests/{AddWalkIn,ListQueue}Request.php
│   └── Resources/{QueueEntry,OwnQueueEntry}Resource.php
├── Models/{QueueDay,QueueEntry}.php
├── Policies/QueueEntryPolicy.php
├── Providers/QueuesServiceProvider.php
├── Repositories/
│   ├── Contracts/QueueRepository.php              # lockDay, nextNumber, create, update, delete, find,
│   │                                              # forDoctor(day), paginateDay, withDoctor, firstWaiting,
│   │                                              # patientWithAnotherDoctor, patientActiveWithDoctor
│   └── EloquentQueueRepository.php
├── Services/
│   ├── QueueService.php                           # checkIn, addWalkIn, leave, undo, list, listForDoctor
│   └── DoctorQueueService.php                     # listOwn, callNext, finish
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_extend_appointment_slot_holding.php
database/migrations/<date>_create_queue_days_table.php
database/migrations/<date>_create_queue_entries_table.php
database/factories/QueueEntryFactory.php
tests/Support/queue-action.php                     # child process for the concurrency test
```

Changed existing files:

- `app/Modules/Appointments/Enums/AppointmentStatus.php` (`CheckedIn`, `Completed`, `holdingSlot()`)
- `app/Modules/Appointments/Repositories/EloquentBookedTimeRepository.php` and
  `EloquentAppointmentRepository.php` (slot filter and overlap checks use `holdingSlot()`)
- `app/Modules/Appointments/Repositories/Contracts/AppointmentRepository.php` (no new method if
  `lockById` and `update` suffice; confirmed during tasks)
- `specs/006-appointments/contracts/appointments.openapi.yaml` (`Status` enum gains two values)
- `bootstrap/providers.php` (register `QueuesServiceProvider`)
- `docs/roadmap.md`, `README.md` (short "Patient queues" section)

### Key rules

- **Authorization** (R7): class-level abilities in each Form Request's `authorize()`, and
  `Gate::authorize()` for actions without a body, before any lookup.
- **Locks first** (R3): queue day, then appointment, then patient; no decision is made from a plain
  read.
- **Today only** (spec): every action uses `ClinicClock::today()`; no date parameter exists.
- **Order** (R4): `order_minute`, then `queue_number`.
- **Slot holding** (R2): `scheduled`, `checked_in`, `completed` hold the slot everywhere in feature
  006; outside availability stays `scheduled`-only.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Feature/Queues/CheckInTest` | check-in today early and late; not today, cancelled, no-show, already checked in → 422; appointment `checked_in`; queue numbers 1, 2, 3; doctor 403 |
| `tests/Feature/Queues/AppointmentQueueStatesTest` | checked-in and completed appointments keep their slot hidden and refuse reschedule, cancel, no-show (006 messages); undo makes it scheduled and changeable again |
| `tests/Feature/Queues/WalkInTest` | walk-in created with next number, no slot change; unknown patient, unknown or inactive doctor, already in queue → 422 |
| `tests/Feature/Queues/QueueListTest` | doctor list in queue order (appointment time, walk-ins by arrival); clinic list by doctor, status filter, paging; no sensitive fields; unknown doctor 404; doctor 403 |
| `tests/Feature/Queues/DoctorQueueTest` | own list; call next picks first in order, skips a patient with another doctor; visit in progress, nobody waiting, finish without visit → 422; finish → seen + appointment completed; no profile 404; staff 403 |
| `tests/Feature/Queues/QueueCorrectionsTest` | leave only waiting; undo only waiting appointment entries; number not reused after undo |
| `tests/Feature/Queues/QueuePersistenceTest` | unique indexes: number, appointment, one with doctor per doctor and per patient; generated column values; slot index with new statuses |
| `tests/Feature/Queues/ConcurrentQueueTest` `#[Group('concurrency')]` | 8 parallel check-ins for one doctor → numbers 1…8; 2 parallel call-next → one patient called |
| `tests/Feature/Queues/QueuesAuthorizationTest` | every role × every endpoint; no token 401; deactivated account 401 |
| `tests/Feature/Queues/QueuesPolicyTest` | roles × abilities; inactive actors refused |
| `tests/Feature/Queues/QueuesMessagesTest` | every new message in `en` and `ar` with identical structure |

`TranslationParityTest` checks the new language files. Feature 006's tests must keep passing
unchanged.

## Implementation Outline (input for `/speckit-tasks`)

1. Appointments: `CheckedIn`, `Completed`, `holdingSlot()`; slot filter and overlap checks; migration
   for `scheduled_flag`; regression tests (006 suite unchanged).
2. Queues schema: `queue_days`, `queue_entries`, models, factory, `QueueStatus`; persistence test.
3. Module skeleton: provider, routes, translations, exceptions, policy; policy test.
4. US1: `QueueRepository` (lock day, numbers, create), `QueueService::checkIn`, check-in route.
5. US2: lists (doctor, clinic) with order and resources.
6. US4: walk-ins.
7. US3: own list, call next, finish (appointment completed).
8. US5: leave and undo.
9. Concurrency: child script and `ConcurrentQueueTest`.
10. Polish: authorization matrix, messages test, 006 contract enum, README and roadmap, Pint,
    quickstart, verification record. Postman collection update as a separate follow-up.

## Approved Decisions (2026-11-02)

All four were approved by the user on 2026-11-02.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | How an appointment shows check-in and completion | **New appointment statuses `checked_in` and `completed`**, holding the slot like `scheduled`; one migration changes the 006 generated column (verified) — R2 | Keep `scheduled` and derive check-in from the queue (Appointments would depend on Queues) |
| D2 | Concurrency and queue numbers | **A per-doctor-per-day row (`queue_days`) locked for every queue change**, holding the last number; unique indexes as backstops — R3 | `MAX()+1` with retries (numbers reused after undo; collisions instead of waiting) |
| D3 | URLs | **`/appointments/{id}/check-in`, `/queue…` for reception, `/doctors/{doctor}/queue`, `/doctors/me/queue[/call-next\|/finish]` for doctors** — R1 | Doctors acting on entry ids (`/queue/{entry}/call`) |
| D4 | A patient waiting for two doctors | **Call next skips a patient who is with another doctor right now**; database backstop on `(patient, date, with doctor)` — R5 | Refuse the call, or let a patient be with two doctors |

## Risks, Assumptions, and Rollback

- **Lock contention**: all queue changes of one doctor on one day wait on one row; transactions are
  a few indexed reads and one or two writes. Acceptable at one clinic's volume.
- **Feature 006 coupling**: changing `scheduled_flag` and the slot filter touches booking; covered
  by the unchanged 006 tests plus new slot-holding tests.
- **Generated column change**: the migration fails safely (duplicate key) if conflicting rows ever
  existed; none can exist before the new statuses do.
- **Rollback**: roll back the three migrations (destructive for queue data; opt-in only; the
  appointments migration's `down()` fails if checked-in/completed rows would now collide, so set
  them back first), remove the provider line, and revert the commit range.

## Project Structure

### Documentation (this feature)

```text
specs/007-patient-queues/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── queues.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Queues/                                     # new (see Module layout above)
├── Appointments/                               # AppointmentStatus, slot holding
├── Patients/                                   # read only (PatientRepository::lockById)
└── Doctors/                                    # read only (DoctorRepository, DoctorService::findOwn)
bootstrap/providers.php                         # changed
database/migrations/  database/factories/QueueEntryFactory.php
tests/Feature/Queues/  tests/Support/queue-action.php
```

**Structure Decision**: The existing modular monolith. One new domain module, `Queues`, following
`docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
