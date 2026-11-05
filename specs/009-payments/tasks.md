---
description: "Task list for feature 009: Payments"
---

# Tasks: Payments

**Input**: Design documents from `specs/009-payments/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/payments.openapi.yaml](contracts/payments.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and XI, and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own. Phase
order follows priority: US1 (P1), US2 (P1), US3 (P2), US4 (P3), US5 (P3).

**Decisions**: These tasks follow decisions D1–D4 in the plan, approved by the user on 2026-11-05.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Payments/`. Mirror `app/Modules/Queues/` and `app/Modules/Encounters/` for
  structure, docblock style, and naming; read the matching file there before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`.
- **Fixed clock in tests**: every Feature test calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`, and visits for
  "today" use `ClinicClock::today()`. **Exception**: the concurrency tests run child processes that do not
  see `travelTo`, so they use the real clinic date (feature 006 R9).
- **Visits in tests**: `QueueEntry::factory()` with its `withDoctor()`, `seen()`, and `left()` states. It
  defaults to queue number 1, so use `number(int $n)` for a second entry of the same doctor and day.
- **Money in tests**: compare the API's strings with `assertSame('150.00', ...)`, never as floats.
- **Never do arithmetic on floats or money strings**: only through `Payments\Support\Money` (research R2).
- **Synthetic data only** (Constitution XII). No new log lines; no patient data or amounts in exception
  messages.
- **Branch per phase**: create a new git branch for each phase from the previous one (for example
  `009-phase-2-foundational`, `009-phase-3-us1-fees`).

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/`tinker`/`test`,
`composer test`, Pint) are listed. If a command does not behave as written, **stop and report**; don't
substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Create branch `009-payments` from `main`, and confirm the baseline
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the seventeen existing migrations `Ran`
    - `docker compose exec app composer test` → 1194 passed (recorded in
      `specs/008-medical-encounters/verification.md`)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Exact money (D1), the schema with its database rules (D2), the module skeleton, translations,
exceptions, and policies.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Write `tests/Unit/Payments/MoneyTest.php` — failing first (SC-004, R2)
  - Scope: `toPiastres`: `'150.00'` → 15000; `'150.5'` → 15050; `'150'` → 15000; `150` → 15000; `150.25` →
    15025; `'0.07'` → 7; `'.5'` → 50; `'5.'` → 500; `'100000'` → 10000000. `fromPiastres`: 15000 →
    `'150.00'`; 7 → `'0.07'`; 0 → `'0.00'`; 5 → `'0.05'`. `due('400.00', '150.00')` → `'250.00'`;
    `balance('400.00', '150.00', '100.10')` → `'149.90'`. Adding `'0.10'` and `'0.20'` through piastres gives
    `'0.30'`.
  - Verify: `docker compose exec app php artisan test --filter=MoneyTest` → fails

- [X] T003 Create `app/Modules/Payments/Support/Money.php` (R2, approved D1)
  - Scope: `final class` with static methods, no state:
    - `toPiastres(string|int|float $amount): int` — `(string) $amount`, split on `.`, fractional part padded
      to two digits; integer arithmetic only, never a float multiply.
    - `fromPiastres(int $piastres): string` — `sprintf('%d.%02d', intdiv($piastres, 100), $piastres % 100)`.
    - `due(string $fee, string $discount): string`; `balance(string $fee, string $discount, string $paid): string`.
    Docblock: why floats are never used (`0.1 + 0.2 != 0.3`). Validation has already limited amounts to two
    decimals, so this does not validate.
  - Accept: T002 passes.
  - Depends: T002

- [X] T004 Create the migrations `database/migrations/<timestamp>_create_doctor_fees_table.php`, `<timestamp>_create_charges_table.php`, `<timestamp>_create_payments_table.php`, `<timestamp>_create_refunds_table.php` (data-model.md, approved D2, D4)
  - Scope: four `make:migration` calls, in this order; each with a one-line comment pointing to
    `specs/009-payments/data-model.md`. `CHECK`s use `DB::statement('ALTER TABLE … ADD CONSTRAINT …')` after
    `Schema::create`. Exactly:
    - `doctor_fees`: `id()`; `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`;
      `decimal('amount', 10, 2)`; `foreignId('set_by')->constrained('users')->restrictOnDelete()`;
      `timestamps()`; `unique(['doctor_id'], 'doctor_fees_doctor_unique')`; check
      `doctor_fees_amount_check (amount > 0 AND amount <= 100000)`.
    - `charges`: `id()`; `foreignId('queue_entry_id')->nullable()->constrained('queue_entries')->nullOnDelete()`;
      `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`;
      `foreignId('patient_id')->constrained('patients')->restrictOnDelete()`; `date('date')`;
      `string('status', 20)` — "`unpaid` \| `partially_paid` \| `paid` \| `cancelled`";
      `unsignedTinyInteger('active_flag')->nullable()->storedAs("IF(status <> 'cancelled', 1, NULL)")`;
      `decimal('fee', 10, 2)`; `decimal('discount', 10, 2)->default(0)`; `decimal('amount_paid', 10, 2)->default(0)`;
      `string('discount_reason', 500)->nullable()`;
      `foreignId('discounted_by')->nullable()->constrained('users')->restrictOnDelete()`;
      `timestamp('discounted_at')->nullable()`; `foreignId('charged_by')->constrained('users')->restrictOnDelete()`;
      `string('cancel_reason', 500)->nullable()`;
      `foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete()`;
      `timestamp('cancelled_at')->nullable()`; `timestamps()`;
      `unique(['queue_entry_id', 'active_flag'], 'charges_visit_active_unique')`;
      `index(['date', 'id'], 'charges_date_index')`; `index(['patient_id', 'date', 'id'], 'charges_patient_index')`;
      `index(['doctor_id', 'date', 'id'], 'charges_doctor_index')`; check
      `charges_money_check (discount >= 0 AND amount_paid >= 0 AND discount + amount_paid <= fee AND fee > 0)`.
    - `payments`: `id()`; `foreignId('charge_id')->constrained('charges')->restrictOnDelete()`;
      `decimal('amount', 10, 2)`; `string('method', 20)` — "`cash` \| `card` \| `wallet`";
      `foreignId('received_by')->constrained('users')->restrictOnDelete()`;
      `timestamp('created_at')->nullable()`; check `payments_amount_check (amount > 0)`.
    - `refunds`: `id()`; `foreignId('charge_id')->constrained('charges')->restrictOnDelete()`;
      `decimal('amount', 10, 2)`; `string('method', 20)`; `string('reason', 500)`;
      `foreignId('refunded_by')->constrained('users')->restrictOnDelete()`;
      `timestamp('created_at')->nullable()`; check `refunds_amount_check (amount > 0)`.
  - Verify: `docker compose exec app php artisan migrate` → all four `DONE`.

