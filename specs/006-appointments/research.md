# Research: Appointments

**Feature**: 006-appointments | **Date**: 2026-11-01

Facts below were checked on 2026-11-01 against the repository: Laravel Framework 13.38.0
(`composer.lock`), PHP 8.5.12 (`docker/php/Dockerfile`), MySQL 9.7.4 (`compose.yaml`), and the
existing `Staff`, `Patients`, `Doctors`, and `Scheduling` modules. Items marked *(approved — Dn)*
were approved by the user on 2026-11-01 and are listed in the plan's Decisions table. Spec
clarifications Q1 and Q2 were answered on 2026-11-01 (spec, Clarifications).

## R1 — New `Appointments` module and URLs

- **Decision**: A new module, `app/Modules/Appointments/`. Staff endpoints are top-level:
  `/appointments`, `/appointments/{appointment}`, and action endpoints
  `/appointments/{appointment}/reschedule`, `/cancel`, `/no-show` (the `POST /…/deactivate` style
  of features 002 and 004). A doctor's own appointments live at `/doctors/me/appointments` and
  `/doctors/me/appointments/{appointment}`, like `/doctors/me/slots` in feature 005.
- **Reads from other modules**: patients through `PatientRepository` (feature 003), doctors through
  `DoctorRepository` and `DoctorService::findOwn` (feature 004), slots through `SlotService`, the
  clock through `ClinicClock`, and times through `ClockTime` (feature 005).
- **Rationale**: The constitution and roadmap list appointments as their own domain; queues,
  encounters, and payments (007–009) will refer to an appointment by its own id, so it is not
  nested under a doctor or a patient.
- **Alternatives**: Nesting under `/doctors/{doctor}/appointments` — an appointment belongs to a
  patient as much as to a doctor, and every later feature would need two ids to find one.

## R2 — Booked slots are hidden through a port in Scheduling *(approved — D1)*

- **Finding**: `SlotService::forDoctor()` computes slots from the schedule, time off, and
  `ClinicClock::now()` and knows nothing about appointments. FR-006 needs it to hide every slot
  that overlaps a scheduled appointment; booking needs the same slots *without* that filter, so it
  can tell "not available" (422) from "already booked" (409), and so a reschedule can move into a
  slot that overlaps the appointment's own current time.
- **Decision**: Scheduling declares one new contract,
  `Scheduling\Repositories\Contracts\BookedTimeRepository::forDoctor(int $doctorId, string $from,
  string $to): list<array{date: string, start_minute: int, end_minute: int}>` — "times this doctor
  is already booked". The Appointments module implements it
  (`EloquentBookedTimeRepository`, scheduled appointments only) and binds it in
  `AppointmentsServiceProvider`. In Scheduling:
  - `SlotCalculator::slots()` gains a last parameter `array $booked = []`; a slot overlapping any
    booked interval on its date is dropped (`start < bookedEnd && end > bookedStart`, as for time
    off).
  - `SlotService` gains `workingSlots()` (schedule, time off, past — no bookings) and `forDoctor()`
    becomes `workingSlots()` minus booked times. Endpoints keep calling `forDoctor()`; responses
    are unchanged in shape.
- **Rationale**: Dependency goes one way (Appointments → Scheduling). Scheduling stays the only
  place that knows how slots are cut.
- **Alternatives**: `SlotService` injecting the Appointments repository — circular module
  dependency. Filtering in an Appointments wrapper — the existing `/slots` endpoints would still
  offer booked slots.

## R3 — Concurrency: row locks first, plus a unique index *(approved — D3)*

Constitution XI requires protection at the database level.

- **Decision**: Booking runs in one transaction, `DB::transaction(..., attempts: 3)`:
  1. **Lock the doctor's schedule row** `FOR UPDATE` (`DoctorScheduleRepository::lockForDoctor`,
     new: returns `null` when the doctor has no schedule, which means no slots → 422). Every
     booking and reschedule for one doctor, and every schedule replacement or clear in feature 005
     (which already locks this row, R4 of 005), is serialized on it.
  2. **Lock the patient row** `FOR UPDATE` (`PatientRepository::lockById`, new: returns `null` for
     an unknown patient → 422 on `patient_id`). Serializes bookings of one patient with different
     doctors (FR-004).
  3. Only then read: the doctor (active?), the working slots, the doctor's overlapping scheduled
     appointments, the patient's overlapping scheduled appointments; then insert.
