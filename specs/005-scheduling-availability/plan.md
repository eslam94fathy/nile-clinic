# Implementation Plan: Scheduling and Availability

**Branch**: `005-scheduling-availability` | **Date**: 2026-10-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/005-scheduling-availability/spec.md`

## Summary

Add a `Scheduling` module with three tables (`doctor_schedules`, `schedule_periods`,
`doctor_time_off`) and ten endpoints nested under `/doctors/{doctor}` and `/doctors/me`.
Administrators set and clear a doctor's weekly schedule (an appointment length plus up to four
periods per weekday; gaps are breaks) and record or remove time off. Every active staff member can
read schedules and time off and request a doctor's bookable slots for up to 31 days within a
90-day horizon. Doctors read their own. Slots are computed on request by a pure calculator from the
schedule and time off, never stored. All dates and times are the clinic's wall clock in
`Africa/Cairo`, a new setting; the app itself stays on UTC (D2). Times of day are stored as
minutes since midnight (D3). A schedule is replaced as a whole inside a locked transaction. No new
dependencies. Evidence for each choice is in [research.md](research.md).

`/speckit-clarify` was skipped; the spec's Assumptions are taken as the decisions. Changing one
later (for example letting receptionists record time off) touches the spec, this plan, and the
policies.

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image, tzdata 2026.5)

**Primary Dependencies**: Laravel Framework 13.38.0 (Carbon for dates), `laravel/sanctum` ^4.3. No
new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. Three new tables
([data-model.md](data-model.md)); `doctors` referenced, not changed.

**Testing**: PHPUnit 12 via `composer test`, against `clinic_testing`; unit tests for the slot
calculator, schedule rules, and clock-time conversion; Feature tests per user story; `travelTo`
fixes "now" in the clinic zone

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: One doctor's 31-day slots in under 2 s with 500 doctors (SC-002), measured
by quickstart Q7

**Constraints**: Wall-clock clinic time throughout (FR-020); a rejected schedule leaves the old
one unchanged (FR-005); synthetic data only

**Scale/Scope**: Single clinic, up to ~500 doctors. 10 endpoints, 1 module, 3 migrations, 1 new
config key

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; class-level policies: reads for active staff, changes for active administrators, `/doctors/me/…` for active doctors; no patient data; the time off reason is documented as not for medical or personal details | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; three new migrations, none edited; `/api/v1` via the module provider; one config key with an `.env.example` entry | ✅ |
| III | Repository pattern | `DoctorScheduleRepository` and `TimeOffRepository` contracts + Eloquent implementations bound in `SchedulingServiceProvider`; doctors read through the existing `DoctorRepository` contract | ✅ |
| IV | Thin controllers | `ScheduleController`, `SlotController`, `TimeOffController`, `OwnScheduleController`: Form Request in → service → Resource out | ✅ |
| V | Services own workflows | `ScheduleService` (replace in a locked transaction, clear), `SlotService` (load + calculate), `TimeOffService` (record, list, remove with the "ended" rule); pure rules in `Support/` | ✅ |
| VI | Form Requests/Resources/Policies | `SetScheduleRequest`, `ListSlotsRequest`, `StoreTimeOffRequest`, `ListTimeOffRequest`; `ScheduleResource`, `SlotResource`, `TimeOffResource`; `SchedulePolicy`, `TimeOffPolicy` | ✅ |
| VII | en/ar localization | `scheduling::messages`, `scheduling::attributes`, `scheduling::days` in both locales; day identifiers in responses never translated; message tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Unit tests for the calculator (SC-003) and schedule rules (SC-004); Feature tests per story; role × endpoint matrix (SC-005); both locales (SC-006) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; no new dependency; D1–D3 approved | ✅ (D1–D3 approved) |
| XI | Integrity & concurrency | Schedule replacement in one transaction with the schedule row locked `FOR UPDATE` after `insertOrIgnore` (R4); no money; slots are not booked here (006) | ✅ |
| XII | No real data | Factories only; tests and quickstart use synthetic doctors | ✅ |

**Gate result (pre-research)**: PASS (D1–D3 approved 2026-10-28).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is
empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   Schedule / Slot / TimeOff / OwnSchedule controllers (thin)
                              │   Form Request: formats, WeeklyScheduleRules, clinic-today checks
                              │   └─ authorize() → SchedulePolicy / TimeOffPolicy
                              ▼
   ScheduleService ─ DB::transaction + lock    SlotService ──▶ SlotCalculator (pure)    TimeOffService
          │                                        │                                         │
          ▼                                        ▼                                         ▼
   DoctorScheduleRepository        DoctorRepository (Doctors module: findById, findByUserId)   TimeOffRepository
```

