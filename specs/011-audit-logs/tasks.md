---
description: "Task list for feature 011: Audit Logs"
---

# Tasks: Audit Logs

**Input**: Design documents from `specs/011-audit-logs/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/audit.openapi.yaml](contracts/audit.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are written first and
must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own. Phase order follows
priority: US1 (P1), US2 (P1), US3 (P2), US4 (P2).

**Decisions**: These tasks follow decisions D1–D7 in the plan, approved by the user on 2026-11-05.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US4)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Audit/`. Mirror `app/Modules/Payments/` for structure, docblock style, and naming; read the
  matching file there before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`.
- **Records in tests**: `Patient`, `Doctor`, `Specialty`, `DoctorSchedule`, `DoctorTimeOff`, `DoctorFee`, `Appointment`, `QueueEntry`, `Encounter`, `Charge`, `Payment`, and `Refund` factories all exist;
  read each before using it. `QueueEntry::factory()` defaults to queue number 1, so use `number(int $n)` for a second entry of the same doctor and day. Fix the clock with `$this->travelTo(...)` where a rule
  depends on the date (for example check-in, no-show, removing time off).
- **Shared test helpers**, created in T009: `assertOneEntry` and `failAuditWrites` in `tests/Feature/Audit/Concerns/InteractsWithAuditEntries.php`.
- **Coverage test**: `tests/Feature/Audit/AuditActionCoverageTest.php` has one method per action, named `test_` plus the action value with `.` replaced by `_` (for example `test_patient_updated`). Each drives the real endpoint and
  calls `assertOneEntry`. Tasks below append to this file. T044 proves that no action was missed.

### Rules for every recording call

- **Inside the transaction, after the writes it describes**: the call goes inside the service's existing `DB::transaction`, so it can never read before a lock (feature 007 R3). Where a later step would wipe or remove what it describes (`replacePeriods`, a delete, deleting tokens), the call goes before that step. `AuditTrail` itself never runs a read query and never opens a transaction.
- **Do not catch it**: a failure must roll the change back (FR-007). Only `recordRefusal` keeps going.
- **Never pass request text or models into `details`**: only names, allow-listed values through `AuditChanges`, and internal ids (FR-006, FR-008).
- **Keep guards outside**: where a method has a uniqueness guard, write `guard(fn () => DB::transaction(...))`, as `DoctorService::create` does (R3).
- **Which method**: `recordChanges` writes nothing when nothing changed. `record` always writes. `discount.set`, sign-ins, sign-outs, password changes, `patient.opened`, `encounter.opened`, and `encounter.history_viewed` use `record`.
- **No rule or response changes** (FR-015). An existing test that fails means the change is wrong: fix the code, never the test.
- **Synthetic data only** (Constitution XII). No new log lines; `recordRefusal` reports failures through `report()`, which already hides SQL bindings (`bootstrap/app.php`).
- **Branch per phase**: create a new git branch for each phase from the previous one: `011-phase-2-foundational`, `011-phase-3-us1-changes`, `011-phase-4-us2-openings`, `011-phase-5-us3-staff`, `011-phase-6-us4-trust`, `011-phase-7-polish`.

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/`tinker`/`test`, `composer test`, Pint, `postman spec lint`) are listed. If a command does not behave as written, **stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Confirm the baseline on branch `011-audit-logs`, and commit the design documents in `specs/011-audit-logs/`
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the twenty-two existing migrations `Ran`
    - `docker compose exec app composer test` → 1663 passed (recorded in `specs/010-reports/verification.md`)
    - `docker compose exec app php artisan test --group=concurrency` → 13 passed
  - Accept: if a count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The table, enums, repository, change detection, and recording service that every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Write `tests/Feature/Audit/AuditEntriesTableTest.php`, then create `database/migrations/<timestamp>_create_audit_entries_table.php` (R10, approved D6)
  - Scope: `make:migration create_audit_entries_table`, with a comment pointing to `specs/011-audit-logs/data-model.md`. Exactly:
    - `id()`; `string('action', 40)`; `string('outcome', 10)`; `string('actor_type', 10)`;
    - `foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete()`; `string('actor_role', 20)->nullable()`;
    - `string('subject_type', 20)->nullable()`; `unsignedBigInteger('subject_id')->nullable()`, with a comment that it deliberately has no foreign key because entries outlive removed records;
    - `foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete()`; `string('network_address', 45)->nullable()`; `json('details')->nullable()`; `timestamp('created_at')`.
    - Indexes: (`subject_type`, `subject_id`), `action`, and `created_at`. The two foreign keys bring `actor_id` and `patient_id` indexes.
  - Test: `Schema::hasColumns`; `Schema::hasIndex` for the three named indexes and both foreign key indexes; a row whose `subject_id` matches no record can be inserted.
  - Verify: `--filter=AuditEntriesTableTest` fails first; `docker compose exec app php artisan migrate` → `DONE`; then passes.

- [X] T003 [P] Create the enums in `app/Modules/Audit/Enums/`: `AuditOutcome.php`, `AuditActorType.php`, `AuditSubject.php`, `AuditAction.php`
  - Scope: string-backed, with exactly the values in [data-model.md](data-model.md), "Enums": `AuditOutcome` 2, `AuditActorType` 3, `AuditSubject` 9, `AuditAction` 44. Case names follow values: `patient.updated` → `PatientUpdated`.
  - Verify: a test in T009 asserts `count(AuditAction::cases()) === 44`, and that the values equal the `AuditAction` enum in `contracts/audit.openapi.yaml`.

- [X] T004 Create `app/Modules/Audit/Models/AuditEntry.php` and `database/factories/AuditEntryFactory.php`
  - Scope: `public const UPDATED_AT = null`, as `Payment`. `$fillable`: all columns but `id`. Casts: `action` → `AuditAction`, `outcome` → `AuditOutcome`, `actor_type` → `AuditActorType`, `subject_type` → `AuditSubject`, `details` → `array`, `created_at` → `datetime`. `actor(): BelongsTo` to `User`. Factory: synthetic defaults, with `actor_type` `staff`, `outcome` `succeeded`, `action` `patient.updated`; it accepts `created_at` directly.
  - Depends: T002, T003

- [X] T005 Create `app/Modules/Audit/Repositories/Contracts/AuditEntryRepository.php` and `app/Modules/Audit/Repositories/EloquentAuditEntryRepository.php`
  - Scope: `append(array $attributes): AuditEntry` only, for now. `search` is added in T018. **No update or delete method, ever** (FR-014). The repository never opens a transaction.
  - Depends: T004

- [X] T006 [P] Write `tests/Feature/Audit/AuditChangesTest.php`, then create `app/Modules/Audit/Support/AuditChanges.php` (R4, R5, FR-006)
  - Scope: `final class` with `public static function of(Model $model, AuditSubject $subject): array` returning `['fields' => list<string>, 'values' => array<string, array{from: mixed, to: mixed}>]`.
    - **Fields**: keys of `getChanges()`; if `$model->wasRecentlyCreated`, keys of `getAttributes()`. Leave out `id`, anything ending in `_at` or `_by`, `name_search`, and generated columns (`active_flag`, `scheduled_flag`, `with_doctor_flag`).
    - **Values**: `private const ALLOWED_VALUES`, keyed by `AuditSubject` value, exactly the table in research R5. Only for allowed fields present in `fields`. Anything else is never read.
    - **Reading a value**: `to` is `$model->getAttribute($field)`. `from` is `null` on creation; otherwise `(clone $model)->setRawAttributes($model->getPrevious())->getAttribute($field)`, so both go through the model's casts. Normalize: `BackedEnum` → `->value`; `CarbonInterface` → `toDateString()`; others as they are.
    - Encrypted casts must never run: encrypted fields are not on any allow-list.
  - Test, using saved models. Reload each with `fresh()` before changing it: a factory-created model keeps `wasRecentlyCreated = true`, which `AuditChanges` would read as a creation.
    - `Charge` discount `0.00` → `200.00`, and status: both in `values`, money as strings; `discount_reason` in `fields`, not `values`; `discounted_by` and `discounted_at` in neither.
    - `Patient` phone change: `phone` in `fields`, `values` empty; the number appears nowhere in the JSON of the result. A name change never shows `name_search`.
    - `Encounter` diagnosis change: `diagnosis` in `fields`, no ciphertext or text in the result; same text → empty.
    - `Appointment` moved: `date` as `Y-m-d`, `start_minute`, and `end_minute` from and to.
    - `User`: `role` as a string value and `is_active` as a boolean, from and to; `password` name only.
    - A new `Charge`: `from` is `null`, and `fee` in `values`.
    - No change: empty `fields`.
  - Verify: `--filter=AuditChangesTest` → fails first, then passes.
  - Depends: T003

- [X] T007 Create `app/Modules/Audit/Services/AuditTrail.php` (R6)
  - Scope: depends on `AuditEntryRepository` only. Methods, with named arguments at call sites:
    - `record(AuditAction $action, ?AuditSubject $subject = null, ?int $subjectId = null, ?int $patientId = null, array $details = [], ?User $actor = null): void`, always writes with `succeeded`;
    - `recordChanges(AuditAction $action, Model $model, AuditSubject $subject, ?int $patientId = null, array $extraFields = [], ?int $subjectId = null): void`: `AuditChanges::of`, plus `$extraFields` added to `fields` without duplicates; if `fields` is empty, writes nothing. `subject_id` is `$subjectId ?? $model->getKey()`;
    - `recordRefusal(AuditAction $action, ?AuditSubject $subject = null, ?int $subjectId = null, ?int $patientId = null, ?User $actor = null): void`: writes with `refused`, and catches `Throwable` and calls `report()`, so that a refusal's response never changes (FR-005).
    - **Actor**: `$actor ?? Auth::user()`. If there is one: `staff`, with `actor_id` and `actor_role` as `$actor->role->value`, read from the model in memory. If none: `system` from `record`, and `unknown` from `recordRefusal`.
    - **Address**: `request()->ip()`, or `null` for `system`.
    - `details` is stored as `null` when empty.
  - Bind nothing here; T008 binds the repository.
  - Depends: T005, T006

- [X] T008 Create the module skeleton: `app/Modules/Audit/Providers/AuditServiceProvider.php`, `app/Modules/Audit/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: the provider mirrors `ReportsServiceProvider`: binds `AuditEntryRepository`, loads the routes, and loads the translations namespace `audit` (the policy is added in T016). Routes file: header comment plus an empty `Route::middleware('auth:sanctum')->group(...)`. Add `AuditServiceProvider::class` **before** the module providers that use it, directly after `SystemServiceProvider::class`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.
  - Depends: T005

