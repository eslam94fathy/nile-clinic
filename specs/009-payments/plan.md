# Implementation Plan: Payments

**Branch**: `009-payments` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/009-payments/spec.md`

## Summary

Add a `Payments` module with four tables (`doctor_fees`, `charges`, `payments`, `refunds`) and eleven
endpoints for administrators and receptionists: set and read fees, list a date's visits with their charge
status, charge a visit, find charges, record payments, and, for administrators, discounts and refunds. A
charge belongs to one queue entry and copies the fee, so later price changes never alter it. Money is stored
as `DECIMAL`, calculated in whole piastres, and returned as strings. Every change locks the charge, and a
database `CHECK` makes overpayment impossible even if the application has a bug. Feature 007 changes in one
place: undoing a check-in is refused while the visit has an active charge. No new dependencies. Evidence:
[research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. Four new tables; `queue_entries`, `patients`,
`doctors`, and `users` are referenced or read, not changed ([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; Feature tests per user story with
`travelTo` in the clinic zone; a Unit test for `Money`; a `concurrency` group with parallel PHP processes
(feature 006 R9); earlier features' tests unchanged

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: A date's visits or a page of charges in under 2 s with 500 doctors and 500,000 charges
(SC-006)

**Constraints**: One active charge per visit, no overpayment, and no over-refund, also under concurrency
(FR-006, FR-009, FR-015); exact money (SC-004); historical fees preserved (FR-005); all-or-nothing changes
(FR-021); clinic wall-clock dates (FR-024); synthetic data only

**Scale/Scope**: Single clinic, ~500 doctors, a few thousand visits a day. 11 endpoints, 1 module, 4
migrations; one small change in Queues

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; class-level policies; doctors 403 on everything; only administrators set fees and discounts or refund; patient summary only; no dependency on `Encounters`; no card data stored | ✅ (D3 approved) |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; four new migrations, none edited; `/api/v1` via the module provider; no patient portal | ✅ |
| III | Repository pattern | `DoctorFeeRepository`, `ChargeRepository`, plus `VisitChargeRepository` declared by Queues and implemented here; no generic repository | ✅ (D4 approved) |
| IV | Thin controllers | `FeeController`, `VisitChargeController`, `ChargeController`: Form Request or Gate → service → Resource | ✅ |
| V | Services own workflows | `FeeService` and `ChargeService` own transactions, locks, money rules, and status; repositories only query | ✅ |
| VI | Form Requests/Resources/Policies | Requests per action; `DoctorFeeResource`, `VisitChargeResource`, `ChargeSummaryResource`, `ChargeResource`; `DoctorFeePolicy`, `ChargePolicy` | ✅ |
| VII | en/ar localization | `payments::messages` and `payments::attributes` in both locales; one new `queues::messages` key; statuses and methods are fixed identifiers | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per story; concurrency group (SC-002); role × endpoint matrix (SC-005); exactness (SC-004); both locales (SC-007) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; framework and MySQL behavior verified (R2, R3, R4); no new dependency; D1–D4 approved | ✅ (D1–D4 approved) |
| XI | Integrity & concurrency | `DECIMAL` money; historical fee on each charge; one transaction per change with row locks; `CHECK` and unique index as database backstops; real parallel-process tests | ✅ (D1, D2 approved) |
| XII | No real data | Factories only; concurrency script refuses any database but `clinic_testing`; no patient data or amounts in logs or exceptions | ✅ |

**Gate result (pre-research)**: PASS (D1–D4 approved 2026-11-05).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   Fee / VisitCharge / Charge controllers (thin)
                              │   Form Request or Gate → class-level policy (else 403, before any lookup)
                              ▼
   ChargeService ─ DB::transaction(attempts: 3)
     charge    lock queue entry ─▶ not left, no active charge, has a fee ─▶ insert with the fee
     pay       lock charge ─▶ not cancelled, at most the balance ─▶ insert, update figures and status
     refund    lock charge ─▶ not cancelled, at most amount paid ─▶ insert, update figures and status
     discount  lock charge ─▶ not cancelled, at most fee less paid ─▶ update figures and status
     cancel    lock charge ─▶ nothing paid ─▶ cancelled

   QueueService::undo (feature 007)
     lock queue day, lock entry ─▶ VisitChargeRepository::hasActiveCharge? 422 : delete
```