- [X] T005 [P] Create `app/Modules/Payments/Enums/ChargeStatus.php` and `app/Modules/Payments/Enums/PaymentMethod.php`
  - Scope: `ChargeStatus`: `Unpaid = 'unpaid'`, `PartiallyPaid = 'partially_paid'`, `Paid = 'paid'`,
    `Cancelled = 'cancelled'`. `PaymentMethod`: `Cash = 'cash'`, `Card = 'card'`, `Wallet = 'wallet'`. Each has
    `static values(): list<string>`. `ChargeStatus` docblock: the status follows from the figures, except
    `cancelled`, which is final (research R3).

- [X] T006 Create the models in `app/Modules/Payments/Models/`: `DoctorFee.php`, `Charge.php`, `Payment.php`, `Refund.php`
  - Scope (persistence mapping only, as `Queues\Models\QueueEntry`), each with `HasFactory`:
    - `DoctorFee`: fillable `doctor_id`, `amount`, `set_by`; cast `amount` → `decimal:2`; `doctor()`, `setBy()`.
    - `Charge`: fillable every column except `id`, `active_flag`, and the timestamps; casts `date` → `date`,
      `status` → `ChargeStatus`, `fee`, `discount`, `amount_paid` → `decimal:2`, `discounted_at`,
      `cancelled_at` → `datetime`; `queueEntry()`, `doctor()`, `patient()`, `chargedBy()`, `discountedBy()`,
      `cancelledBy()`, `payments()` and `refunds()` (each `orderBy('id')`).
    - `Payment`: `const UPDATED_AT = null`; fillable `charge_id`, `amount`, `method`, `received_by`; casts
      `amount` → `decimal:2`, `method` → `PaymentMethod`; `charge()`, `receivedBy()`.
    - `Refund`: `const UPDATED_AT = null`; fillable `charge_id`, `amount`, `method`, `reason`, `refunded_by`;
      same casts; `charge()`, `refundedBy()`.
  - Depends: T004, T005

- [X] T007 [P] Create factories in `database/factories/`: `DoctorFeeFactory.php`, `ChargeFactory.php`, `PaymentFactory.php`, `RefundFactory.php` (synthetic only)
  - Scope: `ChargeFactory` defaults to an `unpaid` charge of `300.00` for a new `QueueEntry::factory()->seen()`,
    copying `doctor_id`, `patient_id`, and `date` from the entry (as `EncounterFactory`), `charged_by` a
    receptionist. States: `forEntry(QueueEntry $e)`; `fee(string $amount)`; `paid()` (`amount_paid` = fee, status
    `paid`); `cancelled()`. `DoctorFeeFactory`: `350.00`. `PaymentFactory`: `100.00`, `cash`. `RefundFactory`:
    `50.00`, `cash`, reason `Synthetic refund`. Factories never update a charge's figures; a docblock says so.
  - Depends: T006

- [X] T008 Write `tests/Feature/Payments/PaymentsPersistenceTest.php` (R3, R4, Constitution XI)
  - Scope: `RefreshDatabase`:
    - directly with `DB::table('charges')->update`: paid above the fee, discount plus paid above the fee, and
      negative paid → `QueryException`; a fee of 0 → `QueryException`
    - a zero or negative payment or refund, and a doctor fee of 0 or 100000.01 → `QueryException`
    - a second active charge on one visit → `UniqueConstraintViolationException`; two cancelled charges → allowed
    - a second `doctor_fees` row for one doctor → violation
    - deleting a visit whose charge is cancelled → the row stays, `queue_entry_id` null, patient, doctor, and
      date kept
    - deleting a charge with a payment, and a doctor, patient, or user referenced by a charge → `QueryException`
    - `'99999.99'` and `'0.07'` round-trip as exactly those strings; `active_flag` is `1` for `unpaid` and null
      for `cancelled`
  - Verify: `docker compose exec app php artisan test --filter=PaymentsPersistenceTest` → pass
  - Depends: T007