### Endpoints ([contract](contracts/scheduling.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| GET | `/doctors/{doctor}/schedule` | any active staff | FR-007 |
| PUT | `/doctors/{doctor}/schedule` | administrator | FR-001–FR-006 |
| DELETE | `/doctors/{doctor}/schedule` | administrator | FR-006 |
| GET | `/doctors/{doctor}/slots?from&to` | any active staff | FR-008–FR-012 |
| GET | `/doctors/{doctor}/time-off?from&to` | any active staff | FR-016 |
| POST | `/doctors/{doctor}/time-off` | administrator | FR-013–FR-015 |
| DELETE | `/doctors/{doctor}/time-off/{timeOff}` | administrator | FR-017 |
| GET | `/doctors/me/schedule` | doctor | FR-018 |
| GET | `/doctors/me/slots?from&to` | doctor | FR-018 |
| GET | `/doctors/me/time-off?from&to` | doctor | FR-018 |

Status codes: `200`, `201` recorded, `204` cleared/removed, `401`, `403`, `404` unknown doctor or
time off (or no own profile), `422` validation (including "time off has ended").

### Module layout (new files)

```text
app/Modules/Scheduling/
├── Exceptions/TimeOffNotFoundException.php        # 404, scheduling::messages.time_off_not_found; not reported
├── Http/
│   ├── Controllers/{Schedule,Slot,TimeOff,OwnSchedule}Controller.php
│   ├── Requests/{SetSchedule,ListSlots,StoreTimeOff,ListTimeOff}Request.php
│   └── Resources/{Schedule,Slot,TimeOff}Resource.php
├── Models/{DoctorSchedule,SchedulePeriod,DoctorTimeOff}.php
├── Policies/{Schedule,TimeOff}Policy.php
├── Providers/SchedulingServiceProvider.php
├── Repositories/
│   ├── Contracts/DoctorScheduleRepository.php      # findForDoctor, lockOrCreateForDoctor, replacePeriods, deleteForDoctor
│   ├── Contracts/TimeOffRepository.php             # overlapping(doctorId, from, to), findForDoctor, create, delete
│   ├── EloquentDoctorScheduleRepository.php
│   └── EloquentTimeOffRepository.php
├── Services/{Schedule,Slot,TimeOff}Service.php      # SlotService::forDoctor(int $doctorId, string $from, string $to)
├── Support/
│   ├── ClockTime.php                                # "HH:MM" ⇄ minutes; patterns
│   ├── Weekday.php                                  # enum sunday..saturday ⇄ 0..6
│   ├── WeeklyScheduleRules.php                      # problems(periods, appointmentMinutes) → errors
│   ├── SlotCalculator.php                           # pure slot generation; MAX_RANGE_DAYS = 31, HORIZON_DAYS = 90
│   └── ClinicClock.php                              # today(), now() in app.clinic_timezone
├── routes/api.php
└── lang/{en,ar}/{messages,attributes,days}.php
database/migrations/<date>_create_{doctor_schedules,schedule_periods,doctor_time_off}_table.php
database/factories/{DoctorSchedule,DoctorTimeOff}Factory.php
```

Changed existing files: `config/app.php` (`clinic_timezone`, D2), `.env.example`
(`CLINIC_TIMEZONE=Africa/Cairo`), `bootstrap/providers.php` (register the provider),
`docs/roadmap.md` (005 status), `README.md` (a short "Scheduling" section linking the contract).

### Key rules

- **Authorization** (R8): class-level abilities checked in each Form Request's `authorize()`, and
  with `Gate::authorize()` for actions without a body (schedule `GET`/`DELETE`, time off
  `DELETE`), before the doctor is loaded.
- **Doctor lookup**: services take a doctor id and load it through `DoctorRepository::findById`
  (→ `DoctorNotFoundException`, 404). Own endpoints use `findByUserId`.
- **Schedule replacement** (R4): validation first (formats + `WeeklyScheduleRules` in `after()`);
  then one transaction: `lockOrCreateForDoctor` (`insertOrIgnore` + `lockForUpdate`), update
  `appointment_minutes`, `replacePeriods` (delete + insert).
- **Slots** (R6): `SlotService::forDoctor()` returns `[]` for an inactive doctor or no schedule;
  otherwise `SlotCalculator::slots()` with the periods grouped by weekday, the overlapping time off,
  and `ClinicClock::now()`.
- **Clinic clock** (R2): `ClinicClock` is the only place that reads `app.clinic_timezone`; Form
  Requests use it for "not before today" and the horizon.
- **Time off removal** (R7): `TimeOffService::remove()` loads the entry for that doctor (else
  `TimeOffNotFoundException`) and refuses with `422` when its end is not after the clinic's now.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Scheduling/ClockTimeTest` | `HH:MM` ⇄ minutes; `24:00`; invalid strings rejected |
