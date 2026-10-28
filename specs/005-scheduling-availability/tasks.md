---
description: "Task list for feature 005: Scheduling and Availability"
---

# Tasks: Scheduling and Availability

**Input**: Design documents from `specs/005-scheduling-availability/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/scheduling.openapi.yaml](contracts/scheduling.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US4)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Scheduling/`. Mirror `app/Modules/Doctors/` for structure, docblock
  style, and naming; read the matching Doctors file before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`), with `App\Modules\Staff\Models\User`.
- **Fixed clock in tests**: every Feature test that depends on "today" or "now" calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`.
  2026-11-01 is a **Sunday**; 2026-11-03 is a Tuesday. Unit tests pass `now` explicitly.
- Times in the API are `"HH:MM"`; in the database they are minutes since midnight (D3):
  `09:00` = 540, `13:00` = 780, `17:00` = 1020, `21:00` = 1260, `24:00` = 1440.

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/
`tinker`/`test`, `composer test`, Pint) are listed. If a command does not behave as written,
**stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Confirm the baseline on branch `005-scheduling-availability` (no file changes)
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the eight existing migrations `Ran`
    - `docker compose exec app composer test` → 486 passed (count observed after feature 004)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Clinic clock (D2), time conversion (D3), the pure schedule rules and slot calculator,