- [X] T009 Create the module skeleton: `app/Modules/Payments/Providers/PaymentsServiceProvider.php`, `app/Modules/Payments/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: provider mirrors `EncountersServiceProvider`, loading routes and the translations namespace
    `payments` (bindings and policies are added as they are created). Routes file: header comment plus an empty
    `Route::middleware('auth:sanctum')->group(...)`. Add `PaymentsServiceProvider::class` after
    `EncountersServiceProvider::class`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.

- [X] T010 [P] Create the translations `app/Modules/Payments/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('payments::messages.<key>')`), en / ar:
    - `charge_not_found`: "No charge was found." / "لم يتم العثور على المطالبة."
    - `visit_not_found`: "No visit was found." / "لم يتم العثور على الزيارة."
    - `visit_left`: "This patient left before being seen, so the visit cannot be charged." / "غادر هذا المريض قبل الكشف، لذا لا يمكن احتساب رسوم لهذه الزيارة."
    - `already_charged`: "This visit has already been charged." / "تم احتساب رسوم هذه الزيارة بالفعل."
    - `no_fee`: "This doctor has no consultation fee yet." / "لم يتم تحديد رسوم كشف لهذا الطبيب بعد."
    - `above_balance`: "The amount is more than the balance owed." / "المبلغ أكبر من الرصيد المستحق."
    - `above_paid`: "The amount is more than what has been paid." / "المبلغ أكبر من المبلغ المدفوع."
    - `discount_too_large`: "The discount is more than the fee less what has been paid." / "الخصم أكبر من الرسوم بعد طرح المبلغ المدفوع."
    - `money_held`: "A charge with money paid cannot be cancelled. Record a refund first." / "لا يمكن إلغاء مطالبة عليها مبلغ مدفوع. سجّل استردادًا أولًا."
    - `charge_cancelled`: "This charge has been cancelled." / "تم إلغاء هذه المطالبة."
    - attributes en / ar: `amount` "amount" / "المبلغ"; `method` "payment method" / "طريقة الدفع"; `reason` "reason" /
      "السبب"; `date` "date" / "التاريخ"; `from` "from date" / "تاريخ البداية"; `to` "to date" / "تاريخ النهاية";
      `doctor_id` "doctor" / "الطبيب"; `patient_id` "patient number" / "رقم المريض"; `charge_status` "charge status" /
      "حالة المطالبة"; `status` "status" / "الحالة"; `visit` "visit" / "الزيارة"; `charge` "charge" / "المطالبة".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [X] T011 [P] Create `app/Modules/Payments/Exceptions/ChargeNotFoundException.php` and `VisitNotFoundException.php`
  - Scope: exactly like `Encounters\Exceptions\VisitNotFoundException`: 404 `{message}` with
    `payments::messages.charge_not_found` and `visit_not_found`; each with an empty `report()`.

- [X] T012 Write `tests/Feature/Payments/PaymentsPolicyTest.php`, then create `app/Modules/Payments/Policies/DoctorFeePolicy.php` and `ChargePolicy.php` (R9)
  - Scope: class-level abilities only. `DoctorFeePolicy`: `viewAny` → active administrator or receptionist; `set` →
    active administrator. `ChargePolicy`: `viewAny`, `create`, `pay`, `cancel` → active administrator or receptionist;
    `discount`, `refund` → active administrator. Test: every role (active and inactive) × every ability, as
    `QueuesPolicyTest`. Then register both with `Gate::policy` in the provider's `boot()`.
  - Verify: `--filter=PaymentsPolicyTest` → fails before the policies exist, passes after.
  - Depends: T006, T009

- [X] T013 Run the Queues, Encounters, and Doctors suites
  - Verify: `docker compose exec app php artisan test tests/Feature/Queues tests/Feature/Encounters tests/Feature/Doctors tests/Feature/Localization` → all pass unchanged.
  - Depends: T009

**Checkpoint**: Money helper, schema with database rules, policies, and module skeleton exist; earlier tests pass.

---

## Phase 3: User Story 1 — An administrator sets a doctor's consultation fee (Priority: P1) 🎯 MVP

**Goal**: `GET /fees`, `GET /doctors/{doctor}/fee`, `PUT /doctors/{doctor}/fee`.

**Independent Test**: Set a doctor's fee, read it back as a receptionist, change it, and confirm the new amount.

### Tests for User Story 1 (write first, must fail)

- [X] T014 [US1] Write `tests/Feature/Payments/DoctorFeeTest.php` (US1-1…5, FR-001, FR-002)
  - Scope: clock fixed.
    - administrator `PUT` `{"amount":"350.00"}` → `200`, `data` exactly `{doctor {id, name, title}, amount: "350.00",
      set_at, set_by {id, name}}`; `doctor_fees` has one row; a second `PUT` of `400.5` → `amount: "400.50"`, still one row
    - `GET` as receptionist and administrator → that amount; a doctor with no fee → `200`, `amount`, `set_at`, `set_by` null
    - `GET /fees` → every doctor, by account name, in pages (`per_page=1`, `per_page=101` → `422`), with fees or null
    - `422` on `amount` for: missing, `0`, `-5`, `1.999`, `'abc'`, `100000.01`; `100000` → `200`; `0.01` → `200`
    - unknown doctor on `GET` and `PUT` → `404`
    - receptionist and doctor `PUT` → `403`; doctor `GET` on both → `403`; a doctor with an invalid body → `403`, not `422`
  - Verify: `--filter=DoctorFeeTest` → fails

### Implementation for User Story 1

- [X] T015 [US1] Create `app/Modules/Payments/Repositories/Contracts/DoctorFeeRepository.php` and `app/Modules/Payments/Repositories/EloquentDoctorFeeRepository.php`, and bind them in the provider (R7)
  - Scope, each with a docblock: `findForDoctor(int $doctorId): ?DoctorFee` with `setBy`; `set(int $doctorId, string
    $amount, int $userId): void` — `upsert` on `doctor_id`, so simultaneous first settings do not collide;
    `paginateDoctors(int $perPage): LengthAwarePaginator<int, Doctor>` — `Doctor::query()` joined to `users`, ordered by
    `users.name`, then `doctors.id`, with `user`; fees for a page are loaded with `feesFor(array $doctorIds):
    Collection` keyed by `doctor_id`, with `setBy`.

- [X] T016 [US1] Create `app/Modules/Payments/Services/FeeService.php`
  - Scope: inject `DoctorFeeRepository`, `DoctorService`. `find(int $doctorId): array{doctor: Doctor, fee: ?DoctorFee}` —
    `DoctorService::find` (404 for unknown). `set(User $actor, int $doctorId, string $amount)` — same check, then
    `set`, then `find`. `list(int $perPage)` — the page, each doctor with its fee set as a relation-free pair the
    resource can read.
  - Depends: T015

- [X] T017 [P] [US1] Create `app/Modules/Payments/Http/Requests/SetFeeRequest.php`, `ListFeesRequest.php`, and `app/Modules/Payments/Http/Resources/DoctorFeeResource.php`
  - Scope: `SetFeeRequest`: `authorize()` → `can('set', DoctorFee::class)`; `amount` `required`, `numeric`,
    `decimal:0,2`, `gt:0`, `max:100000`; attribute from `payments::attributes`; `amount(): string` returns the
    validated value through `Money::fromPiastres(Money::toPiastres(...))`. `ListFeesRequest`: `viewAny`; `page`, `per_page`
    as `ListQueueRequest`. Resource: exactly the contract's `DoctorFee`.

- [X] T018 [US1] Create `app/Modules/Payments/Http/Controllers/FeeController.php` and the routes
  - Scope: `index(ListFeesRequest)`; `show(int $doctor)` → `Gate::authorize('viewAny', DoctorFee::class)` first;
    `update(SetFeeRequest, int $doctor)`. Routes: `GET /fees` `payments.fees`; inside `whereNumber('doctor')`:
    `GET /doctors/{doctor}/fee` `payments.fee`, `PUT /doctors/{doctor}/fee` `payments.fee.set`.
  - Accept: T014 passes.
  - Depends: T016, T017

**Checkpoint**: Fees can be set and read.

---

## Phase 4: User Story 2 — Reception charges a visit and records the payment (Priority: P1)

**Goal**: `POST /visits/{visit}/charge`, `GET /charges/{charge}`, `POST /charges/{charge}/payments`.

**Independent Test**: Charge a visit, record part of the fee, confirm the balance, record the rest, and confirm the
charge shows as paid.

### Tests for User Story 2 (write first, must fail)

- [ ] T019 [P] [US2] Write `tests/Feature/Payments/ChargeVisitTest.php` (US2-1…4, FR-003–FR-006)
  - Scope: clock fixed; receptionist actor; doctor with a `350.00` fee.
    - charge a `seen` visit → `201`, `data` exactly the contract's `Charge`: `status: unpaid`, `fee: "350.00"`,
      `discount: "0.00"`, `amount_due: "350.00"`, `amount_paid: "0.00"`, `balance: "350.00"`, `visit_id`, `date`,
      `doctor {id, name, title}`, `patient {id, full_name, phone}` with no other patient keys, `charged_by`, `charged_at`,
      `payments: []`, `refunds: []`, the reason and cancellation fields null
    - `waiting`, `with_doctor`, and a past-day `seen` visit → `201`; booked and walk-in alike
    - administrator → `201`
    - `left` → `422` `visit_left` on `visit`; no fee → `422` `no_fee`; second charge → `422` `already_charged`; nothing created
    - unknown visit → `404` `visit_not_found`
    - changing the doctor's fee afterwards leaves the charge at `350.00`, and a new visit charges the new fee (SC-003)
    - the entry's status and any appointment's status are unchanged; `POST /doctors/me/queue/call-next` and `finish` work
      on an unpaid visit
    - doctor actor → `403`, nothing created
  - Verify: `--filter=ChargeVisitTest` → fails

- [ ] T020 [P] [US2] Write `tests/Feature/Payments/RecordPaymentTest.php` (US2-5, 6, FR-008–FR-010)
  - Scope: clock fixed; a `300.00` charge.
    - pay `100.10` `cash` → `201`, `partially_paid`, `amount_paid: "100.10"`, `balance: "199.90"`, one payment exactly
      `{id, amount, method, received_by {id, name}, received_at}`
    - pay `199.90` `card` → `paid`, `balance: "0.00"`, two payments in order; another `0.01` → `422` `above_balance`
    - `422` on `amount` for missing, `0`, `-1`, `1.999`, `'abc'`; `422` on `method` for missing and `bitcoin`
    - above the balance → `422` `above_balance` on `amount`, and nothing changes
    - a cancelled charge → `422` `charge_cancelled` on `charge`; unknown charge → `404`
    - `GET /charges/{id}` shows the same figures and payments
    - a payment's response and table hold no card or reference field
    - administrator → `201`; doctor → `403` on both `POST` and `GET`
  - Verify: `--filter=RecordPaymentTest` → fails

- [ ] T021 [P] [US2] Write `tests/Feature/Payments/MoneyExactnessTest.php` (SC-004, R3)
  - Scope: a `7.00` fee and 100 payments of `0.07`: `paid`, `balance: "0.00"`, `amount_paid: "7.00"`; a helper asserts after
    every payment that `charges.amount_paid` equals the database sum of payments less refunds, comparing as strings.
    Also `0.10` + `0.20` on a `0.30` fee ends `paid`.
  - Verify: `--filter=MoneyExactnessTest` → fails

### Implementation for User Story 2

- [ ] T022 [US2] Create `app/Modules/Payments/Repositories/Contracts/ChargeRepository.php` and `app/Modules/Payments/Repositories/EloquentChargeRepository.php`, and bind them in the provider
  - Scope (more methods are added in later stories), each with a docblock: `find(int $id): ?Charge` with `doctor.user`,
    `patient`, `chargedBy`, `discountedBy`, `cancelledBy`, `payments.receivedBy`, `refunds.refundedBy`; `lockById(int $id):
    ?Charge`; `create(array)`; `update(Charge, array)`; `activeExistsForVisit(int $queueEntryId): bool`; `addPayment(Charge,
    string $amount, PaymentMethod $method, int $userId): Payment`. No method updates or deletes a payment (FR-012).

- [ ] T023 [US2] Create `app/Modules/Payments/Services/ChargeService.php` with `charge()`, `find()`, and `pay()` (R3, R5, R6)
  - Scope: inject `ChargeRepository`, `DoctorFeeRepository`, `QueueRepository`.
    - `charge(User $actor, int $visitId): Charge` — transaction, `attempts: 3`, with `QueueRepository::lockById` as the
      first read: null → `VisitNotFoundException`; `left` → `['visit' => visit_left]`; `activeExistsForVisit` →
      `already_charged`; `findForDoctor` null → `no_fee`; `create` with `queue_entry_id`, `doctor_id`, `patient_id`, `date`
      from the entry, `fee` from the fee row, status `unpaid`, `charged_by`. Return `find($id)`.
    - `find(int $id): Charge` — or `ChargeNotFoundException`.
    - `pay(User $actor, int $id, string $amount, PaymentMethod $method): Charge` — transaction: `lockActive($id)`; in
      piastres, amount above the balance → `['amount' => above_balance]`; `addPayment`; `applyPaid` with the new total.
    - private `lockActive(int $id): Charge` — `lockById` or `ChargeNotFoundException`; cancelled → `['charge' =>
      charge_cancelled]`.
    - private `statusFor(int $due, int $paid): ChargeStatus` — `$due - $paid === 0` → `Paid`; `$paid === 0` → `Unpaid`;
      else `PartiallyPaid` (research R3).
    Docblock: no decision is made from a plain read.
  - Depends: T011, T015, T022

- [ ] T024 [P] [US2] Create `app/Modules/Payments/Http/Requests/RecordPaymentRequest.php` and `app/Modules/Payments/Http/Resources/ChargeSummaryResource.php`, `ChargeResource.php`, `PaymentResource.php`, `RefundResource.php`
  - Scope: request: `authorize()` → `can('pay', Charge::class)`; `amount` `required`, `numeric`, `decimal:0,2`, `gt:0`,
    `max:100000`; `method` `required`, `Rule::in(PaymentMethod::values())`; attributes from `payments::attributes`; typed
    accessors `amount(): string`, `method(): PaymentMethod`. Resources: exactly the contract's schemas; `amount_due` and
    `balance` from `Money`; `visit_id` is `queue_entry_id`; `charged_at` is `created_at`; patient `{id, full_name, phone}` only.
    `ChargeResource` extends `ChargeSummaryResource`.

- [ ] T025 [US2] Create `app/Modules/Payments/Http/Controllers/VisitChargeController.php` with `store()`, `app/Modules/Payments/Http/Controllers/ChargeController.php` with `show()` and `pay()`, and the routes
  - Scope: `store(Request, int $visit)` → `Gate::authorize('create', Charge::class)` first, `201`. `show(int $charge)` →
    `Gate::authorize('viewAny', Charge::class)` first. `pay(RecordPaymentRequest, int $charge)` → `201`. Routes:
    `POST /visits/{visit}/charge` `payments.charge`; `GET /charges/{charge}` `payments.charges.show`; `POST
    /charges/{charge}/payments` `payments.charges.pay`, with `whereNumber`.
  - Accept: T019, T020, T021 pass.
  - Depends: T023, T024

- [ ] T026 [US2] Create `tests/Support/payment-action.php` and `tests/Feature/Payments/ConcurrentPaymentTest.php` `#[Group('concurrency')]` with the charge and payment cases (SC-002, R3)
  - Scope: the script is like `tests/Support/encounter-action.php`, with the same bootstrap and **exit 2 unless**
    `DB::connection()->getDatabaseName() === 'clinic_testing'`. Arguments: `action actorId targetId startAt [amount]`, where
    `action` is `charge` (target = visit) or `pay` (target = charge; `cash`). `refund` and `undo` are added in T042. Prints
    `{"result":"ok","id":N}`, `{"result":"refused","errors":{...}}`, or `{"result":"error","class":"..."}`; exit 0.
  - Test: `use DatabaseTruncation`, truncate in `tearDown()`; no `travelTo`. Same pool pattern as `ConcurrentEncounterTest`.
    8 `charge` on one visit → one `ok`, seven `refused` on `visit`, one row. A `100.00` charge and 8 `pay` of `30.00` → three
    `ok`, five `refused` on `amount`; `amount_paid` is `90.00` and equals the payments' sum. On any `error` line, fail.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T025

