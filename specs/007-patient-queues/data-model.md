# Data Model: Patient Queues

**Feature**: 007-patient-queues | **Date**: 2026-11-02

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Two new tables and one change to `appointments`, in new
migrations; no applied migration is edited. Dates are the clinic's wall-clock date
(`Africa/Cairo`); minutes are minutes since midnight; `*_at` columns are UTC instants, as in
features 005–006.

## `queue_days` — Queue day (per doctor)

Model: `App\Modules\Queues\Models\QueueDay`. Locked for every change to one doctor's queue on one
day (research R3).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `date` | `date` | no | Clinic date |
| `last_number` | `smallint unsigned` | no | Last queue number given that day; 0 when created |
| `created_at`, `updated_at` | `timestamp` | yes | |

**Indexes**: `PRIMARY (id)`; `UNIQUE queue_days_doctor_date_unique (doctor_id, date)`.

## `queue_entries` — Queue entry

Model: `App\Modules\Queues\Models\QueueEntry`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; the entry id in the API |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `patient_id` | `bigint unsigned` | no | FK → `patients.id`, `RESTRICT` |
| `appointment_id` | `bigint unsigned` | yes | FK → `appointments.id`, `RESTRICT`; null for a walk-in |
| `date` | `date` | no | Clinic date of arrival |
| `queue_number` | `smallint unsigned` | no | 1, 2, 3… per doctor and date (R3) |
| `order_minute` | `smallint unsigned` | no | Appointment start minute, or the walk-in's arrival minute (R4) |
| `status` | `varchar(20)` | no | `waiting` \| `with_doctor` \| `seen` \| `left` (`QueueStatus` enum) |
| `with_doctor_flag` | `tinyint unsigned` **stored generated** | yes | `IF(status = 'with_doctor', 1, NULL)`; only for the unique indexes; never written or exposed |
| `arrived_at` | `timestamp` | no | Check-in or walk-in time |
| `checked_in_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `called_at` | `timestamp` | yes | When the doctor called the patient |
| `finished_at` | `timestamp` | yes | When the doctor finished the visit |
| `left_at` | `timestamp` | yes | When marked as left |
| `left_by` | `bigint unsigned` | yes | FK → `users.id`, `RESTRICT` |
| `created_at`, `updated_at` | `timestamp` | yes | |

**Indexes**

- `PRIMARY (id)`
- `UNIQUE queue_entries_doctor_number_unique (doctor_id, date, queue_number)` — numbers backstop.
- `UNIQUE queue_entries_appointment_unique (appointment_id)` — an appointment is in a queue at most
  once (NULLs never collide, so walk-ins are unaffected).
- `UNIQUE queue_entries_doctor_with_doctor_unique (doctor_id, date, with_doctor_flag)` — at most one
  patient with a doctor.
- `UNIQUE queue_entries_patient_with_doctor_unique (patient_id, date, with_doctor_flag)` — a patient
  with at most one doctor.
- `INDEX queue_entries_doctor_order_index (doctor_id, date, order_minute, queue_number)` — a
  doctor's list and "call next".
- `INDEX queue_entries_date_index (date, doctor_id)` — the clinic's list for today.
- FK indexes on `checked_in_by`, `left_by` (created by `foreignId`).

Entries are never deleted except by undoing a check-in (spec FR-014).

**Relations** (model): `doctor()` → `Doctors\Models\Doctor`; `patient()` →
`Patients\Models\Patient`; `appointment()` → `Appointments\Models\Appointment`; `checkedInBy()`,
`leftBy()` → `Staff\Models\User`.

## Change to `appointments` (feature 006)

New migration, no new column (research R2):

- `AppointmentStatus` gains `checked_in` and `completed`.
- `scheduled_flag` becomes `IF(status IN ('scheduled', 'checked_in', 'completed'), 1, NULL)`: the
  appointment holds its slot while scheduled, checked in, or completed. The unique index
  `appointments_doctor_slot_unique` is unchanged.

## Validation rules

| Field | Rule | Source |
|-------|------|--------|
| check-in `{appointment}` | existing appointment (404); status `scheduled` and date = clinic today, else 422 `not_checkable` on `appointment` | FR-001, FR-004 |
| walk-in `patient_id` | required integer ≥ 1; existing patient (422); not already `waiting`/`with_doctor` with this doctor today (422 `already_in_queue`) | FR-006 |
| walk-in `doctor_id` | required integer ≥ 1; existing, active doctor (422) | FR-006 |
| list `status` | optional, one of the four statuses | FR-007 |
| list `per_page` | optional 1–100, default 15 (`GET /queue` only) | R8 |

## State transitions

```text
 queue entry
   check-in / walk-in          call next              finish
 (none) ──────────────▶ waiting ─────────▶ with_doctor ───────▶ seen
                          │
                          ├─ leave ─────▶ left
                          └─ undo (appointment entries only) ─▶ (entry removed)

 appointment (feature 006 + 007)
   scheduled ── check-in ──▶ checked_in ── finish ──▶ completed
       ▲                         │
       └──────── undo ───────────┘
```

- `seen` and `left` are final. `with_doctor` cannot be left or undone; it ends with finish.
- `checked_in` and `completed` appointments hold their slot and cannot be rescheduled, cancelled, or
  marked no-show (feature 006 returns `not_changeable`).