- [X] T009 Write `tests/Feature/Audit/AuditTrailTest.php` and create `tests/Feature/Audit/Concerns/InteractsWithAuditEntries.php`
  - Scope, helpers:
    - `assertOneEntry(AuditAction $action, array $expected = []): AuditEntry`: exactly one entry with that action; each key in `$expected` matches; returns it.
    - `failAuditWrites(): void`: rebinds `AuditEntryRepository` in the container to an anonymous class that wraps the real repository, throws `RuntimeException` from `append`, and passes every other method through. T018 adds `search` to it. Call this **before the test's first request to that route**, because Laravel caches controllers per route.
    - `assertNoEntries(AuditAction $action): void`.
  - Scope, tests: an authenticated user gives `staff`, with their id, role, and the request address; a later role change leaves the entry's role as it was; no user gives `system` with no address; an explicit actor is used; `recordChanges` with no change writes nothing; `recordRefusal` with `failAuditWrites` does not throw, and with no user gives `unknown`; `AuditAction` count 44, and its values equal the contract's enum.
  - Verify: `--filter=AuditTrailTest` → pass.
  - Depends: T007, T008

- [X] T010 Write `tests/Feature/Localization/ServerErrorMessageTest.php`, then translate the general error in `bootstrap/app.php`, `lang/en/auth.php`, and `lang/ar/auth.php` (R13, approved D7, Constitution VII)
  - Scope:
    - `bootstrap/app.php`, inside `withExceptions`, after the existing `report` block, importing `Symfony\Component\HttpFoundation\Response`:
      ```php
      // The framework's 500 message is a fixed English string (Constitution VII). Debug output is left alone.
      $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
          if ($response->getStatusCode() === 500 && $request->is('api/*') && ! config('app.debug')) {
              return response()->json(['message' => __('auth.server_error')], 500);
          }

          return $response;
      });
      ```
    - Language files, under the project keys: `'server_error' => 'Something went wrong. Please try again.'` / `'server_error' => 'حدث خطأ ما. يرجى المحاولة مرة أخرى.'`
    - Test, with `config(['app.debug' => false])` and a test-only route that throws a `RuntimeException` with a planted message: `en` and `ar` messages with identical structure, and the planted message nowhere in the body. A `422`, `401`, `403`, and `404` keep their status and body. With `app.debug` true, the response is not replaced.
  - Verify: `--filter=ServerErrorMessageTest` fails first, then passes; `docker compose exec app php artisan test --filter=TranslationParityTest` → pass.

- [X] T011 Run the whole suite on `tests/`
  - Verify: `docker compose exec app composer test` → all pass, with nothing recorded yet by any module.
  - Depends: T009, T010

**Checkpoint**: The recording service works and is tested; nothing calls it yet, and all earlier features are unaffected.

---

## Phase 3: User Story 1 — An administrator finds out who changed a record (Priority: P1) 🎯 MVP

**Goal**: Every change in Patients, Payments, Doctors, Scheduling, Appointments, Visits, and Medical records leaves one correct entry, and administrators can search for them.