**Checkpoint**: US1 + US2 — the minimum usable slice: reception charges and takes payment, safely under concurrency.

---

## Phase 5: User Story 3 — Reception finds charges and what is owed (Priority: P2)

**Goal**: `GET /visits` and `GET /charges`.

**Independent Test**: Create visits with no charge, an unpaid charge, and a paid charge. Filter by each status and confirm
the expected visit is returned.

### Tests for User Story 3 (write first, must fail)

- [ ] T027 [P] [US3] Write `tests/Feature/Payments/VisitChargeListTest.php` (US3-1, 4, FR-016, FR-019, R8)
  - Scope: clock fixed; receptionist actor. Visits of two doctors: no charge; `unpaid`; `partially_paid`; `paid`; a cancelled
    charge; a `left` visit with no charge; a `left` visit with an active charge.
    - `GET /visits?date=2026-11-01` → by doctor, then queue order; each exactly the contract's `VisitCharge`; `balance` and
      `charge_id` null with no active charge
    - each `charge_status` filter, including `none` catching the cancelled one and not the bare `left` visit; the `left`
      visit with an active charge appears under its status; `charge_status=done` → `422`
    - `doctor_id` filter; another date's visits never appear; missing or malformed `date` → `422`; `per_page=101` → `422`
    - patient `{id, full_name, phone}` only; patient made `withNationalId()->withAddress()->withEmergencyContact()`, none of
      those appear
    - doctor → `403`
  - Verify: `--filter=VisitChargeListTest` → fails

