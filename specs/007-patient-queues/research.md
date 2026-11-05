# Research: Patient Queues

**Feature**: 007-patient-queues | **Date**: 2026-11-02

Facts below were checked on 2026-11-02 against the repository after feature 006 was merged
(`main` at `2312047`): Laravel Framework 13.38.0, PHP 8.5.12, MySQL 9.7.4, and the existing
`Staff`, `Patients`, `Doctors`, `Scheduling`, and `Appointments` modules. Spec clarifications Q1–Q3
were answered on 2026-11-02 (spec, Clarifications). Items marked *(approved — Dn)* were approved by the user on 2026-11-02 and are listed
in the plan's Decisions table.

## R1 — New `Queues` module and URLs *(approved — D3)*

- **Decision**: A new module, `app/Modules/Queues/`. Reception endpoints:
  - `POST /appointments/{appointment}/check-in` — check in a booked patient (the action belongs to
    the appointment, like `/cancel` and `/no-show` in feature 006, but it creates a queue entry, so
    the Queues module owns the route);
  - `POST /queue/walk-ins` — add a walk-in (`patient_id`, `doctor_id`);
  - `GET /queue` — today's entries of every doctor, ordered by doctor, then queue order, paged;
  - `GET /doctors/{doctor}/queue` — one doctor's list for today, not paged;
  - `POST /queue/{entry}/leave` — mark a waiting patient as left;
  - `DELETE /queue/{entry}` — undo a check-in (waiting entries with an appointment only).
  Doctor endpoints: `GET /doctors/me/queue`, `POST /doctors/me/queue/call-next`,
  `POST /doctors/me/queue/finish` (finishes the visit of the patient currently with the doctor, so
  doctors never send an entry id).
- **Rationale**: Same shapes as features 004–006 (`/doctors/me/…`, `POST …/{action}` for state
  changes, `DELETE` for removal). "Today" is implied: no date parameter, so nothing can be changed
  on other days (spec, Edge Cases).
- **Alternatives**: Check-in under `/queue/check-ins` with an `appointment_id` body — one more
  lookup path for the same thing. Doctor actions on `/queue/{entry}/call` — doctors would need ids
  and could probe other doctors' entries.

## R2 — Appointment states `checked_in` and `completed` *(approved — D1)*

- **Finding**: Feature 006 lets only `scheduled` appointments hold a slot: the slot filter
  (`EloquentBookedTimeRepository`), the overlap checks (`EloquentAppointmentRepository::
  scheduledOverlapping`), and the database backstop (`scheduled_flag = IF(status = 'scheduled', 1,
  NULL)` in `appointments_doctor_slot_unique`). Reschedule, cancel, and no-show already refuse any
  status other than `scheduled` with `not_changeable`.
- **Decision**: Add `checked_in` and `completed` to `AppointmentStatus`. Introduce one list,
  `AppointmentStatus::holdingSlot()` = `scheduled`, `checked_in`, `completed`, used by the slot
  filter and both overlap checks; a new migration changes the generated column to
  `IF(status IN ('scheduled', 'checked_in', 'completed'), 1, NULL)`. The column keeps its name
  (`scheduled_flag`) to avoid a rename; its comment and the data model describe it as "holds the
  slot". Outside availability (006 R7) stays limited to `scheduled`: a patient who is here is not
  flagged.
- **Why the slot must stay held**: a patient may check in early and finish before their slot time;
  if a checked-in or completed appointment released its slot, the slot would be offered and booked
  again (double booking, Constitution XI).
- **Verified on MySQL 9.7.4** (scratch table in `clinic_testing`, dropped afterwards): `ALTER TABLE
  … MODIFY <col> … AS (…) STORED` works on a stored generated column that is part of a unique index;
  afterwards a `checked_in` row blocks a second `scheduled` row at the same key, `cancelled` rows
  never collide; the `ALTER` fails with a duplicate-key error if existing rows conflict under the
  new rule. No existing row can conflict, because no appointment has the new statuses before the
  migration runs. The migration uses `DB::statement` for this column, since the schema builder's
  `change()` support for generated columns is not used anywhere in the project; `down()` restores
  the 006 expression.