**Independent Test**: As different staff members, set a discount on a charge, record a refund, and update a patient. Search by each record and confirm that exactly one entry exists for each action, with the right staff member, action, and time.

### Search

- [X] T012 [P] [US1] Write `tests/Feature/Audit/AuditSearchTest.php` — failing first (FR-009, FR-010, FR-012)
  - Scope, using `AuditEntry::factory()`:
    - each filter alone, and several together;
    - `subject_type` with `subject_id`, including entries whose record no longer exists;
    - `patient_id`; `action`; `outcome`;
    - `from`/`to`: an entry one minute either side of midnight Cairo time, on an ordinary day and on both 2026 daylight-saving changes;
    - newest first, by `id`, including entries with the same `created_at`;
    - cursor paging: walk all pages with `meta.next_cursor`, inserting entries between requests, and assert that nothing repeats or goes missing; no `total`;
    - a response entry has exactly the fields in the contract's `AuditEntry` schema, with `actor.name` the current name after a rename and `actor.role` the role stored in the entry;
    - `system` and `unknown` actors with null `id`, `name`, and `role`;
    - unknown `staff_id` or `patient_id` → `200` with an empty `data`.
  - Verify: `--filter=AuditSearchTest` → fails (no route yet).

- [X] T013 [P] [US1] Write `tests/Feature/Audit/AuditValidationTest.php` — failing first (FR-009, data-model "Validation")
  - Scope: `422` for a malformed `from`, `to` before `from`, `subject_type` not in the enum, `subject_id` without `subject_type` and the reverse, an `action` or `outcome` not in the enum, `staff_id` `0`, `per_page` `0` and `101`, and a `cursor` of `not-a-cursor` and of a validly encoded value with no `id`. `200` for no filters, and for a period longer than 366 days.
  - Verify: `--filter=AuditValidationTest` → fails.

- [X] T014 [P] [US1] Write `tests/Feature/Audit/AuditEntryPolicyTest.php` — failing first (FR-013)
  - Scope: as `PaymentsPolicyTest`: every role, active and inactive, with `viewAny`; only the active administrator is allowed.
  - Verify: `--filter=AuditEntryPolicyTest` → fails.

- [X] T015 [P] [US1] Create the translations `app/Modules/Audit/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('audit::messages.<key>')`), en / ar: `invalid_cursor`: "The cursor is not valid." / "المؤشر غير صالح."
    Attributes en / ar: `from` "from date" / "تاريخ البداية"; `to` "to date" / "تاريخ النهاية"; `staff_id` "staff member" / "الموظف"; `patient_id` "patient" / "المريض"; `subject_type` "record type" / "نوع السجل"; `subject_id` "record" / "السجل"; `action` "action" / "الإجراء"; `outcome` "outcome" / "النتيجة"; `per_page` "items per page" / "عدد العناصر في الصفحة"; `cursor` "cursor" / "المؤشر".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [X] T016 [US1] Create `app/Modules/Audit/Policies/AuditEntryPolicy.php`, registered in `AuditServiceProvider`
  - Scope: `viewAny(User $actor): bool` → `$actor->is_active && $actor->role === Role::Administrator`. `Gate::policy(AuditEntry::class, AuditEntryPolicy::class)`.
  - Verify: `--filter=AuditEntryPolicyTest` → pass.

- [X] T017 [US1] Create `app/Modules/Audit/Http/Requests/SearchAuditEntriesRequest.php`
  - Scope: `authorize()` → `$this->user()->can('viewAny', AuditEntry::class)`. Rules, as `ListChargesRequest`:
    - `from`: `nullable`, `date_format:Y-m-d`; `to`: `nullable`, `date_format:Y-m-d`, `after_or_equal:from`;
    - `staff_id`, `patient_id`: `nullable`, `integer`, `min:1`;
    - `subject_type`: `nullable`, `required_with:subject_id`, `Rule::enum(AuditSubject::class)`; `subject_id`: `nullable`, `required_with:subject_type`, `integer`, `min:1`;
    - `action`: `nullable`, `Rule::enum(AuditAction::class)`; `outcome`: `nullable`, `Rule::enum(AuditOutcome::class)`;
    - `per_page`: `nullable`, `integer`, `min:1`, `max:100`; `cursor`: `nullable`, `string`, `max:1000`, and a closure: `Cursor::fromEncoded($value)` must not be `null`, and its `toArray()` must hold an integer `id`, else `audit::messages.invalid_cursor`. `Cursor::parameter` throws on a missing `id`, which would otherwise be a `500`.
    `attributes()` from `audit::attributes`. Accessors `filters(): array` (the validated filters only, without `per_page` and `cursor`) and `perPage(): int` (default 15).
  - Verify: `--filter=AuditValidationTest` → pass once the route exists (T019).
  - Depends: T015, T016

- [X] T018 [US1] Add `search` to `AuditEntryRepository` and `EloquentAuditEntryRepository`, and create `app/Modules/Audit/Services/AuditSearchService.php` (R9, R10, approved D5)
  - Scope:
    - Add a pass-through `search` to the wrapper in `failAuditWrites` (T009), so that it still satisfies the contract.
    - Repository `search(array $filters, ?CarbonImmutable $start, ?CarbonImmutable $end, int $perPage): CursorPaginator`: `with('actor:id,name')`; `when` per filter; `created_at >= $start` and `created_at < $end`; `staff_id` as `where(fn ($q) => $q->where('actor_id', $id)->orWhere(fn ($q) => $q->where('subject_type', 'staff_member')->where('subject_id', $id)))`; `orderByDesc('id')`; `cursorPaginate($perPage)->withQueryString()`.
    - Service `search(array $filters, int $perPage): CursorPaginator`: converts `from` to the start of that clinic day and `to` to the start of the next clinic day, both in UTC, through `ClinicClock::timezone()`, and calls the repository. Search recording is added in T042.
  - Depends: T017

- [X] T019 [US1] Create `app/Modules/Audit/Http/Resources/AuditEntryResource.php` and `app/Modules/Audit/Http/Controllers/AuditEntryController.php`; add the route to `app/Modules/Audit/routes/api.php`
  - Scope: exactly the contract's `AuditEntry`:
    - `occurred_at`: `created_at->toISOString()`, as the project's other resources; `actor`: `{type, id, name, role}`, `name` from the relation;
    - `subject`: `{type, id}` or `null`; `changes`: `{fields, values}` when `details.fields` exists, else `null`; `filters`: `details.filters` or `null`.
    Controller: `index(SearchAuditEntriesRequest $request): AnonymousResourceCollection`. Route: `GET /audit-entries`, named `audit.entries.index`. No other route (FR-014).
  - Verify: `--filter='AuditSearchTest|AuditValidationTest'` → pass. `php artisan route:list --path=audit-entries` → only `GET`.
  - Depends: T012, T013, T018