- [ ] T028 [P] [US3] Write `tests/Feature/Payments/ChargeListTest.php` (US3-2…4, FR-017, FR-018)
  - Scope: clock fixed.
    - `GET /charges?from&to` → newest first; each exactly the contract's `ChargeSummary`, with no reasons, payments, or
      refunds; filters by `patient_id`, `doctor_id`, and each `status`, combined; paging
    - from or to missing → `422`; `to` before `from` → `422`; 92 days → `200`; 93 → `422`; `status=done` → `422`
    - `GET /charges/{id}` has every payment and refund with method, amount, time, and staff; unknown → `404`
    - no medical content: a finalized encounter with planted text on the same visit never appears in any response
    - doctor → `403`
  - Verify: `--filter=ChargeListTest` → fails

### Implementation for User Story 3

- [ ] T029 [US3] Add `paginateVisits` and `paginate` to `ChargeRepository` / `EloquentChargeRepository` (R8)
  - Scope: `paginateVisits(string $date, array $filters, int $perPage): LengthAwarePaginator<int, QueueEntry>` —
    `QueueEntry::query()`, `select('queue_entries.*', 'charges.id as charge_id', 'charges.status as charge_status',
    'charges.fee as charge_fee', 'charges.discount as charge_discount', 'charges.amount_paid as charge_amount_paid')`, left join
    on `charges.queue_entry_id = queue_entries.id` and `charges.active_flag = 1`; the date; optional `doctor_id`;
    `charge_status` `none` → `charges.id` null, otherwise `charges.status` =; left visits only with an active charge; ordered
    `doctor_id`, `order_minute`, `queue_number`; `with('patient', 'doctor.user')`; `withQueryString()`.
    `paginate(array $filters, int $perPage): LengthAwarePaginator<int, Charge>` — date between `from` and `to`; optional
    `patient_id`, `doctor_id`, `status`; `orderByDesc('date')`, `orderByDesc('id')`; `with('patient', 'doctor.user')`;
    `withQueryString()`.

