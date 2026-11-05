# Research: Medical Encounters

**Feature**: 008-medical-encounters | **Date**: 2026-11-05

Facts below were checked on 2026-11-05 against `007-phase-8-polish` at `b8cdae4` (feature 007 is not
yet merged into `main`) and the running stack: Laravel Framework 13.38.0, PHP 8.5.12, MySQL 9.7.4,
and the existing `Staff`, `Patients`, `Doctors`, `Scheduling`, `Appointments`, and `Queues` modules.
Spec clarifications Q1–Q3 were answered on 2026-11-05 (spec, Clarifications). Items marked
*(approved — Dn)* were approved by the user on 2026-11-05 and are listed in the plan's Decisions
table.

## R1 — New `Encounters` module and URLs *(approved — D3)*

- **Decision**: A new module, `app/Modules/Encounters/`. Every endpoint is for doctors and sits under
  `/doctors/me`:
  - `GET /doctors/me/visits` — own visits with their record status;
  - `POST /doctors/me/visits/{visit}/encounter` — start the visit's record;
  - `GET /doctors/me/encounters/{encounter}` — open one record;
  - `PUT /doctors/me/encounters/{encounter}` — save a draft;
  - `POST /doctors/me/encounters/{encounter}/finalize` — finalize;
  - `POST /doctors/me/encounters/{encounter}/addenda` — add an addendum;
  - `GET /doctors/me/patients/{patient}/encounters` — a patient's history.
- **Rationale**: Same shapes as features 004–007 (`/doctors/me/…`, `POST …/{action}`). The prefix
  says plainly that nobody else uses these routes. `{visit}` is the queue entry id, which doctors
  already receive from `GET /doctors/me/queue`.
- **Alternatives**: `/encounters/{id}` and `/patients/{id}/encounters` — they read as staff routes,
  and `/patients…` is the area doctors are refused in feature 003.
- **Module direction**: `Encounters` depends on `Queues`, `Doctors`, and `Patients`; none of them
  depends on it, so queue and appointment responses cannot gain record data by accident (FR-014).

## R2 — A record belongs to a queue entry

- **Finding**: `queue_entries.appointment_id` is null for walk-ins, so an appointment cannot be the
  link (spec FR-002). `DoctorQueueService::finish` only acts on today's `with_doctor` entry and does
  not know about records, so a record cannot be tied to finishing (FR-005).
- **Decision**: `encounters.queue_entry_id` is required and unique. `doctor_id`, `patient_id`, and
  `date` are copied from the entry when the record starts. None of them can change on an entry, and
  the copies let a patient's history and the doctor check run without joining `queue_entries`.
- **Undo is safe**: `QueueService` undoes only `waiting` entries, which cannot have a record, and the
  foreign key is `RESTRICT` as a backstop.

## R3 — Concurrency

- **Start**: inside a transaction, lock the queue entry `FOR UPDATE` (`QueueRepository::lockById`
  exists), check it belongs to the doctor, check its status, check no record exists, then insert. Two
  simultaneous starts wait on the row, and the second gets the 422 the spec asks for (US1-4). The
  unique index on `queue_entry_id` is the backstop; a violation cannot happen while the row is locked
  and is not mapped.
- **Lock order** (no cycles): starting a record locks only the entry. Feature 007 locks queue day →
  entry, and `finish` never waits on anything a start holds, because a start takes no other lock.
- **Save and finalize**: inside a transaction, lock the encounter `FOR UPDATE` and re-check that it is
  a draft. Simultaneous save and finalize run one after the other, so finalized content is exactly
  what was stored at that moment and a later save gets 422 (FR-008, US2-4). Two saves of one draft:
  last save wins (spec, Edge Cases).
- **Addendum**: a plain insert. `finalized` never reverts, so no lock is needed, and simultaneous
  addenda are all kept (US5-6).

## R4 — Access rule in a policy *(spec Q2)*

- **Decision**: `EncounterPolicy` holds the rule, the one place for it (Constitution I, VI):
  - `access` (class-level, checked first): active doctor. Receptionists and administrators get 403
    before any lookup, so they cannot learn whether a record exists (FR-014).
  - `view(User, Encounter)` (record-level): own record → allow; another doctor's draft → deny; a
    finalized record whose patient has an entry on the doctor's list today with status `waiting`,
    `with_doctor`, or `seen` → allow; otherwise deny. On denial the controller throws
    `EncounterNotFoundException`, the same exception as an unknown id.
  - `write(User, Encounter)` (record-level): only the author; otherwise 403.