### Patients and money

- [X] T020 [US1] Start `tests/Feature/Audit/AuditActionCoverageTest.php` and write `tests/Feature/Audit/AuditAtomicityTest.php` and `tests/Feature/Audit/AuditNoChangeTest.php` for Patients — failing first (SC-001, SC-004, US1 scenarios 1, 2, 5)
  - Scope:
    - Coverage: `test_patient_registered`, `test_patient_updated`: actor, role, address, subject, `patient_id`, `fields`, and empty `values`.
    - Atomicity: with `failAuditWrites()`, updating a patient returns a general `500`, and the phone is unchanged; registering leaves no patient. A `422` on update leaves no entry.
    - No change: the same update twice gives one entry.
  - Verify: `--filter='AuditActionCoverageTest|AuditAtomicityTest|AuditNoChangeTest'` → fails.

- [X] T021 [US1] Record Patients changes in `app/Modules/Patients/Services/PatientService.php` (R3, approved D2, D3)
  - Scope: inject `AuditTrail`. `register`: `guardUniqueNationalId(fn () => DB::transaction(...))`, with the repository call followed by `recordChanges(AuditAction::PatientRegistered, $patient, AuditSubject::Patient, patientId: $patient->id)` inside it. `update`: the same with `PatientUpdated`.
  - Verify: the tests from T020 pass; `docker compose exec app php artisan test tests/Feature/Patients` → all pass unchanged.
  - Depends: T020

- [X] T022 [US1] Add Money cases to `tests/Feature/Audit/AuditActionCoverageTest.php`, `AuditAtomicityTest.php`, and `AuditNoChangeTest.php` — failing first
  - Scope:
    - Coverage: `test_fee_set` (`fee` from and to; `from` null the first time), `test_charge_created`, `test_payment_recorded` and `test_refund_recorded` (`amount_paid` and `status` from and to), `test_discount_set` (`discount` from and to; `discount_reason` in `fields`, but its text nowhere), `test_charge_cancelled`.
    - Atomicity: with `failAuditWrites()`, `pay` leaves no payment and `amount_paid` unchanged; `discount` and `cancel` leave the charge unchanged; `set` leaves the fee unchanged. An over-balance payment (`422`) leaves no entry.
    - No change: the same fee twice gives one entry. The same discount twice gives **two** (spec, Edge Cases).
  - Verify: `--filter='AuditActionCoverageTest|AuditAtomicityTest|AuditNoChangeTest'` → the new ones fail.

- [X] T023 [US1] Record Money in `app/Modules/Payments/Services/ChargeService.php` and `app/Modules/Payments/Services/FeeService.php` (R4, R5)
  - Scope:
    - `charge`: `recordChanges(ChargeCreated, $charge, Charge, patientId: $charge->patient_id)` before the closure returns.
    - `pay`, `refund`: `recordChanges(PaymentRecorded|RefundRecorded, ...)` after `applyPaid`, whose single update provides `amount_paid` and `status`.
    - `discount`: `record(DiscountSet, Charge, $charge->id, $charge->patient_id, AuditChanges::of($charge, AuditSubject::Charge))`, since it always writes.
    - `cancel`: `recordChanges(ChargeCancelled, ...)`.
    - `FeeService::set`: wrap in `DB::transaction`. Read `$this->fees->findForDoctor($doctorId)` first, as `upsert` leaves no model changes. Same amount through `Money::toPiastres` → nothing recorded. Otherwise `record(FeeSet, Doctor, $doctorId, details: ['fields' => ['fee'], 'values' => ['fee' => ['from' => <old or null>, 'to' => <new>]]])`. `set_by` still updates when the amount is the same, as today.
  - Verify: tests from T022 pass; `docker compose exec app php artisan test tests/Feature/Payments` → all pass unchanged; `docker compose exec app php artisan test --group=concurrency` → pass.
  - Depends: T022

### Doctors and schedules

- [X] T024 [US1] Add Doctors and Schedules cases to `tests/Feature/Audit/AuditActionCoverageTest.php`, `AuditAtomicityTest.php`, and `AuditNoChangeTest.php` — failing first
  - Scope:
    - Coverage: `test_specialty_created`, `test_specialty_updated`, `test_specialty_deactivated`, `test_specialty_reactivated` (`is_active` from and to), `test_doctor_created`, `test_doctor_updated` (`specialty_ids` in `fields` when the set changes), `test_schedule_set` (a first schedule writes an entry with `appointment_minutes` in `fields`, even with an empty `periods` array, which `SetScheduleRequest` allows), `test_schedule_cleared`, `test_time_off_added` (`reason` in `fields`, but its text nowhere), `test_time_off_removed`, the last also asserting that the entry is still found by `subject_type=time_off` after removal.
    - Atomicity: with `failAuditWrites()`: renaming a specialty, updating a doctor (the specialty set also unchanged), replacing a schedule (periods unchanged), clearing a schedule (it still exists), adding and removing time off.
    - No change: a repeated rename; deactivating twice; repeating a doctor update with the same specialty ids; repeating the same schedule with its periods sent in another order; clearing a doctor with no schedule.
  - Verify: `--filter='AuditActionCoverageTest|AuditAtomicityTest|AuditNoChangeTest'` → the new ones fail.

- [X] T025 [US1] Record Doctors changes in `app/Modules/Doctors/Services/SpecialtyService.php`, `app/Modules/Doctors/Services/DoctorService.php`, and `DoctorRepository`
  - Scope:
    - `SpecialtyService::{create,rename}`: `guardUniqueNames(..., fn () => DB::transaction(...))` with `recordChanges`. `deactivate`, `reactivate`: `DB::transaction` around the existing update with `recordChanges`; the idempotent path writes nothing.
    - `DoctorRepository::syncSpecialties` returns `bool`, whether anything was attached or detached: `$changes = $doctor->specialties()->sync($ids); return $changes['attached'] !== [] || $changes['detached'] !== [];`. Update the contract and the implementation.
    - `DoctorService::create`: `recordChanges(DoctorCreated, $doctor, Doctor, extraFields: ['specialty_ids'])` inside its transaction.
    - `DoctorService::update`: inside its transaction, capture whether the sync changed anything, and call `recordChanges(DoctorUpdated, $doctor, Doctor, extraFields: $changed ? ['specialty_ids'] : [])` last.
  - Verify: the tests from T024 for these pass; `docker compose exec app php artisan test tests/Feature/Doctors` → all pass unchanged.
  - Depends: T024