- [ ] T030 [US3] Create `app/Modules/Payments/Http/Requests/ListVisitChargesRequest.php`, `ListChargesRequest.php`, and `app/Modules/Payments/Http/Resources/VisitChargeResource.php`; add `listVisits()` and `list()` to `ChargeService`
  - Scope: `ListVisitChargesRequest`: `viewAny`; `date` `required`, `date_format:Y-m-d`; `doctor_id` nullable integer min 1;
    `charge_status` nullable, in `none`, `unpaid`, `partially_paid`, `paid`; `page`; `per_page` 1–100, default 15.
    `ListChargesRequest`: `viewAny`; `from`, `to` as `ListOwnVisitsRequest`, with `validateRange` (at most 92 days);
    `patient_id`, `doctor_id` nullable integer min 1; `status` nullable, in `ChargeStatus::values()`. Resource: exactly the
    contract's `VisitCharge`; `charge_status` `?? 'none'`; `balance` from `Money` or null.
  - Depends: T029

- [ ] T031 [US3] Add `index()` to `VisitChargeController` and `ChargeController`, and the routes
  - Scope: routes `GET /visits` `payments.visits`; `GET /charges` `payments.charges`.
  - Accept: T027, T028 pass.
  - Depends: T030

**Checkpoint**: Staff can find uncharged visits and what is owed.

---

## Phase 6: User Story 4 — An administrator gives a discount (Priority: P3)

**Goal**: `PUT /charges/{charge}/discount`.

**Independent Test**: Discount a charge, confirm the amount due and balance fall, then discount it to zero and confirm it
shows as paid.

### Tests for User Story 4 (write first, must fail)

- [ ] T032 [US4] Write `tests/Feature/Payments/DiscountTest.php` (US4-1…4, FR-011)
  - Scope: clock fixed; administrator; a `400.00` charge.
    - `150.00` with a reason → `200`, `fee: "400.00"`, `discount: "150.00"`, `amount_due: "250.00"`, `balance: "250.00"`, reason,
      `discounted_by`, `discounted_at`
    - a later discount replaces it; `0` removes it, and the new reason is recorded
    - after paying `100.00`: discount `300.00` → `balance: "0.00"`, `paid`; `300.01` → `422` `discount_too_large` on `amount`
    - whole fee with nothing paid → `balance: "0.00"`, `paid`
    - no reason, 501 characters, `-1`, `1.999` → `422`; cancelled → `422` `charge_cancelled`; unknown → `404`
    - receptionist and doctor → `403`, nothing changes
  - Verify: `--filter=DiscountTest` → fails

### Implementation for User Story 4

- [ ] T033 [P] [US4] Create `app/Modules/Payments/Http/Requests/SetDiscountRequest.php`
  - Scope: `authorize()` → `can('discount', Charge::class)`; `amount` `required`, `numeric`, `decimal:0,2`, `min:0`,
    `max:100000`; `reason` `required`, `string`, `max:500`; attributes; typed accessors.

- [ ] T034 [US4] Add `discount()` to `ChargeService`, `discount()` to `ChargeController`, and the route
  - Scope: service: transaction: `lockActive`; in piastres, amount above fee less paid → `['amount' => discount_too_large]`;
    `update` with `discount`, `discount_reason`, `discounted_by`, `discounted_at now()`, and `status` from `statusFor`. Route:
    `PUT /charges/{charge}/discount` `payments.charges.discount`.
  - Accept: T032 passes.
  - Depends: T033

