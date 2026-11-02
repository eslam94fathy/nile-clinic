# Implementation Plan: Appointments

**Branch**: `006-appointments` | **Date**: 2026-11-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/006-appointments/spec.md`

## Summary

Add an `Appointments` module with one table (`appointments`) and eight endpoints: six under
`/appointments` for administrators and receptionists (list, book, show, reschedule, cancel,
no-show) and two under `/doctors/me/appointments` for a doctor's own appointments. A booking must
start on a slot feature 005 currently offers and takes that slot's end. Double booking is
prevented by locking the doctor's schedule row and the patient row before any read, inside one
transaction, with a unique index on scheduled `(doctor, date, start)` as a database backstop (D3).
Feature 005's slots stop offering anything that overlaps a scheduled appointment, through a small
`BookedTimeRepository` contract that Scheduling declares and Appointments implements (D1).
Schedule and time off changes are never blocked; affected appointments are shown as "outside
availability", computed in SQL on every read and filterable (D2, spec Q2). Doctors see only their
own appointments with a short patient summary, get 404 for another doctor's id (D4), and still
have no patient-record access (spec Q1). No new dependencies. Evidence: [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. One new table with a stored generated
column ([data-model.md](data-model.md)); `patients`, `doctors`, `users`, and the three Scheduling
tables are referenced or read, not changed.

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; unit tests for the slot
calculator change and status rules; Feature tests per user story; a `concurrency` group running
parallel PHP processes (R9); `travelTo` fixes "now" in the clinic zone

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: One doctor's day, or one patient's history, in under 2 s with 100,000
appointments (SC-005), measured by quickstart Q8

**Constraints**: No double booking under concurrency (FR-003, FR-004); all-or-nothing changes
(FR-005); clinic wall-clock time (FR-020); synthetic data only

**Scale/Scope**: Single clinic, ~500 doctors, ~100,000 appointments. 8 endpoints, 1 module, 1
migration; small changes in Scheduling and Patients (data-model.md, last table)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; class-level `AppointmentPolicy` (staff: admin + receptionist; own: doctor); doctors get 404 for other doctors' ids and still no `/patients` access (Q1); lists carry only the patient summary; the note only in single views and documented as non-medical | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; one new migration, none edited; `/api/v1` via the module provider | ✅ |
| III | Repository pattern | `AppointmentRepository` + `EloquentAppointmentRepository`; `EloquentBookedTimeRepository` implements Scheduling's new `BookedTimeRepository`; two purpose-named methods added to existing contracts (`lockForDoctor`, `lockById`); no generic repository | ✅ |
| IV | Thin controllers | `AppointmentController`, `AppointmentActionController`, `OwnAppointmentController`: Form Request in → service → Resource out | ✅ |
| V | Services own workflows | `BookingService` (book, reschedule: locks, slot check, overlap checks, insert/update) and `AppointmentService` (list, find, cancel, no-show) own transactions; the outside-availability expression is a query in the repository, with no business workflow | ✅ |
| VI | Form Requests/Resources/Policies | `ListAppointmentsRequest`, `BookAppointmentRequest`, `RescheduleAppointmentRequest`, `CancelAppointmentRequest`, `ListOwnAppointmentsRequest`; `AppointmentResource`, `AppointmentSummaryResource`, `OwnAppointmentResource`, `OwnAppointmentSummaryResource`; `AppointmentPolicy` | ✅ |
| VII | en/ar localization | `appointments::messages` and `appointments::attributes` in both locales; `status` and conflict `reason` are fixed identifiers; message tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per story; concurrency group (SC-002); slot consistency after every change (SC-004); outside-availability matrix (SC-008); role × endpoint matrix (SC-006); both locales (SC-007) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; no new dependency; D1–D4 approved | ✅ (D1–D4 approved) |
| XI | Integrity & concurrency | One transaction per change with `attempts: 3`; schedule row then patient row locked `FOR UPDATE` before any read; unique index on scheduled `(doctor_id, date, start_minute)`; real parallel-process tests; no money in this feature | ✅ |
| XII | No real data | Factories only; the concurrency script refuses any database but `clinic_testing`; no patient data in logs or exceptions | ✅ |

**Gate result (pre-research)**: PASS (D1–D4 approved 2026-11-01).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   Appointment / AppointmentAction / OwnAppointment controllers (thin)
                              │   Form Request: formats, ranges, horizon (ClinicClock)
                              │   └─ authorize() → AppointmentPolicy (class-level)
                              ▼
   BookingService ─ DB::transaction(attempts: 3)            AppointmentService
     1 lock schedule row ─▶ DoctorScheduleRepository::lockForDoctor   (Scheduling)
     2 lock patient row  ─▶ PatientRepository::lockById               (Patients)
     3 doctor active?    ─▶ DoctorRepository::findById                (Doctors)
     4 slot exists?      ─▶ SlotService::workingSlots                 (Scheduling)
     5 overlaps?         ─▶ AppointmentRepository::doctorOverlaps / patientOverlaps
     6 insert / update   ─▶ AppointmentRepository
                                                   ▲
   Scheduling SlotService::forDoctor ─▶ BookedTimeRepository ◀── EloquentBookedTimeRepository (Appointments)
```