- [X] T026 [US1] Record Schedules in `app/Modules/Scheduling/Services/ScheduleService.php` and `app/Modules/Scheduling/Services/TimeOffService.php` (R3, R4)
  - Scope:
    - `EloquentDoctorScheduleRepository::lockOrCreateForDoctor`: keep the result of `insertOrIgnore`, and set `$schedule->wasRecentlyCreated = $inserted > 0` on the model it returns. Without this, a first schedule with no periods looks like no change, and writes no entry.
    - `ScheduleService::replace`, inside its transaction: after `lockOrCreateForDoctor`, compare `$schedule->periods` (a relation read after the lock, as `DoctorService::update` already does) with `$periods`, each reduced to `[weekday, start_minute, end_minute]` and sorted, to get `$periodsChanged`. Then `update`. Then call `recordChanges(ScheduleSet, $schedule, Doctor, extraFields: $periodsChanged ? ['periods'] : [], subjectId: $doctorId)`, **before** `replacePeriods`: its `$schedule->touch()` re-syncs the model and wipes `getChanges()`.
    - `ScheduleService::clear`: `DB::transaction`: if `lockForDoctor($doctorId)` finds one, `deleteForDoctor`, then `record(ScheduleCleared, Doctor, $doctorId)`; if not, nothing.
    - `TimeOffService::record`: `DB::transaction` with `recordChanges(TimeOffAdded, $entry, TimeOff)`. `remove`: `DB::transaction` around the delete, with `record(TimeOffRemoved, TimeOff, $entry->id)`.
  - Verify: all tests from T024 pass; `docker compose exec app php artisan test tests/Feature/Scheduling` → all pass unchanged.
  - Depends: T025

### Appointments, visits, and medical records

- [X] T027 [US1] Add Appointments and Visits cases to `tests/Feature/Audit/AuditActionCoverageTest.php` and `AuditAtomicityTest.php` — failing first
  - Scope:
    - Coverage: `test_appointment_booked`, `test_appointment_rescheduled` (`date`, `start_minute`, and `end_minute` from and to), `test_appointment_cancelled` (`status` from and to; `cancellation_reason` in `fields`, but its text nowhere), `test_appointment_no_show`, `test_visit_checked_in`, `test_visit_walk_in_added`, `test_visit_left`, `test_visit_check_in_undone` (the entry survives with `subject_id` and `patient_id`, although its record is gone), `test_visit_called`, `test_visit_finished`. Each asserts `patient_id`. Check-in and finish write one entry, though they also change the appointment (spec, Edge Cases).
    - Atomicity: with `failAuditWrites()`: booking leaves no appointment and the slot free; cancel leaves it `scheduled`; check-in leaves no visit and the appointment `scheduled`; undo leaves the visit; call-next leaves it `waiting`.
  - Verify: `--filter='AuditActionCoverageTest|AuditAtomicityTest'` → the new ones fail.

- [X] T028 [US1] Record Appointments in `app/Modules/Appointments/Services/BookingService.php` and `app/Modules/Appointments/Services/AppointmentService.php`
  - Scope: `recordChanges` with `AuditSubject::Appointment` and `patientId: $appointment->patient_id`, last inside each existing transaction: `book` (keep the guard outside), `reschedule`, `cancel`, `markNoShow`. Read each method's end before editing, so the call uses the model that was just saved.
  - Verify: the new Appointments tests pass; `docker compose exec app php artisan test tests/Feature/Appointments` → all pass unchanged; `docker compose exec app php artisan test --group=concurrency` → pass.
  - Depends: T027

- [X] T029 [US1] Record Visits in `app/Modules/Queues/Services/QueueService.php` and `app/Modules/Queues/Services/DoctorQueueService.php`
  - Scope: `recordChanges` with `AuditSubject::Visit` and `patientId: $entry->patient_id`, last inside each existing transaction: `checkIn`, `addWalkIn`, `leave`, `callNext`, `finish`. `callNext` returns from inside a `foreach`, so assign the updated entry and record before returning; `finish` likewise. `undo`: `record(VisitCheckInUndone, Visit, $entry->id, $entry->patient_id)` before `$this->queue->delete($entry)`.
  - Verify: all tests from T027 pass; `docker compose exec app php artisan test tests/Feature/Queues` → all pass unchanged; `docker compose exec app php artisan test --group=concurrency` → pass.
  - Depends: T028

- [X] T030 [US1] Add Medical records changes to `tests/Feature/Audit/AuditActionCoverageTest.php`, `AuditAtomicityTest.php`, and `AuditNoChangeTest.php` — failing first
  - Scope:
    - Coverage: `test_encounter_started`, `test_encounter_draft_saved` (changed clinical field names; no clinical text or ciphertext anywhere in the entry), `test_encounter_finalized` (`status` from and to), `test_encounter_addendum_added` (`text` in `fields`, but its text nowhere; subject is the encounter).
    - Atomicity: with `failAuditWrites()`: saving leaves the draft's content unchanged, finalizing leaves it `draft`, and an addendum is not added.
    - No change: saving the same content twice gives one `encounter.draft_saved`.
  - Verify: `--filter='AuditActionCoverageTest|AuditAtomicityTest|AuditNoChangeTest'` → the new ones fail.

- [X] T031 [US1] Record Medical records changes in `app/Modules/Encounters/Services/EncounterService.php`
  - Scope: `recordChanges` with `AuditSubject::Encounter` and `patientId: $encounter->patient_id`, last inside each existing transaction: `start` (assign the created encounter, record, then return it), `save` (keep the locked model, whose `update` provides the changes), `finalize`. `addAddendum`: `DB::transaction` with `addAddendum` and `record(EncounterAddendumAdded, Encounter, $encounter->id, $encounter->patient_id, ['fields' => ['text']])`.
  - Verify: tests from T030 pass; `docker compose exec app php artisan test tests/Feature/Encounters` → all pass unchanged.
  - Depends: T030

- [X] T032 [US1] Run the whole suite and the concurrency group over `tests/`
  - Verify: `docker compose exec app composer test` → all pass with no earlier test changed; `docker compose exec app php artisan test --group=concurrency` → pass. `git diff --stat --diff-filter=MD 476e76a -- tests/` prints nothing: no earlier test was changed or deleted. `476e76a` is the commit this feature branched from, so the result does not depend on whether local `main` is up to date.
  - Accept: failures are reported; deadlocks in the concurrency group mean research R10's lock reasoning was wrong: **stop and report**.
  - Depends: T019, T021, T023, T026, T029, T031

**Checkpoint**: US1 is usable on its own: a disputed discount or a cancelled appointment can be traced. This is the MVP.

---

## Phase 4: User Story 2 — An administrator sees who opened a patient's records (Priority: P1)

**Goal**: Openings of patient details, medical records, and medical history are recorded, and refused attempts on the last two, with no change to any response.

**Independent Test**: As a receptionist, open a patient. As a doctor with access, open that patient's medical record and history. As a doctor without access, try to open them. Search by the patient and confirm each entry and outcome, and that the refused doctor received the same response as before this feature.

