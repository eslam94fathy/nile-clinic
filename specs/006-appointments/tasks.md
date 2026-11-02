---
description: "Task list for feature 006: Appointments"
---

# Tasks: Appointments

**Input**: Design documents from `specs/006-appointments/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/appointments.openapi.yaml](contracts/appointments.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and XI, and the plan's Testing Strategy). Each story's tests
are written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.
Phase order follows priority: US1 (P1), US2 (P1), US3 (P2), US5 (P2), US4 (P3).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Appointments/`. Mirror `app/Modules/Scheduling/` and
  `app/Modules/Doctors/` for structure, docblock style, and naming; read the matching file there
  before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`.
- **Fixed clock in tests**: every Feature test that depends on "today" or "now" calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`.
  2026-11-01 is a **Sunday**; 2026-11-03 is a Tuesday; 2026-11-08 the next Sunday. Standard test
  doctor: `DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->withPeriod('tuesday',
  '10:00', '11:00')` (20-minute appointments) → Sunday slots `09:00`, `09:20`, `09:40`.
  **Exception**: the concurrency test (T027) runs child processes that do not see `travelTo`; it
  uses real dates (R9).
- Times: API `"HH:MM"`; database minutes since midnight (`09:00` = 540, `09:20` = 560,
  `09:40` = 580, `10:00` = 600). Convert only with `Scheduling\Support\ClockTime`.
- "Now" and "today" only through `Scheduling\Support\ClinicClock`. An appointment has **started**
  when `date + start ≤ ClinicClock::now()` (wall clock).
- **Branch per phase**: create a new git branch for each phase from the previous one (for example
  `006-phase-2-foundational`, `006-phase-3-us1-book`).

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/
`tinker`/`test`, `composer test`, Pint) are listed. If a command does not behave as written,
**stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Confirm the baseline on branch `006-appointments` (no file changes)
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the eleven existing migrations `Ran`
    - `docker compose exec app composer test` → 682 passed (count recorded after feature 005 in
      `specs/005-scheduling-availability/verification.md`)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, model, factory, the Scheduling and Patients extensions (D1, D3), module
skeleton, translations, exceptions, and the policy.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Create the `appointments` migration in `database/migrations/<timestamp>_create_appointments_table.php`
  - Scope: `docker compose exec app php artisan make:migration create_appointments_table`; one-line
    comment pointing to `specs/006-appointments/data-model.md` and R3. Columns, exactly:
    - `id()`
    - `foreignId('patient_id')->constrained('patients')->restrictOnDelete()`
    - `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`
    - `date('date')`; `unsignedSmallInteger('start_minute')`; `unsignedSmallInteger('end_minute')`
    - `string('status', 20)` — "`scheduled` | `cancelled` | `no_show`"
    - `unsignedTinyInteger('scheduled_flag')->nullable()->storedAs("IF(status = 'scheduled', 1, NULL)")`
    - `string('note', 500)->nullable()`
    - `foreignId('booked_by')->constrained('users')->restrictOnDelete()`
    - `foreignId('rescheduled_by')->nullable()->constrained('users')->restrictOnDelete()`; `timestamp('rescheduled_at')->nullable()`
    - `foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete()`; `timestamp('status_changed_at')->nullable()`
    - `string('cancellation_reason', 255)->nullable()`; `timestamps()`
    - `unique(['doctor_id', 'date', 'start_minute', 'scheduled_flag'], 'appointments_doctor_slot_unique')`
    - `index(['patient_id', 'date', 'start_minute'], 'appointments_patient_date_index')`
    - `index(['date', 'start_minute'], 'appointments_date_index')`
  - Verify: `docker compose exec app php artisan migrate` → `create_appointments_table` `DONE`;
    `migrate:status` shows it `Ran`.

- [X] T003 [P] Create `app/Modules/Appointments/Enums/AppointmentStatus.php`
  - Scope: `enum AppointmentStatus: string` with `Scheduled = 'scheduled'`,
    `Cancelled = 'cancelled'`, `NoShow = 'no_show'`; `static values(): list<string>`. Docblock:
    transitions per research R5 (scheduled → scheduled by reschedule; → cancelled; → no_show;
    the last two are final).

- [X] T004 Create `app/Modules/Appointments/Models/Appointment.php`
  - Scope (persistence mapping only, as `Doctors\Models\Doctor`): fillable `patient_id`,
    `doctor_id`, `date`, `start_minute`, `end_minute`, `status`, `note`, `booked_by`,
    `rescheduled_by`, `rescheduled_at`, `status_changed_by`, `status_changed_at`,
    `cancellation_reason` (never `scheduled_flag`); casts `date` → `date`, minutes → `integer`,
    `status` → `AppointmentStatus`, `rescheduled_at`/`status_changed_at` → `datetime`,
    `outside_availability` → `boolean` (a selected computed column, R7). Relations: `patient()`
    → `Patients\Models\Patient`; `doctor()` → `Doctors\Models\Doctor`; `bookedBy()`,
    `rescheduledBy()`, `statusChangedBy()` → `Staff\Models\User` (foreign keys `booked_by`,
    `rescheduled_by`, `status_changed_by`). `HasFactory` with `AppointmentFactory`.
  - Depends: T002, T003

- [X] T005 [P] Create `database/factories/AppointmentFactory.php` (synthetic only)
  - Scope: `patient_id` → `Patient::factory()`; `doctor_id` → `Doctor::factory()`; `date` →
    `ClinicClock::now()->addDay()->toDateString()`; `start_minute` 540; `end_minute` 560; `status`
    `scheduled`; `note` null; `booked_by` → `User::factory()->receptionist()`. States:
    `at(string $date, string $start, int $minutes = 20)` (sets date and both minutes via
    `ClockTime::toMinutes`); `cancelled()` (status, `status_changed_at` now,
    `status_changed_by` a receptionist, `cancellation_reason` `'Synthetic reason'`); `noShow()`.
  - Depends: T004