### Endpoints ([contract](contracts/payments.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| GET | `/fees?page&per_page` | admin, receptionist | FR-001 |
| GET | `/doctors/{doctor}/fee` | admin, receptionist | FR-001 |
| PUT | `/doctors/{doctor}/fee` | admin | FR-001, FR-002 |
| GET | `/visits?date&doctor_id&charge_status&page&per_page` | admin, receptionist | FR-016 |
| POST | `/visits/{visit}/charge` | admin, receptionist | FR-003–FR-006 |
| GET | `/charges?from&to&patient_id&doctor_id&status&page&per_page` | admin, receptionist | FR-017 |
| GET | `/charges/{charge}` | admin, receptionist | FR-018 |
| POST | `/charges/{charge}/payments` | admin, receptionist | FR-008–FR-010 |
| POST | `/charges/{charge}/refunds` | admin | FR-015 |
| PUT | `/charges/{charge}/discount` | admin | FR-011 |
| POST | `/charges/{charge}/cancel` | admin, receptionist | FR-013 |

Status codes: `200`, `201` charge, payment, or refund, `401`, `403`, `404` (unknown doctor, visit, or charge),
`422` validation (`visit_left`, `already_charged`, `no_fee`, `above_balance`, `above_paid`,
`discount_too_large`, `money_held`, `charge_cancelled`). Feature 007's `DELETE /queue/{entry}` gains 422
`has_active_charge`.

### Module layout (new files)

```text
app/Modules/Payments/
├── Enums/{ChargeStatus,PaymentMethod}.php
├── Exceptions/{ChargeNotFound,VisitNotFound}Exception.php   # 404; not reported
├── Http/
│   ├── Controllers/{Fee,VisitCharge,Charge}Controller.php
│   ├── Requests/{SetFee,ListFees,ListVisitCharges,ListCharges,RecordPayment,RecordRefund,SetDiscount,CancelCharge}Request.php
│   └── Resources/{DoctorFee,VisitCharge,ChargeSummary,Charge,Payment,Refund}Resource.php
├── Models/{DoctorFee,Charge,Payment,Refund}.php
├── Policies/{DoctorFee,Charge}Policy.php
├── Providers/PaymentsServiceProvider.php
├── Repositories/
│   ├── Contracts/{DoctorFeeRepository,ChargeRepository}.php
│   ├── Eloquent{DoctorFee,Charge}Repository.php
│   └── EloquentVisitChargeRepository.php          # implements the Queues contract
├── Services/{FeeService,ChargeService}.php
├── Support/Money.php                              # toPiastres, fromPiastres; never a float
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_create_{doctor_fees,charges,payments,refunds}_table.php
database/factories/{DoctorFee,Charge,Payment,Refund}Factory.php
tests/Support/payment-action.php                   # child process for the concurrency test
```

Changed existing files:

- `app/Modules/Queues/Repositories/Contracts/VisitChargeRepository.php` (new contract, declared by Queues)
- `app/Modules/Queues/Services/QueueService.php` (`undo` checks it)
- `app/Modules/Queues/lang/{en,ar}/messages.php` (`has_active_charge`)
- `specs/007-patient-queues/contracts/queues.openapi.yaml` (`DELETE /queue/{entry}` gains the 422 reason)
- `bootstrap/providers.php` (register `PaymentsServiceProvider`)
- `docs/roadmap.md`, `README.md` (short "Payments" section; dependencies)

### Key rules

- **Money** (R2): `DECIMAL` stored, whole piastres calculated, strings returned. No float arithmetic.
- **Status** (R3): always worked out from the figures, except `cancelled`.
- **Locks first** (R4, R5, R6): no decision to change money is made from a plain read.
- **Historical fee** (FR-005): charges copy the fee, patient, doctor, and date, and never change them.
- **Insert-only** (FR-012): repositories have no method to update or delete a payment or refund.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Payments/MoneyTest` | `toPiastres` and `fromPiastres`, including `.5`, `5.`, `0.07`, `100000`, numbers and strings; no float drift |
| `tests/Feature/Payments/DoctorFeeTest` | set, change, read, list with null fees; limits and decimals; unknown doctor 404; roles |
| `tests/Feature/Payments/ChargeVisitTest` | charge at the current fee for waiting, with doctor, seen, past day; left, charged, no fee → 422; unknown 404; booked and walk-in alike; later fee change leaves the charge alone; no effect on queue or appointment status |
| `tests/Feature/Payments/RecordPaymentTest` | part and full payment, several methods, status and balance; above balance, zero, negative, 3 decimals, unknown method → 422; cancelled → 422 |
| `tests/Feature/Payments/MoneyExactnessTest` | SC-004: 100 payments of `0.07` on `7.00` end `paid` at `0.00`; `amount_paid` equals payments less refunds after every action |
| `tests/Feature/Payments/VisitChargeListTest` | date, doctor, and status filters; `none` ignores cancelled charges; left visits only with an active charge; order; paging; patient summary only |
| `tests/Feature/Payments/ChargeListTest` | range limit, filters, newest first; no reasons, payments, or sensitive fields; show has everything |
| `tests/Feature/Payments/DiscountTest` | set, replace, remove; whole-fee → `paid`; above fee less paid → 422; no reason → 422; fee stays visible; roles |
| `tests/Feature/Payments/RefundTest` | part and full refund, status and balance; original payment unchanged; above paid → 422; no reason → 422; roles |
| `tests/Feature/Payments/CancelChargeTest` | cancel with nothing paid, recharge allowed; money held → 422; further changes → 422; refunded-to-zero can cancel |
| `tests/Feature/Payments/UndoWithChargeTest` | undo refused while active; allowed after cancel; cancelled charge kept with `visit_id` null and its patient, doctor, date |
| `tests/Feature/Payments/PaymentsPersistenceTest` | `CHECK` refuses overpayment directly in the database; unique index; foreign keys; `SET NULL`; decimals round-trip |
| `tests/Feature/Payments/ConcurrentPaymentTest` `#[Group('concurrency')]` | 8 parallel charges → one; payments together above the balance → total never above; refunds together above paid → total never above; charge against undo → consistent |
| `tests/Feature/Payments/PaymentsAuthorizationTest` | every role × every endpoint; no token 401; deactivated account 401; doctors 403 before validation and lookup |
| `tests/Feature/Payments/PaymentsPolicyTest` | roles × abilities; inactive actors refused |
| `tests/Feature/Payments/PaymentsMessagesTest` | every new message in `en` and `ar`, including `has_active_charge`, with identical structure |

