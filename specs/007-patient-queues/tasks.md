---
description: "Task list for feature 007: Patient Queues"
---

# Tasks: Patient Queues

**Input**: Design documents from `specs/007-patient-queues/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/queues.openapi.yaml](contracts/queues.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and XI, and the plan's Testing Strategy). Each story's tests
are written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.
Phase order follows priority: US1 (P1), US2 (P1), US3 (P2), US4 (P2), US5 (P3).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Queues/`. Mirror `app/Modules/Appointments/` for structure, docblock
  style, and naming; read the matching file there before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`; a doctor with a
  profile signs in as `$doctor->user`.
- **Fixed clock in tests**: every Feature test calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`
  (2026-11-01 is a Sunday). Today's appointments come from
  `Appointment::factory()->at('2026-11-01', '09:20')->create([...])` (status `scheduled`).
  **Exception**: the concurrency tests (T022, T032) run child processes that do not see `travelTo`;
  they use the real clinic date (feature 006 R9).
- Queue entries for "today" use `ClinicClock::today()`; times of day use `ClockTime` and minutes
  since midnight, as in features 005–006.
- **Factory caveat**: `QueueEntryFactory` writes entries without touching `queue_days`. Never mix
  factory-made entries and API check-ins/walk-ins for the same doctor in one test, or queue numbers
  can collide.
- **Branch per phase**: create a new git branch for each phase from the previous one (for example
  `007-phase-2-foundational`, `007-phase-3-us1-check-in`).

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/
`tinker`/`test`, `composer test`, Pint) are listed. If a command does not behave as written,
**stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [ ] T001 Confirm the baseline on branch `007-patient-queues` (no file changes)
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the twelve existing migrations `Ran`
      (the last is `create_appointments_table`)
    - `docker compose exec app composer test` → 879 passed (count recorded in
      `specs/006-appointments/verification.md`)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Appointment slot holding (D1), the queue schema and model (D2), the module skeleton,
translations, exceptions, and the policy.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T002 Extend `app/Modules/Appointments/Enums/AppointmentStatus.php` (research R2, approved D1)
  - Scope: add `CheckedIn = 'checked_in'` and `Completed = 'completed'`; add
    `static holdingSlot(): list<string>` returning the values of `Scheduled`, `CheckedIn`,
    `Completed`. Update the docblock transitions: scheduled → checked_in (check-in, feature 007);
    checked_in → scheduled (undo); checked_in → completed (finish); cancelled, no_show, and
    completed are final.

- [ ] T003 Write `tests/Feature/Queues/AppointmentQueueStatesTest.php` — failing first (FR-005, FR-012, R2)
  - Scope: `RefreshDatabase`; clock fixed; doctor with `DoctorSchedule::factory()->withPeriod(
    'sunday', '09:00', '10:00')`; receptionist actor. For each of `checked_in` and `completed` (data
    provider), a factory appointment at `2026-11-01 09:20` updated to that status:
    - `GET /doctors/{id}/slots?from=2026-11-01&to=2026-11-01` → `09:00`, `09:40` only (slot held)
    - booking `09:20` for another patient → `409 slot_booked`; booking the same patient with a
      second doctor at `09:20` → `409 patient_busy`
    - `POST /appointments/{id}/reschedule`, `/cancel`, `/no-show` (clock moved to 09:30 for
      no-show) → `422` `appointments::messages.not_changeable` on `appointment`
    - a second `scheduled` row at the same doctor, date, start via the factory →
      `UniqueConstraintViolationException`
    - `GET /appointments?from=2026-11-01&to=2026-11-01&status=<status>` → `200` with that
      appointment
  - Verify: `docker compose exec app php artisan test --filter=AppointmentQueueStatesTest` → fails

- [ ] T004 Create the migration `database/migrations/<timestamp>_extend_appointment_slot_holding.php` (R2)
  - Scope: `docker compose exec app php artisan make:migration extend_appointment_slot_holding`;
    one-line comment pointing to `specs/007-patient-queues/research.md` R2. `up()`:
    `DB::statement("ALTER TABLE appointments MODIFY scheduled_flag TINYINT UNSIGNED NULL AS
    (IF(status IN ('scheduled', 'checked_in', 'completed'), 1, NULL)) STORED")`. `down()`: the same
    with `IF(status = 'scheduled', 1, NULL)`. Nothing else changes; the unique index
    `appointments_doctor_slot_unique` stays.
  - Verify: `docker compose exec app php artisan migrate` → `DONE`; `migrate:status` shows it `Ran`.