- [X] T006 [P] Add `lockForDoctor` to `app/Modules/Scheduling/Repositories/Contracts/DoctorScheduleRepository.php` and `app/Modules/Scheduling/Repositories/EloquentDoctorScheduleRepository.php` (R3)
  - Scope: `public function lockForDoctor(int $doctorId): ?DoctorSchedule;` docblock: "The
    doctor's schedule row locked `FOR UPDATE` until the caller's transaction ends, or null when the
    doctor has no schedule. Serializes bookings for one doctor with each other and with schedule
    changes (feature 006, research R3)." Implementation:
    `DoctorSchedule::query()->where('doctor_id', $doctorId)->lockForUpdate()->first()`. Does not
    create a row and loads no relations.

- [X] T007 [P] Add `lockById` to `app/Modules/Patients/Repositories/Contracts/PatientRepository.php` and `app/Modules/Patients/Repositories/EloquentPatientRepository.php` (R3)
  - Scope: `public function lockById(int $id): ?Patient;` docblock: "The patient row locked
    `FOR UPDATE` until the caller's transaction ends, or null. Serializes bookings for one patient
    (feature 006, research R3)." Implementation:
    `Patient::query()->whereKey($id)->lockForUpdate()->first()`.

- [X] T008 [P] Create the contract `app/Modules/Scheduling/Repositories/Contracts/BookedTimeRepository.php` (approved D1, R2)
  - Scope: `interface BookedTimeRepository` with
    `public function forDoctor(int $doctorId, string $from, string $to): array;` returning
    `list<array{date: string, start_minute: int, end_minute: int}>` — the doctor's times already
    taken by scheduled appointments on those dates (inclusive). Docblock: declared by Scheduling,
    implemented and bound by the Appointments module, so the dependency goes one way.

- [X] T009 Extend `tests/Unit/Scheduling/SlotCalculatorTest.php` with booked times (FR-006, R2) — failing first
  - Scope: add a `$booked` argument (last, `list<array{date, start_minute, end_minute}>`) to new
    cases; existing cases stay unchanged and pass without it. Cases (now `2026-10-31 12:00`):
    - Sunday `[540, 600]`, 20 min, booked `2026-11-01` 560–580 → `09:00`, `09:40` only
    - booked on another date (`2026-11-03` 560–580) → all three Sunday slots
    - old-length booking 560–580 with 30-minute grid on `[540, 660]` → `09:00–09:30` and
      `09:30–10:00` dropped (both overlap), `10:00–10:30`, `10:30–11:00` kept
    - touching booking 600–620 next to slot `09:40–10:00` → slot kept
  - Verify: `docker compose exec app php artisan test --filter=SlotCalculatorTest` → new cases fail

- [X] T010 Update `app/Modules/Scheduling/Support/SlotCalculator.php`
  - Scope: add the last parameter `array $booked = []` to `slots()` with the docblock type from
    T009. Group booked intervals by date once; skip a slot when
    `$start < $bookedEnd && $end > $bookedStart` for any interval on that date (reuse the private
    `overlapsAny`). Nothing else changes.
  - Accept: T009 passes; the whole `SlotCalculatorTest` passes.
  - Depends: T009

- [X] T011 Update `app/Modules/Scheduling/Services/SlotService.php` (R2, R4)
  - Scope: inject `BookedTimeRepository $booked`. Add `public function workingSlots(int $doctorId,
    string $from, string $to): array` — today's body of `forDoctor()` (schedule, time off, past,
    inactive doctor, DST), with no booked times. `forDoctor()` becomes: the same inputs plus
    `$this->booked->forDoctor($doctorId, $from, $to)` passed as `$booked`. Both throw
    `DoctorNotFoundException` for an unknown doctor. Docblock on `workingSlots()`: "for booking
    checks (feature 006); does not hide booked slots".
  - Depends: T008, T010

- [X] T012 Create `app/Modules/Appointments/Repositories/Contracts/AppointmentRepository.php` and `app/Modules/Appointments/Repositories/EloquentAppointmentRepository.php` with the methods booking needs
  - Scope (more methods are added in later stories):
    - `create(array $attributes): Appointment`
    - `update(Appointment $appointment, array $attributes): Appointment`
    - `lockById(int $id): ?Appointment` — `lockForUpdate()->find($id)`, no relations
    - `doctorOverlaps(int $doctorId, string $date, int $start, int $end, ?int $exceptId = null): bool`
      — any `status = scheduled` row of the doctor on `date` with `start_minute < $end AND
      end_minute > $start`, excluding `$exceptId`
    - `patientOverlaps(int $patientId, string $date, int $start, int $end, ?int $exceptId = null): bool` — same for the patient
    - `find(int $id, CarbonImmutable $now): ?Appointment` — with `patient`, `doctor.user`,
      `bookedBy`, `rescheduledBy`, `statusChangedBy` loaded and the `outside_availability` column
      (until T030, select `false AS outside_availability`; T030 replaces it)
  - Depends: T004

- [X] T013 [P] Create `app/Modules/Appointments/Repositories/EloquentBookedTimeRepository.php` (D1)
  - Scope: implements `BookedTimeRepository::forDoctor()`: scheduled appointments of the doctor
    with `date` between `$from` and `$to` → `list` of `['date' => Y-m-d, 'start_minute' => int,
    'end_minute' => int]`, ordered by date, start. Docblock: the Appointments side of D1.
  - Depends: T004, T008