**Checkpoint**: Administrators can discount and waive fees.

---

## Phase 7: User Story 5 — Correcting mistakes (Priority: P3)

**Goal**: `POST /charges/{charge}/refunds`, `POST /charges/{charge}/cancel`, and refusing an undo while a charge is active.

**Independent Test**: Refund a payment as an administrator, confirm the balance rises and the original payment is unchanged,
then cancel the charge and confirm the visit can be charged again.

### Tests for User Story 5 (write first, must fail)

- [ ] T035 [P] [US5] Write `tests/Feature/Payments/RefundTest.php` (US5-1, 2, 7, FR-015)
  - Scope: clock fixed; administrator; a `300.00` charge with `300.00` paid through the API.
    - refund `50.00` `cash` with a reason → `201`, `amount_paid: "250.00"`, `balance: "50.00"`, `partially_paid`; one refund
      exactly `{id, amount, method, reason, refunded_by {id, name}, refunded_at}`; the payment row unchanged
    - refund the rest → `amount_paid: "0.00"`, `unpaid`
    - above the amount paid → `422` `above_paid`; no reason, 501 characters, `0`, `1.999`, unknown method → `422`
    - cancelled → `422` `charge_cancelled`; unknown → `404`
    - receptionist and doctor → `403`, nothing changes
    - no route changes or deletes a payment or refund: `PUT` and `DELETE` on `/charges/{id}/payments/{id}` and `/refunds/{id}` →
      `404` or `405`
  - Verify: `--filter=RefundTest` → fails

- [ ] T036 [P] [US5] Write `tests/Feature/Payments/CancelChargeTest.php` (US5-4, 5, FR-013)
  - Scope: clock fixed; receptionist.
    - an unpaid charge with a reason → `200` `cancelled`, reason, `cancelled_by`, `cancelled_at`; still found by `GET`; the visit
      can be charged again → `201` at the current fee, and both rows exist
    - money held → `422` `money_held` on `charge`; refunded to zero → `200`; whole-fee discount with nothing paid → `200`
    - again → `422` `charge_cancelled`; no reason, 501 characters → `422`; unknown → `404`
    - payment, refund, and discount on a cancelled charge → `422` `charge_cancelled`
    - administrator → `200`; doctor → `403`
  - Verify: `--filter=CancelChargeTest` → fails

- [ ] T037 [P] [US5] Write `tests/Feature/Payments/UndoWithChargeTest.php` (US5-6, FR-014, R4)
  - Scope: clock fixed; a waiting entry for a checked-in appointment.
    - with no charge → `DELETE /queue/{entry}` `204`, as before
    - with an active charge, including `paid` → `422` `queues::messages.has_active_charge` on `entry`; the entry and appointment
      unchanged
    - cancel the charge, then undo → `204`; `GET /charges/{id}` → `cancelled`, `visit_id: null`, with its patient, doctor, and
      date, and it no longer blocks anything
  - Verify: `--filter=UndoWithChargeTest` → fails

### Implementation for User Story 5

- [ ] T038 [US5] Add `addRefund` to `ChargeRepository` / `EloquentChargeRepository`, and create `app/Modules/Payments/Http/Requests/RecordRefundRequest.php` and `CancelChargeRequest.php`
  - Scope: `addRefund(Charge, string $amount, PaymentMethod $method, string $reason, int $userId): Refund`; no method updates or
    deletes one. `RecordRefundRequest`: `can('refund', Charge::class)`; `amount` as payment; `method` as payment; `reason`
    `required`, `string`, `max:500`. `CancelChargeRequest`: `can('cancel', Charge::class)`; `reason` `required`, `string`, `max:500`.

- [ ] T039 [US5] Add `refund()` and `cancel()` to `ChargeService`, `refund()` and `cancel()` to `ChargeController`, and the routes (R6)
  - Scope: `refund`: transaction: `lockActive`; amount above `amount_paid` → `['amount' => above_paid]`; `addRefund`; update
    `amount_paid` and `status` from `statusFor`. Returns `find($id)`; controller `201`. `cancel`: transaction: `lockActive`;
    `amount_paid` above 0 → `['charge' => money_held]`; update status `cancelled`, `cancel_reason`, `cancelled_by`, `cancelled_at`.
    Controller `200`. Routes: `POST /charges/{charge}/refunds` `payments.charges.refund`; `POST /charges/{charge}/cancel`
    `payments.charges.cancel`.
  - Accept: T035, T036 pass.
  - Depends: T038

- [ ] T040 [US5] Create `app/Modules/Queues/Repositories/Contracts/VisitChargeRepository.php` and `app/Modules/Payments/Repositories/EloquentVisitChargeRepository.php`, and bind the contract in `PaymentsServiceProvider` (R4, approved D4)
  - Scope: contract, in the style of `Scheduling\…\BookedTimeRepository`, with a docblock that it is declared by Queues and
    implemented by Payments so the dependency goes one way: `hasActiveCharge(int $queueEntryId): bool`. Implementation: an `exists()`
    on `charges` with that entry and `active_flag = 1`.

- [ ] T041 [US5] Change `QueueService::undo` in `app/Modules/Queues/Services/QueueService.php`, and add `has_active_charge` to `app/Modules/Queues/lang/en/messages.php` and `ar/messages.php`
  - Scope: inject `VisitChargeRepository`. Right after `lockWaiting`, as the transaction's first plain read: active charge →
    `ValidationException(['entry' => queues::messages.has_active_charge])`. Update the `@throws` docblock. Messages: "This visit has an
    active charge. Cancel the charge first." / "لهذه الزيارة مطالبة نشطة. ألغِ المطالبة أولًا." Nothing else in the Queues module changes.
  - Accept: T037 passes; `docker compose exec app php artisan test tests/Feature/Queues` → all pass unchanged.
  - Depends: T039, T040