- **Feature 006 effects**: reschedule, cancel, and no-show refuse the new statuses with the existing
  `not_changeable` message (no code change); listings accept the new `status` filter values
  (`AppointmentStatus::values()`); the 006 contract's `Status` enum gains the two values.
- **Alternatives**: Keep the appointment `scheduled` and derive "checked in" from the queue entry —
  Appointments would have to ask Queues before every reschedule, cancel, and no-show (a dependency
  in the wrong direction) and completion would still need a new status.

## R3 — Concurrency: one locked counter row per doctor and day *(approved — D2)*

- **Decision**: A small table `queue_days` (`doctor_id`, `date`, `last_number`, unique per doctor
  and date). Every change to a doctor's queue on a day (check-in, walk-in, call next, finish, leave,
  undo) first makes sure the row exists (a no-op `INSERT … ON DUPLICATE KEY UPDATE`) and locks it
  `FOR UPDATE`. Feature 005's `insertOrIgnore` (005 R4) does not work here: on a duplicate it keeps a
  shared lock, so simultaneous first changes of a day all wait to upgrade and deadlock, more than
  `attempts: 3` absorbs (observed in `ConcurrentQueueTest` on 2026-11-05, 5 of 5 runs failed; none in
  10 runs after the change). Then, inside the same transaction:
  - new entries take `last_number + 1`, and the row is updated — numbers are unique, without gaps,
    and never reused even after an undo deletes the entry (spec FR-003);
  - "call next" and "finish" read the doctor's entries knowing no other change for that doctor and
    day can run at the same time (FR-011).
- **Lock order** (no cycles): queue day → appointment → patient. Check-in reads the appointment's
  doctor first with a plain read (the doctor of an appointment never changes, 006 FR-011), then
  locks the queue day, then the appointment, and re-checks its status. Feature 006 locks appointment
  → schedule → patient; the queue day row is a different lock, so the two never wait on each other
  in opposite order.
- **Backstops**: unique `(doctor_id, date, queue_number)`; unique `(appointment_id)` (an appointment
  is in a queue at most once); and a generated column `with_doctor_flag = IF(status =
  'with_doctor', 1, NULL)` with unique `(doctor_id, date, with_doctor_flag)` (one patient with a
  doctor at a time) and unique `(patient_id, date, with_doctor_flag)` (a patient with one doctor at
  a time). A violation is reported as a 409 conflict with a fixed `reason`, as in feature 006:
  `already_checked_in` (appointment) or `patient_with_another_doctor`; a queue-number or
  one-patient-per-doctor violation cannot happen while the queue day is locked and is not mapped
  (it would be a bug, reported as a server error).
- **Alternatives**: `MAX(queue_number) + 1` with the unique index and retries — numbers would be
  reused when the last entry is undone (violates FR-003) and concurrent check-ins would collide
  instead of waiting. Locking the doctor's entries with `SELECT … FOR UPDATE` — gap locks on an
  empty day make simultaneous first check-ins deadlock.

## R4 — Queue order *(spec Q2)*

- **Decision**: Each entry stores `order_minute`: the appointment's start minute for a check-in
  (it cannot change while checked in), or the clinic-time minute of arrival for a walk-in. Lists and
  "call next" order by `order_minute`, then `queue_number`. Stored rather than computed, so the order
  is one indexed sort and does not change if an appointment's details change later.
- **Effects**: an early patient waits in their appointment position; a late patient goes ahead of
  patients booked later (spec, Edge Cases); a walk-in arriving at 09:50 goes after the 09:40
  appointment and before the 10:00 one.

## R5 — Call next, finish, leave, undo *(spec Q3; approved — D4)*