- [X] T014 Create the module skeleton: `app/Modules/Appointments/Providers/AppointmentsServiceProvider.php`, `app/Modules/Appointments/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: provider mirrors `SchedulingServiceProvider`: `register()` binds
    `AppointmentRepository` → `EloquentAppointmentRepository` and
    `Scheduling\Repositories\Contracts\BookedTimeRepository` → `EloquentBookedTimeRepository`;
    `boot()` loads `routes/api.php` with `api` middleware and `api/v1` prefix and translations
    namespace `appointments` (the policy line is added in T017).
    Routes file: header comment only plus an empty `Route::middleware('auth:sanctum')->group(...)`
    (filled per story). Add `AppointmentsServiceProvider::class` after
    `SchedulingServiceProvider::class` in `bootstrap/providers.php`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.
  - Depends: T012, T013

- [X] T015 [P] Create the translations `app/Modules/Appointments/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('appointments::messages.<key>')`), en / ar:
    - `slot_unavailable`: "This time is not an available slot for this doctor." / "هذا الوقت ليس من المواعيد المتاحة لهذا الطبيب."
    - `slot_booked`: "This slot is already booked." / "هذا الموعد محجوز بالفعل."
    - `patient_busy`: "This patient already has an appointment at this time." / "لدى هذا المريض موعد آخر في هذا الوقت."
    - `not_changeable`: "This appointment can no longer be changed." / "لم يعد من الممكن تعديل هذا الموعد."
    - `not_started`: "This appointment has not started yet." / "لم يبدأ هذا الموعد بعد."
    - `not_found`: "No appointment was found." / "لم يتم العثور على الموعد."
    - `patient_not_found`: "No patient was found with this patient number." / "لا يوجد مريض بهذا الرقم."
    - `doctor_not_found`: "No doctor was found." / "لم يتم العثور على الطبيب."
    - `range_too_long`: "The date range can be at most :days days." / "يمكن أن يكون نطاق التاريخ :days يومًا على الأكثر."
    - attributes en / ar: `patient_id` "patient number" / "رقم المريض"; `doctor_id` "doctor" /
      "الطبيب"; `date` "date" / "التاريخ"; `start` "start time" / "وقت البدء"; `note` "note" /
      "الملاحظة"; `reason` "reason" / "السبب"; `from` "from date" / "من تاريخ"; `to` "to date" /
      "إلى تاريخ"; `status` "status" / "الحالة"; `outside_availability` "outside availability" /
      "خارج أوقات العمل"; `appointment` "appointment" / "الموعد".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [X] T016 [P] Create `app/Modules/Appointments/Exceptions/AppointmentNotFoundException.php` and `AppointmentConflictException.php` (R6)
  - Scope: `AppointmentNotFoundException` exactly like `PatientNotFoundException` (404,
    `appointments::messages.not_found`, empty `report()`). `AppointmentConflictException`:
    constructor `public function __construct(public readonly string $reason)` where `$reason` is
    `'slot_booked'` or `'patient_busy'`; `render()` → `response()->json(['message' =>
    __("appointments::messages.{$this->reason}"), 'reason' => $this->reason], 409)`; empty
    `report()`.

- [X] T017 Write `tests/Feature/Appointments/AppointmentPolicyTest.php`, then create `app/Modules/Appointments/Policies/AppointmentPolicy.php` (R8)
  - Scope: class-level abilities on `Appointment::class`: `viewAny`, `view`, `create`, `update` →
    active administrator or receptionist; `viewOwn` → active doctor. Test: every role (active and
    inactive) × every ability, as `tests/Feature/Scheduling/SchedulingPolicyTest.php`. Then add
    `Gate::policy(Appointment::class, AppointmentPolicy::class)` to the provider's `boot()`.
  - Verify: `docker compose exec app php artisan test --filter=AppointmentPolicyTest` → fails
    before the policy exists, passes after.
  - Depends: T014

- [X] T018 Write `tests/Feature/Appointments/AppointmentPersistenceTest.php` (R3)
  - Scope: `RefreshDatabase`. Using the factory: two `scheduled` rows with the same doctor, date,
    `start_minute` → `UniqueConstraintViolationException`; a `cancelled` and a `scheduled` row at
    the same slot → allowed; two `cancelled` rows at the same slot → allowed; `scheduled_flag` is
    `1` for scheduled and `null` after updating status to `cancelled`; deleting a patient, doctor,
    or booking user with an appointment → `QueryException` (restrict).
  - Verify: `docker compose exec app php artisan test --filter=AppointmentPersistenceTest` → pass
  - Depends: T005

- [X] T019 Run the Scheduling and Patients suites unchanged
  - Verify: `docker compose exec app php artisan test tests/Feature/Scheduling tests/Unit/Scheduling tests/Feature/Patients` → all pass (no booked times exist, so slot results are unchanged).
  - Depends: T011, T014

**Checkpoint**: Schema, model, the slot extension, and the module skeleton exist; all earlier tests pass.

---

## Phase 3: User Story 1 — Receptionist books a patient into a free slot (Priority: P1) 🎯 MVP

**Goal**: `POST /api/v1/appointments` books a patient into a slot the doctor offers; booked slots
disappear from `/slots`; simultaneous bookings never double-book.

**Independent Test**: Standard test doctor; book a patient at Sunday 09:20; `/doctors/{id}/slots`
for Sunday returns only `09:00` and `09:40`; the response contains the patient, doctor, date, and
times.

### Tests for User Story 1 (write first, must fail)

- [X] T020 [P] [US1] Write `tests/Feature/Appointments/BookAppointmentTest.php` (US1-1…8, FR-001–FR-007, SC-003)
  - Scope: clock fixed (Sunday 08:00); standard test doctor; receptionist actor unless stated.
    - book `{patient_id, doctor_id, date: 2026-11-01, start: 09:20, note: "Synthetic first visit"}`
      → `201`; `data` matches `Appointment` schema in the contract: `status: scheduled`,
      `start: 09:20`, `end: 09:40`, `outside_availability: false`, `doctor {id, name, title}`,
      `patient {id, full_name, phone}` and no other patient keys, `note`, `booked_by {id, name}`,
      `booked_at`, null reschedule/status fields; one row in `appointments`
    - administrator can book too → `201`
    - `slot_unavailable` (422, error on `start`, nothing saved) for: `08:00` (outside hours);
      `09:30` (off grid); Monday `2026-11-02 09:00` (no period); a whole-day time off on Sunday;
      a partial time off 09:20–09:40; date `2026-10-31` (past); today `09:00` with clock at
      `09:10`; `2026-11-01` + 91 days (beyond horizon, a doctor with a period on that weekday);
      inactive doctor (`user->update(['is_active' => false])`); doctor without a schedule
    - second booking of Sunday 09:20 for another patient → `409`,
      `{message, reason: slot_booked}`; still one row
    - booking a slot overlapping an old-length appointment (insert a 09:20–09:50 appointment with
      the factory, then book `09:40`) → `409 slot_booked`
    - same patient, second doctor with Sunday 09:00–10:00, book `09:20` → `409 patient_busy`;
      same patient at Tuesday 10:00 → `201`
    - a cancelled appointment at Sunday 09:20 does not block booking it → `201`
    - unknown `patient_id` 999999 → `422` on `patient_id` (`patient_not_found`); unknown
      `doctor_id` → `422` on `doctor_id`
    - format errors: missing fields; `start: "9:20"`; `date: "01-11-2026"`; `note` of 501
      characters → `422`
    - doctor actor → `403` and no row
  - Verify: `docker compose exec app php artisan test --filter=BookAppointmentTest` → fails (route missing)

- [X] T021 [P] [US1] Extend `tests/Feature/Scheduling/SlotsTest.php` with booked slots (FR-006, SC-004)
  - Scope: with a scheduled factory appointment at Sunday 09:20–09:40 for the test doctor,
    `GET /doctors/{id}/slots?from=2026-11-01&to=2026-11-01` → `09:00`, `09:40` only; the same
    through `/doctors/me/slots` for that doctor; a `cancelled()` or `noShow()` appointment at
    09:20 hides nothing; an appointment of another doctor hides nothing.
  - Verify: `docker compose exec app php artisan test --filter=SlotsTest` → new cases pass already
    (T011, T013) — if they fail, fix before continuing

### Implementation for User Story 1

- [X] T022 [US1] Create `app/Modules/Appointments/Http/Requests/BookAppointmentRequest.php`
  - Scope: `authorize()` → `can('create', Appointment::class)`. Rules: `patient_id` required
    integer min 1; `doctor_id` required integer min 1; `date` required `date_format:Y-m-d`;
    `start` required string `regex:` `ClockTime::START_PATTERN`; `note` nullable string max 500.
    `prepareForValidation()` trims `note`, empty → null. `attributes()` from
    `appointments::attributes`. `after()`: when no other errors and `date` is before
    `ClinicClock::today()` or after today + `SlotCalculator::HORIZON_DAYS` → error on `start`
    `appointments::messages.slot_unavailable`.

- [X] T023 [US1] Create `app/Modules/Appointments/Services/BookingService.php` with `book()` (R3, R4)
  - Scope: inject `AppointmentRepository`, `DoctorScheduleRepository`, `PatientRepository`,
    `DoctorRepository`, `SlotService`.
    `public function book(User $actor, array $input): Appointment` with
    `array{patient_id: int, doctor_id: int, date: string, start: string, note?: ?string}`:
    `DB::transaction(function () {...}, attempts: 3)` whose body is, **in this order** (docblock:
    "locks first — no plain read before them, research R3"):
    1. `$this->schedules->lockForDoctor($doctorId)` (may be null)
    2. `$this->patients->lockById($patientId) ?? ValidationException(['patient_id' => patient_not_found])`
    3. `$this->doctors->findById($doctorId) ?? ValidationException(['doctor_id' => doctor_not_found])`
    4. `$slot` = the entry of `$this->slots->workingSlots($doctorId, $date, $date)` whose `start`
       equals the input; none (or `$schedule === null`) → `ValidationException(['start' => slot_unavailable])`
    5. `doctorOverlaps(...)` → `AppointmentConflictException('slot_booked')`;
       `patientOverlaps(...)` → `AppointmentConflictException('patient_busy')`
    6. `create([... 'status' => AppointmentStatus::Scheduled, 'start_minute', 'end_minute' from
       the slot via ClockTime, 'note', 'booked_by' => $actor->id])`
    Wrap the transaction call: `UniqueConstraintViolationException` →
    `AppointmentConflictException('slot_booked')`. Return
    `$this->appointments->find($id, ClinicClock::now())`.
  - Depends: T012, T016, T022

- [X] T024 [P] [US1] Create `app/Modules/Appointments/Http/Resources/AppointmentSummaryResource.php` and `AppointmentResource.php`
  - Scope: summary: `id`, `date` (Y-m-d), `start`/`end` (`ClockTime::fromMinutes`), `status`
    (`->value`), `outside_availability` (bool), `doctor` `{id, name: doctor->user->name, title}`,
    `patient` `{id, full_name, phone}` — never `note`, national ID, address, emergency contact
    (FR-009). Full resource: summary fields plus `note`, `booked_by` / `rescheduled_by` /
    `status_changed_by` as `{id, name}` or null, `booked_at` (`created_at`), `rescheduled_at`,
    `status_changed_at` (ISO 8601 or null), `cancellation_reason`.

- [X] T025 [US1] Create `app/Modules/Appointments/Http/Controllers/AppointmentController.php` with `store()`, and add the route
  - Scope: `store(BookAppointmentRequest $request)` →
    `AppointmentResource::make($this->booking->book($request->user(), $request->validated()))->response()->setStatusCode(201)`.
    Route in `routes/api.php`: `Route::post('/appointments', [AppointmentController::class,
    'store'])->name('appointments.store');`
  - Accept: T020 passes; `route:list --path=appointments` shows the route.
  - Depends: T023, T024

- [X] T026 [US1] Create `tests/Support/book-appointment.php` (child process for R9)
  - Scope: plain PHP script: `require __DIR__.'/../../vendor/autoload.php'`; `$app = require
    __DIR__.'/../../bootstrap/app.php'`; bootstrap the console kernel; **exit 2 unless**
    `DB::connection()->getDatabaseName() === 'clinic_testing'`. Arguments: `actorId patientId
    doctorId date start startAtMicrotime`. Busy-wait (`usleep(1000)`) until `startAt`, then call
    `app(BookingService::class)->book(User::findOrFail($actorId), [...])`. Print one JSON line:
    `{"result":"booked","id":N}`, `{"result":"conflict","reason":"..."}`, or
    `{"result":"error","class":"...","message":"..."}`; exit 0.

- [X] T027 [US1] Write `tests/Feature/Appointments/ConcurrentBookingTest.php` `#[Group('concurrency')]` (SC-002, R9)
  - Scope: `use DatabaseTruncation` (not `RefreshDatabase`: children need committed data). No
    `travelTo`; pick `$date` = the next Sunday at least 2 days after the real clinic today. Create
    a doctor with a Sunday 09:00–10:00 schedule, 8 patients, a receptionist. `$startAt =
    microtime(true) + 1.5`. `Process::pool` with 8 processes `php tests/Support/book-appointment.php
    ...` for the same slot `09:20`, each with `->env(['APP_ENV' => 'testing', 'DB_DATABASE' =>
    'clinic_testing'])` and `->path(base_path())`. Assert: exactly one `booked`, seven
    `conflict` with `slot_booked`, no `error`; exactly one scheduled row. Second case: one patient,
    8 doctors with the same Sunday schedule, each process books a different doctor at `09:20` →
    exactly one `booked`, seven `patient_busy`. On any `error` line, fail with its message.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T025, T026