- [ ] T042 [US5] Extend `tests/Feature/Payments/ConcurrentPaymentTest.php` with the refund and undo cases, and enable `refund` and `undo` in `tests/Support/payment-action.php` (SC-002, R4)
  - Scope: `refund` (target = charge; `cash`, a synthetic reason), `undo` (target = entry; `QueueService::undo`). A charge with `90.00`
    paid and 8 `refund` of `30.00` → three `ok`, five `refused`; `amount_paid` is `0.00` and equals payments less refunds. Charge
    against undo, repeated 5 times with a fresh waiting appointment entry: exactly one of these holds: the charge `ok`, the undo `refused`
    on `entry`, and the entry exists; or the undo `ok`, the charge `404` as an `error` the test allows only for
    `VisitNotFoundException`, and no charge exists.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T026, T041

**Checkpoint**: All five stories done.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T043 [P] Write `tests/Feature/Payments/PaymentsAuthorizationTest.php` (SC-005)
  - Scope: data providers over all eleven endpoints with valid input (clock fixed): doctor × every endpoint → `403`
    (`auth.forbidden`) and nothing changed; receptionist × fee set, discount, refund → `403`; receptionist × the other eight → success
    code; administrator × all → success code; no token × every endpoint → `401`; a receptionist with a real token then deactivated →
    `401` (`InteractsWithStaffTokens::usingToken()`); a doctor with an invalid body or unknown id → `403`, not `422` or `404`.

- [ ] T044 [P] Write `tests/Feature/Payments/PaymentsMessagesTest.php` (FR-025, SC-007)
  - Scope: for `en` and `ar`: every key in `payments::messages` triggered through the API, `queues::messages.has_active_charge`, a
    translated attribute name in a `required` message, `403` → `auth.forbidden`; JSON keys identical across locales; `status`, `method`,
    and money strings identical across locales.

- [ ] T045 [P] Update `specs/007-patient-queues/contracts/queues.openapi.yaml`, `docs/roadmap.md`, and `README.md`
  - Scope: 007 contract: `DELETE /queue/{entry}` 422 gains `has_active_charge` on `entry`, with a one-line note "added by feature 009".
    Roadmap: 009 `Done` with the spec link and depends on `006, 007`; 010 `Next`. README: a short "Payments" section after "Medical
    encounters": fees, charging a visit, payments, discounts and refunds for administrators, cancelling, the undo rule; money is returned as
    strings; this records money and processes no cards; link the contract.
  - Verify: `postman spec lint specs/009-payments/contracts/payments.openapi.yaml --issueType=syntax` and the same for the 007 contract → no issues.

- [ ] T046 Run Pint and the full suite
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
  - Depends: T043, T044, T045

- [ ] T047 Measure SC-006 on `clinic_testing` only
  - Scope: as feature 008's SC-005: in a transaction rolled back afterwards, 500 factory doctors, 2,000 patients, and 500,000 queue entries and
    charges bulk-inserted; `paginateVisits` for one date, and `paginate` for a 92-day range with and without filters, three runs each. Refuse any
    database but `clinic_testing`. Target: under 2 s. A miss is reported, not hidden.
  - Depends: T046

- [ ] T048 Run quickstart Q1–Q8 from `specs/009-payments/quickstart.md` on the development stack
  - Scope: synthetic data only. Record what was run.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T046

- [ ] T049 Record the results in `specs/009-payments/verification.md`
  - Scope: per Q-step and SC-006: date, command, observed output summary, pass/fail; failures reported as failures (Constitution VIII). Postman
    collection update is a separate follow-up.
  - Depends: T047, T048

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 → T003; T004 → T006 → T007 → T008; T006, T009 → T012; T013 last.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: after US1 (a charge copies the doctor's fee).
- **US3 (Phase 5)**: after Foundational and US2's repository (T022); factories provide the records.
- **US4 (Phase 6)**: after US2 (`lockActive`, `statusFor`, resources).
- **US5 (Phase 7)**: after US2. T042 extends T026.
- **Polish (Phase 8)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- Foundational: T005 alongside T004; T007, T010, T011 together after T006.
- US1: T017 alongside T015–T016.
- US2: T019, T020, T021 together; T024 alongside T022–T023.
- US3: T027 and T028 together.
- US4: T033 alongside T032's implementation.
- US5: T035, T036, T037 together.
- Polish: T043, T044, T045 together.

---

## Parallel Example: User Story 2

```text
Task: "ChargeVisitTest"                  (T019)
Task: "RecordPaymentTest"                (T020)
Task: "MoneyExactnessTest"               (T021)
```

## Parallel Example: Polish

```text
Task: "PaymentsAuthorizationTest"        (T043)
Task: "PaymentsMessagesTest"             (T044)
Task: "007 contract, roadmap, README"    (T045)
```

---

## Implementation Strategy

### MVP First (User Stories 1 and 2)

1. Phase 1 Setup → Phase 2 Foundational (money helper, schema with database rules, policies).
2. Phase 3 US1 → fees exist. Nothing can be charged yet, so this alone is not usable.
3. Phase 4 US2 → **stop and validate**: a visit can be charged and paid, with no overpayment under concurrency, three runs in a row.

### Incremental Delivery

1. US1 + US2 → charge and take payment (MVP).
2. US3 → find uncharged visits and balances.
3. US4 → discounts.
4. US5 → refunds, cancelling, and the undo rule.
5. Polish → authorization matrix, messages, docs, measurement, quickstart, verification.