- **Why record-level here**: every other module's policy is class-level, because those decisions
  depend only on the role. This one depends on the record; `docs/architecture.md` limits class-level
  checks to role-only decisions.
- **Why not `Response::denyAsNotFound`**: Laravel's handler (`Foundation/Exceptions/Handler.php:774`)
  renders it as an `HttpException`, and with `APP_DEBUG=true` (set in `.env`; `phpunit.xml` does not
  override it) that response gains `exception`, `file`, `line`, and `trace` keys. A refused record
  would then differ from an unknown one, breaking FR-013. Sharing one exception makes the bodies
  identical in every mode, and a test asserts it.
- **New repository method**: `QueueRepository::patientOnList(doctorId, patientId, date)`, a plain read
  for those three statuses. `left` is excluded and an undone entry no longer exists, so access ends in
  those cases (US4-5). `queue_entries_doctor_order_index` serves it; a doctor's day is small.
- **Order for a write**: Form Request (class-level, then validation) → find (404) → `view` (404) →
  `write` (403) → service (lock, status, 422). Validation before lookup reveals nothing, since its
  messages do not depend on the record.

## R5 — Patient history

- **Decision**: `patientOnList` decides the scope. On the list: all the patient's finalized records.
  Not on the list: only the doctor's own finalized records, and a total of 0 means 404 (US4-3). No
  separate patient lookup is needed, so an unknown patient and a refused one produce the same 404
  (FR-013). On the list with no records: an empty page.
- **Rationale**: This is which rows a doctor gets rather than allow or deny, so it stays a scope in the
  service, using the same `patientOnList` as the policy.

## R6 — Privacy: encryption, logs, and listings

### Encryption at rest *(approved — D1)*

- **Decision**: Clinical columns use Laravel's built-in `encrypted` and `encrypted:array` casts (AES-256-CBC
  with `APP_KEY`). No new package.
- **Rationale**: Anyone with database access, including phpMyAdmin in Compose, a dump, or a backup,
  sees only ciphertext. Nothing in the spec searches clinical text.
- **Costs**: Losing `APP_KEY` makes clinical content unrecoverable, so the key needs its own backup
  before production. Rotation uses `APP_PREVIOUS_KEYS`. Reports (010) cannot group by diagnosis or
  medication in SQL.
- **Alternatives**: Plain columns with disk or database-level encryption only — those do not protect
  against database access or dumps. Application-level envelope keys — more than one clinic needs.

### Column sizes (measured)

Measured on 2026-11-05 with the app's cipher; nothing was written to a database:

| Worst case | Plain bytes | Encrypted bytes | Fits `TEXT` (65,535)? |
|------------|-------------|-----------------|-----------------------|
| 10,000 Arabic characters | 20,000 | 35,752 | yes |
| 10,000 four-byte characters | 40,000 | 71,312 | **no** |
| 20 prescription lines, 5 fields of 500 four-byte characters | 601,541 (JSON) | 1,069,600 | **no** |

- **Decision**: All clinical columns are `MEDIUMTEXT` (16 MB). MySQL would otherwise refuse or fail on
  text the spec allows.

### Stack traces in logs *(approved — D4)*

- **Finding**: Feature 003 stops database errors from logging bound values (`bootstrap/app.php`), but
  other exceptions log a stack trace. With the container's current settings (`zend.exception_ignore_args`
  is `0`), a trace printed `f('sssssssssssssss...')`: the first 15 characters of a string argument. A
  service or cast failing with clinical text as an argument would write that to the log, violating
  FR-021 and Constitution XII.
- **Verified fix**: With `zend.exception_ignore_args=1`, the same trace printed `f()`.
- **Decision**: Add `zend.exception_ignore_args = On` to `docker/php/conf.d/app.ini`, and a test that
  asserts the setting is on. The image copies this file at build time, so it needs `docker compose build
  app`. The setting also helps every existing module, at the cost of no argument values in local traces.

### Listings and other leak paths