**Checkpoint**: Booking works and is safe under concurrency (MVP).

---

## Phase 4: User Story 2 — Staff find and view appointments (Priority: P1)

**Goal**: `GET /appointments` (range, filters, paging) and `GET /appointments/{id}`, with
`outside_availability` computed on read (D2).

**Independent Test**: Three appointments across two doctors and two days; list one doctor's day,
one patient's appointments, and open one — each returns exactly the expected rows in order.

### Tests for User Story 2 (write first, must fail)

- [X] T028 [P] [US2] Write `tests/Feature/Appointments/ListAppointmentsTest.php` (US2-1…6, FR-008–FR-010)
  - Scope: clock fixed. Factory appointments across two doctors, two patients, two days, one
    cancelled.
    - `GET /appointments?from=2026-11-01&to=2026-11-03` → all, ordered by date, start, id; each
      item has exactly the `AppointmentSummary` keys; no `note`, `national_id`, `address`,
      `emergency_contact*` anywhere in the JSON (US2-6)
    - filters `doctor_id`, `patient_id`, `status=cancelled`, and combined → only matches;
      unknown `doctor_id` → empty `data`
    - paging: `per_page=2` → `meta.total` and two items; `per_page=101` → `422`
    - range: `to` before `from` → `422`; 93 days inclusive → `422` with `range_too_long`; 92 days
      → `200`; past ranges allowed
    - `GET /appointments/{id}` → full `Appointment` shape with `note`, `booked_by`; unknown id →
      `404` `not_found`
    - doctor actor on both → `403`
  - Verify: `--filter=ListAppointmentsTest` → fails