### Endpoints ([contract](contracts/appointments.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| GET | `/appointments?from&to&doctor_id&patient_id&status&outside_availability` | admin, receptionist | FR-008, FR-009, FR-018 |
| POST | `/appointments` | admin, receptionist | FR-001–FR-007 |
| GET | `/appointments/{appointment}` | admin, receptionist | FR-010 |
| POST | `/appointments/{appointment}/reschedule` | admin, receptionist | FR-011 |
| POST | `/appointments/{appointment}/cancel` | admin, receptionist | FR-012 |
| POST | `/appointments/{appointment}/no-show` | admin, receptionist | FR-013 |
| GET | `/doctors/me/appointments?from&to&status` | doctor | FR-015 |
| GET | `/doctors/me/appointments/{appointment}` | doctor | FR-015, R8 |

Status codes: `200`, `201` booked, `401`, `403`, `404` unknown appointment (or another doctor's,
or no own profile), `409` `slot_booked` / `patient_busy`, `422` validation (including
`slot_unavailable`, `not_changeable`, `not_started`, unknown `patient_id`/`doctor_id`).

### Module layout (new files)

```text
app/Modules/Appointments/
├── Enums/AppointmentStatus.php                     # scheduled, cancelled, no_show
├── Exceptions/
│   ├── AppointmentNotFoundException.php            # 404; not reported
│   └── AppointmentConflictException.php            # 409 {message, reason}; not reported
├── Http/
│   ├── Controllers/{Appointment,AppointmentAction,OwnAppointment}Controller.php
│   ├── Requests/{ListAppointments,BookAppointment,RescheduleAppointment,CancelAppointment,ListOwnAppointments}Request.php
│   └── Resources/{Appointment,AppointmentSummary,OwnAppointment,OwnAppointmentSummary}Resource.php
├── Models/Appointment.php
├── Policies/AppointmentPolicy.php
├── Providers/AppointmentsServiceProvider.php       # binds both repositories, incl. Scheduling's BookedTimeRepository
├── Repositories/
│   ├── Contracts/AppointmentRepository.php         # paginate(filters, now), find(id, now), findForDoctor(doctorId, id, now),
│   │                                               # lockById, doctorOverlaps, patientOverlaps (excludeId), create, update
│   ├── EloquentAppointmentRepository.php           # outside_availability expression (R7)
│   └── EloquentBookedTimeRepository.php            # implements Scheduling\…\BookedTimeRepository
├── Services/
│   ├── BookingService.php                          # book(), reschedule(): locks first (R3), slot + overlap checks (R4)
│   └── AppointmentService.php                      # list(), find(), listOwn(), findOwn(), cancel(), markNoShow()
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_create_appointments_table.php
database/factories/AppointmentFactory.php
tests/Support/book-appointment.php                  # child process for the concurrency test (R9)
```

Changed existing files:

- `app/Modules/Scheduling/Repositories/Contracts/BookedTimeRepository.php` (new contract, R2)
- `app/Modules/Scheduling/Support/SlotCalculator.php` (`$booked` parameter)
- `app/Modules/Scheduling/Services/SlotService.php` (`workingSlots()`; `forDoctor()` subtracts booked)
- `app/Modules/Scheduling/Repositories/{Contracts/DoctorScheduleRepository,EloquentDoctorScheduleRepository}.php` (`lockForDoctor`)
- `app/Modules/Patients/Repositories/{Contracts/PatientRepository,EloquentPatientRepository}.php` (`lockById`)
- `bootstrap/providers.php` (register `AppointmentsServiceProvider`)
- `docs/roadmap.md` (006 status), `README.md` (short "Appointments" section linking the contract)