- [ ] T005 Use `AppointmentStatus::holdingSlot()` in `app/Modules/Appointments/Repositories/EloquentBookedTimeRepository.php` and in `scheduledOverlapping()` of `app/Modules/Appointments/Repositories/EloquentAppointmentRepository.php`
  - Scope: replace `->where('status', AppointmentStatus::Scheduled->value)` with
    `->whereIn('status', AppointmentStatus::holdingSlot())` in exactly those two places; rename the
    private method to `holdingOverlapping()` and update both callers and docblocks ("scheduled,
    checked in, or completed"). The `outside_availability` expression stays `scheduled`-only.
  - Accept: T003 passes; `docker compose exec app php artisan test tests/Feature/Appointments
    tests/Feature/Scheduling tests/Unit/Scheduling` → all pass unchanged.
  - Depends: T002, T003, T004

- [ ] T006 Create the migrations `database/migrations/<timestamp>_create_queue_days_table.php` and `<timestamp>_create_queue_entries_table.php` (data-model.md, approved D2)
  - Scope: two `make:migration` calls, in this order; each with a one-line comment pointing to
    `specs/007-patient-queues/data-model.md`. `queue_days`, exactly:
    - `id()`; `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`; `date('date')`;
      `unsignedSmallInteger('last_number')->default(0)`; `timestamps()`
    - `unique(['doctor_id', 'date'], 'queue_days_doctor_date_unique')`
    `queue_entries`, exactly:
    - `id()`; `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`;
      `foreignId('patient_id')->constrained('patients')->restrictOnDelete()`;
      `foreignId('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete()`
    - `date('date')`; `unsignedSmallInteger('queue_number')`; `unsignedSmallInteger('order_minute')`
    - `string('status', 20)` — "`waiting` | `with_doctor` | `seen` | `left`"
    - `unsignedTinyInteger('with_doctor_flag')->nullable()->storedAs("IF(status = 'with_doctor', 1, NULL)")`
    - `timestamp('arrived_at')`; `foreignId('checked_in_by')->constrained('users')->restrictOnDelete()`
    - `timestamp('called_at')->nullable()`; `timestamp('finished_at')->nullable()`;
      `timestamp('left_at')->nullable()`; `foreignId('left_by')->nullable()->constrained('users')->restrictOnDelete()`
    - `timestamps()`
    - `unique(['doctor_id', 'date', 'queue_number'], 'queue_entries_doctor_number_unique')`
    - `unique(['appointment_id'], 'queue_entries_appointment_unique')`
    - `unique(['doctor_id', 'date', 'with_doctor_flag'], 'queue_entries_doctor_with_doctor_unique')`
    - `unique(['patient_id', 'date', 'with_doctor_flag'], 'queue_entries_patient_with_doctor_unique')`
    - `index(['doctor_id', 'date', 'order_minute', 'queue_number'], 'queue_entries_doctor_order_index')`
    - `index(['date', 'doctor_id'], 'queue_entries_date_index')`
  - Verify: `docker compose exec app php artisan migrate` → both `DONE`.

- [ ] T007 [P] Create `app/Modules/Queues/Enums/QueueStatus.php`
  - Scope: `enum QueueStatus: string` with `Waiting = 'waiting'`, `WithDoctor = 'with_doctor'`,
    `Seen = 'seen'`, `Left = 'left'`; `static values(): list<string>`. Docblock: transitions per
    data-model.md (waiting → with_doctor → seen; waiting → left; seen and left final).

- [ ] T008 Create `app/Modules/Queues/Models/QueueDay.php` and `app/Modules/Queues/Models/QueueEntry.php`
  - Scope (persistence mapping only, as `Appointments\Models\Appointment`): `QueueDay` fillable
    `doctor_id`, `date`, `last_number`; casts `date` → `date`, `last_number` → `integer`.
    `QueueEntry` fillable `doctor_id`, `patient_id`, `appointment_id`, `date`, `queue_number`,
    `order_minute`, `status`, `arrived_at`, `checked_in_by`, `called_at`, `finished_at`,
    `left_at`, `left_by` (never `with_doctor_flag`); casts `date` → `date`, numbers → `integer`,
    `status` → `QueueStatus`, the four `*_at` → `datetime`. Relations: `doctor()` →
    `Doctors\Models\Doctor`; `patient()` → `Patients\Models\Patient`; `appointment()` →
    `Appointments\Models\Appointment`; `checkedInBy()`, `leftBy()` → `Staff\Models\User`
    (foreign keys `checked_in_by`, `left_by`). `QueueEntry` uses `HasFactory` with
    `QueueEntryFactory`.
  - Depends: T006, T007

- [ ] T009 [P] Create `database/factories/QueueEntryFactory.php` (synthetic only)
  - Scope: `doctor_id` → `Doctor::factory()`; `patient_id` → `Patient::factory()`;
    `appointment_id` null; `date` → `ClinicClock::today()`; `queue_number` 1; `order_minute` 540;
    `status` `waiting`; `arrived_at` now; `checked_in_by` → `User::factory()->receptionist()`.
    States: `forAppointment(Appointment $a)` (doctor, patient, date, `order_minute` =
    `$a->start_minute`, `appointment_id`); `number(int $n)`; `withDoctor()` (status, `called_at`);
    `seen()` (status, `called_at`, `finished_at`); `left()` (status, `left_at`, `left_by` a
    receptionist). Docblock: the factory caveat from this file's header.
  - Depends: T008

- [ ] T010 Write `tests/Feature/Queues/QueuePersistenceTest.php` (R3)
  - Scope: `RefreshDatabase`; with the factory: two entries with the same doctor, date, number →
    `UniqueConstraintViolationException`; two entries for one appointment → violation; two
    walk-ins (null appointment) → allowed; two `with_doctor` entries of one doctor on one date →
    violation, on different dates → allowed; one patient `with_doctor` with two doctors on one date
    → violation; `with_doctor_flag` is `1` for `with_doctor` and `null` after updating to `seen`;
    deleting a doctor, patient, appointment, or the checking-in user referenced by an entry →
    `QueryException`; two `queue_days` rows for one doctor and date → violation.
  - Verify: `docker compose exec app php artisan test --filter=QueuePersistenceTest` → pass
  - Depends: T009

- [ ] T011 Create `app/Modules/Queues/Repositories/Contracts/QueueRepository.php` and `app/Modules/Queues/Repositories/EloquentQueueRepository.php` with the methods check-in needs
  - Scope (more methods are added in later stories), each with a docblock:
    - `lockDay(int $doctorId, string $date): QueueDay` — `insertOrIgnore` the row (`last_number`
      0, timestamps), then `where(...)->lockForUpdate()->firstOrFail()`, as
      `EloquentDoctorScheduleRepository::lockOrCreateForDoctor` (R3)
    - `takeNumber(QueueDay $day): int` — increments `last_number`, saves, returns the new number
    - `create(array $attributes): QueueEntry`
    - `update(QueueEntry $entry, array $attributes): QueueEntry`
    - `find(int $id): ?QueueEntry` — with `patient`, `doctor.user`, `appointment`
  - Depends: T008

- [ ] T012 Create the module skeleton: `app/Modules/Queues/Providers/QueuesServiceProvider.php`, `app/Modules/Queues/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: provider mirrors `AppointmentsServiceProvider`: `register()` binds `QueueRepository` →
    `EloquentQueueRepository`; `boot()` loads `routes/api.php` with `api` middleware and `api/v1`
    prefix and translations namespace `queues` (the policy line is added in T015). Routes file:
    header comment only plus an empty `Route::middleware('auth:sanctum')->group(...)`. Add
    `QueuesServiceProvider::class` after `AppointmentsServiceProvider::class`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.
  - Depends: T011

- [ ] T013 [P] Create the translations `app/Modules/Queues/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('queues::messages.<key>')`), en / ar:
    - `not_checkable`: "Only a scheduled appointment for today can be checked in." / "يمكن تسجيل الوصول لموعد مجدول اليوم فقط."
    - `already_checked_in`: "This appointment is already checked in." / "تم تسجيل الوصول لهذا الموعد بالفعل."
    - `already_in_queue`: "This patient is already in this doctor's queue today." / "هذا المريض موجود بالفعل في قائمة انتظار هذا الطبيب اليوم."
    - `patient_not_found`: "No patient was found with this patient number." / "لا يوجد مريض بهذا الرقم."
    - `doctor_not_found`: "No doctor was found." / "لم يتم العثور على الطبيب."
    - `doctor_inactive`: "This doctor is not active." / "هذا الطبيب غير نشط."
    - `entry_not_found`: "No queue entry was found." / "لم يتم العثور على الدور في قائمة الانتظار."
    - `not_changeable`: "This queue entry can no longer be changed." / "لم يعد من الممكن تعديل هذا الدور."
    - `visit_in_progress`: "Finish the current visit before calling the next patient." / "أنهِ الكشف الحالي قبل استدعاء المريض التالي."
    - `nobody_waiting`: "Nobody is waiting." / "لا يوجد أحد في الانتظار."
    - `no_current_visit`: "No patient is with the doctor." / "لا يوجد مريض مع الطبيب."
    - `patient_with_another_doctor`: "This patient is with another doctor." / "هذا المريض مع طبيب آخر."
    - attributes en / ar: `patient_id` "patient number" / "رقم المريض"; `doctor_id` "doctor" /
      "الطبيب"; `status` "status" / "الحالة"; `appointment` "appointment" / "الموعد"; `entry`
      "queue entry" / "الدور"; `queue` "queue" / "قائمة الانتظار".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [ ] T014 [P] Create `app/Modules/Queues/Exceptions/QueueEntryNotFoundException.php` and `QueueConflictException.php` (R3)
  - Scope: exactly like `AppointmentNotFoundException` (404, `queues::messages.entry_not_found`)
    and `AppointmentConflictException` (constructor `public readonly string $reason`, `$reason` is
    `'already_checked_in'` or `'patient_with_another_doctor'`; 409 `{message, reason}` with
    `queues::messages.{reason}`); both with an empty `report()`.

- [ ] T015 Write `tests/Feature/Queues/QueuesPolicyTest.php`, then create `app/Modules/Queues/Policies/QueueEntryPolicy.php` (R7)
  - Scope: class-level abilities on `QueueEntry::class`: `viewAny`, `create`, `update` → active
    administrator or receptionist; `viewOwn`, `updateOwn` → active doctor. Test: every role (active
    and inactive) × every ability, as `tests/Feature/Appointments/AppointmentPolicyTest.php`. Then
    add `Gate::policy(QueueEntry::class, QueueEntryPolicy::class)` to the provider's `boot()`.
  - Verify: `--filter=QueuesPolicyTest` → fails before the policy exists, passes after.
  - Depends: T012

- [ ] T016 Run the Appointments, Scheduling, and Patients suites
  - Verify: `docker compose exec app php artisan test tests/Feature/Appointments tests/Feature/Scheduling tests/Unit/Scheduling tests/Feature/Patients` → all pass.
  - Depends: T005, T012

**Checkpoint**: Appointments hold their slot when checked in or completed; queue schema, model, and
module skeleton exist; all earlier tests pass.

---

## Phase 3: User Story 1 — Receptionist checks in an arriving patient (Priority: P1) 🎯 MVP

**Goal**: `POST /appointments/{appointment}/check-in` adds the patient to the doctor's list for
today with the next queue number; the appointment becomes `checked_in`.

**Independent Test**: Book a patient for today, check them in, and confirm the appointment shows
`checked_in` and the response is a `waiting` entry with queue number 1.

### Tests for User Story 1 (write first, must fail)

- [ ] T017 [US1] Write `tests/Feature/Queues/CheckInTest.php` (US1-1…7, FR-001–FR-005)
  - Scope: clock fixed (Sunday 08:00); receptionist actor unless stated; factory appointments
    dated `2026-11-01`.
    - check in a 09:20 appointment → `201`; `data` matches the contract's `QueueEntry`:
      `queue_number: 1`, `status: waiting`, `walk_in: false`, `arrived_at` now,
      `called_at`/`finished_at`/`left_at` null, `appointment {id, start: 09:20, end: 09:40}`,
      `doctor {id, name, title}`, `patient {id, full_name, phone}` and no other patient keys; the
      appointment's status is `checked_in`; one row in `queue_entries`
    - administrator can check in → `201`
    - two more appointments of the same doctor checked in → numbers `2`, `3`; an appointment of
      another doctor → number `1`
    - early (clock 08:00 for 09:20) and late (clock 11:00 for 09:20) → `201`
    - `422` `not_checkable` on `appointment`, nothing saved, for: an appointment dated
      `2026-11-02`; one dated `2026-10-31`; `cancelled()`; `noShow()`; already checked in (second
      call)
    - an appointment of an inactive doctor (`user->update(['is_active' => false])`) → `201`
    - unknown appointment → `404` with `appointments::messages.not_found`
    - doctor actor → `403`, nothing saved
  - Verify: `docker compose exec app php artisan test --filter=CheckInTest` → fails (route missing)

### Implementation for User Story 1

- [ ] T018 [US1] Create `app/Modules/Queues/Services/QueueService.php` with `checkIn()` (R3, R6)
  - Scope: inject `QueueRepository`, `AppointmentRepository`.
    `public function checkIn(User $actor, int $appointmentId): QueueEntry`:
    1. `$appointment = $this->appointments->find($appointmentId, ClinicClock::now()) ?? throw new
       AppointmentNotFoundException` (plain read; the doctor never changes — R3)
    2. `DB::transaction(function () {...}, attempts: 3)`, **in this order** (docblock: "lock order
       queue day → appointment → patient, research R3"): `$day = lockDay($doctorId,
       ClinicClock::today())`; `$locked = appointments->lockById($appointmentId)`; refuse with
       `ValidationException(['appointment' => queues::messages.not_checkable])` unless
       `status === Scheduled` and `date->toDateString() === ClinicClock::today()`; `update` the
       appointment to `CheckedIn`; `create` the entry: doctor, patient, appointment, date today,
       `queue_number = takeNumber($day)`, `order_minute = $locked->start_minute`, `status
       waiting`, `arrived_at now()`, `checked_in_by $actor->id`
    Wrap the transaction: `UniqueConstraintViolationException` →
    `QueueConflictException('already_checked_in')`. Return `$this->queue->find($entry->id)`.
  - Depends: T011, T014

- [ ] T019 [P] [US1] Create `app/Modules/Queues/Http/Resources/QueueEntryResource.php` (R8)
  - Scope: `id`, `queue_number`, `status` (`->value`), `walk_in` (`appointment_id === null`),
    `arrived_at`, `called_at`, `finished_at`, `left_at` (ISO 8601 or null), `appointment` `{id,
    start, end}` via `ClockTime::fromMinutes` or null, `doctor` `{id, name: doctor->user->name,
    title}`, `patient` `{id, full_name, phone}` — never the note, national ID, address, emergency
    contact (FR-009).

- [ ] T020 [US1] Create `app/Modules/Queues/Http/Controllers/CheckInController.php` and the route
  - Scope: `__invoke(Request $request, int $appointment)` → `Gate::authorize('create',
    QueueEntry::class)` first, then `QueueEntryResource::make($this->queue->checkIn(...))
    ->response()->setStatusCode(201)`. Route in `routes/api.php` inside
    `Route::whereNumber('appointment')`: `Route::post('/appointments/{appointment}/check-in',
    CheckInController::class)->name('queues.check-in');`
  - Accept: T017 passes; `route:list --path=check-in` shows the route.
  - Depends: T018, T019

- [ ] T021 [US1] Create `tests/Support/queue-action.php` (child process for the concurrency tests, R9)
  - Scope: plain PHP script like `tests/Support/book-appointment.php` (same bootstrap and **exit 2
    unless** `DB::connection()->getDatabaseName() === 'clinic_testing'`). Arguments: `action
    actorId targetId startAtMicrotime`, where `action` is `check-in` (target = appointment id,
    calls `QueueService::checkIn`) or `call-next` (actor = the doctor's user id, target unused;
    calls `DoctorQueueService::callNext`, added in T030 — until then the action prints an error).
    Busy-wait until `startAt`, then run once. Print one JSON line: `{"result":"ok","id":N,
    "queue_number":N}`, `{"result":"refused","errors":{...}}` (a `ValidationException`),
    `{"result":"conflict","reason":"..."}`, or `{"result":"error","class":"...","message":"..."}`;
    exit 0.

- [ ] T022 [US1] Write `tests/Feature/Queues/ConcurrentQueueTest.php` `#[Group('concurrency')]` — check-in case (SC-002, R9)
  - Scope: `use DatabaseTruncation`, truncate again in `tearDown()` (as
    `ConcurrentBookingTest`). No `travelTo`. One doctor, 8 patients, 8 factory appointments on
    `ClinicClock::today()` at `09:00`, `09:20`, … (any times), a receptionist. `Process::pool`
    with 8 `php tests/Support/queue-action.php check-in …` processes, one per appointment, same
    `startAt` (`microtime(true) + 1.5`), `->env(['APP_ENV' => 'testing', 'DB_DATABASE' =>
    'clinic_testing'])`, `->path(base_path())`. Assert: 8 `ok`; queue numbers exactly `1…8`;
    `queue_days.last_number` is 8; every appointment `checked_in`. Second case: 8 processes check
    in **the same** appointment → exactly one `ok`, seven `refused` with `appointment`; one entry.
    On any `error` line, fail with its message.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T020, T021

**Checkpoint**: Check-in works and is safe under concurrency (MVP).

---

## Phase 4: User Story 2 — Reception sees the waiting lists for today (Priority: P1)

**Goal**: `GET /doctors/{doctor}/queue` and `GET /queue` in queue order.

**Independent Test**: Check in three patients for two doctors; one doctor's list and the clinic's
list contain exactly the expected entries in queue order.

### Tests for User Story 2 (write first, must fail)

- [ ] T023 [US2] Write `tests/Feature/Queues/QueueListTest.php` (US2-1…5, FR-007–FR-009, R4)
  - Scope: clock fixed; receptionist actor. Doctor A: appointments at 09:40, 09:00, 09:20 checked
    in through the API **in that order** (numbers 1, 2, 3). Doctor B: one appointment checked in.
    - `GET /doctors/{A}/queue` → `200`, three entries ordered 09:00, 09:20, 09:40 (numbers 2, 3,
      1); each exactly the `QueueEntry` keys; no `note`, `national_id`, `address`,
      `emergency_contact` anywhere in the JSON (patient made with `withNationalId()` and an
      address, appointment with a note)
    - (ties on equal times are covered in T033: a walk-in arriving at an appointment's minute)
    - `GET /queue` → A's three then B's one (doctor id order), `meta.total` 4; `per_page=2` → two
      items; `status=waiting` → all four; `status=seen` → none; `status=done` → `422`;
      `per_page=101` → `422`
    - a doctor with no entries → `200` `{"data": []}`; unknown doctor → `404`
    - entries of yesterday (factory, `date` `2026-10-31`, doctor C only) never appear
    - doctor actor on both → `403`
  - Verify: `--filter=QueueListTest` → fails

### Implementation for User Story 2

- [ ] T024 [US2] Add `forDoctorDay` and `paginateDay` to `QueueRepository` / `EloquentQueueRepository` (R4, R8)
  - Scope: `forDoctorDay(int $doctorId, string $date): Collection<int, QueueEntry>` — ordered
    `order_minute`, `queue_number`; relations `patient`, `doctor.user`, `appointment`.
    `paginateDay(string $date, ?string $status, int $perPage): LengthAwarePaginator` — ordered
    `doctor_id`, `order_minute`, `queue_number`; same relations; `withQueryString()`.

- [ ] T025 [US2] Create `app/Modules/Queues/Http/Requests/ListQueueRequest.php` and add `list()` and `listForDoctor()` to `QueueService`
  - Scope: request: `authorize()` → `can('viewAny', QueueEntry::class)`; rules `status` nullable
    `in:` `QueueStatus::values()`; `page` nullable integer min 1; `per_page` nullable integer 1–100;
    attributes from `queues::attributes`; `perPage()` default 15. Service (inject `DoctorService`):
    `list(?string $status, int $perPage)` → `paginateDay(ClinicClock::today(), ...)`;
    `listForDoctor(int $doctorId)` → `DoctorService::find($doctorId)` (404 for unknown) then
    `forDoctorDay($doctorId, ClinicClock::today())`.

- [ ] T026 [US2] Create `app/Modules/Queues/Http/Controllers/QueueController.php` with `index()` and `forDoctor()`, and the routes
  - Scope: `index(ListQueueRequest)` → `QueueEntryResource::collection(...)`; `forDoctor(int
    $doctor)` → `Gate::authorize('viewAny', QueueEntry::class)` then the collection. Routes:
    `GET /queue` `queues.index`; `GET /doctors/{doctor}/queue` `queues.doctor` with
    `whereNumber('doctor')`.
  - Accept: T023 passes.
  - Depends: T024, T025

**Checkpoint**: US1 + US2 — reception can check patients in and see the lists.

---

## Phase 5: User Story 3 — A doctor calls the next patient and finishes the visit (Priority: P2)

**Goal**: `GET /doctors/me/queue`, `POST /doctors/me/queue/call-next`, `POST /doctors/me/queue/finish`.

**Independent Test**: With two waiting patients, call next (the first in order is with the
doctor), finish (seen; appointment completed), call next (the second is with the doctor).

### Tests for User Story 3 (write first, must fail)

- [ ] T027 [US3] Write `tests/Feature/Queues/DoctorQueueTest.php` (US3-1…7, FR-010–FR-013, R5)
  - Scope: clock fixed. Doctor D with appointments at 09:20 (patient P1) and 09:00 (P2), checked in
    through the API by a receptionist in that order; then act as `$doctor->user`.
    - own list → `200`, P2 then P1, exactly the `OwnQueueEntry` keys, patient `{id, full_name, sex,
      date_of_birth}` (no `phone`, no `doctor`)
    - call next → `200`, P2's entry `with_doctor`, `called_at` now; again → `422`
      `visit_in_progress` on `queue`
    - finish → `200`, `seen`, `finished_at` now; P2's appointment `completed`; again → `422`
      `no_current_visit` on `queue`
    - call next → P1; finish; call next → `422` `nobody_waiting` on `queue`
    - skip rule: P3 checked in with doctor D (09:40) and with doctor E (factory appointment, checked
      in through the API); E calls next → P3 with E; D calls next (P1, P2 already seen) → `422`
      `nobody_waiting` (P3 skipped while with E); E finishes; D calls next → P3
    - a doctor without a profile → `404` on all three; receptionist and administrator → `403` on
      all three
    - a doctor never sees another doctor's entries in the own list
  - Verify: `--filter=DoctorQueueTest` → fails

### Implementation for User Story 3

- [ ] T028 [US3] Add `withDoctor`, `waitingInOrder`, and `patientWithDoctor` to `QueueRepository` / `EloquentQueueRepository`
  - Scope (all locking reads, `lockForUpdate()`; docblock: "call only with the queue day locked"):
    `withDoctor(int $doctorId, string $date): ?QueueEntry` — the doctor's `with_doctor` entry;
    `waitingInOrder(int $doctorId, string $date): Collection` — `waiting` entries ordered
    `order_minute`, `queue_number`; `patientWithDoctor(int $patientId, string $date): bool` — any
    `with_doctor` entry of the patient that date (plain `exists()` after the patient row lock).

- [ ] T029 [P] [US3] Create `app/Modules/Queues/Http/Resources/OwnQueueEntryResource.php`
  - Scope: the `QueueEntryResource` fields without `doctor`, with `patient` `{id, full_name, sex,
    date_of_birth}` (contract `OwnQueueEntry`).

- [ ] T030 [US3] Create `app/Modules/Queues/Services/DoctorQueueService.php` (R5)
  - Scope: inject `QueueRepository`, `AppointmentRepository`, `PatientRepository`, `DoctorService`.
    Each method resolves `$doctorId = DoctorService::findOwn($user)->id` (404 without a profile).
    - `listOwn(User $user)` → `forDoctorDay($doctorId, today)`
    - `callNext(User $user): QueueEntry` — transaction, `attempts: 3`: `lockDay`; `withDoctor` →
      `ValidationException(['queue' => visit_in_progress])`; for each of `waitingInOrder`: lock its
      patient (`PatientRepository::lockById`), skip when `patientWithDoctor`; otherwise update to
      `with_doctor`, `called_at now()`, stop; none →
      `ValidationException(['queue' => nobody_waiting])`. Wrap:
      `UniqueConstraintViolationException` → `QueueConflictException('patient_with_another_doctor')`.
      Return `find($id)`.
    - `finish(User $user): QueueEntry` — transaction: `lockDay`; `withDoctor` or
      `ValidationException(['queue' => no_current_visit])`; when it has an appointment:
      `AppointmentRepository::lockById` and update status `Completed`; update the entry to `seen`,
      `finished_at now()`. Return `find($id)`.
  - Depends: T028

- [ ] T031 [US3] Create `app/Modules/Queues/Http/Controllers/OwnQueueController.php` and the routes
  - Scope: `index`, `callNext`, `finish`, each `Gate::authorize('viewOwn'|'updateOwn',
    QueueEntry::class)` first, returning `OwnQueueEntryResource` (collection for `index`). Routes:
    `GET /doctors/me/queue` `queues.me.index`; `POST /doctors/me/queue/call-next`
    `queues.me.call-next`; `POST /doctors/me/queue/finish` `queues.me.finish`.
  - Accept: T027 passes; `route:list --path=doctors/me` shows the three next to the existing
    `/doctors/me…` routes.
  - Depends: T029, T030

- [ ] T032 [US3] Extend `tests/Feature/Queues/ConcurrentQueueTest.php` with the call-next case and enable `call-next` in `tests/Support/queue-action.php`
  - Scope: 4 factory `waiting` entries (numbers 1–4, today, one doctor) and a `QueueDay` row with
    `last_number` 4; 2 processes `call-next` for the doctor's user at the same `startAt` → exactly
    one `ok`, one `refused` with `queue` (`visit_in_progress`); exactly one `with_doctor` entry and
    it is the first in order.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; 3 runs.
  - Depends: T022, T031

**Checkpoint**: Doctors move their own list.

---

## Phase 6: User Story 4 — Reception adds a walk-in patient (Priority: P2)

**Goal**: `POST /queue/walk-ins`.

**Independent Test**: Add a registered patient without an appointment to a doctor's list; they are
waiting with the next number, `walk_in: true`, and the doctor's slots are unchanged.

### Tests for User Story 4 (write first, must fail)

- [ ] T033 [US4] Write `tests/Feature/Queues/WalkInTest.php` (US4-1…3, FR-006, R6)
  - Scope: clock fixed at 09:50; doctor with `withPeriod('sunday', '09:00', '11:00')`;
    appointments at 09:40 and 10:00 checked in through the API (numbers 1, 2).
    - walk-in `{patient_id, doctor_id}` → `201`, number 3, `walk_in: true`, `appointment: null`,
      `waiting`; the doctor's list is 09:40, walk-in (09:50), 10:00
    - `/doctors/{id}/slots` for today returns the same before and after the walk-in
    - a walk-in at 10:00 (clock moved) is listed after the 10:00 appointment (equal time → queue
      number)
    - the same patient again while `waiting` → `422` `already_in_queue` on `patient_id`; after
      their entry is set to `left` (direct model update) → `201` again
    - unknown patient → `422` `patient_not_found` on `patient_id`; unknown doctor → `422`
      `doctor_not_found` on `doctor_id`; inactive doctor → `422` `doctor_inactive`; missing
      fields → `422`
    - doctor actor → `403`
  - Verify: `--filter=WalkInTest` → fails

### Implementation for User Story 4

- [ ] T034 [US4] Add `patientActive` to `QueueRepository` / `EloquentQueueRepository`
  - Scope: `patientActive(int $doctorId, int $patientId, string $date): bool` — a `waiting` or
    `with_doctor` entry of the patient with that doctor that date.

- [ ] T035 [US4] Create `app/Modules/Queues/Http/Requests/AddWalkInRequest.php` and add `addWalkIn()` to `QueueService`
  - Scope: request: `authorize()` → `can('create', QueueEntry::class)`; rules `patient_id`,
    `doctor_id` required integer min 1; attributes from `queues::attributes`. Service (inject
    `PatientRepository`, `DoctorRepository`): transaction, `attempts: 3`: doctor
    `findById` (plain; null → `ValidationException(['doctor_id' => doctor_not_found])`; inactive
    → `doctor_inactive`); `lockDay`; `PatientRepository::lockById` (null → `patient_not_found` on
    `patient_id`); `patientActive` → `already_in_queue`; create the entry with `appointment_id`
    null, `order_minute` = `ClinicClock::now()` hour × 60 + minute, the next number, `waiting`,
    `arrived_at`, `checked_in_by`. Return `find($id)`.
  - Depends: T034

- [ ] T036 [US4] Add `walkIn()` to `QueueController` and the route
  - Scope: `walkIn(AddWalkInRequest)` → `QueueEntryResource` with status `201`. Route:
    `POST /queue/walk-ins` `queues.walk-ins`.
  - Accept: T033 passes.
  - Depends: T035

**Checkpoint**: Walk-ins join the lists.

---

## Phase 7: User Story 5 — Reception corrects the waiting list (Priority: P3)

**Goal**: `POST /queue/{entry}/leave` and `DELETE /queue/{entry}`.

**Independent Test**: Mark one waiting patient as left and undo another's check-in; the first is
skipped by call next, the second's appointment is scheduled again.

### Tests for User Story 5 (write first, must fail)

- [ ] T037 [US5] Write `tests/Feature/Queues/QueueCorrectionsTest.php` (US5-1…4, FR-014, FR-015)
  - Scope: clock fixed; two appointments checked in through the API (numbers 1, 2) and a walk-in
    (3).
    - leave entry 1 → `200`, `left`, `left_at` now, `left_by` the actor
    - undo entry 2 → `204`; the entry is gone; its appointment is `scheduled` again; checking it in
      again → `201` with number 4 (3 is the walk-in; numbers are not reused)
    - undo the walk-in → `422` `not_changeable` on `entry`
    - the doctor calls next twice, finishing in between → entries 4 and 3 in queue order; entry 1
      (left) is never called
    - after the above, entry 3 is `seen` and entry 4 is `seen`; call next is not repeated. Leave and
      undo each of entry 1 (`left`) and entry 4 (`seen`) → `422` `not_changeable`; a new walk-in
      (number 5) called by the doctor (`with_doctor`) → leave and undo → `422` `not_changeable`
    - a further walk-in (number 6) while `waiting` → leave → `200`
    - unknown entry → `404` `entry_not_found` on both
    - doctor actor → `403` on both
  - Verify: `--filter=QueueCorrectionsTest` → fails

### Implementation for User Story 5

- [ ] T038 [US5] Add `lockById` and `delete` to `QueueRepository` / `EloquentQueueRepository`
  - Scope: `lockById(int $id): ?QueueEntry` — `lockForUpdate()->find($id)`, no relations;
    `delete(QueueEntry $entry): void`.

- [ ] T039 [US5] Add `leave()` and `undo()` to `QueueService`
  - Scope: both: plain `find($id)` (null → `QueueEntryNotFoundException`) for doctor and date;
    transaction: `lockDay(doctor, date)`; `lockById` (null → 404); refuse unless `waiting` with
    `ValidationException(['entry' => not_changeable])`. `leave(User $actor, int $id)` → update
    `left`, `left_at now()`, `left_by`; return `find($id)`. `undo(int $id)` → also refuse a walk-in
    (`appointment_id` null); `AppointmentRepository::lockById` and update status `Scheduled`;
    `delete` the entry (the queue day's `last_number` is unchanged, so the number is not reused).
  - Depends: T038

- [ ] T040 [US5] Add `leave()` and `undo()` to `QueueController` and the routes
  - Scope: both `Gate::authorize('update', QueueEntry::class)` first; `leave` → `QueueEntryResource`
    (200); `undo` → `response()->noContent()`. Routes inside `Route::whereNumber('entry')`:
    `POST /queue/{entry}/leave` `queues.leave`; `DELETE /queue/{entry}` `queues.undo`.
  - Accept: T037 passes.
  - Depends: T039

**Checkpoint**: All five stories done.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T041 [P] Write `tests/Feature/Queues/QueuesAuthorizationTest.php` (SC-005)
  - Scope: data providers over all nine endpoints with valid input (clock fixed): doctor × the six
    reception endpoints → `403` (`auth.forbidden`) and no row changed; administrator and
    receptionist × the six → success code; administrator and receptionist × the three `/me`
    endpoints → `403`; no token × every endpoint → `401`; a receptionist with a real token then
    deactivated → `401` (`InteractsWithStaffTokens::usingToken()`); doctor with an invalid walk-in
    body → `403`, not `422`.

- [ ] T042 [P] Write `tests/Feature/Queues/QueuesMessagesTest.php` (FR-019, SC-006)
  - Scope: for `en` and `ar`: `not_checkable`, `already_in_queue`, `patient_not_found`,
    `doctor_not_found`, `doctor_inactive`, `entry_not_found` (404), `not_changeable`,
    `visit_in_progress`, `nobody_waiting`, `no_current_visit`, a translated attribute name in a
    `required` message, `403` → `auth.forbidden`; JSON keys identical across locales; `status`
    values identical across locales.

- [ ] T043 [P] Update `specs/006-appointments/contracts/appointments.openapi.yaml`, `docs/roadmap.md`, and `README.md`
  - Scope: 006 contract: `Status` enum gains `checked_in`, `completed`, with a one-line note
    "added by feature 007; such appointments keep their slot and cannot be rescheduled, cancelled,
    or marked no-show". Roadmap: 007 `Done` with the spec link, 008 `Next`. README: a short "Patient
    queues" section after "Appointments": check in at `POST /api/v1/appointments/{id}/check-in`,
    walk-ins at `/api/v1/queue/walk-ins`, lists at `/api/v1/queue` and
    `/api/v1/doctors/{doctor}/queue`, leave and undo under `/api/v1/queue/{entry}`, doctors use
    `/api/v1/doctors/me/queue`, `/call-next`, `/finish`; order rule; link the contract.
  - Verify: `postman spec lint specs/006-appointments/contracts/appointments.openapi.yaml --issueType=syntax` → no issues.

- [ ] T044 Run Pint and the full suite
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
  - Depends: T041, T042, T043

- [ ] T045 Run quickstart Q1–Q7 from `specs/007-patient-queues/quickstart.md` on the development stack
  - Scope: synthetic data only; the doctor needs hours for the real clinic today (set them in Q2).
    Record what was run.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T044

- [ ] T046 Record the results in `specs/007-patient-queues/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; failures reported as
    failures (Constitution VIII). Postman collection update is a separate follow-up (as for 005,
    006).
  - Depends: T045

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 → T003 → T004 → T005;
  T006 → T008 → T009/T011; T011 → T012 → T015; T016 after T005 and T012.
- **US1 (Phase 3)**: after Foundational. T022 (concurrency) needs T020 and T021.
- **US2 (Phase 4)**: after US1 (its tests check patients in through the API).
- **US3 (Phase 5)**: after US1; uses US2's `forDoctorDay` (T024) for the own list. T032 extends
  T022.
- **US4 (Phase 6)**: after US2 (list order assertions in T033).
- **US5 (Phase 7)**: after US3 (call next and finish in T037) and US4 (walk-in in T037).
- **Polish (Phase 8)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- Foundational: T007 alongside T002–T006; T009, T013, T014 together after T008.
- US1: T019 alongside T018.
- US3: T029 alongside T028–T030.
- Polish: T041, T042, T043 together.

---

## Parallel Example: Foundational

```text
Task: "QueueStatus enum"                 (T007)
Task: "Queues translations"              (T013)
Task: "Queues exceptions"                (T014)
```

## Parallel Example: Polish

```text
Task: "QueuesAuthorizationTest"          (T041)
Task: "QueuesMessagesTest"               (T042)
Task: "006 contract, roadmap, README"    (T043)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (appointments hold their slot when checked in).
2. Phase 3 US1 → **stop and validate**: check-in works, numbers are 1…N under concurrency three
   runs in a row.

### Incremental Delivery

1. US1 → check-in (MVP).
2. US2 → waiting lists.
3. US3 → doctors call and finish.
4. US4 → walk-ins.
5. US5 → corrections.
6. Polish → authorization matrix, messages, docs, quickstart, verification.