- [X] T029 [P] [US2] Write `tests/Feature/Appointments/OutsideAvailabilityTest.php` (FR-018, SC-008, US2-7…9)
  - Scope: clock fixed; standard test doctor; booked Sunday 09:20–09:40 (via `POST`) and a
    Tuesday 10:00–10:20. Each case asserts the flag in `GET /appointments/{id}` and in the list:
    - baseline → `false` for both
    - whole-day time off on Sunday (via `POST /doctors/{id}/time-off` as administrator → `201`,
      not blocked) → Sunday `true`, Tuesday `false`; dates and times unchanged
    - partial time off 09:30–09:50 → `true`; partial 09:40–10:00 (touching) → `false`
    - Sunday period replaced by 09:30–10:00 (no longer covers) → `true`; by 09:00–12:00 → `false`
    - schedule cleared → both `true`; doctor account deactivated → both `true`
    - appointment length changed to 30 with the same periods → `false`; `/slots` hides every
      slot overlapping 09:20–09:40
    - removing the time off → `false` again
    - a cancelled or no-show appointment, or one that has started (clock moved to 09:30), → `false`
    - filter `outside_availability=true` returns only flagged rows; `false` only the rest
  - Verify: `--filter=OutsideAvailabilityTest` → fails

### Implementation for User Story 2