### Key rules

- **Authorization** (R8): class-level abilities in each Form Request's `authorize()`, and
  `Gate::authorize()` for actions without a body (show, no-show, own show), before any lookup.
- **Locks first** (R3): in `book()` the first two statements in the transaction are
  `lockForDoctor` and `lockById`; in `reschedule()` the appointment is locked first, then the
  same two. No plain read happens before the locks.
- **Bookable** (R4): `start` must equal a start in `SlotService::workingSlots($doctorId, $date,
  $date)`; otherwise 422 `slot_unavailable` on `start`. Then doctor overlap → 409 `slot_booked`,
  then patient overlap → 409 `patient_busy`. A unique violation on insert/update → 409
  `slot_booked`.
- **Transitions** (R5): reschedule and cancel only `scheduled` and not started; no-show only
  `scheduled` and started; otherwise 422 `not_changeable` / `not_started` on `appointment`.
- **Clock**: `ClinicClock::now()` (feature 005) is passed to the repository for "not started" and
  `outside_availability`; Form Requests use `ClinicClock::today()` for the horizon.
- **Responses**: the staff summary includes `outside_availability`, doctor `{id, name, title}`,
  patient `{id, full_name, phone}`; the own summary drops `doctor` and shows patient
  `{id, full_name, sex, date_of_birth}`; neither list includes `note`.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Scheduling/SlotCalculatorTest` (extended) | booked intervals drop overlapping slots; a booking under an old length hides every overlapping slot of the new grid; touching intervals keep the slot |
| `tests/Feature/Scheduling/SlotsTest` (extended) | `/slots` and `/doctors/me/slots` hide booked slots; cancelled and no-show appointments do not |
| `tests/Feature/Appointments/BookAppointmentTest` | book and read back; `slot_unavailable` for outside hours, time off, past, beyond horizon, off grid, inactive doctor, no schedule (SC-003); `slot_booked`; `patient_busy` across doctors; unknown patient/doctor 422; nothing saved on refusal |
| `tests/Feature/Appointments/ListAppointmentsTest` | range rules; each filter and combinations; order; paging; no `note` or sensitive patient fields in lists (US2-6); single view fields |
| `tests/Feature/Appointments/RescheduleAppointmentTest` | move frees old slot and takes new one (SC-004); same rules as booking; into a slot overlapping its own old time; cancelled/no-show/started refused |
| `tests/Feature/Appointments/CancelAndNoShowTest` | cancel frees slot, reason kept; rebooking a cancelled slot; no-show only after start; final states refused (FR-014) |
| `tests/Feature/Appointments/OutsideAvailabilityTest` | time off whole and partial, period removed or shortened, schedule cleared, doctor deactivated → `true`; length change alone → `false`; started, cancelled, no-show → `false`; filter `true`/`false`; restoring hours clears it (SC-008) |
| `tests/Feature/Appointments/OwnAppointmentsTest` | own list and show; another doctor's id 404; no profile 404; other roles 403; `/patients/{id}` still 403 for doctors (Q1) |
| `tests/Feature/Appointments/ConcurrentBookingTest` `#[Group('concurrency')]` | 8 parallel processes, one slot → one success, rest `slot_booked`; one patient, 8 doctors → one success, rest `patient_busy` (SC-002) |
| `tests/Feature/Appointments/AppointmentPersistenceTest` | unique index rejects a second scheduled row and allows cancelled duplicates; foreign keys restrict deletes; generated column values |
| `tests/Feature/Appointments/AppointmentsAuthorizationTest` | every role × every endpoint; no token 401; deactivated account 401 (SC-006) |
| `tests/Feature/Appointments/AppointmentPolicyTest` | roles × abilities; inactive actors refused |
| `tests/Feature/Appointments/AppointmentsMessagesTest` | `slot_unavailable`, `slot_booked`, `patient_busy`, `not_changeable`, `not_started`, `not_found`, `403` in `en` and `ar` with identical structure (SC-007) |