- **Locks first**: InnoDB's REPEATABLE READ takes the read view at the first *plain* read in the
  transaction, not at `BEGIN`. Taking both locks before any plain read means the checks see every
  booking committed by the previous lock holder. This order is a rule in the service and is
  covered by the concurrency test (R9).
- **Lock order** is always appointment (reschedule only) → schedule → patient; cancel and no-show
  lock only the appointment. No cycle is possible; `attempts: 3` retries a deadlock the database
  still reports.
- **Unique index backstop**: a stored generated column `scheduled_flag = IF(status = 'scheduled',
  1, NULL)` and `UNIQUE (doctor_id, date, start_minute, scheduled_flag)`. NULLs never collide, so
  any number of cancelled or no-show rows can share a slot, but two scheduled appointments at the
  same doctor, date, and start cannot exist even if application code is wrong. A
  `UniqueConstraintViolationException` is reported as the same 409 `slot_booked`.
- **Alternatives**: Locks only — no database-level guarantee if a future code path skips the lock.
  Locking the `doctors` row — needs a new method in the Doctors module and does not serialize
  against schedule changes. `READ COMMITTED` for these transactions — works, but changes isolation
  for one code path only, which is easy to miss.

## R4 — What "bookable" means at booking time (FR-002, FR-007)

- **Decision**: After the locks, `SlotService::workingSlots($doctorId, $date, $date)` returns the
  doctor's slots for that date (empty for inactive doctor, no schedule, time off, past, DST gap).
  The requested `start` must equal one slot's start, and the appointment takes that slot's `end`.
  Otherwise → 422 on `start`, `appointments::messages.slot_unavailable`.
- **Horizon**: the date must be from clinic today to today + `SlotCalculator::HORIZON_DAYS` (90);
  checked in the Form Request with the same message.
- **Conflicts** (409, R6): any *scheduled* appointment of the doctor overlapping
  `[start, end)` → `slot_booked`; any scheduled appointment of the patient overlapping it, with
  any doctor → `patient_busy`. Doctor overlap is checked first.
- **Reschedule** uses the same steps, excluding the appointment itself from both overlap checks, so
  it may move into a slot that overlaps its own current time (after a length change).

## R5 — Statuses and transitions (FR-011–FR-014)

- **Decision**: `AppointmentStatus` enum: `scheduled`, `cancelled`, `no_show`, stored as
  `varchar(20)`. Transitions:

  ```text
  scheduled ──reschedule (not started)──▶ scheduled
  scheduled ──cancel (not started)──────▶ cancelled   (final)
  scheduled ──no-show (started)─────────▶ no_show     (final)
  ```

  "Started" means `date + start ≤ ClinicClock::now()` on the wall clock. A refused transition is
  422 on `appointment` with `not_changeable` (cancelled, no-show, or started) or `not_started`
  (no-show too early).
- Each transition locks the appointment row (`AppointmentRepository::lockById`) and checks the
  status inside the transaction, so a cancel and a no-show at the same moment cannot both apply.
- **Recorded**: `booked_by` (+ `created_at`), `rescheduled_by` + `rescheduled_at` (latest only),
  `status_changed_by` + `status_changed_at` for cancel and no-show, `cancellation_reason`. A full
  history belongs to "Audit logs" (spec Assumptions).

## R6 — Error responses

- **422**: Laravel's standard validation shape (`message`, `errors`), as in every module: bad
  formats, unknown `patient_id`/`doctor_id`, `slot_unavailable` on `start`, `not_changeable` /
  `not_started` on `appointment`, range rules on `to`.
- **409**: new `AppointmentConflictException` rendering
  `{"message": "...", "reason": "slot_booked" | "patient_busy"}`; `reason` is a fixed identifier
  in every locale (FR-021), so clients branch on it rather than on text. Not reported to the log
  (client error), like the module `NotFound` exceptions.
- **404**: `AppointmentNotFoundException` (`appointments::messages.not_found`), not reported.
- **Unknown patient or doctor on booking**: 422 (spec US1-7), not 404 — they are body fields.

## R7 — "Outside availability" computed in SQL on read *(approved — D2)*