- [X] T030 [US2] Add `paginate` and the `outside_availability` expression to `AppointmentRepository` / `EloquentAppointmentRepository` (R7, R10)
  - Scope: `paginate(array $filters, CarbonImmutable $now, int $perPage): LengthAwarePaginator`
    with `array{from: string, to: string, doctor_id?: int, patient_id?: int, status?: string,
    outside_availability?: bool}`; ordered `date`, `start_minute`, `id`; relations `patient`,
    `doctor.user`. One private method builds the expression, used as
    `selectRaw('appointments.*, (<expr>) AS outside_availability', $bindings)` in `paginate`,
    `find`, and (later) the own-doctor queries, and as `whereRaw('(<expr>) = 1' / '= 0')` for
    the filter. Expression (bindings `:today`, `:minute` from `$now`):
    `status = 'scheduled' AND (date > :today OR (date = :today AND start_minute > :minute)) AND (`
    `NOT EXISTS (SELECT 1 FROM doctor_schedules s JOIN schedule_periods p ON p.doctor_schedule_id = s.id WHERE s.doctor_id = appointments.doctor_id AND p.weekday = DAYOFWEEK(appointments.date) - 1 AND p.start_minute <= appointments.start_minute AND p.end_minute >= appointments.end_minute)`
    `OR EXISTS (SELECT 1 FROM doctor_time_off t WHERE t.doctor_id = appointments.doctor_id AND t.start_date <= appointments.date AND t.end_date >= appointments.date AND (t.start_minute IS NULL OR (t.start_minute < appointments.end_minute AND t.end_minute > appointments.start_minute)))`
    `OR NOT EXISTS (SELECT 1 FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = appointments.doctor_id AND u.is_active = 1 AND u.role = 'doctor'))`.
    Use positional bindings in a fixed order (MySQL PDO does not repeat named bindings). Replace
    the placeholder in `find()` from T012. Docblock cites R7 and FR-018.

- [X] T031 [US2] Create `app/Modules/Appointments/Http/Requests/ListAppointmentsRequest.php` and `app/Modules/Appointments/Services/AppointmentService.php` with `list()` and `find()`
  - Scope: request: `authorize()` → `can('viewAny', Appointment::class)`; rules `from`, `to`
    required `date_format:Y-m-d`, `to` `after_or_equal:from`; `doctor_id`, `patient_id` nullable
    integer min 1; `status` nullable `in:` `AppointmentStatus::values()`; `outside_availability`
    nullable boolean; `page` nullable integer min 1; `per_page` nullable integer 1–100. `after()`:
    more than 92 days inclusive → error on `to` `range_too_long` (`days` = 92; constant
    `MAX_RANGE_DAYS = 92` on the request). `filters()` returns the sent keys typed (ints, bool);
    `perPage()` default 15. Service: `list(array $filters, int $perPage)` →
    `paginate($filters, ClinicClock::now(), $perPage)`; `find(int $id)` → repository `find` ??
    `AppointmentNotFoundException`.

- [X] T032 [US2] Add `index()` and `show()` to `AppointmentController` and the routes
  - Scope: `index(ListAppointmentsRequest)` → `AppointmentSummaryResource::collection(...)`;
    `show(int $appointment)` → `Gate::authorize('view', Appointment::class)` then
    `AppointmentResource::make($this->appointments->find($appointment))`. Routes:
    `GET /appointments` `appointments.index`; inside `Route::whereNumber('appointment')`:
    `GET /appointments/{appointment}` `appointments.show`.
  - Accept: T028 and T029 pass.
  - Depends: T030, T031

**Checkpoint**: US1 + US2 — staff can book and find appointments.

---

## Phase 5: User Story 3 — Reschedule or cancel (Priority: P2)

**Goal**: `POST /appointments/{id}/reschedule` and `/cancel`; freed slots return to `/slots`.

**Independent Test**: Book Sunday 09:20, reschedule to Tuesday 10:00 (Sunday 09:20 offered again,
Tuesday 10:00 not), then cancel (Tuesday 10:00 offered again; status `cancelled` with reason).

### Tests for User Story 3 (write first, must fail)

- [X] T033 [P] [US3] Write `tests/Feature/Appointments/RescheduleAppointmentTest.php` (US3-1…3, US3-5, FR-011)
  - Scope: clock fixed; standard test doctor.
    - Sunday 09:20 → Tuesday 10:00 → `200`; same `id`, patient, doctor; `rescheduled_by` the
      actor, `rescheduled_at` set; `/slots` shows Sunday 09:20 again and not Tuesday 10:00
      (SC-004)
    - into `08:00`, off-grid, past, beyond horizon → `422 slot_unavailable`; into a booked slot →
      `409 slot_booked`; patient busy with another doctor → `409 patient_busy`; the appointment
      unchanged after each refusal
    - after a length change to 30 minutes, a 09:20–09:40 appointment moves to `09:30` (overlaps
      its own old time) → `200`, `end` `10:00`
    - cancelled, no-show, or started (clock at 09:30) → `422` on `appointment` `not_changeable`
    - unknown id → `404`; doctor actor → `403`; missing `date`/`start` → `422`

- [X] T034 [P] [US3] Write `tests/Feature/Appointments/CancelAppointmentTest.php` (US3-4…7, FR-012, FR-014)
  - Scope: cancel with `{"reason": "Synthetic reason"}` → `200`, `status: cancelled`,
    `status_changed_by`, `status_changed_at`, `cancellation_reason`; `/slots` offers the slot
    again; booking the slot for another patient → `201` and the cancelled row unchanged; cancel
    without a body → `200`, reason null; reason of 256 characters → `422`; cancel again →
    `422 not_changeable`; started appointment → `422 not_changeable`; unknown id → `404`; doctor
    actor → `403`.

### Implementation for User Story 3

