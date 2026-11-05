# Data Model: Payments

**Feature**: 009-payments | **Date**: 2026-11-05

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Four new tables in new migrations; no applied migration is
edited and no existing table changes. Money is `DECIMAL(10,2)` in Egyptian pounds. Dates are the clinic's
wall-clock date (`Africa/Cairo`); `*_at` columns are UTC instants, as in features 005–008.

## `doctor_fees` — Doctor fee

Model: `App\Modules\Payments\Models\DoctorFee`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `amount` | `decimal(10,2)` | no | Current fee |
| `set_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `created_at`, `updated_at` | `timestamp` | yes | `updated_at` is `set_at` in the API |

**Indexes**: `PRIMARY (id)`; `UNIQUE doctor_fees_doctor_unique (doctor_id)`.
**Check**: `doctor_fees_amount_check (amount > 0 AND amount <= 100000)`.

## `charges` — Charge

Model: `App\Modules\Payments\Models\Charge`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; the charge id in the API |
| `queue_entry_id` | `bigint unsigned` | yes | FK → `queue_entries.id`, `ON DELETE SET NULL`; null only for a cancelled charge whose check-in was undone (research R4) |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT`; copied from the visit |
| `patient_id` | `bigint unsigned` | no | FK → `patients.id`, `RESTRICT`; copied from the visit |
| `date` | `date` | no | The visit's clinic date; copied from the visit |
| `status` | `varchar(20)` | no | `unpaid` \| `partially_paid` \| `paid` \| `cancelled` (`ChargeStatus` enum) |
| `active_flag` | `tinyint unsigned` **stored generated** | yes | `IF(status <> 'cancelled', 1, NULL)`; only for the unique index; never written or exposed |
| `fee` | `decimal(10,2)` | no | The doctor's fee when charged; never changes |
| `discount` | `decimal(10,2)` | no | Default `0.00` |
| `amount_paid` | `decimal(10,2)` | no | Default `0.00`; payments less refunds |
| `discount_reason` | `varchar(500)` | yes | |
| `discounted_by` | `bigint unsigned` | yes | FK → `users.id`, `RESTRICT` |
| `discounted_at` | `timestamp` | yes | |
| `charged_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `cancel_reason` | `varchar(500)` | yes | |
| `cancelled_by` | `bigint unsigned` | yes | FK → `users.id`, `RESTRICT` |
| `cancelled_at` | `timestamp` | yes | |
| `created_at`, `updated_at` | `timestamp` | yes | `created_at` is `charged_at` in the API |

**Indexes**

- `PRIMARY (id)`
- `UNIQUE charges_visit_active_unique (queue_entry_id, active_flag)` — one active charge per visit (FR-006).
  NULLs never collide, so cancelled charges are unaffected.
- `INDEX charges_date_index (date, id)` — the charge list.
- `INDEX charges_patient_index (patient_id, date, id)`; `INDEX charges_doctor_index (doctor_id, date, id)`.

**Check**: `charges_money_check (discount >= 0 AND amount_paid >= 0 AND discount + amount_paid <= fee AND fee > 0)`.

**Calculated, never stored**: `amount_due = fee - discount`; `balance = amount_due - amount_paid`.

**Relations** (model): `queueEntry()`, `doctor()`, `patient()`, `payments()` and `refunds()` (ordered by
`id`), `chargedBy()`, `discountedBy()`, `cancelledBy()`.

## `payments` — Payment

Model: `App\Modules\Payments\Models\Payment`, with `UPDATED_AT = null`. Rows are only ever inserted.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key |
| `charge_id` | `bigint unsigned` | no | FK → `charges.id`, `RESTRICT`; indexed |
| `amount` | `decimal(10,2)` | no | Above 0 |
| `method` | `varchar(20)` | no | `cash` \| `card` \| `wallet` (`PaymentMethod` enum) |
| `received_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `created_at` | `timestamp` | yes | When it was recorded; `received_at` in the API |

**Check**: `payments_amount_check (amount > 0)`.

## `refunds` — Refund

Model: `App\Modules\Payments\Models\Refund`, with `UPDATED_AT = null`. Rows are only ever inserted.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key |
| `charge_id` | `bigint unsigned` | no | FK → `charges.id`, `RESTRICT`; indexed |
| `amount` | `decimal(10,2)` | no | Above 0 |
| `method` | `varchar(20)` | no | `cash` \| `card` \| `wallet` (`PaymentMethod` enum) |
| `reason` | `varchar(500)` | no | |
| `refunded_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `created_at` | `timestamp` | yes | When it was recorded; `refunded_at` in the API |

**Check**: `refunds_amount_check (amount > 0)`.

## Change to feature 007's behavior

No schema change. `QueueService::undo` refuses with 422 `has_active_charge` while the visit has an active
charge (research R4).

## Validation rules

| Field | Rule | Source |
|-------|------|--------|
| fee `amount` | required; `numeric`, `decimal:0,2`, `gt:0`, `max:100000` | FR-002 |
| charge `{visit}` | existing (404 `visit_not_found`); not `left` (422 `visit_left`); no active charge (422 `already_charged`); doctor has a fee (422 `no_fee`) | FR-004, FR-006 |
| payment `amount` | required; `numeric`, `decimal:0,2`, `gt:0`, `max:100000`; at most the balance (422 `above_balance`) | FR-009 |
| payment, refund `method` | required, one of `cash`, `card`, `wallet` | FR-008, FR-015 |
| refund `amount` | as payment; at most `amount_paid` (422 `above_paid`) | FR-015 |
| refund, discount, cancel `reason` | required string up to 500 characters | FR-011, FR-013, FR-015 |
| discount `amount` | required; `numeric`, `decimal:0,2`, `min:0`, `max:100000`; at most `fee - amount_paid` (422 `discount_too_large`) | FR-011 |
| cancel | `amount_paid` is 0 (422 `money_held`) | FR-013 |
| any change to a cancelled charge | 422 `charge_cancelled` | FR-013 |
| visits `date` | required `Y-m-d` | FR-016 |
| visits `doctor_id` | optional integer ≥ 1 | FR-016 |
| visits `charge_status` | optional, one of `none`, `unpaid`, `partially_paid`, `paid` | FR-016 |
| charges `from`, `to` | required `Y-m-d`; `to` ≥ `from`; at most 92 days | FR-017 |
| charges `patient_id`, `doctor_id` | optional integer ≥ 1 | FR-017 |
| charges `status` | optional, one of the four statuses | FR-017 |
| `per_page` | optional 1–100, default 15 | FR-016, FR-017 |

## State transitions

```text
 charge
              pay in part         pay the rest
   unpaid ───────────────▶ partially_paid ───────▶ paid
     │  ▲                      ▲     │              │
     │  └───── full refund ────┤     │              │
     │                         └──── part refund ───┘
     │  whole-fee discount, or full payment ──────▶ paid
     │
     └── cancel (amount paid is 0) ──▶ cancelled (final; the visit can be charged again)
```

- The status always follows from the figures (research R3), except `cancelled`, which is final.
- A charge can be cancelled from `unpaid`, or from `paid` when a whole-fee discount left nothing paid.
- Charges, payments, and refunds are never deleted (FR-012).
