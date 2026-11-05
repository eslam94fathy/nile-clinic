---
description: "Task list for feature 010: Reports"
---

# Tasks: Reports

**Input**: Design documents from `specs/010-reports/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/reports.openapi.yaml](contracts/reports.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are written first and
must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own. Phase order follows
priority: US1 (P1), US2 (P1), US3 (P2), US4 (P3).

**Decisions**: These tasks follow decisions D1–D5 in the plan, approved by the user on 2026-11-05.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US4)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Reports/`. Mirror `app/Modules/Payments/` for structure, docblock style, and naming; read the
  matching file there before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`.
- **Fixed clock in tests**: every Feature test calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`, unless a task gives other instants.
- **Records in tests**:
  - `QueueEntry::factory()` with `withDoctor()`, `seen()`, and `left()`. It defaults to queue number 1, so use `number(int $n)` for a
    second entry of the same doctor and day.
  - `Charge::factory()->forEntry($entry)` with `fee()`, `paid()`, `partiallyPaid()`, and `cancelled()`. Factories never update a charge's figures.
  - `Payment` and `Refund` factories accept `created_at` directly in `create([...])`, and so does `Patient`.
  - `Appointment::factory()` has `at()`, `cancelled()`, and `noShow()`; pass `status` for `checked_in` and `completed`.
  - `Encounter::factory()->forEntry($entry)` with `finalized()`.
- **Money in tests**: compare the API's strings with `assertSame('150.00', ...)`, never as floats.
- **Never do arithmetic on floats or money strings**: only whole piastres through `Payments\Support\Money` (research R6).
- **Query builder only** in the repository: no Eloquent models, and no clinical, patient-detail, or reason column in any `select`
  ([data-model.md](data-model.md), "What each report reads"). The repository has no method that writes.
- **Synthetic data only** (Constitution XII). No new log lines; no patient data or amounts in exception messages.
- **Branch per phase**: create a new git branch for each phase from the previous one: `010-phase-2-foundational`,
  `010-phase-3-us1-money`, `010-phase-4-us2-billing`, `010-phase-5-us3-activity`, `010-phase-6-us4-times`, `010-phase-7-polish`.

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/`tinker`/`test`, `composer test`, Pint,
`postman spec lint`) are listed. If a command does not behave as written, **stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Confirm the baseline on branch `010-reports`, and commit the design documents in `specs/010-reports/`
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the twenty-one existing migrations `Ran`
    - `docker compose exec app composer test` → 1476 passed (recorded in `specs/009-payments/verification.md`)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Negative money (D5), the indexes (D3), the module skeleton, translations, authorization, validation, and calculations.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Add negative cases to `tests/Unit/Payments/MoneyTest.php` — failing first (R5)
  - Scope: a new method `test_from_piastres_keeps_the_sign`: -150 → `'-1.50'`; -50 → `'-0.50'`; -5 → `'-0.05'`; -15000 → `'-150.00'`. Existing cases stay
    exactly as they are.
  - Verify: `docker compose exec app php artisan test --filter=MoneyTest` → the new method fails (today: `-1.-50`).

- [X] T003 Make `fromPiastres` handle a sign in `app/Modules/Payments/Support/Money.php` (R5, approved D5)
  - Scope: format the absolute value with `sprintf('%s%d.%02d', $piastres < 0 ? '-' : '', intdiv(abs($piastres), 100), abs($piastres) % 100)`. Replace the
    docblock's "Amounts are never negative" with: stored amounts are never negative, but a report's net can be. `toPiastres` does not change.
  - Verify: `docker compose exec app php artisan test tests/Unit/Payments tests/Feature/Payments` → all pass, with no existing test changed.
  - Depends: T002

- [X] T004 [P] Write `tests/Feature/Reports/ReportIndexesTest.php`, then create `database/migrations/<timestamp>_add_report_indexes.php` (R3, approved D3)
  - Scope: `make:migration add_report_indexes`, with a one-line comment pointing to `specs/010-reports/data-model.md`. Exactly:
    `index('created_at', 'payments_created_at_index')` on `payments`, `index('created_at', 'refunds_created_at_index')` on `refunds`, and
    `index('created_at', 'patients_created_at_index')` on `patients`. `down` drops the three. Test: `Schema::hasIndex` for each name.
  - Verify: `--filter=ReportIndexesTest` fails first; `docker compose exec app php artisan migrate` → `DONE`; then passes.