- [X] T035 [US3] Add `reschedule()` to `app/Modules/Appointments/Services/BookingService.php` (R3, R4)
  - Scope: `reschedule(User $actor, int $appointmentId, string $date, string $start): Appointment`.
    One `DB::transaction(..., attempts: 3)`, in this order: `lockById($appointmentId)` (null →
    `AppointmentNotFoundException`); status must be `scheduled` and not started (else
    `ValidationException(['appointment' => not_changeable])`); `lockForDoctor($appointment->doctor_id)`;
    `patients->lockById($appointment->patient_id)`; then steps 4–5 of `book()` with
    `$exceptId = $appointment->id`; `update(['date', 'start_minute', 'end_minute',
    'rescheduled_by' => $actor->id, 'rescheduled_at' => now()])`. Same unique-violation mapping.
    Extract the shared slot/overlap check into one private method used by both `book()` and
    `reschedule()`. The horizon check (past or beyond 90 days) is in the request (T036).

- [X] T036 [US3] Create `RescheduleAppointmentRequest.php` and `CancelAppointmentRequest.php` in `app/Modules/Appointments/Http/Requests/`
  - Scope: both `authorize()` → `can('update', Appointment::class)`. Reschedule: `date`, `start`
    as in `BookAppointmentRequest` with the same horizon `after()` (share via a small private
    trait or copy — prefer one trait `ValidatesBookableDate` in `Http/Requests/Concerns/` if both
    requests need it). Cancel: `reason` nullable string max 255, trimmed, empty → null.

- [X] T037 [US3] Add `cancel()` to `app/Modules/Appointments/Services/AppointmentService.php`
  - Scope: `cancel(User $actor, int $id, ?string $reason): Appointment` — transaction:
    `lockById` (null → 404); `scheduled` and not started, else `not_changeable`; update `status`
    `cancelled`, `status_changed_by`, `status_changed_at` `now()`, `cancellation_reason`. Return
    `find($id)`.

- [X] T038 [US3] Create `app/Modules/Appointments/Http/Controllers/AppointmentActionController.php` with `reschedule()` and `cancel()`, and the routes
  - Scope: both return `AppointmentResource` (200). Routes in the `whereNumber('appointment')`
    group: `POST /appointments/{appointment}/reschedule` `appointments.reschedule`;
    `POST /appointments/{appointment}/cancel` `appointments.cancel`.
  - Accept: T033 and T034 pass.
  - Depends: T035, T036, T037

**Checkpoint**: US1–US3 — the full booking lifecycle for reception.

---

## Phase 6: User Story 5 — A doctor sees their own appointments (Priority: P2)

**Goal**: `GET /doctors/me/appointments` and `/doctors/me/appointments/{id}` for doctors only.

**Independent Test**: A doctor with two appointments and another doctor with one; the doctor's own
list returns exactly their two with the short patient summary.

### Tests for User Story 5 (write first, must fail)

- [ ] T039 [P] [US5] Write `tests/Feature/Appointments/OwnAppointmentsTest.php` (US5-1…5, FR-015–FR-017)
  - Scope: clock fixed. Own list for a range → only own appointments, ordered, each with exactly
    `OwnAppointmentSummary` keys: `patient {id, full_name, sex, date_of_birth}` (no `phone`, no
    sensitive fields, no `doctor`, no `note`); `status` filter; range rules as T028. Own show →
    `OwnAppointment` (adds `note`, `booked_at`); another doctor's id → `404`; unknown id → `404`.
    Doctor without a profile → `404` on both. Administrator and receptionist → `403` on both.
    Doctor on `GET /appointments` and `/appointments/{id}` → `403`; doctor on
    `GET /patients/{patient_id}` of a booked patient → `403` (Q1, `PatientPolicy` unchanged).

### Implementation for User Story 5

- [ ] T040 [US5] Add own-doctor queries to `AppointmentRepository` / `EloquentAppointmentRepository`
  - Scope: `paginateForDoctor(int $doctorId, array $filters, CarbonImmutable $now, int $perPage)`
    (`from`, `to`, optional `status`; relations `patient` only) and
    `findForDoctor(int $doctorId, int $id, CarbonImmutable $now): ?Appointment` (null when the
    appointment belongs to another doctor, R8); both select `outside_availability` with the T030
    expression.

- [ ] T041 [US5] Create `ListOwnAppointmentsRequest.php`, `OwnAppointmentSummaryResource.php`, `OwnAppointmentResource.php`, and add `listOwn()` / `findOwn()` to `AppointmentService`
  - Scope: request: `authorize()` → `can('viewOwn', Appointment::class)`; rules `from`, `to`,
    `status`, `page`, `per_page` and the 92-day `after()` as `ListAppointmentsRequest` (share the
    rule via the request constant). Resources per the contract (`OwnAppointmentSummary`,
    `OwnAppointment`). Service: inject `DoctorService`; `listOwn(User $user, array $filters, int
    $perPage)` and `findOwn(User $user, int $id)` resolve `DoctorService::findOwn($user)` (404 when
    no profile) and call the T040 methods; `findOwn` → `AppointmentNotFoundException` on null.

- [ ] T042 [US5] Create `app/Modules/Appointments/Http/Controllers/OwnAppointmentController.php` and the routes
  - Scope: `index(ListOwnAppointmentsRequest)`; `show(int $appointment)` with
    `Gate::authorize('viewOwn', Appointment::class)` first. Routes: `GET /doctors/me/appointments`
    `appointments.me.index`; `GET /doctors/me/appointments/{appointment}` `appointments.me.show`
    with `whereNumber('appointment')`.
  - Accept: T039 passes; `route:list --path=doctors/me` shows both next to the Scheduling routes.
  - Depends: T040, T041

**Checkpoint**: Doctors see their own day.

