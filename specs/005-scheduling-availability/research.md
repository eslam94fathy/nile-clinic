# Research: Scheduling and Availability

**Feature**: 005-scheduling-availability | **Date**: 2026-10-28

Facts below were checked on 2026-10-28 against the running development stack (PHP 8.5.12, tzdata
2026.5, Laravel Framework 13.38.0, MySQL 9.7.4) and the existing `Doctors` and `Staff` modules.
Items marked *(approved — Dn)* were approved on 2026-10-28 and are listed in the plan's Decisions table.

`/speckit-clarify` was not run for this feature; the spec's defaults (Assumptions section) are
taken as given. A change to any of them after approval means updating the spec, this file, and
the plan together.

## R1 — New `Scheduling` module *(approved — D1)*

- **Decision**: A new module, `app/Modules/Scheduling/`, holding weekly schedules, working
  periods, time off, and slot calculation. Its endpoints are nested under the doctor they belong
  to: `/doctors/{doctor}/schedule`, `/doctors/{doctor}/slots`, `/doctors/{doctor}/time-off`, plus
  `/doctors/me/…` for a doctor's own data. It reads doctors through the existing
  `DoctorRepository` contract (`findById`, `findByUserId`) and `Doctor::isActive()` from feature
  004; nothing in `Doctors` changes.
- **Rationale**: The constitution and roadmap list "scheduling and availability" as its own
  domain, and feature 006 (appointments) will depend on it separately from doctor profiles.
  Nested URLs say which doctor the data is for without new identifiers.
- **Alternatives**: Adding scheduling to the `Doctors` module — mixes two domains and grows a
  module that 006 should not need to depend on as a whole. Top-level `/schedules/{id}` — adds a
  schedule id clients never need.

## R2 — Clinic time zone *(approved — D2)*

- **Finding**: `config/app.php` has `'timezone' => 'UTC'` and `docker/php/conf.d/app.ini` sets
  `date.timezone = UTC`; record timestamps are UTC. PHP's tzdata 2026.5 has `Africa/Cairo` with
  Egypt's current rules: in 2026 the clock jumps 00:00 → 01:00 on Friday 24 April
  (`2026-04-24 00:30` resolves to `01:30 +03:00`) and falls back at the end of Thursday 29
  October (23:00–24:00 happens twice).
- **Decision**: Keep the application time zone UTC. Add one setting, `app.clinic_timezone`
  (`CLINIC_TIMEZONE`, default `Africa/Cairo`), used only to answer "what is today and now at the
  clinic" and to check whether a wall-clock time exists on a given date. Schedules, time off, and
  slots are stored and returned as **wall-clock dates and times** without an offset (FR-020).
- **Daylight saving** (spec edge case): slots are generated from wall-clock times, so a repeated
  hour naturally appears once. A slot whose start does not exist that day (the spring-forward
  gap) is dropped: the service builds the start in the clinic zone and drops it if the result's
  wall-clock time differs from the requested one.
- **Alternatives**: Switching the whole app to `Africa/Cairo` — changes how every existing
  timestamp is stored and read, for no gain outside scheduling. Storing UTC instants for periods — a weekly
  "09:00" is a wall-clock rule, and its UTC time moves twice a year.

## R3 — Storing times as minutes of the day *(approved — D3)*

- **Decision**: Period and time off times are stored as `smallint unsigned` minutes since
  midnight (0–1440; 1440 = 24:00, allowed only as an end). The API reads and writes `"HH:MM"`
  strings (`"24:00"` allowed only as an end). Conversion lives in one small class,
  `Support/ClockTime` (`toMinutes`, `fromMinutes`).
- **Rationale**: Every rule is arithmetic on minutes: ordering, overlap, "at least one
  appointment long", 5-minute boundaries, cutting slots. `24:00` has no clean representation in
  PHP time objects, and MySQL `TIME` values come back as strings that would be converted anyway.
- **Alternatives**: MySQL `TIME` columns — readable in a database client, but every comparison
  needs conversion and `24:00:00` is a special case.

## R4 — Weekly schedule shape and replacement (FR-001–FR-007)

- **Decision**: Table `doctor_schedules` (one row per doctor: `doctor_id` unique,
  `appointment_minutes`) and `schedule_periods` (`doctor_schedule_id`, `weekday` 0–6 with Sunday =
  0 as in PHP's `dayOfWeek`, `start_minute`, `end_minute`). The API takes a flat list
  `periods: [{day, start, end}]` with day names `sunday`…`saturday` and returns all seven days in
  that order, each with its periods sorted by start.
- **Replace atomically** (FR-005): `PUT /doctors/{doctor}/schedule` runs in one transaction:
  `insertOrIgnore` the doctor's schedule row, load it `FOR UPDATE` (serializes two administrators
  saving at once), update the appointment length, delete its periods, insert the new ones. A
  validation failure happens before the transaction, so the old schedule stays.
- **Clear**: `DELETE /doctors/{doctor}/schedule` deletes the schedule row; its periods go with it
  (`ON DELETE CASCADE` on `schedule_periods` only — periods have no meaning without their
  schedule). No history is kept; the spec asks for none.
- **No schedule yet**: `GET` returns the same shape with `appointment_minutes: null`, seven empty
  days, and `updated_at: null`, so clients never branch on a missing object.

## R5 — Validating a schedule (FR-002–FR-004)