`TranslationParityTest` checks the new language files. Earlier features' tests must keep passing unchanged.

## Implementation Outline (input for `/speckit-tasks`)

1. Baseline: run the suite.
2. Foundation: `Money` and its unit test; four migrations with `CHECK`s; enums, models, factories; persistence
   test.
3. Module skeleton: provider, routes, translations, exceptions, policies; policy test.
4. US1: `DoctorFeeRepository`, `FeeService`, fee routes.
5. US2: `ChargeRepository`, `ChargeService::charge` and `pay`, routes, exactness test, concurrency.
6. US3: visit list and charge list.
7. US4: discount.
8. US5: refund, cancel, the Queues contract and `undo` change, concurrency.
9. Polish: authorization matrix, messages test, 007 contract note, README and roadmap, Pint, SC-006
   measurement, quickstart, verification record. Postman collection update as a separate follow-up.

## Approved Decisions (2026-11-05)

All four were approved by the user on 2026-11-05.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Exact money | **Stored as `DECIMAL(10,2)`, calculated in whole piastres with a small `Money` helper, returned as strings** — R2 | A money package (a new dependency for add and compare); returning JSON numbers (a client's parser can change them) |
| D2 | Charge figures | **`amount_paid` and `status` kept on the charge, updated under a row lock, with a database `CHECK` against overpayment** — R3 | Summing payments on every read (slower lists, status filtering becomes a subquery) |
| D3 | URLs | **`/fees`, `/doctors/{doctor}/fee`, `/visits`, `/visits/{visit}/charge`, `/charges…`** — R1 | Nesting everything under `/payments`, which hides that a charge is the main record |
| D4 | Undoing a check-in | **Queues declares a small contract, Payments implements it, and `charges.queue_entry_id` is `ON DELETE SET NULL`**, as `BookedTimeRepository` does — R4 | Refusing to charge waiting visits (patients often pay first); Queues reading `charges` directly (a dependency the wrong way) |

## Risks, Assumptions, and Rollback

- **Change to feature 007**: `undo` gains one check and a new refusal. Its existing tests stay unchanged, and
  `UndoWithChargeTest` covers the new path.
- **Stored figures can drift**: `amount_paid` duplicates the payment rows. `MoneyExactnessTest` compares them
  after every action, and the `CHECK` limits the damage.
- **Legal**: Egyptian tax and e-receipt obligations are unverified and may require fields or documents this
  feature lacks. They need review before production (spec, Assumptions).
- **Rollback**: roll back the four migrations (destroys payment data; opt-in only), remove the provider line,
  and revert the commit range, including the `undo` check.

## Project Structure

### Documentation (this feature)

```text
specs/009-payments/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── payments.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Payments/                                   # new (see Module layout above)
├── Queues/                                     # VisitChargeRepository contract, undo check, one message
├── Patients/                                   # read only (patient summary)
└── Doctors/                                    # read only (DoctorService)
bootstrap/providers.php                         # changed
database/migrations/  database/factories/
tests/Feature/Payments/  tests/Unit/Payments/  tests/Support/payment-action.php
```

**Structure Decision**: The existing modular monolith. One new domain module, `Payments`, following
`docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