---

## Phase 7: User Story 4 — Record a no-show (Priority: P3)

**Goal**: `POST /appointments/{id}/no-show` for started, scheduled appointments.

**Independent Test**: Book, move the clock past the start, mark no-show → status `no_show`; marking
a future appointment → `422 not_started`.

- [ ] T043 [P] [US4] Write `tests/Feature/Appointments/NoShowTest.php` (US4-1…3, FR-013, FR-014)
  - Scope: book Sunday 09:20 at 08:00; `travelTo` 09:20 (exactly the start: started) → no-show
    `200`, `status: no_show`, `status_changed_by/at`; at 09:19 → `422 not_started`; cancelled or
    already no-show → `422 not_changeable`; a no-show appointment cannot be rescheduled or
    cancelled (`422 not_changeable`) and does not hide its slot; unknown id → `404`; doctor actor
    → `403`.

- [ ] T044 [US4] Add `markNoShow()` to `AppointmentService` and `noShow()` to `AppointmentActionController`, with the route
  - Scope: service: transaction, `lockById` (null → 404); not `scheduled` → `not_changeable`;
    not started → `ValidationException(['appointment' => not_started])`; update `status`
    `no_show`, `status_changed_by`, `status_changed_at`. Controller: `noShow(int $appointment)`
    with `Gate::authorize('update', Appointment::class)` first. Route:
    `POST /appointments/{appointment}/no-show` `appointments.no-show`.
  - Accept: T043 passes.
  - Depends: T043

**Checkpoint**: All five stories done.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T045 [P] Write `tests/Feature/Appointments/AppointmentsAuthorizationTest.php` (SC-006)
  - Scope: data providers over all eight endpoints with valid input (clock fixed): doctor × the
    six `/appointments…` endpoints → `403` (`auth.forbidden`) and no row changed; administrator and
    receptionist × the six → success code; administrator and receptionist × the two `/me`
    endpoints → `403`; no token × every endpoint → `401`; a receptionist with a real token then
    deactivated → `401` (`InteractsWithStaffTokens::usingToken()`); doctor with an invalid booking
    body → `403`, not `422`.

- [ ] T046 [P] Write `tests/Feature/Appointments/AppointmentsMessagesTest.php` (FR-021, SC-007)
  - Scope: for `en` and `ar`: `slot_unavailable`, `slot_booked`, `patient_busy`, `not_changeable`,
    `not_started`, `not_found` (404), `patient_not_found`, `range_too_long`, a translated attribute
    name in a `required` message, `403` → `auth.forbidden`; JSON keys identical across locales;
    `status` values and conflict `reason` identical across locales.

- [ ] T047 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: roadmap: 006 `Done` with the spec link, 007 `Next`. README: a short "Appointments"
    section after "Scheduling": reception books at `POST /api/v1/appointments` into a slot from
    `/doctors/{doctor}/slots`; reschedule, cancel, and no-show under `/appointments/{id}/…`;
    `409` with `reason` for taken times; `outside_availability` after schedule changes; doctors use
    `/api/v1/doctors/me/appointments`; link the contract. Note the `concurrency` test group.

- [ ] T048 Run Pint and the full suite
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
  - Depends: T045, T046, T047

- [ ] T049 Run quickstart Q1–Q9 from `specs/006-appointments/quickstart.md` on the development stack
  - Scope: synthetic data only; Q8 against `clinic_testing` only (adapt the factory call to the
    implemented states; record what was run).
  - Accept: every Q-step's expected outcome is observed, including Q8 under 2 s.
  - Depends: T048

- [ ] T050 Record the results in `specs/006-appointments/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; failures reported as
    failures (Constitution VIII). Postman collection update is a separate follow-up (as for 005).
  - Depends: T049

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 → T004 → T005/T012/T013;
  T008 → T010 (after T009) → T011; T014 (provider) needs T012, T013; T017 needs T014.
- **US1 (Phase 3)**: after Foundational. T027 (concurrency) needs T025 and T026.
- **US2 (Phase 4)**: after US1 (booking creates its test data through the API in T029).
- **US3 (Phase 5)**: after US1 (reuses `BookingService`); independent of US2 except `find()` (T031).
- **US5 (Phase 6)**: after US2 (reuses the outside-availability expression, T030).
- **US4 (Phase 7)**: after US3 (`AppointmentActionController`).
- **Polish (Phase 8)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- Foundational: T003, T006, T007, T008 together; T005, T013, T015, T016 together after T004.
- US1: T020 and T021 together; T024 alongside T022–T023.
- US2: T028 and T029 together.
- US3: T033 and T034 together.
- Polish: T045, T046, T047 together.

---

## Parallel Example: Foundational

```text
Task: "AppointmentStatus enum"                  (T003)
Task: "DoctorScheduleRepository::lockForDoctor" (T006)
Task: "PatientRepository::lockById"             (T007)
Task: "BookedTimeRepository contract"           (T008)
```

## Parallel Example: User Story 1

```text
Task: "BookAppointmentTest"            (T020)
Task: "SlotsTest booked-slot cases"    (T021)
```

## Parallel Example: Polish

```text
Task: "AppointmentsAuthorizationTest"  (T045)
Task: "AppointmentsMessagesTest"       (T046)
Task: "Roadmap and README updates"     (T047)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 US1 → **stop and validate**: bookings are saved, slots disappear, and the concurrency
   group passes three runs in a row.

### Incremental Delivery

1. US1 → booking (MVP).
2. US2 → listing, details, outside availability.
3. US3 → reschedule and cancel.
4. US5 → doctors' own view.
5. US4 → no-show.
6. Polish → authorization matrix, messages, docs, quickstart, verification.