- **Decision**: The Form Request checks each field's format: `appointment_minutes` integer
  5–240, multiple of 5; `periods` an array of at most 28 items; each `day` one of the seven names;
  `start` matching `^([01]\d|2[0-3]):[0-5][05]$`; `end` the same or `24:00`. Its `after()` hook then
  runs `WeeklyScheduleRules::problems(periods, appointmentMinutes)` — a pure function returning
  errors keyed `periods.<index>` for: end not after start; shorter than one appointment; overlaps
  or touches another period on the same day; fifth period on a day.
- **Rationale**: These are input-shape rules on one request body with no database access, so they
  belong with validation (Constitution VI), and a pure function is unit-testable without HTTP.
- **Messages**: `scheduling::messages.period_end_before_start`, `period_shorter_than_appointment`,
  `periods_overlap`, `too_many_periods`, each naming the day.

## R6 — Calculating slots (FR-008–FR-012)

- **Decision**: A pure class `Support/SlotCalculator::slots(periodsByWeekday, appointmentMinutes,
  timeOff, from, to, now, timezone)` returns `{date, start, end}` items in order. For each date in
  the range: take that weekday's periods; cut each from its start into appointment-length pieces,
  dropping a leftover shorter than one appointment; drop pieces that overlap time off on that date
  (whole-day, or `start < offEnd && end > offStart`); drop pieces whose start is not after `now`
  on today's date; drop starts that do not exist that day (R2). `SlotService` loads the doctor,
  returns an empty list if the doctor is inactive or has no schedule, and otherwise loads the
  schedule and the time off overlapping the range and calls the calculator.
- **Range rules** (FR-010), checked in the Form Request against the clinic's today: `from` and
  `to` required dates; `from` not before today; `to` not before `from`; at most 31 days inclusive;
  `to` at most 90 days after today. The limits are constants on `SlotCalculator` so 006 can reuse
  them.
- **Not stored**: nothing is written; feature 006 will subtract booked appointments (spec
  Assumptions).
- **Performance**: at most 31 days × 4 periods per weekday, cut into at most 288 slots per day — a
  few thousand items in memory from two small queries. Quickstart Q7 measures SC-002.

## R7 — Time off (FR-013–FR-017)

- **Decision**: Table `doctor_time_off` (`doctor_id`, `start_date`, `end_date`,
  `start_minute`/`end_minute` both null for whole days, `reason` varchar(255) nullable,
  timestamps), indexed `(doctor_id, start_date, end_date)`.
- **Validation** (Form Request): `start_date` a date not before the clinic's today; `end_date` not
  before `start_date`; `start_time` and `end_time` either both absent (whole days) or both present
  with `start_date = end_date`, `HH:MM` on 5-minute boundaries, end after start (end may be
  `24:00`); `reason` at most 255 characters. Overlapping entries are allowed (FR-015).
- **Partial day starting earlier today**: accepted — `start_date` is today, which is not in the
  past; it simply hides the remaining slots.
- **List**: `GET /doctors/{doctor}/time-off?from=&to=` returns entries with
  `start_date <= to and end_date >= from`, ordered by `start_date`, then start time, then id. Both
  dates required, at most 366 days apart; past dates allowed (it is a record).
- **Remove** (FR-017): `DELETE /doctors/{doctor}/time-off/{timeOff}`; the entry must belong to that
  doctor (otherwise `404`). It has ended when its end (`end_date` plus `end_minute`, or the end of
  `end_date` for whole days) is not after the clinic's now; then `422` with
  `scheduling::messages.time_off_ended`.

## R8 — Authorization (FR-006, FR-007, FR-012, FR-013, FR-016–FR-019)

- **Decision**: Class-level policies, checked before any lookup, as in features 002–004:
  - `SchedulePolicy` (on `DoctorSchedule`): `view` — any active staff (schedule and slots);
    `update` — active administrators (set and clear); `viewOwn` — active doctors.
  - `TimeOffPolicy` (on `DoctorTimeOff`): `viewAny` — any active staff; `create`, `delete` —
    active administrators; `viewOwn` — active doctors.
- **Own data** (FR-018): `/doctors/me/schedule|slots|time-off` resolve the doctor with
  `DoctorRepository::findByUserId`; no profile → `404` (`doctors::messages.doctor_not_found`, the
  existing exception).

## R9 — Localization (FR-021)

- **Decision**: `scheduling::messages` (schedule rule messages above, `time_off_ended`,
  `time_off_not_found`) and `scheduling::attributes` (`appointment_minutes`, `periods`, `day`,
  `start`, `end`, `from`, `to`, `start_date`, `end_date`, `start_time`, `end_time`, `reason`) in en
  and ar. Day names in responses are fixed English identifiers; in Arabic messages the day is
  named through `scheduling::days.<name>` (e.g. `sunday` → `الأحد`). `TranslationParityTest`
  checks key parity.

## R10 — Synthetic data (FR-022)

- **Decision**: Factories `DoctorScheduleFactory` (default 20 minutes; states
  `withPeriod(day, start, end)` and `weekdays()` — Sunday–Thursday 09:00–13:00 and 17:00–21:00) and `DoctorTimeOffFactory` (whole day tomorrow by default; states
  `partial(start, end)`, `withReason`). No seeder. Tests fix "now" with `travelTo` in the clinic
  zone so day-of-week and past-slot rules are deterministic.

## Sources

- Installed code: `app/Modules/Doctors/` (`DoctorRepository`, `Doctor::isActive`,
  `DoctorNotFoundException`, routes), `config/app.php`, `docker/php/conf.d/app.ini`,
  `docs/architecture.md`.
- PHP checks run on 2026-10-28 in the `app` container (R2).
- Features 002–004 research (policies, unique races, localization pattern).