- **Call next**: with the queue day locked: if an entry is `with_doctor`, refuse (422
  `visit_in_progress`); take waiting entries in queue order and pick the first whose patient is not
  `with_doctor` with another doctor today (spec: "with only one doctor at a time"); none → 422
  `nobody_waiting`. The candidate's patient row is locked before the check; the unique index on
  `(patient_id, date, with_doctor_flag)` is the backstop (409 `patient_with_another_doctor`). Sets
  `with_doctor`, `called_at`.
- **Finish**: the doctor's `with_doctor` entry today becomes `seen` with `finished_at`; its
  appointment, if any, becomes `completed`. Nobody with the doctor → 422 `no_current_visit`.
- **Leave**: only `waiting` → `left` with `left_at`, `left_by`. Otherwise 422 `not_changeable`.
- **Undo check-in**: only `waiting` entries with an appointment; deletes the entry and sets the
  appointment back to `scheduled`. The queue number stays used (R3). Walk-ins are corrected by
  marking them as left (spec US5). Otherwise 422 `not_changeable`.
- **Rationale**: Doctors act only on "their current patient" and "the next one", so they never need
  an entry id; reception actions take an entry id.

## R6 — Check-in and walk-in rules (FR-001–FR-006)

- **Check-in**: appointment unknown → 404 (feature 006 `not_found`); not `scheduled` or not dated
  the clinic's today → 422 on `appointment` `not_checkable`. Any time of the day is allowed (early
  and late). An inactive doctor or an appointment outside availability does not block check-in
  (spec, Edge Cases).
- **Walk-in**: `patient_id`, `doctor_id` required integers; unknown patient → 422 `patient_id`;
  unknown doctor or inactive doctor → 422 `doctor_id`; the patient already `waiting` or
  `with_doctor` in this doctor's list today → 422 on `patient_id` `already_in_queue`. A walk-in takes
  no slot and changes nothing in Scheduling.
- **Arrival time**: `arrived_at` is a UTC instant (like `booked_at`); the walk-in `order_minute`
  is its clinic wall-clock minute (`ClinicClock`).

## R7 — Authorization

- **Decision**: `QueueEntryPolicy`, class-level only, as in every module:
  - `viewAny`, `create` (check-in, walk-in), `update` (leave, undo): active administrator or
    receptionist;
  - `viewOwn`, `updateOwn` (call next, finish): active doctor.
- A doctor's own actions resolve the doctor with `DoctorService::findOwn` (no profile → 404). Doctors
  keep no access to `/patients…` (feature 003, FR-014) or to other doctors' lists.

## R8 — Responses and privacy

- **Staff entry** (`QueueEntry`): `id`, `queue_number`, `status`, `walk_in`, `arrived_at`,
  `called_at`, `finished_at`, `left_at` (ISO 8601 UTC or null), `appointment` (`{id, start, end}` or
  null), `doctor` `{id, name, title}`, `patient` `{id, full_name, phone}`.
- **Own entry** (`OwnQueueEntry`): the same without `doctor`, with the patient as `{id, full_name,
  sex, date_of_birth}` (feature 006 own view).
- Never the booking note, national ID, address, or emergency contact (FR-009). No new log lines;
  exceptions carry no patient data.
- `GET /queue` uses Laravel's paginated shape (`per_page` 1–100, default 15) and an optional
  `status` filter; `GET /doctors/{doctor}/queue` and `GET /doctors/me/queue` return the whole day
  (a doctor's day is small, like `/slots`).

## R9 — Testing

- Feature tests per story with `travelTo` (clinic clock), as in feature 006.
- A `concurrency` group test reusing feature 006's approach (R9 there): parallel PHP processes
  check in many appointments of one doctor at once (numbers 1…N, no duplicates or gaps), and two
  processes call next for one doctor at once (exactly one patient called).
- Regression: feature 006 tests stay unchanged and pass; new tests show checked-in and completed
  appointments keep their slot hidden and refuse reschedule, cancel, and no-show.