- **Decision**: `EloquentAppointmentRepository` adds a computed boolean `outside_availability` to
  every query that returns appointments (list, find, own list) and uses the same expression for the
  `outside_availability` filter. For appointment `a` it is true when `a.status = 'scheduled'`,
  `(a.date, a.start_minute)` is after the clinic's now (passed in as parameters), and any of:
  - **no covering period**: `NOT EXISTS` a `schedule_periods` row of the doctor's
    `doctor_schedules` with `weekday = DAYOFWEEK(a.date) - 1` (MySQL Sunday = 1; Scheduling
    Sunday = 0), `start_minute ≤ a.start_minute`, and `end_minute ≥ a.end_minute`;
  - **time off**: `EXISTS` a `doctor_time_off` row of the doctor with `start_date ≤ a.date ≤
    end_date` and either whole-day (`start_minute IS NULL`) or `start_minute < a.end_minute AND
    end_minute > a.start_minute`;
  - **inactive doctor**: `NOT EXISTS` the doctor's `users` row with `is_active = 1` and
    `role = 'doctor'` (`Doctor::isActive()`, feature 004).
- An appointment length change alone never sets it: only coverage by a period counts, not the slot
  grid (spec FR-018).
- **Rationale**: Always current, nothing to keep in sync with features 004, 005, or Staff. The
  repository reading Scheduling's and Staff's tables is a read-only query concern; rules stay in
  one documented expression with its own tests (R9).
- **Alternatives**: A stored flag recalculated on every schedule, time off, and account change —
  needs hooks in three modules and can drift.

## R8 — Authorization and the doctor's own view *(approved — D4)*

- **Decision**: `AppointmentPolicy`, class-level only (checked against `Appointment::class` before
  any lookup, as in every module):
  - `viewAny`, `view`, `create`, `update` (reschedule, cancel, no-show): active administrator or
    receptionist;
  - `viewOwn`: active doctor.
- A doctor uses `/doctors/me/appointments[/{id}]`; the service resolves the doctor with
  `DoctorService::findOwn` (no profile → 404) and loads the appointment *for that doctor*. Another
  doctor's id → **404** (spec US5-2, updated), so ids cannot be probed. Any doctor on
  `/appointments…` → 403.
- Doctors still have no access to `/patients…` (Q1; `PatientPolicy` unchanged).

## R9 — Testing concurrency for real

- **Decision**: `tests/Feature/Appointments/ConcurrentBookingTest` uses `DatabaseTruncation` (data
  must be committed to be seen by other connections). It creates a doctor, schedule, and patients,
  then starts 8 PHP processes with `Process::pool`, each running
  `tests/Support/book-appointment.php` (boots the application from `bootstrap/app.php` and calls
  the booking service once). The processes wait for a shared start time a moment in the future, so
  their transactions overlap. Assertions: exactly one success, the rest `slot_booked`; one
  scheduled row. A second case books one patient with 8 different doctors at the same time:
  exactly one success, the rest `patient_busy`. Tagged `#[Group('concurrency')]`.
- Child processes inherit the PHPUnit environment (`DB_DATABASE=clinic_testing`); the script
  refuses to run against any other database, like `TestCase::ensureTestingDatabase()`.
- Deterministic tests cover the backstop separately: inserting a second scheduled row through the
  repository raises the unique violation, and the service maps it to 409.
- **Alternatives**: HTTP requests against the dev server — would use the development database.
  Two connections in one process — cannot run two blocking transactions at once.

## R10 — Listing (FR-008, FR-009, FR-015)

- **Decision**: `GET /appointments?from&to` (required, `Y-m-d`, `to ≥ from`, at most 92 days
  inclusive) with optional `doctor_id`, `patient_id`, `status`, `outside_availability` (boolean),
  `page`, `per_page` (1–100, default 15). Ordered by `date`, `start_minute`, `id`. Laravel's
  paginated resource shape (`data`, `links`, `meta`), as in features 003 and 004. Unknown
  `doctor_id`/`patient_id` filters simply match nothing.
- Own list: `GET /doctors/me/appointments?from&to&status&page&per_page`, same rules, own doctor
  only.
- Ranges may include past dates (history); there is no "not before today" rule here, unlike slots.
- **Indexes**: `(date, start_minute)` for range lists; the unique index
  `(doctor_id, date, start_minute, scheduled_flag)` for doctor lists and overlap checks;
  `(patient_id, date, start_minute)` for patient history and overlap checks (SC-005).

## R11 — Booking note and privacy

- `note`: optional, at most 500 characters, trimmed, empty → null; only in the single-appointment
  views (staff and own). Its OpenAPI description says not to enter medical details.
  `cancellation_reason`: optional, at most 255 characters, same handling.
- Staff list shows the patient as `{id, full_name, phone}`; the doctor's own list as
  `{id, full_name, sex, date_of_birth}`; never national ID, address, or emergency contact.
- Logs: no new log lines; exceptions carry no patient data.