- **Listings select no clinical columns**: The visit list and history queries name their columns, so
  clinical content is never loaded for them, whatever a Resource does (FR-018). This also avoids
  decrypting rows no one asked for.
- **Accidental serialization**: The model's `$hidden` lists every clinical attribute.
- **Validation**: Messages use `:attribute` and limits only, never `:input`, so they cannot repeat
  submitted text. Validation exceptions are not logged by default.
- **Query errors**: Feature 003's handler logs placeholder SQL only, and bound values would be
  ciphertext anyway.
- **Verified by test**: SC-002 plants recognizable text and searches responses, the log after a forced
  failure, and raw database values.

## R7 — Prescription storage *(approved — D2)*

- **Decision**: One `encrypted:array` column, `encounters.prescriptions`, holding the lines as a JSON
  array in order. In the API each line is a structured object.
- **Rationale**: A save replaces all content (US1-2), so lines are always written with the record and
  lock with it. Encryption rules out querying them in SQL either way, so a child table would add a
  model, a repository path, and delete-and-insert on each save for no gain.
- **Alternatives**: An `encounter_prescription_lines` table — better only if a later feature needs
  rows with their own identity, and data can be moved to it then.

## R8 — Saving, finalizing, and addenda (FR-003–FR-011)

- **Save** (`PUT`): replaces all content. Every key must be sent (`present`), with `null` or `[]` for
  empty, so a client that forgets a field gets 422 rather than silently erasing it. The status must be
  `draft`, otherwise 422 `not_editable`.
- **Limits**: Clinical text `max:10000` and line fields `max:500` count characters; `prescriptions` has
  at most 20 items; each line requires `medication`.
- **Finalize**: Rules depend on stored content, so they live in the service: `chief_complaint` and
  `diagnosis` must be filled, and each line needs `dosage`, `frequency`, and `duration`. Errors use the
  field names, such as `prescriptions.1.dosage`, with a `required_to_finalize` message. Not a draft →
  422 `not_editable`.
- **Addendum**: `text` required, up to 10,000 characters; the record must be finalized, otherwise 422
  `not_finalized`. The repository has only a create method for addenda, and the table has no
  `updated_at`, so nothing can change one (FR-011).
- **Start**: Not the doctor's visit → 404 `visit_not_found` (as feature 006 R8 treats another doctor's
  appointment). `waiting` or `left` → 422 `visit_not_started`. Already has a record → 422
  `already_started`.

## R9 — Visit list

- **Decision**: `EncounterRepository::paginateVisits` reads `queue_entries` left-joined to `encounters`,
  selecting the entry's columns plus `encounters.id` and `encounters.status` only. `record_status`
  maps to `encounters.id IS NULL` or `encounters.status = ?`. Order: `date`, `order_minute`,
  `queue_number`, which `queue_entries_doctor_order_index` serves.
- **Filters**: `from` and `to` are required, at most 92 days, reusing
  `ListAppointmentsRequest::validateRange` and its translated message as feature 006's own list does.
  `patient_id` is only a filter on the doctor's own visits, so an unknown id gives an empty page and
  reveals nothing.

## R10 — Responses

- **Patient summary**: `{id, full_name, sex, date_of_birth}`, the same as `OwnQueueEntryResource`
  (FR-015). As in features 006–007, the patient `id` is the patient number.
- **Times**: `date` is the clinic date (`Y-m-d`); `*_at` values are ISO 8601 UTC (`toISOString`), as in
  feature 007.
- **History item**: `{id, date, doctor: {id, name, specialties}}`. `DoctorSummaryResource` is not reused,
  because it adds `title` and `is_active` beyond what US4-1 allows.
- **Ids in listings**: The spec's field lists describe content. Each item also carries the ids a client
  needs to open it (`id`, `encounter_id`).

## R11 — Testing

- Feature tests per story with `travelTo`, as in feature 007.
- A `concurrency` group using real PHP processes (feature 006 R9): many starts on one visit → one
  record; saves against a finalize → final content matches what was stored at finalization.
- Regression: feature 007's tests stay unchanged and pass, showing finishing a visit needs no record.
- Encrypted casts use `APP_KEY` from `.env`, which `phpunit.xml` does not override; child processes
  read the same value.
- SC-005 is measured once with synthetic rows in `clinic_testing` and recorded in `verification.md`.