`TranslationParityTest` (feature 001) checks the new language files. Feature 005's existing tests
must keep passing unchanged.

## Implementation Outline (input for `/speckit-tasks`)

1. Migration, `AppointmentStatus`, model, factory; persistence test.
2. Scheduling changes: `BookedTimeRepository` contract, `SlotCalculator` `$booked`, `SlotService`
   `workingSlots()`/`forDoctor()`, `lockForDoctor`; Patients `lockById`; unit tests; 005 tests
   still green.
3. Module skeleton: provider (bindings incl. `BookedTimeRepository`), routes, translations,
   exceptions, policy; registered in `bootstrap/providers.php`; policy test.
4. US1: `AppointmentRepository` (create, overlaps, find), `BookingService::book`,
   `BookAppointmentRequest`, `AppointmentResource`, `POST /appointments`; booking tests; slots
   tests extended.
5. Concurrency: `tests/Support/book-appointment.php` and `ConcurrentBookingTest`.
6. US2: `paginate` with filters and the `outside_availability` expression, `GET /appointments` and
   `GET /appointments/{id}`; list tests; outside-availability tests.
7. US3: `reschedule`, `cancel`; their tests.
8. US4: `markNoShow`; its tests.
9. US5: own list and show; own tests.
10. Polish: authorization matrix, messages test, README and roadmap, Pint, quickstart (Q8
    timing), verification record. Postman collection update as a separate follow-up, as for 005.

## Approved Decisions (2026-11-01)

All four were approved by the user on 2026-11-01.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Hiding booked slots | **Port in Scheduling**: `BookedTimeRepository` contract declared by Scheduling, implemented and bound by Appointments; one-way dependency — R2 | `SlotService` uses the Appointments repository (circular) |
| D2 | Outside availability | **Computed in SQL on every read** in `EloquentAppointmentRepository`, also used for the filter — R7 | Stored flag kept in sync from schedule, time off, and account changes |
| D3 | Concurrency | **Lock schedule row, then patient row, before any read, plus a unique index on scheduled `(doctor_id, date, start_minute)`** — R3 | Row locks only |
| D4 | Doctor opening another doctor's appointment | **404 on `/doctors/me/appointments/{id}`; 403 on `/appointments…`**; spec US5-2 updated — R8 | 403 after loading the record |

## Risks, Assumptions, and Rollback

- **Lock contention**: every booking for a doctor waits on that doctor's schedule row, and
  schedule replacement waits on bookings. Transactions are short (a few indexed reads and one
  write); acceptable at one clinic's volume.
- **Snapshot timing**: correctness depends on taking the locks before any plain read in the
  transaction (R3). The concurrency test fails if this order is broken.
- **Child-process environment**: the concurrency test assumes child processes inherit PHPUnit's
  `DB_DATABASE=clinic_testing`; the script checks and refuses otherwise. To be confirmed during
  implementation.
- **Outside-availability query cost**: three correlated subqueries per row; bounded by page size
  and indexed lookups; measured by quickstart Q8.
- **Module coupling**: `EloquentAppointmentRepository` reads Scheduling and Staff tables for R7
  (read-only, one documented expression).
- **Rollback**: `php artisan migrate:rollback --step=1` (destructive for appointments; opt-in
  only), remove the provider line, and revert the whole commit range, including the Scheduling
  changes: `SlotService` needs a `BookedTimeRepository` binding, which only the Appointments
  provider supplies.

## Project Structure

### Documentation (this feature)

```text
specs/006-appointments/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── appointments.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Appointments/                               # new (see Module layout above)
├── Scheduling/                                 # BookedTimeRepository, SlotCalculator, SlotService, lockForDoctor
├── Patients/                                   # PatientRepository::lockById
└── Doctors/                                    # read only (DoctorRepository, DoctorService::findOwn)
bootstrap/providers.php                         # changed
database/migrations/  database/factories/AppointmentFactory.php
tests/Feature/Appointments/  tests/Support/book-appointment.php
tests/Unit/Scheduling/  tests/Feature/Scheduling/   # extended
```

**Structure Decision**: The existing modular monolith. One new domain module, `Appointments`,
following `docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