| `tests/Unit/Scheduling/WeeklyScheduleRulesTest` | end before start; equal; shorter than one appointment; overlap; touching; fifth period on a day; valid schedules produce no problems; errors keyed `periods.<index>` |
| `tests/Unit/Scheduling/SlotCalculatorTest` | cutting with leftover; several periods and days; whole-day and partial time off (boundaries: touching windows keep the slot); past slots today; empty schedule; 24:00 end; DST: 2026-04-24 (spring forward) drops 00:00–00:55 starts; 2026-10-29 (fall back) returns 23:00–24:00 slots once (SC-003) |
| `tests/Feature/Scheduling/WeeklyScheduleTest` | set, read grouped and sorted, replace, clear, empty schedule shape, each validation rule over HTTP, failed `PUT` leaves the old schedule (SC-004), unknown doctor `404` |
| `tests/Feature/Scheduling/SlotsTest` | slots over HTTP for a fixed "now"; range rules (past start, > 31 days, beyond 90 days, `to` before `from`); inactive doctor and no schedule → empty; unknown doctor `404` |
| `tests/Feature/Scheduling/TimeOffTest` | record whole days and partial day; validation rules; overlap allowed; list by range; remove future, remove ongoing (slots return), remove ended → `422`; another doctor's id → `404`; slots reflect each change |
| `tests/Feature/Scheduling/OwnScheduleTest` | doctor's own schedule, slots, time off; doctor without profile `404`; other roles `403` |
| `tests/Feature/Scheduling/SchedulingAuthorizationTest` | every role × every endpoint (SC-005); no token `401`; deactivated account `401` |
| `tests/Feature/Scheduling/SchedulingPolicyTest` | roles × abilities; inactive actors refused |
| `tests/Feature/Scheduling/SchedulingMessagesTest` | schedule rule messages naming the day, `time_off_ended`, `404`, `403` in `en` and `ar` with identical structure (SC-006) |

`TranslationParityTest` (feature 001) checks the new language files.

## Implementation Outline (input for `/speckit-tasks`)

1. Config key `app.clinic_timezone` and `.env.example`; `ClinicClock`, `ClockTime`, `Weekday` with
   unit tests.
2. `WeeklyScheduleRules` and `SlotCalculator` with unit tests (pure, no database).
3. Migrations, models, factories.
4. Module skeleton: provider, routes, translations (`messages`, `attributes`, `days`),
   `TimeOffNotFoundException`; registered in `bootstrap/providers.php`.
5. Policies and the policy test.
6. US1: schedule repository, `ScheduleService`, `SetScheduleRequest`, `ScheduleResource`,
   `GET`/`PUT`/`DELETE` schedule, weekly schedule tests.
7. US2: `SlotService`, `ListSlotsRequest`, `SlotResource`, `GET` slots, slot tests.
8. US3: time off repository, `TimeOffService`, requests, `TimeOffResource`, time off endpoints,
   time off tests.
9. US4: own-schedule endpoints and tests.
10. Polish: authorization matrix, messages test, README and roadmap, Pint, quickstart (Q7 timing),
    verification record.

## Approved Decisions (2026-10-28)

All three were approved by the user on 2026-10-28.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Module and URLs | **New `Scheduling` module**, endpoints nested under `/doctors/{doctor}/…` and `/doctors/me/…`, reading doctors through the Doctors module's repository — R1 | Add scheduling to the `Doctors` module; or top-level `/schedules/{id}` |
| D2 | Time zone | **Keep the app on UTC; add `app.clinic_timezone` = `Africa/Cairo`**; store and return wall-clock dates and times; DST gap starts dropped, repeated hour once — R2 | Switch the whole app to `Africa/Cairo`; or store UTC instants for periods |
| D3 | Time storage | **Minutes since midnight** (`smallint`, 0–1440) in the database, `"HH:MM"` in the API — R3 | MySQL `TIME` columns |

## Risks, Assumptions, and Rollback

- **DST rules change**: Egypt has changed its daylight-saving rules before. Correct slots depend
  on the PHP image's tzdata; a rule change means rebuilding with a newer image, not a code change.
- **Schedule changes vs. future appointments**: this feature changes slots immediately; feature 006
  must decide what happens to appointments already booked outside the new hours.
- **Spec defaults not clarified**: see Summary.
- **Rollback**: `php artisan migrate:rollback --step=3` (destructive for schedules and time off;
  opt-in only), remove the provider line and the config key, and revert the commit range.

## Project Structure

### Documentation (this feature)

```text
specs/005-scheduling-availability/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── scheduling.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Doctors/                                    # existing; DoctorRepository, Doctor::isActive read by Scheduling
└── Scheduling/                                 # new (see Module layout above)
config/app.php  .env.example  bootstrap/providers.php   # changed
database/migrations/  database/factories/{DoctorSchedule,DoctorTimeOff}Factory.php
tests/Feature/Scheduling/  tests/Unit/Scheduling/
```

**Structure Decision**: The existing modular monolith. One new domain module, `Scheduling`,
following `docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