- [X] T033 [P] [US2] Write `tests/Feature/Audit/AuditOpeningsTest.php`, and add `test_patient_opened`, `test_encounter_opened`, and `test_encounter_history_viewed` to `AuditActionCoverageTest` — failing first (FR-004, FR-007, US2 scenarios 1, 4–6, US4 scenario 3)
  - Scope:
    - Opening a patient writes `patient.opened`; a doctor opening their own record writes `encounter.opened`, as does another doctor's permitted record; history writes `encounter.history_viewed`, once per page requested, including an empty `200`.
    - With `failAuditWrites()`: each returns a general `500`, and its body holds no patient detail or clinical text.
    - `GET /patients`, `/appointments`, `/queue`, `/charges`, `GET /charges/{id}`, `/doctors/me/visits`, and every report write no entry.
    - Searching by `patient_id` returns entries from patient details, an appointment, a visit, a record, and a charge together (US2 scenario 4).
  - Verify: `--filter='AuditOpeningsTest|test_patient_opened|test_encounter_opened|test_encounter_history_viewed'` → fails.

- [X] T034 [P] [US2] Write `tests/Feature/Audit/AuditRefusedOpeningsTest.php` — failing first (FR-005, SC-006, US2 scenarios 2, 3; R7, approved D4)
  - Scope:
    - Another doctor's unpermitted record → `404` with a `refused` `encounter.opened`; a receptionist and an administrator → `403`, each with `refused`.
    - Refused history → `404` or `403`, with `refused` `encounter.history_viewed`, when the patient has a finalized record.
    - Unknown encounter id and a patient with no finalized record: same response, no entry.
    - A receptionist with an invalid `per_page` on history → still `403`, and recorded. A doctor with an invalid `per_page` → `422`, no entry.
    - **Byte-identical**: the refused doctor's body equals the body for an unknown id, as 008 FR-013 requires.
    - With `failAuditWrites()`: the same status and body, with no `500`, and the failure reported, using `Illuminate\Support\Facades\Exceptions::fake()` and `Exceptions::assertReported(RuntimeException::class)`.
    - Refused saves, finalizations, and addenda write no entry (FR-005). No clinical text in any entry.
  - Verify: `--filter=AuditRefusedOpeningsTest` → fails.

- [X] T035 [US2] Add `open` to `app/Modules/Patients/Services/PatientService.php` and use it in `app/Modules/Patients/Http/Controllers/PatientController.php` (R8)
  - Scope: `open(int $id): Patient`: `find`, then `record(PatientOpened, Patient, $id, $id)`. `PatientController::show` calls `open` in place of `find`. **`find` is unchanged**, as `update` and other modules call it. Authorization stays first. `update` also records `patient.opened` when nothing changed, as it still returns the whole record (research R8).
  - Verify: the patient tests of T033 pass; `docker compose exec app php artisan test tests/Feature/Patients` → pass unchanged.
  - Depends: T033

- [X] T036 [US2] Add `open` and history recording to `app/Modules/Encounters/Services/EncounterService.php`, and use `open` in `app/Modules/Encounters/Http/Controllers/EncounterController.php` (R8)
  - Scope: `open(Encounter $encounter): Encounter`: `record(EncounterOpened, Encounter, $encounter->id, $encounter->patient_id)`, then return it. `show`: `EncounterResource::make($this->encounters->open($this->readable($encounter)))`. `update`, `finalize`, and `addAddendum` keep `readable` and record no opening. `history`: after the `HistoryNotFoundException` check, `record(EncounterHistoryViewed, Patient, $patientId, $patientId)`, then return the page.
  - Verify: tests of T033 pass; `docker compose exec app php artisan test tests/Feature/Encounters` → pass unchanged, including `EncounterPrivacyTest`.
  - Depends: T033

- [X] T037 [US2] Create `app/Modules/Encounters/Http/Middleware/RecordRefusedOpening.php` with existence checks, and apply it in `app/Modules/Encounters/routes/api.php` (R7, approved D4)
  - Scope:
    - `EncounterRepository`: `patientIdOfEncounter(int $id): ?int` and `patientHasFinalizedRecord(int $patientId): bool`, selecting `patient_id` and using `exists()`. **No `select *`, no clinical column, and no casts run** (`EncounterPrivacyTest`).
    - `EncounterService`: `recordRefusedOpening(int $encounterId): void` and `recordRefusedHistory(int $patientId): void`, each doing nothing unless the record exists, then `recordRefusal(...)`.
    - Middleware `handle`: `$response = $next($request);`; if the status is `403` or `404`, inside `try/catch (Throwable $e) { report($e); }` call the service with `$request->route('encounter')` or `$request->route('patient')`; return `$response` untouched.
    - Routes: add `->middleware(RecordRefusedOpening::class)` to `encounters.show` and `encounters.history` only.
  - Accept: this relies on the routing pipeline rendering exceptions into responses before an outer middleware sees them, including a Form Request's authorization failure. T034's receptionist case proves it. If it does not hold, **stop and report**.
  - Verify: `--filter=AuditRefusedOpeningsTest` → pass; `docker compose exec app php artisan test tests/Feature/Encounters` → pass unchanged.
  - Depends: T034, T036

**Checkpoint**: A privacy complaint can be answered.

---

## Phase 5: User Story 3 — An administrator reviews a staff member's activity (Priority: P2)

**Goal**: Sign-ins and changes to staff accounts are recorded, and filtering by staff member shows what they did and what was done to their account.

**Independent Test**: Sign in as a staff member, fail a sign-in with the wrong password, carry out several actions, have an administrator change their role and deactivate them, then confirm that filtering by staff member and period returns exactly those entries.

- [X] T038 [P] [US3] Write `tests/Feature/Audit/AuditSignInTest.php` and `tests/Feature/Audit/AuditCommandTest.php`, and add Staff cases to `AuditActionCoverageTest`, `AuditAtomicityTest`, and `AuditNoChangeTest` — failing first (R6, US3 scenarios 1–6)
  - Scope:
    - Coverage: `test_auth_sign_in` (actor and subject are the staff member, even with nobody authenticated), `test_auth_sign_out`, `test_auth_password_changed`, `test_staff_created`, `test_staff_updated` (`role` from and to), `test_staff_deactivated`, `test_staff_reactivated` (`is_active` from and to), `test_staff_password_reset`. Password actions have `password` in `fields`, and never a value.
    - Sign-in: wrong password on an existing account → `refused`, naming the account; a deactivated account → the same; an unknown email → `refused`, with `actor_type` `unknown`, no subject, and the email text stored nowhere; a planted password appears in no entry; attempts past the limit (6 for one email) add no entries; the validation failure on a missing password adds none; with `failAuditWrites()` the refusal's response is still `422`.
    - Command: `staff:create-admin` as `CreateAdministratorCommandTest` runs it writes `staff.created` with `actor_type` `system` and no address.
    - Filter: `staff_id` returns what they did and what was done to their account, and failed sign-ins on it; a deactivated member's entries remain, and the filter still works (US3 scenario 6). An administrator changing someone's role names both.
    - Atomicity: with `failAuditWrites()`: a role change, deactivation, password reset, and own password change leave everything unchanged, tokens included. A successful sign-in issues no token.
    - No change: a repeated update; deactivating and reactivating when already so.
  - Verify: `--filter='AuditSignInTest|AuditCommandTest|test_auth_|test_staff_'` → fails.