- [X] T005 Create the module skeleton: `app/Modules/Reports/Providers/ReportsServiceProvider.php`, `app/Modules/Reports/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: provider mirrors `PaymentsServiceProvider`, loading routes and the translations namespace `reports` (the binding and gate are added as they are created).
    Routes file: header comment plus an empty `Route::middleware('auth:sanctum')->group(...)`. Add `ReportsServiceProvider::class` after
    `PaymentsServiceProvider::class`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.

- [X] T006 [P] Create the translations `app/Modules/Reports/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('reports::messages.<key>')`), en / ar:
    - `range_too_long`: "The period cannot be longer than :days days." / "لا يمكن أن تتجاوز الفترة :days يومًا."
    - attributes en / ar: `from` "from date" / "تاريخ البداية"; `to` "to date" / "تاريخ النهاية"; `doctor_id` "doctor" / "الطبيب".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T005

- [X] T007 Write `tests/Feature/Reports/ReportsPolicyTest.php`, then create `app/Modules/Reports/Policies/ReportPolicy.php` (R9, FR-001)
  - Scope: `view(User $actor): bool` → `$actor->is_active && $actor->role === Role::Administrator`. In the provider's `boot()`:
    `Gate::define('view-reports', [ReportPolicy::class, 'view'])`. Test: every role, active and inactive, with `Gate::forUser($actor)->allows('view-reports')`, as
    `PaymentsPolicyTest`; only the active administrator is allowed.
  - Verify: `--filter=ReportsPolicyTest` → fails before the policy exists, passes after.
  - Depends: T005

- [X] T008 Create `app/Modules/Reports/Http/Requests/ReportRequest.php` (R10, FR-002, FR-003)
  - Scope: `authorize()` → `$this->user()->can('view-reports')`. Rules, as `ListChargesRequest`:
    - `from`: `required`, `date_format:Y-m-d`; `to`: `required`, `date_format:Y-m-d`, `after_or_equal:from`;
    - `doctor_id`: `nullable`, `integer`, `min:1`; `page`: `nullable`, `integer`, `min:1`; `per_page`: `nullable`, `integer`, `min:1`, `max:100`.
    `attributes()` from `reports::attributes`. `private const MAX_RANGE_DAYS = 366`. `after()`: if there are no errors yet and
    `CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > 366`, add `reports::messages.range_too_long` (`days` 366) on `to`. Accessors `from(): string`,
    `to(): string`, `doctorId(): ?int`, and `perPage(): int` (default 15).
  - Accept: covered by T013.
  - Depends: T006, T007

- [X] T009 [P] Write `tests/Unit/Reports/ReportMathTest.php`, then create `app/Modules/Reports/Support/ReportMath.php` (R8)
  - Scope: `final class` with static methods, integers only until the final division:
    - `averageMinutes(int $seconds, int $count): ?int` — `null` if `$count` is 0, else `intdiv(2 * $seconds + 60 * $count, 120 * $count)`. Cases: (90, 1) → 2; (89, 1) → 1; (300, 2) → 3;
      (0, 3) → 0; (500, 0) → `null`.
    - `noShowRate(int $noShow, int $checkedIn, int $completed): ?float` — `$base` is their sum; `null` if 0, else `intdiv(2000 * $noShow + $base, 2 * $base) / 10`. Cases: (1, 7, 0) → 12.5;
      (1, 2, 0) → 33.3; (2, 1, 0) → 66.7; (0, 2, 3) → 0.0; (5, 0, 0) → 100.0; (0, 0, 0) → `null`.
  - Verify: `--filter=ReportMathTest` → fails first, then passes.

- [X] T010 Run the Payments, Queues, and localization suites against `tests/Feature/Payments`, `tests/Feature/Queues`, and `tests/Feature/Localization`
  - Verify: `docker compose exec app php artisan test tests/Feature/Payments tests/Feature/Queues tests/Feature/Localization` → all pass unchanged.
  - Depends: T003, T004, T005

**Checkpoint**: Signed money, indexes, the gate, validation, calculations, and the module skeleton exist; earlier tests pass.

---

## Phase 3: User Story 1 — An administrator sees the money collected (Priority: P1) 🎯 MVP

**Goal**: `GET /reports/money-collected`.

**Independent Test**: Record payments in several methods by two staff members, refund one on a later day, request the period, and confirm that the totals and every
breakdown match by hand.

### Tests for User Story 1 (write first, must fail)

- [ ] T011 [P] [US1] Write `tests/Feature/Reports/MoneyCollectedReportTest.php` (US1-1…5, FR-007, FR-008)
  - Scope: two charges for two doctors; payments and refunds with chosen `created_at`, methods, and staff:
    - totals of `received`, `refunded`, and `net`; `by_method` in the order cash, card, wallet, with `staff` under each by name; `by_day` oldest first; all as exact strings
    - every breakdown adds up to the totals
    - a payment on one day and its refund on a later day: each day requested alone counts only its own; the earlier day's total is unchanged
    - a refund larger than the day's payments in that method → negative `net`, such as `'-49.90'` and `'-0.50'`
    - `doctor_id` counts only that doctor's charges
    - a cancelled charge's payment and refund still count and net to zero
    - a period with no money → `200`, zero totals (`'0.00'`), empty `by_method` and `by_day`
    - a method or day with no money is not listed
    - a deactivated staff member still appears
    - exact JSON structure per the contract's `MoneyCollected`, including `from` and `to`

- [ ] T012 [P] [US1] Write `tests/Feature/Reports/ClinicDayBoundaryTest.php` (SC-004, FR-008, FR-022, R4)
  - Scope: payments at these UTC instants, each day requested alone and checked in `by_day`:
    - ordinary day: `2026-11-04 21:59:00` → clinic `2026-11-04`; `2026-11-04 22:00:00` → `2026-11-05`
    - clocks forward: `2026-04-23 21:59:00` → `2026-04-23`; `2026-04-23 22:00:00` → `2026-04-24`
    - clocks back: `2026-10-29 20:30:00` and `2026-10-29 21:30:00` (the 23:30 that occurs twice) → both `2026-10-29`; `2026-10-29 21:59:00` → `2026-10-29`;
      `2026-10-29 22:00:00` → `2026-10-30`
    - refunds at the same instants behave the same way
    - a period ending on a day clocks go forward the next morning (`to=2026-04-23`) excludes `2026-04-23 22:00:00`

- [ ] T013 [P] [US1] Write `tests/Feature/Reports/ReportValidationTest.php` (FR-002, FR-003, R10)
  - Scope: a `private const ENDPOINTS` array that starts with `/api/v1/reports/money-collected`, used by a data provider so that later stories add their paths:
    - missing `from` or `to`; `from=2026-02-30`; `from=05-11-2026`; `to` before `from` → `422` on the right field
    - a period of 367 days → `422` on `to` with the range message; exactly 366 days → `200`
    - `doctor_id` of `abc` or `0` → `422`; unknown doctor → `404` with `doctors::messages.doctor_not_found`
    - a known doctor with no activity → `200` with zero totals
    - `per_page` of `0` or `101` → `422`, for paged endpoints

- [ ] T014 [P] [US1] Write `tests/Feature/Reports/MoneyExactnessReportTest.php` (SC-003, FR-019, R6)
  - Scope: bulk-insert with `DB::table(...)->insert` on one charge: 1,000 payments of `0.07` and 100 refunds of `0.03`, spread over several clinic days, the three methods, and two staff
    members. Assert `received` `'70.00'`, `refunded` `'3.00'`, and `net` `'67.00'`. Assert that the piastres of `by_method`, of `staff` within each method, and of `by_day` each add up exactly to
    those totals.

### Implementation for User Story 1

- [ ] T015 [US1] Create `app/Modules/Reports/Repositories/Contracts/ReportRepository.php` and `app/Modules/Reports/Repositories/QueryReportRepository.php` with the money methods (R2, R4, approved D2, D4)
  - Scope: `moneyReceivedByHour(string $start, string $end, ?int $doctorId): array` and `moneyRefundedByHour(...)`, each returning rows of `hour`, `method`, `staff_id`, `staff_name`, `amount`:
    - `DB::table('payments')` (or `refunds`), `join('users', ...)` on `received_by` (or `refunded_by`);
    - `where('created_at', '>=', $start)->where('created_at', '<', $end)`;
    - only when `$doctorId` is set: `join('charges', ...)` and `where('charges.doctor_id', $doctorId)`;
    - `selectRaw("DATE_FORMAT(payments.created_at, '%Y-%m-%d %H:00:00') as hour, method, ... SUM(amount) as amount")`, grouped by hour, method, staff id, staff name.
    Bind the contract in the provider's `register()`.
  - Depends: T005

- [ ] T016 [US1] Create `app/Modules/Reports/Services/ReportService.php` with `moneyCollected` (R4, R6, FR-003, FR-007, FR-008)
  - Scope: inject `ReportRepository` and `DoctorService`. `moneyCollected(string $from, string $to, ?int $doctorId): array`:
    - if `$doctorId` is set, `DoctorService::find` (404 when unknown);
    - a private `period($from, $to)`: start = `CarbonImmutable::parse("{$from} 00:00:00", ClinicClock::timezone())->utc()`; end is the same with the day after `to`, worked out on the date
      alone (`CarbonImmutable::parse($to, 'UTC')->addDay()->toDateString()`), so a clock change cannot shift it; both formatted `Y-m-d H:i:s`;
    - for every row, convert `hour` from UTC to the clinic date, `Money::toPiastres($row->amount)`, and add it to the totals, method, staff within method, and day;
    - output: `from`, `to`, `totals`, `by_method` in `PaymentMethod::cases()` order, with `staff` sorted with `strcmp` on name then id, and `by_day` sorted by date; each with `received`, `refunded`, and `net`
      through `Money::fromPiastres`.
    Read only: no transaction (R6). The service builds no query.
  - Depends: T003, T015

- [ ] T017 [US1] Create `app/Modules/Reports/Http/Resources/MoneyCollectedResource.php`, `app/Modules/Reports/Http/Controllers/ReportController.php`, and the route in `app/Modules/Reports/routes/api.php`
  - Scope: the Resource wraps the service's array in the contract's `MoneyCollected` shape. Controller action `moneyCollected(ReportRequest $request)` → `MoneyCollectedResource::make($this->reports->moneyCollected($request->from(), $request->to(), $request->doctorId()))`, and nothing else.
    Route: `GET /reports/money-collected`, name `reports.money-collected`.
  - Verify: `docker compose exec app php artisan test --filter="MoneyCollectedReportTest|ClinicDayBoundaryTest|ReportValidationTest|MoneyExactnessReportTest"` → pass.
  - Depends: T008, T016

**Checkpoint**: US1 complete. Administrators can check the money collected for any period, on the correct clinic day.

---

## Phase 4: User Story 2 — An administrator sees what was billed and what is owed, by doctor (Priority: P1)

**Goal**: `GET /reports/billing`.

**Independent Test**: Create charges for two doctors with a discount, a part payment, a cancelled charge, and a seen visit with no charge. Confirm each doctor's figures and the overall totals.

### Tests for User Story 2 (write first, must fail)

- [ ] T018 [P] [US2] Write `tests/Feature/Reports/BillingReportTest.php` (US2-1…5, FR-009–FR-011, FR-018)
  - Scope:
    - per doctor and in `totals`: `active_charges`, `fees`, `discounts`, `amount_due`, `amount_paid`, `balance`, `cancelled_charges`, `uncharged_seen_visits`, exact strings and integers
    - a cancelled charge in no money figure, only in `cancelled_charges`
    - a fully discounted charge: its fee and discount count, `amount_due` and `balance` `'0.00'`
    - a charge counts by its own `date`, whenever it was created
    - changing a charge's discount or `amount_paid` and requesting again shows the current figures
    - uncharged: a `seen` visit with no charge, and one with only a cancelled charge, both count; a `seen` visit with an active charge, `waiting`, `with_doctor`, and `left` do not
    - an existing active charge on a `left` visit counts in the money figures
    - a doctor with only uncharged visits appears; a doctor with no activity does not
    - `doctor_id` filters every figure
    - `per_page=1`: `meta.total` is 2, doctors ordered by account name, and `totals` covers both on each page
    - a deactivated doctor with activity appears
    - exact JSON structure per the contract, with `doctor` as `{id, name, title}`
  - Add `/api/v1/reports/billing` to `ENDPOINTS` in `tests/Feature/Reports/ReportValidationTest.php`.

### Implementation for User Story 2

- [ ] T019 [US2] Add `billingByDoctor`, `unchargedSeenVisitsByDoctor`, and `doctorsByName` to `app/Modules/Reports/Repositories/Contracts/ReportRepository.php` and `QueryReportRepository.php` (R7, R8)
  - Scope, each with `from`, `to`, and `?doctorId` unless noted:
    - `billingByDoctor`: `charges` where `date` is in the range, grouped by `doctor_id`, with `SUM(status <> 'cancelled') as active_charges`, `SUM(status = 'cancelled') as cancelled_charges`, and
      `SUM(CASE WHEN status <> 'cancelled' THEN <column> ELSE 0 END)` as `fees`, `discounts`, and `amount_paid`. Use `ChargeStatus::Cancelled->value`.
    - `unchargedSeenVisitsByDoctor`: `queue_entries` with status `QueueStatus::Seen->value`, `leftJoin('charges', ...)` on `queue_entry_id` and `charges.active_flag = 1`, `whereNull('charges.id')`, count grouped by `doctor_id`.
    - `doctorsByName(array $ids)`: `doctors` joined to `users` on `user_id`, selecting `doctors.id`, `doctors.title`, and `users.name`; `orderBy('users.name')->orderBy('doctors.id')`.
  - Depends: T015

- [ ] T020 [US2] Add `billing` and a private per-doctor paging helper to `app/Modules/Reports/Services/ReportService.php` (R6, R7)
  - Scope: `billing(string $from, string $to, ?int $doctorId, int $perPage): array{rows: LengthAwarePaginator, totals: array}`:
    - find the doctor if a filter is sent; merge the two queries by doctor id, in piastres; `amount_due = fees - discounts`; `balance = amount_due - amount_paid`;
    - totals are added up from the rows, across all doctors, and are zero when there are none;
    - helper: from the ids, read `doctorsByName`, attach `doctor` to each row in that order, and slice with
      `new LengthAwarePaginator(array_slice(...), $total, $perPage, Paginator::resolveCurrentPage(), ['path' => Paginator::resolveCurrentPath()])`, then `withQueryString()`.
  - Depends: T016, T019

- [ ] T021 [US2] Create `app/Modules/Reports/Http/Resources/BillingRowResource.php`, and add the action to `app/Modules/Reports/Http/Controllers/ReportController.php` and the route to `app/Modules/Reports/routes/api.php`
  - Scope: `billing(ReportRequest $request)` → `BillingRowResource::collection($report['rows'])->additional(['totals' => $report['totals']])`. Route: `GET /reports/billing`, name `reports.billing`.
  - Verify: `docker compose exec app php artisan test --filter="BillingReportTest|ReportValidationTest"` → pass.
  - Depends: T018, T020

**Checkpoint**: US1 and US2 complete: **the MVP**. Administrators see money collected, what is owed, and which seen visits were never charged.

---

## Phase 5: User Story 3 — An administrator sees how busy the clinic was (Priority: P2)

**Goal**: `GET /reports/activity`.

**Independent Test**: Create appointments in each status and visits of each kind for two doctors, then confirm the counts and the no-show rate.

### Tests for User Story 3 (write first, must fail)

- [ ] T022 [P] [US3] Write `tests/Feature/Reports/ActivityReportTest.php` (US3-1…4, FR-012–FR-014)
  - Scope:
    - per doctor and in `totals`: appointments in each of `scheduled`, `cancelled`, `no_show`, `checked_in`, `completed`, with zero for the rest
    - `no_show_rate`: `1` no-show, `5` checked in, `2` completed → `12.5` (compare with `assertEquals`); with only `scheduled` and `cancelled` → `null`; `totals` calculated from the counts, not from the doctors' rates
    - visits in each of `waiting`, `with_doctor`, `seen`, `left`; `booked` and `walk_in`
    - an undone check-in (`DELETE /api/v1/queue/{entry}`, as an administrator) leaves no visit, and its appointment counts as `scheduled`
    - a past `scheduled` appointment counts as `scheduled`, not as a no-show
    - a rescheduled appointment counts once, on its current date
    - a period with future dates shows upcoming appointments
    - `totals.new_patients` counts patients by clinic day: `created_at` of `2026-11-04 21:59:00` and `2026-11-04 22:00:00`, with `from=to=2026-11-05` → 1
    - with `doctor_id`, `new_patients` is absent from `totals` (`assertJsonMissingPath`)
    - paging keeps `totals`; exact JSON structure per the contract
  - Add `/api/v1/reports/activity` to `ENDPOINTS` in `tests/Feature/Reports/ReportValidationTest.php`.

### Implementation for User Story 3

- [ ] T023 [US3] Add `appointmentsByDoctor`, `visitsByDoctor`, and `patientsRegistered` to `app/Modules/Reports/Repositories/Contracts/ReportRepository.php` and `QueryReportRepository.php`
  - Scope:
    - `appointmentsByDoctor(from, to, ?doctorId)`: `appointments` by `date`, with `doctor_id`, `status`, `COUNT(*)`, grouped by both.
    - `visitsByDoctor(from, to, ?doctorId)`: `queue_entries` by `date`, with `doctor_id`, `status`, `COUNT(*)`, and `COUNT(appointment_id) as booked`, grouped by both.
    - `patientsRegistered(string $start, string $end): int`: count of `patients` with `created_at` in those instants.
  - Depends: T015

- [ ] T024 [US3] Add `activity` to `app/Modules/Reports/Services/ReportService.php`
  - Scope: merge by doctor, with every status present as 0, using `AppointmentStatus` and `QueueStatus` values. `walk_in` = visits − booked. `no_show_rate` through `ReportMath::noShowRate`, for rows and for `totals` (FR-012). `totals.new_patients` only when
    `$doctorId` is null, using the private `period()`. Reuse the paging helper.
  - Depends: T009, T020, T023

- [ ] T025 [US3] Create `app/Modules/Reports/Http/Resources/ActivityRowResource.php`, and add the action to `app/Modules/Reports/Http/Controllers/ReportController.php` and the route to `app/Modules/Reports/routes/api.php`
  - Scope: as T021. Route: `GET /reports/activity`, name `reports.activity`.
  - Verify: `docker compose exec app php artisan test --filter="ActivityReportTest|ReportValidationTest"` → pass.
  - Depends: T022, T024

**Checkpoint**: US3 complete. Administrators can see appointment outcomes, walk-ins, and new patients.

---

## Phase 6: User Story 4 — An administrator sees visit times and unfinished records (Priority: P3)

**Goal**: `GET /reports/times`.

**Independent Test**: Create visits with known arrival, call, and finish times, and seen visits with a finalized record, a draft, and no record. Confirm the averages and the three counts.

### Tests for User Story 4 (write first, must fail)

- [ ] T026 [P] [US4] Write `tests/Feature/Reports/TimesReportTest.php` (US4-1…4, FR-015–FR-017)
  - Scope:
    - `wait` from known `arrived_at` and `called_at`, and `consultation` from `called_at` and `finished_at`, per doctor, with `average_minutes` as integers and `visits` as counts
    - rounding: an average of 90 seconds → `2`
    - `totals`: doctor A with 1 visit of 10 minutes and doctor B with 3 visits of 2 minutes → 4 minutes overall, not the 6 an average of averages gives
    - a `waiting` visit in neither average; a `with_doctor` visit in `wait` only
    - no covered visits → `average_minutes` `null` and `visits` 0
    - `records`: a `seen` visit with a finalized encounter, one with a draft, and one with none → 1, 1, 1, adding up to the seen visits
    - a draft on a `with_doctor` visit is not counted
    - paging keeps `totals`; exact JSON structure per the contract
  - Add `/api/v1/reports/times` to `ENDPOINTS` in `tests/Feature/Reports/ReportValidationTest.php`.

### Implementation for User Story 4

- [ ] T027 [US4] Add `visitTimesByDoctor` and `recordsByDoctor` to `app/Modules/Reports/Repositories/Contracts/ReportRepository.php` and `QueryReportRepository.php` (R8)
  - Scope:
    - `visitTimesByDoctor(from, to, ?doctorId)`: `queue_entries` by `date`, grouped by `doctor_id`, with `COUNT(called_at) as wait_visits`, `SUM(TIMESTAMPDIFF(SECOND, arrived_at, called_at)) as wait_seconds`,
      `SUM(called_at IS NOT NULL AND finished_at IS NOT NULL) as consultation_visits`, and `SUM(TIMESTAMPDIFF(SECOND, called_at, finished_at)) as consultation_seconds`. `TIMESTAMPDIFF` gives NULL where a time is missing, and `SUM` ignores it.
    - `recordsByDoctor(from, to, ?doctorId)`: `queue_entries` with status `seen`, `leftJoin('encounters', ...)` on `queue_entry_id`, grouped by `doctor_id` and `encounters.status`, selecting `doctor_id`, `encounters.status as record_status`, and `COUNT(*)`. **No other `encounters` column** (FR-017).
  - Depends: T015

- [ ] T028 [US4] Add `times` to `app/Modules/Reports/Services/ReportService.php`
  - Scope: merge by doctor; cast sums to `int` (a null sum → 0). Averages through `ReportMath::averageMinutes`, and `totals` from total seconds over total count (R8). `records`: `finalized`, `draft`, and `none` (null `record_status`). Reuse the paging helper.
  - Depends: T009, T020, T027

- [ ] T029 [US4] Create `app/Modules/Reports/Http/Resources/TimesRowResource.php`, and add the action to `app/Modules/Reports/Http/Controllers/ReportController.php` and the route to `app/Modules/Reports/routes/api.php`
  - Scope: as T021. Route: `GET /reports/times`, name `reports.times`.
  - Verify: `docker compose exec app php artisan test --filter="TimesReportTest|ReportValidationTest"` → pass.
  - Depends: T026, T028

**Checkpoint**: All four reports work.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Authorization, privacy, read-only proof, messages, docs, measurement, and verification.

- [ ] T030 [P] Write `tests/Feature/Reports/ReportsAuthorizationTest.php` (SC-006, FR-001, FR-020)
  - Scope: as `PaymentsAuthorizationTest`, for all four endpoints: administrator `200`; receptionist and doctor `403` with `auth.forbidden`, **also** with invalid dates and with an unknown `doctor_id`, proving that they are refused before validation and lookup; no token `401`;
    a deactivated account's token `401`.
  - Verify: `--filter=ReportsAuthorizationTest` → pass.

- [ ] T031 [P] Write `tests/Feature/Reports/ReportPrivacyTest.php` (SC-005, FR-004, FR-017, R11)
  - Scope: create a patient with unique synthetic values in name, patient number, phone, national ID, address, and emergency contact; an appointment note, cancellation reason, discount reason, refund reason, and cancel reason; and encounter text in every clinical field. Request all four reports across the period, and assert that none of the planted values appears in any response body. Then
    overwrite the clinical columns with `DB::table('encounters')->update(['diagnosis' => 'not-ciphertext', ...])`, and assert that `/reports/times` still returns `200` with correct counts, proving that they are never decrypted.
  - Verify: `--filter=ReportPrivacyTest` → pass.

- [ ] T032 [P] Write `tests/Feature/Reports/ReportReadOnlyTest.php` (SC-007, FR-005)
  - Scope: with records of every kind, snapshot the row counts and `max(updated_at)` of every table the reports read, request all four reports, and assert that nothing changed. With `DB::listen`, assert that every statement starts with `select`.
  - Verify: `--filter=ReportReadOnlyTest` → pass.

- [ ] T033 [P] Write `tests/Feature/Reports/ReportsMessagesTest.php` (SC-008, FR-023)
  - Scope: as `PaymentsMessagesTest`, for `en` and `ar`: `range_too_long` with its day count, validation messages using the translated attributes, the unknown doctor message, and `auth.forbidden`. For each report, the JSON keys are identical in both locales, and statuses and methods are never translated.
  - Verify: `--filter=ReportsMessagesTest` → pass.

- [ ] T034 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: Roadmap: 010 `Done` with the spec link; Audit logs `Next`. README: a short "Reports" section after "Payments": the four reports, administrators only, read only, periods of up to 366 days, money collected counts on the clinic day it was recorded while billing shows charges as they stand now, and no patient identifiers; link the contract.
  - Verify: `postman spec lint specs/010-reports/contracts/reports.openapi.yaml --issueType=syntax` → no issues.

- [ ] T035 Run Pint and the full suite over `app/Modules/Reports/` and `tests/`
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
  - Depends: T030, T031, T032, T033, T034

- [ ] T036 Measure SC-001 and SC-002 on `clinic_testing` only, and keep the figures for `specs/010-reports/verification.md`
  - Scope: as feature 009's T047: in a transaction rolled back afterwards, bulk-insert 500 factory doctors, 2,000 patients, 500,000 queue entries with their charges, one payment per charge, 5,000 refunds, 400,000 encounters, and 600,000 appointments, spread over 366 days. Call the `ReportService` methods, which include the PHP calculation, three runs each: a day's money collected (target under 2 s), and each of the four reports over 366 days (target under 5 s). Refuse any database but `clinic_testing`; confirm 0 rows after the rollback. A miss is reported, not hidden, and stored summaries need separate approval (plan: Risks).
  - Depends: T035

- [ ] T037 Run quickstart Q1–Q8 from `specs/010-reports/quickstart.md` on the development stack
  - Scope: synthetic data only. Record what was run.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T035

- [ ] T038 Record the results in `specs/010-reports/verification.md`
  - Scope: per Q-step and SC-001/SC-002: date, command, observed output summary, pass/fail; failures reported as failures (Constitution VIII). The Postman collection update is a separate follow-up.
  - Depends: T036, T037

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 → T003; T005 → T006, T007 → T008; T010 last.
- **US1 (Phase 3)**: after Foundational. Creates the repository, service, and controller.
- **US2 (Phase 4)**: after US1 (those files, and `ReportValidationTest`). Creates the paging helper.
- **US3 (Phase 5)**: after US2 (the paging helper).
- **US4 (Phase 6)**: after US2 (the paging helper). Independent of US3, but both edit the same service and repository files, so run them one after the other.
- **Polish (Phase 7)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → resource, controller action, and route.

### Parallel Opportunities

- Foundational: T004, T006, and T009 alongside each other (T006 after T005).
- US1: T011, T012, T013, and T014 together.
- Polish: T030, T031, T032, T033, and T034 together.

Only one test task opens each of US2, US3, and US4, and their implementation tasks edit shared files, so within those stories the tasks run in order.

---

## Parallel Example: User Story 1

```text
Task: "MoneyCollectedReportTest"         (T011)
Task: "ClinicDayBoundaryTest"            (T012)
Task: "ReportValidationTest"             (T013)
Task: "MoneyExactnessReportTest"         (T014)
```

## Parallel Example: Polish

```text
Task: "ReportsAuthorizationTest"         (T030)
Task: "ReportPrivacyTest"                (T031)
Task: "ReportReadOnlyTest"               (T032)
Task: "ReportsMessagesTest"              (T033)
Task: "Roadmap and README"               (T034)
```

---

## Implementation Strategy

### MVP First (User Stories 1 and 2)

1. Phase 1 Setup → Phase 2 Foundational (signed money, indexes, gate, validation).
2. Phase 3 US1 → **stop and validate**: money collected matches by hand, on the correct clinic day across both clock changes. This alone is usable for checking the day's cash.
3. Phase 4 US2 → what is owed and which visits were never charged.

### Incremental Delivery

1. US1 + US2 → the financial reports (MVP).
2. US3 → activity and no-show rate.
3. US4 → visit times and unfinished records.
4. Polish → authorization matrix, privacy and read-only proof, messages, docs, measurement, quickstart, verification.