schema, models, factories, module skeleton, translations, and policies.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Add the clinic time zone setting in `config/app.php` and `.env.example` (approved D2)
  - Scope: in `config/app.php`, right after `'timezone' => 'UTC',`, add a docblock comment
    ("Wall-clock time zone of the clinic for schedules and slots (feature 005, research R2); the
    application itself stays on UTC.") and
    `'clinic_timezone' => env('CLINIC_TIMEZONE', 'Africa/Cairo'),`. In `.env.example`, after the
    `APP_*` block, add a comment line and `CLINIC_TIMEZONE=Africa/Cairo`.
  - Verify: `docker compose exec app php artisan tinker --execute="echo config('app.clinic_timezone');"` → `Africa/Cairo`

- [X] T003 [P] Create `app/Modules/Scheduling/Support/Weekday.php`
  - Scope: `enum Weekday: int` with `Sunday = 0, Monday = 1, Tuesday = 2, Wednesday = 3, Thursday = 4,
    Friday = 5, Saturday = 6` (PHP/Carbon `dayOfWeek` numbering); `name(): string` → lower-case
    English (`'sunday'`); `static fromName(string $name): self`; `static names(): list<string>` in
    order `sunday`…`saturday`.

- [X] T004 [P] Write the failing unit test `tests/Unit/Scheduling/ClockTimeTest.php` (R3)
  - Scope: `PHPUnit\Framework\TestCase`. `ClockTime::toMinutes('00:00')` → 0, `'09:05'` → 545,
    `'23:55'` → 1435, `'24:00'` → 1440; `fromMinutes(545)` → `'09:05'`, `fromMinutes(1440)` →
    `'24:00'`; `START_PATTERN` matches `'09:00'`, `'23:55'`, not `'09:03'`, `'24:00'`, `'9:00'`;
    `END_PATTERN` matches `'24:00'` and `'09:00'`, not `'24:05'`.
  - Verify: `docker compose exec app php artisan test --filter=ClockTimeTest` → fails (class missing)

- [X] T005 Create `app/Modules/Scheduling/Support/ClockTime.php`
  - Scope: `final class ClockTime` with
    `public const START_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][05]$/';`,
    `public const END_PATTERN = '/^(([01][0-9]|2[0-3]):[0-5][05]|24:00)$/';`,
    `static toMinutes(string $time): int`, `static fromMinutes(int $minutes): string` (zero-padded,
    1440 → `'24:00'`). Docblock: the one conversion between API times and stored minutes (D3).
  - Accept: T004 passes.
  - Depends: T004

- [X] T006 [P] Create `app/Modules/Scheduling/Support/ClinicClock.php`
  - Scope: `final class ClinicClock` with static `timezone(): string` (`config('app.clinic_timezone')`),
    `now(): CarbonImmutable` (`CarbonImmutable::now($tz)`), `today(): string` (`now()->toDateString()`).
    Docblock: the only reader of `app.clinic_timezone` (R2). Respects `travelTo` in tests.
  - Depends: T002

- [X] T007 [P] Write the failing unit test `tests/Unit/Scheduling/WeeklyScheduleRulesTest.php` (R5, SC-004)
  - Scope: input periods are `list<array{day: string, start: int, end: int}>` (minutes) plus the
    appointment length. `WeeklyScheduleRules::problems()` returns `list<array{index: int, rule:
    string, day: string}>`. Cases (20-minute appointments unless stated):
    - valid: Sunday 540–780 and 1020–1260, Tuesday 600–840 → `[]`; empty list → `[]`; a period
      ending at 1440 → `[]`
    - `end_before_start`: 600–540 and 600–600 → rule `period_end_before_start` for that index
    - `period_shorter_than_appointment`: 540–550 → that rule
    - overlap: Sunday 540–780 and 700–900 → `periods_overlap` on the **later-starting** period's
      index; touching 540–780 and 780–900 → `periods_overlap`; the same times on different days → `[]`
    - five periods on Monday → `too_many_periods` reported once, on the fifth by start time
    - input order does not matter (periods given out of order still validate)
  - Verify: `docker compose exec app php artisan test --filter=WeeklyScheduleRulesTest` → fails

- [X] T008 Create `app/Modules/Scheduling/Support/WeeklyScheduleRules.php`
  - Scope: `final class WeeklyScheduleRules` with `public const MAX_PERIODS_PER_DAY = 4;` and
    `static problems(array $periods, int $appointmentMinutes): array` as described in T007. Pure:
    no database, no translation (the Form Request translates the `rule` keys). Check per period
    first (end after start, then at least one appointment), then per day sorted by start (overlap
    or touch: `next.start <= previous.end`; count > 4).
  - Accept: T007 passes.
  - Depends: T007

- [X] T009 [P] Write the failing unit test `tests/Unit/Scheduling/SlotCalculatorTest.php` (R6, SC-003)
  - Scope: `SlotCalculator::slots(array $periodsByWeekday, int $appointmentMinutes, array $timeOff,
    string $from, string $to, CarbonImmutable $now): list<array{date: string, start: string, end:
    string}>`, where `$periodsByWeekday` is `array<int, list<array{0: int, 1: int}>>` (weekday →
    `[start, end]` minutes) and `$timeOff` is `list<array{start_date: string, end_date: string,
    start_minute: ?int, end_minute: ?int}>`. `$now` carries the zone (`Africa/Cairo`). Cases (now =
    `2026-10-31 12:00` Saturday unless stated):
    - Sunday `[540, 600]`, 20 min, range `2026-11-01`…`2026-11-01` → `09:00–09:20`, `09:20–09:40`,
      `09:40–10:00`
    - Sunday `[540, 610]`, 20 min → still three slots (10-minute leftover dropped)
    - Sunday `[540, 600]` and `[1020, 1080]` plus Tuesday `[600, 640]`, range Sunday–Tuesday →
      five slots in date then time order; Monday contributes none
    - period ending at 1440: Sunday `[1380, 1440]`, 30 min → `23:00–23:30`, `23:30–24:00`
    - whole-day time off on `2026-11-01` → no Sunday slots; multi-day time off `2026-11-01`…`2026-11-03`
      → none on those dates
    - partial time off `2026-11-01` 560–580 with 20-minute slots from 540 → `09:20–09:40` removed,
      `09:00–09:20` and `09:40–10:00` kept (touching boundaries keep the slot)
    - now = `2026-11-01 09:20`: `09:00` and `09:20` dropped (start not after now), `09:40` kept
    - empty `$periodsByWeekday` → `[]`
    - DST spring forward: Friday `2026-04-24`, period `[0, 120]`, 30 min, now = `2026-04-23 08:00`
      → `01:00–01:30`, `01:30–02:00` only (00:00 and 00:30 do not exist)
    - DST fall back: Thursday `2026-10-29`, period `[1320, 1440]`, 30 min, now = `2026-10-28 08:00`
      → four slots `22:00`…`23:30`, each once
  - Verify: `docker compose exec app php artisan test --filter=SlotCalculatorTest` → fails

- [X] T010 Create `app/Modules/Scheduling/Support/SlotCalculator.php`
  - Scope: `final class SlotCalculator` with `public const MAX_RANGE_DAYS = 31;`,
    `public const HORIZON_DAYS = 90;`, and `static slots(...)` as in T009. Pure. For each date from
    `$from` to `$to` (inclusive, `CarbonImmutable` in `$now`'s zone): weekday periods sorted by
    start; cut `[start, start + length)` while `start + length <= end`; skip a slot when a
    whole-day entry covers the date, when a partial entry on that date overlaps
    (`start < offEnd && end > offStart`), when `"$date ".start` is not after `$now->format('Y-m-d
    H:i')`, or when `CarbonImmutable::createFromFormat('Y-m-d H:i', "$date $start", $tz)->format('H:i')
    !== $start` (DST gap, R2). Use `ClockTime::fromMinutes()` for output.
  - Accept: T009 passes.
  - Depends: T005, T009

- [X] T011 Create the `doctor_schedules` migration in `database/migrations/<timestamp>_create_doctor_schedules_table.php`
  - Scope: `make:migration create_doctor_schedules_table`; one-line comment pointing to
    `specs/005-scheduling-availability/data-model.md`:
    - `id()`; `foreignId('doctor_id')->unique()->constrained('doctors')->restrictOnDelete()`
    - `unsignedSmallInteger('appointment_minutes')`; `timestamps()`

- [X] T012 Create the `schedule_periods` migration in `database/migrations/<timestamp>_create_schedule_periods_table.php`
  - Scope: generated after T011: `id()`;
    `foreignId('doctor_schedule_id')->constrained('doctor_schedules')->cascadeOnDelete()`;
    `unsignedTinyInteger('weekday')`; `unsignedSmallInteger('start_minute')`;
    `unsignedSmallInteger('end_minute')`; index `['doctor_schedule_id', 'weekday']` named
    `schedule_periods_schedule_weekday_index`; no timestamps.
  - Depends: T011

- [X] T013 Create the `doctor_time_off` migration in `database/migrations/<timestamp>_create_doctor_time_off_table.php`
  - Scope: generated after T012: `id()`;
    `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`; `date('start_date')`;
    `date('end_date')`; `unsignedSmallInteger('start_minute')->nullable()`;
    `unsignedSmallInteger('end_minute')->nullable()`; `string('reason', 255)->nullable()`;
    `timestamps()`; index `['doctor_id', 'start_date', 'end_date']` named
    `doctor_time_off_doctor_dates_index`.
  - Depends: T012

- [X] T014 Create the models in `app/Modules/Scheduling/Models/`: `DoctorSchedule.php`, `SchedulePeriod.php`, `DoctorTimeOff.php`
  - Scope (persistence mapping only, as `Doctors\Models\Doctor`):
    - `DoctorSchedule`: fillable `doctor_id`, `appointment_minutes`; cast `appointment_minutes` →
      `integer`; `periods(): HasMany` → `SchedulePeriod`, ordered by `weekday`, then `start_minute`;
      `doctor(): BelongsTo` → `Doctors\Models\Doctor`; `HasFactory` with `DoctorScheduleFactory`.
    - `SchedulePeriod`: `$timestamps = false`; fillable `weekday`, `start_minute`, `end_minute`;
      integer casts.
    - `DoctorTimeOff`: `$table = 'doctor_time_off'`; fillable `doctor_id`, `start_date`, `end_date`,
      `start_minute`, `end_minute`, `reason`; casts `start_date`/`end_date` → `date`, minutes →
      `integer`; `isWholeDay(): bool` (`start_minute === null`); `HasFactory` with
      `DoctorTimeOffFactory`.
  - Depends: T011–T013

- [X] T015 [P] Create `database/factories/DoctorScheduleFactory.php` and `database/factories/DoctorTimeOffFactory.php` (synthetic only, R10)
  - Scope:
    - `DoctorScheduleFactory`: `doctor_id` → `Doctor::factory()`; `appointment_minutes` 20;
      `withPeriod(string $day, string $start, string $end)` → `afterCreating` adds a period
      (`Weekday::fromName`, `ClockTime::toMinutes`); `weekdays()` → Sunday–Thursday 09:00–13:00
      and 17:00–21:00.
    - `DoctorTimeOffFactory`: `doctor_id` → `Doctor::factory()`; whole day on the clinic's
      tomorrow (`ClinicClock::now()->addDay()->toDateString()` for both dates); minutes and reason
      null; `partial(string $start, string $end)`; `withReason()` (`'Synthetic leave'`).
  - Depends: T005, T006, T014

- [X] T016 Run the migrations and confirm the suite still passes
  - Verify:
    - `docker compose exec app php artisan migrate` → the three migrations `DONE`
    - `docker compose exec app composer test` → 486 + the new unit tests pass
  - Depends: T010, T015

- [X] T017 Create the module skeleton: `app/Modules/Scheduling/Providers/SchedulingServiceProvider.php`, `app/Modules/Scheduling/routes/api.php`, `bootstrap/providers.php`
  - Scope: as `DoctorsServiceProvider` (routes under `api/v1` with the `api` middleware,
    `loadTranslationsFrom(..., 'scheduling')`); `register()` empty for now. Routes file: comment
    header and an empty `auth:sanctum` group noting that `/doctors/me/…` routes are declared before
    the `whereNumber('doctor')` group. Append `SchedulingServiceProvider::class` after
    `DoctorsServiceProvider` (with its `use` line in alphabetical order).
  - Verify: `docker compose exec app php artisan route:list --path=api/v1/doctors` → only the five feature 004 routes
  - Depends: T016

- [X] T018 [P] Add module translations in `app/Modules/Scheduling/lang/en/` and `app/Modules/Scheduling/lang/ar/` (R9)
  - Scope: three files per locale, same keys in both:
    - `messages.php`:
      - `period_end_before_start` — `On :day, a period must end after it starts.` / `في يوم :day يجب أن تنتهي الفترة بعد بدايتها.`
      - `period_shorter_than_appointment` — `On :day, a period must be at least one appointment long.` / `في يوم :day يجب ألا تقل الفترة عن مدة موعد واحد.`
      - `periods_overlap` — `On :day, periods must not overlap or touch.` / `في يوم :day يجب ألا تتداخل الفترات أو تتلاصق.`
      - `too_many_periods` — `On :day, there can be at most :max periods.` / `في يوم :day لا يمكن أن يزيد عدد الفترات عن :max.`
      - `range_too_long` — `The date range can be at most :days days.` / `لا يمكن أن يزيد نطاق التواريخ عن :days يوماً.`
      - `beyond_horizon` — `Slots can be requested at most :days days ahead.` / `يمكن طلب المواعيد المتاحة لمدة :days يوماً مقدماً على الأكثر.`
      - `time_off_times_single_day` — `Times can only be given for time off on a single day.` / `لا يمكن تحديد الأوقات إلا لإجازة في يوم واحد.`
      - `time_off_ended` — `This time off has already ended and cannot be removed.` / `انتهت هذه الإجازة بالفعل ولا يمكن حذفها.`
      - `time_off_not_found` — `No time off was found.` / `لم يتم العثور على الإجازة.`
    - `attributes.php` (flat keys): `appointment_minutes` appointment length / مدة الموعد;
      `periods` periods / الفترات; `day` day / اليوم; `start` start time / وقت البداية;
      `end` end time / وقت النهاية; `from` from date / من تاريخ; `to` to date / إلى تاريخ;
      `start_date` start date / تاريخ البداية; `end_date` end date / تاريخ النهاية;
      `start_time` start time / وقت البداية; `end_time` end time / وقت النهاية; `reason` reason / السبب
    - `days.php`: `sunday` Sunday / الأحد, `monday` Monday / الاثنين, `tuesday` Tuesday / الثلاثاء,
      `wednesday` Wednesday / الأربعاء, `thursday` Thursday / الخميس, `friday` Friday / الجمعة,
      `saturday` Saturday / السبت
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T017

- [X] T019 [P] Create `app/Modules/Scheduling/Exceptions/TimeOffNotFoundException.php`
  - Scope: as `DoctorNotFoundException`: `render()` → `404` with
    `scheduling::messages.time_off_not_found`; empty `report()`.
  - Depends: T018

- [X] T020 [P] Write the failing policy test `tests/Feature/Scheduling/SchedulingPolicyTest.php` (R8)
  - Scope: as `DoctorsPolicyTest`, `Gate::forUser($actor)->allows($ability, <Model>::class)`:
    - `DoctorSchedule`: `view` all three roles; `update` administrator only; `viewOwn` doctor only
    - `DoctorTimeOff`: `viewAny` all three; `create`, `delete` administrator only; `viewOwn` doctor only
    - inactive actors of every role refused every ability
  - Verify: `docker compose exec app php artisan test --filter=SchedulingPolicyTest` → fails
  - Depends: T016

- [X] T021 Create `app/Modules/Scheduling/Policies/SchedulePolicy.php` and `TimeOffPolicy.php`; register both in the provider
  - Scope: class-level abilities as in T020 with private helpers (`isActiveAdministrator`,
    `isActiveDoctor`), docblocks as `DoctorPolicy`. `Gate::policy(DoctorSchedule::class,
    SchedulePolicy::class)`; `Gate::policy(DoctorTimeOff::class, TimeOffPolicy::class)`.
  - Accept: T020 passes.
  - Depends: T017, T020

**Checkpoint**: Foundation ready.

---

## Phase 3: User Story 1 – Administrator sets a doctor's weekly working hours (Priority: P1) 🎯 MVP

**Goal**: `GET`, `PUT`, `DELETE /api/v1/doctors/{doctor}/schedule`.

**Independent Test**: Give a doctor two Sunday periods and one Tuesday period with 20-minute
appointments, read it back grouped by day, then replace and clear it.

### Tests for User Story 1 (write first, must fail)

- [X] T022 [US1] Write `tests/Feature/Scheduling/WeeklyScheduleTest.php`
  - Scope (administrator unless stated; doctor via `Doctor::factory()->create()`):
    - `PUT` 20 minutes, Sunday 17:00–21:00 and 09:00–10:00, Tuesday 10:00–14:00 → `200`; `data` has
      exactly `doctor_id`, `appointment_minutes`, `days`, `updated_at`; `days` is seven items in order
      `sunday`…`saturday`; Sunday periods `[{start:'09:00',end:'10:00'},{start:'17:00',end:'21:00'}]`
    - `GET` as receptionist and as doctor → the same `data`
    - second `PUT` with only Monday 08:00–12:00 → Sunday and Tuesday now empty; `schedule_periods`
      has one row for the doctor
    - `GET` for a doctor without a schedule → `200`, `appointment_minutes` null, seven empty days,
      `updated_at` null
    - `DELETE` → `204`; `GET` then shows the empty shape; `DELETE` again → `204`
    - `periods: []` → `200` (no working hours)
    - each rule → `422` on the named key and the old schedule unchanged: `appointment_minutes` 4,
      245, 7, missing; `day` `'funday'`; `start` `'09:03'`, `'24:00'`; `end` `'24:05'`; end before
      start (`periods.0`); 09:00–09:10 (`periods.0`); touching 09:00–13:00 and 13:00–15:00
      (`periods.1`); five Monday periods; 29 periods
    - `end: '24:00'` accepted
    - unknown doctor → `404` on `GET`, `PUT` (valid body), `DELETE`
  - Verify: `docker compose exec app php artisan test --filter=WeeklyScheduleTest` → fails
  - Depends: T021

### Implementation for User Story 1

- [X] T023 [US1] Create `app/Modules/Scheduling/Repositories/Contracts/DoctorScheduleRepository.php` and `EloquentDoctorScheduleRepository.php`; bind them in the provider
  - Scope:
    - `findForDoctor(int $doctorId): ?DoctorSchedule` — with `periods`
    - `lockOrCreateForDoctor(int $doctorId, int $appointmentMinutes): DoctorSchedule` —
      `DoctorSchedule::query()->insertOrIgnore([...doctor_id, appointment_minutes, created_at,
      updated_at])`, then `where('doctor_id', …)->lockForUpdate()->firstOrFail()` (R4; called inside
      the service's transaction)
    - `update(DoctorSchedule $schedule, array $attributes): DoctorSchedule`
    - `replacePeriods(DoctorSchedule $schedule, array $periods): void` — delete all, then insert
      `list<array{weekday, start_minute, end_minute}>`
    - `deleteForDoctor(int $doctorId): void`
  - Depends: T021

- [X] T024 [US1] Create `app/Modules/Scheduling/Services/ScheduleService.php`
  - Scope (constructor: `DoctorScheduleRepository`, Doctors' `DoctorRepository`):
    - private `ensureDoctor(int $doctorId): Doctor` → `findById` or `DoctorNotFoundException`
    - `show(int $doctorId): DoctorSchedule` — the stored schedule, or an unsaved
      `new DoctorSchedule(['doctor_id' => $doctorId])` with `setRelation('periods', collect())` so the
      resource always has one shape (R4)
    - `replace(int $doctorId, int $appointmentMinutes, array $periods): DoctorSchedule` — inside
      `DB::transaction`: `lockOrCreateForDoctor`, `update(['appointment_minutes' => …])`,
      `replacePeriods`; return `findForDoctor`
    - `clear(int $doctorId): void` — ensure doctor, then `deleteForDoctor`
  - Depends: T023

- [X] T025 [P] [US1] Create `app/Modules/Scheduling/Http/Requests/SetScheduleRequest.php`
  - Scope (rules quoted from data-model.md):
    - `authorize()` → `can('update', DoctorSchedule::class)`
    - `appointment_minutes` → `required`, `integer`, `min:5`, `max:240`, `multiple_of:5`
    - `periods` → `present`, `array`, `max:28`
    - `periods.*.day` → `required`, `Rule::in(Weekday::names())`
    - `periods.*.start` → `required`, `string`, `regex:` `ClockTime::START_PATTERN`
    - `periods.*.end` → `required`, `string`, `regex:` `ClockTime::END_PATTERN`
    - `after()`: only when the validator has no errors yet, convert periods to minutes and run
      `WeeklyScheduleRules::problems()`; add each as `periods.<index>` →
      `__("scheduling::messages.<rule>", ['day' => __("scheduling::days.<day>"), 'max' =>
      WeeklyScheduleRules::MAX_PERIODS_PER_DAY])`
    - `attributes()` → `scheduling::attributes` for `appointment_minutes`, `periods`,
      `periods.*.day|start|end`
    - `periodsInMinutes(): list<array{weekday: int, start_minute: int, end_minute: int}>`
  - Depends: T005, T008, T018

- [X] T026 [P] [US1] Create `app/Modules/Scheduling/Http/Resources/ScheduleResource.php`
  - Scope: keys exactly `doctor_id`, `appointment_minutes` (null when unsaved), `days` (seven items
    `{day, periods: [{start, end}]}` built from `Weekday::cases()` and the `periods` relation, times
    via `ClockTime::fromMinutes`), `updated_at` (ISO 8601 or null).
  - Depends: T005, T014

- [X] T027 [US1] Create `app/Modules/Scheduling/Http/Controllers/ScheduleController.php`; add the routes
  - Scope: `show(int $doctor)` → `Gate::authorize('view', DoctorSchedule::class)`;
    `update(SetScheduleRequest, int $doctor)` → `replace(...)`; `destroy(int $doctor)` →
    `Gate::authorize('update', DoctorSchedule::class)`, `clear`, `response()->noContent()`. Routes
    `scheduling.schedule.show|update|destroy` on `/doctors/{doctor}/schedule` in a
    `whereNumber('doctor')` group.
  - Accept: T022 passes.
  - Verify: `docker compose exec app php artisan test --filter=WeeklyScheduleTest` → pass
  - Depends: T022, T024, T025, T026

**Checkpoint**: US1 complete — weekly schedules can be set, read, and cleared.

---

## Phase 4: User Story 2 – Staff see a doctor's bookable slots (Priority: P1)

**Goal**: `GET /api/v1/doctors/{doctor}/slots?from&to`.

**Independent Test**: Sunday 09:00–10:00 with 20-minute appointments gives exactly 09:00, 09:20,
09:40 for that Sunday.

### Tests for User Story 2 (write first, must fail)

- [X] T028 [US2] Write `tests/Feature/Scheduling/SlotsTest.php`
  - Scope (receptionist; clock fixed at Sunday 2026-11-01 08:00):
    - schedule Sunday 09:00–10:00 and 17:00–21:00, 20 min; `from=to=2026-11-01` → 15 slots,
      first `{date:'2026-11-01',start:'09:00',end:'09:20'}`, each item exactly `date`, `start`, `end`
    - `travelTo` 09:30 the same day → first slot `09:40`
    - range `2026-11-01`…`2026-11-08` → slots on both Sundays only, in order
    - whole-day time off on `2026-11-08` (factory) → no slots on that date
    - `from=2026-10-31` → `422` on `from`; `to` before `from` → `422`; 32-day range → `422`
      (`range_too_long`); `from=2027-01-01&to=2027-01-31` (beyond 90 days) → `422` (`beyond_horizon`); missing
      either → `422`
    - doctor without schedule → `200` and `[]`; doctor whose account is deactivated → `[]`; account
      changed to receptionist → `[]`
    - administrator and doctor roles also get `200`; unknown doctor → `404`
  - Verify: `docker compose exec app php artisan test --filter=SlotsTest` → fails
  - Depends: T027

### Implementation for User Story 2

- [X] T029 [US2] Create `app/Modules/Scheduling/Repositories/Contracts/TimeOffRepository.php` and `EloquentTimeOffRepository.php` with `overlapping()`; bind them in the provider
  - Scope: `overlapping(int $doctorId, string $from, string $to): Collection` —
    `start_date <= $to and end_date >= $from`, ordered by `start_date`, `start_minute`, `id` (US3
    adds the rest).
  - Depends: T021

- [X] T030 [US2] Create `app/Modules/Scheduling/Services/SlotService.php`
  - Scope (constructor: `DoctorRepository`, `DoctorScheduleRepository`, `TimeOffRepository`):
    `forDoctor(int $doctorId, string $from, string $to): array` — doctor or
    `DoctorNotFoundException`; `[]` when `! $doctor->isActive()` or no schedule; otherwise group the
    periods by weekday into `[start_minute, end_minute]` pairs, map time off to the calculator's
    array shape, and return `SlotCalculator::slots(..., ClinicClock::now())`.
  - Depends: T010, T023, T029

- [X] T031 [P] [US2] Create `app/Modules/Scheduling/Http/Requests/ListSlotsRequest.php`
  - Scope: `authorize()` → `can('view', DoctorSchedule::class)`; `from` → `required`,
    `date_format:Y-m-d`, `after_or_equal:` `ClinicClock::today()`; `to` → `required`,
    `date_format:Y-m-d`, `after_or_equal:from`; `after()` (only without earlier errors): inclusive
    day count > `SlotCalculator::MAX_RANGE_DAYS` → error on `to` (`range_too_long`, `days` 31);
    `to` after today + `HORIZON_DAYS` → error on `to` (`beyond_horizon`, `days` 90);
    `attributes()` from `scheduling::attributes`.
  - Depends: T006, T010, T018

- [X] T032 [P] [US2] Create `app/Modules/Scheduling/Http/Resources/SlotResource.php`
  - Scope: keys exactly `date`, `start`, `end` from the calculator's array.

- [X] T033 [US2] Create `app/Modules/Scheduling/Http/Controllers/SlotController.php`; add the route
  - Scope: `index(ListSlotsRequest, int $doctor)` → `SlotResource::collection($this->slots->forDoctor(
    $doctor, $request->validated('from'), $request->validated('to')))`. Route
    `scheduling.slots.index` `GET /doctors/{doctor}/slots` in the `whereNumber` group.
  - Accept: T028 passes.
  - Verify: `docker compose exec app php artisan test --filter=SlotsTest` → pass
  - Depends: T028, T030, T031, T032

**Checkpoint**: US2 complete — staff see bookable slots.

---

## Phase 5: User Story 3 – Administrator records a doctor's time off (Priority: P2)

**Goal**: `GET`/`POST /api/v1/doctors/{doctor}/time-off`, `DELETE …/time-off/{timeOff}`.

**Independent Test**: Whole-day time off next Sunday and 10:00–12:00 next Monday remove exactly
those slots.

### Tests for User Story 3 (write first, must fail)

- [X] T034 [US3] Write `tests/Feature/Scheduling/TimeOffTest.php`
  - Scope (administrator; clock fixed at Sunday 2026-11-01 08:00; doctor with `weekdays()` schedule):
    - `POST` whole days `2026-11-02`…`2026-11-03` with reason → `201`; `data` exactly `id`,
      `doctor_id`, `start_date`, `end_date`, `start_time` (null), `end_time` (null), `reason`,
      `created_at`; slots for those dates → `[]`
    - `POST` partial `2026-11-04` 10:00–12:00 → `201` with `start_time` `'10:00'`; slots that day
      exclude 10:00–12:00 and keep 09:40 and 12:00
    - overlapping entry → `201`
    - `422`: `start_date` `2026-10-31`; `end_date` before `start_date`; only `start_time`;
      times on two dates (`time_off_times_single_day`); `start_time` `'10:03'`; end not after start;
      `reason` of 256 characters
    - `start_date` today with a partial window already started (07:00–10:00) → `201`
    - `GET ?from=2026-11-01&to=2026-11-30` lists the entries in order; `from` after `to` → `422`;
      more than 366 days → `422`
    - `DELETE` future entry → `204` and its slots return; ongoing entry (`travelTo` 2026-11-02 12:00
      during the whole-day entry) → `204`; ended entry (`travelTo` 2026-11-04 13:00 for the partial
      entry) → `422` with `time_off_ended`; another doctor's entry id → `404`; unknown id → `404`
  - Verify: `docker compose exec app php artisan test --filter=TimeOffTest` → fails
  - Depends: T033

### Implementation for User Story 3

- [X] T035 [US3] Add `findForDoctor(int $doctorId, int $id): ?DoctorTimeOff`, `create(array $attributes): DoctorTimeOff`, and `delete(DoctorTimeOff $timeOff): void` to `TimeOffRepository` and `EloquentTimeOffRepository`
  - Depends: T029

- [X] T036 [US3] Create `app/Modules/Scheduling/Services/TimeOffService.php`
  - Scope (constructor: `DoctorRepository`, `TimeOffRepository`):
    - `list(int $doctorId, string $from, string $to): Collection` — ensure doctor, `overlapping`
    - `record(int $doctorId, array $attributes): DoctorTimeOff` — ensure doctor; convert
      `start_time`/`end_time` with `ClockTime::toMinutes` (null when absent); create
    - `remove(int $doctorId, int $timeOffId): void` — ensure doctor; `findForDoctor` or
      `TimeOffNotFoundException`; ended when `CarbonImmutable::parse(end_date, ClinicClock::timezone())
      ->addMinutes(end_minute ?? 1440)` is not after `ClinicClock::now()` → `ValidationException`
      on `time_off` with `scheduling::messages.time_off_ended` (R7); otherwise delete
  - Depends: T035

- [X] T037 [P] [US3] Create `StoreTimeOffRequest` and `ListTimeOffRequest` in `app/Modules/Scheduling/Http/Requests/`
  - Scope (rules quoted from data-model.md):
    - Store: `authorize()` → `can('create', DoctorTimeOff::class)`; `start_date` → `required`,
      `date_format:Y-m-d`, `after_or_equal:` `ClinicClock::today()`; `end_date` → `required`,
      `date_format:Y-m-d`, `after_or_equal:start_date`; `start_time` → `nullable`,
      `required_with:end_time`, `regex:` `START_PATTERN`; `end_time` → `nullable`,
      `required_with:start_time`, `regex:` `END_PATTERN`; `reason` → `nullable`, `string`, `max:255`;
      `after()` (only without earlier errors): times with `start_date !== end_date` → error on
      `start_time` (`time_off_times_single_day`); end minutes not after start → error on `end_time`
      (`validation.after` with the translated `start_time` attribute)
    - List: `authorize()` → `can('viewAny', DoctorTimeOff::class)`; `from`, `to` → `required`,
      `date_format:Y-m-d`, `to` `after_or_equal:from`; `after()`: more than 366 days apart → error on
      `to` (`range_too_long`, `days` 366)
    - `attributes()` from `scheduling::attributes`
  - Depends: T006, T018

- [X] T038 [P] [US3] Create `app/Modules/Scheduling/Http/Resources/TimeOffResource.php`
  - Scope: keys exactly `id`, `doctor_id`, `start_date`, `end_date` (`toDateString()`),
    `start_time`, `end_time` (`ClockTime::fromMinutes` or null), `reason`, `created_at` (ISO 8601).
  - Depends: T014

- [X] T039 [US3] Create `app/Modules/Scheduling/Http/Controllers/TimeOffController.php`; add the routes
  - Scope: `index(ListTimeOffRequest, int $doctor)`, `store(StoreTimeOffRequest, int $doctor)`
    (`201` for the new model), `destroy(int $doctor, int $timeOff)` →
    `Gate::authorize('delete', DoctorTimeOff::class)`, `remove`, `noContent()`. Routes
    `scheduling.time-off.index|store|destroy`; `{timeOff}` also `whereNumber`.
  - Accept: T034 passes.
  - Verify: `docker compose exec app php artisan test --filter="TimeOffTest|SlotsTest"` → pass
  - Depends: T034, T036, T037, T038

**Checkpoint**: US3 complete — time off removes slots and can be undone until it ends.

---

## Phase 6: User Story 4 – A doctor views their own schedule and slots (Priority: P3)

**Goal**: `GET /api/v1/doctors/me/schedule|slots|time-off`.

**Independent Test**: A doctor's own schedule and slots equal what an administrator sees for that
doctor.

- [X] T040 [US4] Write `tests/Feature/Scheduling/OwnScheduleTest.php`
  - Scope (clock fixed at Sunday 2026-11-01 08:00): a doctor with a profile and `weekdays()`
    schedule: `/doctors/me/schedule`, `/doctors/me/slots?from=2026-11-01&to=2026-11-01`,
    `/doctors/me/time-off?from=2026-11-01&to=2026-11-30` each return exactly what the
    `/doctors/{id}/…` endpoints return for that doctor; slots range rules apply (`from` yesterday →
    `422`); doctor-role account without a profile → `404` on each; administrator and receptionist →
    `403` on each.
  - Verify: `docker compose exec app php artisan test --filter=OwnScheduleTest` → fails
  - Depends: T039

- [X] T041 [US4] Create `app/Modules/Scheduling/Http/Controllers/OwnScheduleController.php`; add the routes
  - Scope: inject Doctors' `DoctorService` and the three scheduling services. `schedule(Request)` →
    `Gate::authorize('viewOwn', DoctorSchedule::class)`, `$id = DoctorService::findOwn($user)->id`,
    `ScheduleResource`; `slots(ListSlotsRequest)` and `timeOff(ListTimeOffRequest)` — the requests'
    `authorize()` must accept `viewOwn` on these routes: add `authorize()` logic
    `$this->routeIs('scheduling.me.*') ? can('viewOwn', …) : can('view'|'viewAny', …)`. Routes
    `scheduling.me.schedule|slots|time-off`, declared **before** the `whereNumber('doctor')` group.
  - Accept: T040 passes.
  - Verify: `docker compose exec app php artisan route:list --path=api/v1/doctors` → 15 routes (5 from 004, 10 new)
  - Depends: T040

**Checkpoint**: US4 complete — doctors see their own availability.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T042 [P] Write `tests/Feature/Scheduling/SchedulingAuthorizationTest.php` (SC-005)
  - Scope: data providers over all ten endpoints with valid input (clock fixed at Sunday 2026-11-01
    08:00): receptionist and doctor × the four change endpoints (`PUT`/`DELETE` schedule, `POST`/
    `DELETE` time off) → `403` with `auth.forbidden` and the schedule, periods, and time off rows
    unchanged; every role × the three read endpoints → `200`; administrator and receptionist × the
    three `/me` endpoints → `403`; no token × every endpoint → `401`; an administrator with a real
    token then deactivated → `401` (`InteractsWithStaffTokens::usingToken()`); receptionist with an
    invalid `PUT` body → `403`, not `422`.
  - Verify: `docker compose exec app php artisan test --filter=SchedulingAuthorizationTest` → pass
  - Depends: T041

- [X] T043 [P] Write `tests/Feature/Scheduling/SchedulingMessagesTest.php` (FR-021, SC-006)
  - Scope: for `en` and `ar`: `periods_overlap` with the translated day name (`Sunday` / `الأحد`);
    `range_too_long`; `beyond_horizon`; `time_off_times_single_day`; `time_off_ended`;
    `time_off_not_found` (`404`); `403` → `auth.forbidden`; JSON keys identical across locales; day
    identifiers in schedule responses identical across locales (`sunday`).
  - Verify: `docker compose exec app php artisan test --filter=SchedulingMessagesTest` → pass
  - Depends: T041

- [X] T044 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: roadmap: 005 `Done` with the spec link, 006 `Next`. README: a short "Scheduling" section
    after "Doctors and specialties": administrators set weekly hours and time off under
    `/api/v1/doctors/{doctor}/schedule` and `/time-off`; staff list slots at `/slots?from&to` (up to
    31 days, 90 days ahead); doctors use `/api/v1/doctors/me/…`; all times are clinic time
    (`CLINIC_TIMEZONE`, default `Africa/Cairo`); link the contract.
  - Depends: T041

- [X] T045 Run Pint (`vendor/bin/pint`) and the full suite (`tests/`)
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0
  - Depends: T042, T043, T044

- [X] T046 Run quickstart Q1–Q8 from `specs/005-scheduling-availability/quickstart.md` on the development stack
  - Scope: synthetic data only; Q7 against `clinic_testing` only.
  - Accept: every Q-step's expected outcome is observed, including Q7 under 2 s.
  - Depends: T045

- [X] T047 Record the results in `specs/005-scheduling-availability/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; failures reported as
    failures (Constitution VIII).
  - Depends: T046

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. The pure classes (T003–T010)
  come first and need no database.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: needs US1 (schedule repository; schedules to cut slots from).
- **US3 (Phase 5)**: needs US2 (time off repository from T029; slot checks in its tests).
- **US4 (Phase 6)**: needs US1–US3 (reuses their services and requests).
- **Polish (Phase 7)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- T003, T004, T006, T007, T009 together (pure classes and their tests); T015 alongside T014's
  completion; T018, T019, T020 together.
- US1: T025 and T026 together.
- US2: T031 and T032 together.
- US3: T037 and T038 together.
- Polish: T042, T043, T044 together.

---

## Parallel Example: Foundational pure classes

```text
Task: "Weekday enum"                       (T003)
Task: "ClockTimeTest"                      (T004)
Task: "ClinicClock"                        (T006)
Task: "WeeklyScheduleRulesTest"            (T007)
Task: "SlotCalculatorTest"                 (T009)
```

## Parallel Example: Polish

```text
Task: "SchedulingAuthorizationTest"   (T042)
Task: "SchedulingMessagesTest"        (T043)
Task: "Roadmap and README updates"    (T044)
```

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 US1 → **stop and validate**: weekly schedules can be set and read.
3. Phase 4 US2 → slots — the part feature 006 needs.

### Incremental Delivery

1. US1 → weekly schedules.
2. US2 → bookable slots.
3. US3 → time off.
4. US4 → doctors' own view.
5. Polish → authorization matrix, both locales, docs, verification.

Following the project's convention, each phase is implemented on its own branch (e.g.
`005-phase-2-foundational`) and merged by pull request.

---

## Notes

- [P] = different files, no dependency on an unfinished task.
- Commit after each task (or a tightly related group), with the task ID in the message.
- Never weaken or delete a test to make a task pass; report failures as observed.
- Synthetic doctors and schedules only (factories); time off reasons in tests are synthetic.