- [X] T039 [US3] Record sign-in, sign-out, and own password in `app/Modules/Staff/Services/AuthenticationService.php`
  - Scope:
    - `signIn`: leave the rate-limit block first and unchanged, so stopped attempts record nothing. In the failure branch, **before** throwing: `recordRefusal(AuthSignIn, $user ? StaffMember : null, $user?->id, actor: $user)`. On success, wrap `deleteIdleFor`, `issueFor`, and `record(AuthSignIn, StaffMember, $user->id, actor: $user)` in `DB::transaction`, keeping `RateLimiter::clear` before it.
    - `signOut`: `DB::transaction` with `deleteCurrent` and `record(AuthSignOut, StaffMember, $user->id)`.
    - `changeOwnPassword`: inside its transaction, `record(AuthPasswordChanged, StaffMember, $user->id, details: ['fields' => ['password']])`.
    - Failed sign-ins take about the same time for known and unknown emails, as each writes one entry (002 SC-004).
  - Verify: tests of T038 for these pass; `docker compose exec app php artisan test tests/Feature/Staff` → all pass unchanged.
  - Depends: T038

- [X] T040 [US3] Record staff changes in `app/Modules/Staff/Services/StaffManagementService.php`
  - Scope, with `AuditSubject::StaffMember`:
    - `create`: `guardUniqueEmail(fn () => DB::transaction(...))` with `recordChanges(StaffCreated, ...)`. No change to `CreateAdministratorCommand`: with nobody authenticated, the actor is `system` (R6).
    - `update`: a private helper that updates and then calls `recordChanges(StaffUpdated, ...)`. The ordinary path becomes `guardUniqueEmail(fn () => DB::transaction(fn () => helper))`; the demotion path keeps its transaction and last-administrator check, and calls the helper inside its guard.
    - `deactivate`: `recordChanges(StaffDeactivated, ...)` inside its transaction, right after `update`, before deleting tokens. The early return for an inactive member stays and records nothing.
    - `reactivate`: `DB::transaction` with `recordChanges(StaffReactivated, ...)`; the same early return.
    - `setPassword`: `record(StaffPasswordReset, StaffMember, $user->id, details: ['fields' => ['password']])` inside its transaction.
  - Verify: all tests of T038 pass; `docker compose exec app php artisan test tests/Feature/Staff` → all pass unchanged; `docker compose exec app php artisan test --group=concurrency` → pass.
  - Depends: T038

- [X] T041 [US3] Write `tests/Feature/Audit/ConcurrentAuditTest.php` and `tests/Support/audit-action.php` (R10, Constitution XI)
  - Scope:
    - Child script: same shape as `tests/Support/payment-action.php`, including refusing any database but `clinic_testing`, waiting for a shared start time, and printing one JSON line. It calls `Auth::setUser($actor)` before the action, so `actor_id` is real and the user row is locked. **The existing child scripts stay unchanged**: they never authenticate, so they cannot exercise that lock.
    - Test, with `#[Group('concurrency')]`, mirroring `ConcurrentPaymentTest`, with simultaneous processes in each scenario:
      - an administrator deactivating a staff member while that member sets discounts;
      - a doctor saving an encounter while the same patient's appointment is rescheduled and another doctor calls them next;
      - a cancellation and a check-in on the same patient's appointments.
    - Assert: no process fails with a deadlock or lock timeout; each success has exactly one entry, and each failure has none.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass.
  - Accept: a deadlock or lock timeout means research R10 is wrong: **stop and report**. Do not add retries or change the lock order without approval.
  - Depends: T032, T039, T040

**Checkpoint**: All 43 actions outside the trail itself are recorded, and the new locks are tested.

---

## Phase 6: User Story 4 — The clinic can rely on the trail (Priority: P2)

**Goal**: Searches are themselves recorded, the trail cannot be changed, and no action can have been missed.

**Independent Test**: Attempt to change or delete an entry as every role and confirm no way exists. Force an entry to fail to save and confirm that the change or the opening does not go ahead.

- [X] T042 [US4] Add `test_audit_searched` to `AuditActionCoverageTest` and search recording cases to `AuditSearchTest` — failing first, then record searches in `app/Modules/Audit/Services/AuditSearchService.php` (FR-011, US4 scenario 4)
  - Scope:
    - Test: each request writes one `audit.searched`, including each page, with `details.filters` equal to the validated filters; `per_page` and `cursor` not in it; a search does not list its own entry; a search by a rejected role or with a `422` writes none. Update the cursor test of T012 so that pages stay stable while real searches add entries. With `failAuditWrites()`, the search returns a general `500`, and no results.
    - Service: read first, then `record(AuditSearched, details: ['filters' => $filters])`, then return the page.
  - Verify: `--filter='AuditSearchTest|test_audit_searched'` → fails first, then passes.

- [X] T043 [P] [US4] Write `tests/Feature/Audit/AuditImmutabilityTest.php` (FR-014, US4 scenario 1)
  - Scope:
    - As an administrator, `POST`, `PUT`, `PATCH`, and `DELETE` on `/audit-entries` and `/audit-entries/1` get `404` or `405`, and the entry is unchanged.
    - `route:list`: exactly one route with `audit-entries` in it, a `GET`.
    - By reflection, `AuditEntryRepository` has exactly the methods `append` and `search`.
    - A deactivated staff member's entries remain; a removed visit's remain; deleting a user or patient with entries fails with a `QueryException`.
  - Verify: `--filter=AuditImmutabilityTest` → pass.

- [X] T044 [US4] Add `test_every_action_has_a_scenario` to `tests/Feature/Audit/AuditActionCoverageTest.php` (SC-001)
  - Scope: for each `AuditAction::cases()`, assert that `method_exists($this, 'test_'.str_replace('.', '_', $case->value))`, and name any that are missing in the failure message.
  - Verify: `--filter=AuditActionCoverageTest` → all pass, covering 44 actions.
  - Depends: T032, T037, T040, T042

**Checkpoint**: Every story is complete.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Proof across the whole feature, documentation, measurement, and verification.

- [X] T045 [P] Write `tests/Feature/Audit/AuditAuthorizationTest.php` (SC-005, FR-013, FR-016)
  - Scope: every role against `GET /audit-entries`: only the administrator `200`; receptionist and doctor `403`, also with invalid filters, so refusal comes before validation; no token `401`; a deactivated account's token `401`.
  - Verify: `--filter=AuditAuthorizationTest` → pass.

- [X] T046 [P] Write `tests/Feature/Audit/AuditPrivacyTest.php` (SC-003, FR-006, FR-008, FR-012, FR-017)
  - Scope: create a patient with unique planted synthetic values in name, patient number, phone, national ID, address, and emergency contact; drive updates that plant values in an appointment note and cancellation reason, discount, refund, and cancel reasons, a time-off reason, each clinical field, an addendum, a password, and a patient search's text. Then assert that no planted value appears in `SELECT details FROM audit_entries` (all rows, as raw JSON) or in the body of a search across all entries. Also assert that no value of an encounter's ciphertext appears. Then capture the application log with a temporary `single` channel, as `EncounterPrivacyTest` does, force a recording failure on a refused opening and on a change, and assert that the failure is logged and that no planted value is in the log.
  - Verify: `--filter=AuditPrivacyTest` → pass.

- [X] T047 [P] Write `tests/Feature/Audit/AuditMessagesTest.php` (SC-008, FR-019)
  - Scope: as `PaymentsMessagesTest`, for `en` and `ar`: `invalid_cursor`, validation messages using the translated attributes, and `auth.forbidden`. The JSON keys of a search response are identical in both locales, and actions and outcomes are never translated.
  - Verify: `--filter=AuditMessagesTest` → pass.

- [X] T048 [P] Update `docs/architecture.md`, `docs/roadmap.md`, and `README.md`
  - Scope:
    - Architecture: a short "Audit trail" section: a new sensitive action adds an `AuditAction` case and an `AuditTrail` call inside its transaction, with a coverage test method, and any new value needs a deliberate allow-list entry. Also add `Http/Middleware/` to the module layout, for middleware used by one module's routes; `app/Http/Middleware/` stays for cross-cutting concerns.
    - Roadmap: Audit logs row as `011`, `Done`, with the spec link. Add a "Before production" note on retention, network addresses, and trusted proxies (plan: Risks).
    - README: a short "Audit logs" section after "Reports": what is recorded, administrators only, append only, no patient details or clinical text, cursor paging, no retention yet, and a note that behind a load balancer trusted proxies must be configured. State that for a `refused` `auth.sign_in`, the staff member is the account that was targeted, not necessarily the person who tried. Link the contract.
  - Verify: `postman spec lint specs/011-audit-logs/contracts/audit.openapi.yaml --issueType=syntax` → no issues.

- [X] T049 Run Pint and the full suite over `app/Modules/` and `tests/`
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
    - `git diff --stat --diff-filter=MD 476e76a -- tests/` prints nothing: no earlier test was changed or deleted (SC-006)
  - Depends: T045, T046, T047, T048

- [X] T050 Measure SC-002 and SC-007 on `clinic_testing` only, and keep the figures for `specs/011-audit-logs/verification.md`
  - Scope, as feature 010's T036:
    - **SC-002**: in a transaction rolled back afterwards, bulk-insert 5,000,000 synthetic entries, spread over 2 years, 2,000 patients, and 100 staff. Call `AuditSearchService::search`, three runs each: by patient, by record, by `staff_id`, with no filter, with `outcome=refused` alone (rare, and not indexed), and with a date range alone (target under 2 s each).
    - **SC-007**: time 100 patient updates with `AuditTrail` recording, against the same updates with `AuditEntryRepository` bound to a wrapper whose `append` does nothing and whose `search` passes through (target: no more than 100 ms added).
    - Refuse any database but `clinic_testing`; confirm 0 rows after the rollback. A miss is reported, not hidden.
  - Depends: T049

- [X] T051 Run quickstart Q1–Q9 from `specs/011-audit-logs/quickstart.md` on the development stack
  - Scope: synthetic data only. Record what was run.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T049

- [X] T052 Record the results in `specs/011-audit-logs/verification.md`
  - Scope: per Q-step and SC-002/SC-007: date, command, observed output summary, pass/fail; failures reported as failures (Constitution VIII). The Postman collection update is a separate follow-up.
  - Depends: T050, T051

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002, T003 → T004 → T005; T003 → T006; T005, T006 → T007; T005 → T008; T007, T008 → T009 → T010 → T011.
- **US1 (Phase 3)**: after Foundational. Creates the search endpoint and records all changes outside Staff.
- **US2 (Phase 4)**: after Foundational, for recording. Its search-by-patient scenario needs US1's search (T019), and it edits the same two services as T021 and T031, so run it after US1.
- **US3 (Phase 5)**: after Foundational, for recording. Its filter scenarios need US1's search (T019). T041 comes last, because it needs recording in every module it exercises.
- **US4 (Phase 6)**: T042 and T043 need US1's search; T044 needs every story.
- **Polish (Phase 7)**: after all stories.

### Within Each Story

Tests first and failing → service calls or endpoint → the module's existing suite unchanged.

### Parallel Opportunities

- Foundational: T003 and T006 alongside each other.
- US1: T012, T013, T014, and T015 together. Module tasks append to the same test files, so they run one after the other.
- US2: T033 and T034 together; T035 and T036 touch different modules, once T033 is done.
- US3: T038 alone opens the phase; T039 and T040 touch different services, but both need T038.
- US4: T043 alongside T042.
- Polish: T045, T046, T047, and T048 together.

---

## Parallel Example: User Story 1 (Search)

```text
Task: "AuditSearchTest"                  (T012)
Task: "AuditValidationTest"              (T013)
Task: "AuditEntryPolicyTest"             (T014)
Task: "Translations"                     (T015)
```

## Parallel Example: Polish

```text
Task: "AuditAuthorizationTest"           (T045)
Task: "AuditPrivacyTest"                 (T046)
Task: "AuditMessagesTest"                (T047)
Task: "Architecture, roadmap, README"    (T048)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (table, change detection, recording service). Nothing is recorded yet, and the suite stays green.
2. Phase 3 US1: search, then Patients and Payments (T012–T023) → **stop and validate**: a discount and a patient update can be traced. Then the other modules one at a time, each with its own suite passing, and the concurrency group after each module that has locks.
3. Phase 4 US2 → who opened what, and refused attempts.

### Incremental Delivery

1. US1 → who changed what (MVP).
2. US2 → who opened what: answers a privacy complaint.
3. US3 → sign-ins and staff accounts.
4. US4 → search recording, proof of immutability and full coverage.
5. Polish → authorization, privacy proof, messages, docs, measurement, quickstart, verification.

### Stop and Report If

- A concurrency test deadlocks or fails after recording is added (T023, T028, T029, T032, T040, T041): research R10's lock reasoning would be wrong.
- `RecordRefusedOpening` cannot see a Form Request's authorization failure (T037): research R7 would be wrong, and D4 needs revisiting.
- An earlier feature's test fails and the cause is not an obvious slip in the new call: the change has altered behavior (FR-015).
- SC-002 is missed at 5,000,000 entries (T050): report it, with the query, and do not add indexes or change paging without approval.
