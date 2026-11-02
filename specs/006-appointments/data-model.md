# Data Model: Appointments

**Feature**: 006-appointments | **Date**: 2026-11-01

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. One new table in one new migration. No existing
migration is edited; `patients`, `doctors`, `users`, `doctor_schedules`, `schedule_periods`, and
`doctor_time_off` are referenced or read, not changed.

Dates and times are the clinic's wall clock (`Africa/Cairo`), as in feature 005. Times of day are
minutes since midnight (`540` = 09:00); the API uses `"HH:MM"` (`ClockTime`, feature 005 R3).

## `appointments` — Appointment

Model: `App\Modules\Appointments\Models\Appointment`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; the appointment id in the API |
| `patient_id` | `bigint unsigned` | no | FK → `patients.id`, `RESTRICT` |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `date` | `date` | no | Clinic date |
| `start_minute` | `smallint unsigned` | no | 0–1435, on the slot grid at booking time |
| `end_minute` | `smallint unsigned` | no | `start_minute` + the doctor's appointment length at booking time; kept when the length later changes |
| `status` | `varchar(20)` | no | `scheduled` \| `cancelled` \| `no_show` (`AppointmentStatus` enum) |
| `scheduled_flag` | `tinyint unsigned` **stored generated** | yes | `IF(status = 'scheduled', 1, NULL)`; only for the unique index (R3); never written or exposed |
| `note` | `varchar(500)` | yes | Administrative booking note; single-appointment views only |
| `booked_by` | `bigint unsigned` | no | FK → `users.id`, `RESTRICT` |
| `rescheduled_by` | `bigint unsigned` | yes | FK → `users.id`, `RESTRICT`; latest reschedule |
| `rescheduled_at` | `timestamp` | yes | Latest reschedule (UTC instant) |
| `status_changed_by` | `bigint unsigned` | yes | FK → `users.id`, `RESTRICT`; who cancelled or marked no-show |
| `status_changed_at` | `timestamp` | yes | When it was cancelled or marked no-show |
| `cancellation_reason` | `varchar(255)` | yes | Only for `cancelled` |
| `created_at`, `updated_at` | `timestamp` | yes | `created_at` is "booked at" |

**Indexes**

- `PRIMARY (id)`
- `UNIQUE appointments_doctor_slot_unique (doctor_id, date, start_minute, scheduled_flag)` — at
  most one scheduled appointment per doctor slot start (FR-003 backstop, R3); also serves doctor
  range queries and overlap checks.
- `INDEX appointments_patient_date_index (patient_id, date, start_minute)` — patient history and
  patient overlap checks (FR-004, SC-005).
- `INDEX appointments_date_index (date, start_minute)` — range listings (FR-008).
- FK indexes on `booked_by`, `rescheduled_by`, `status_changed_by` (created by `foreignId`).

Never deleted (spec, No deletion). `RESTRICT` on every foreign key keeps referenced patients,
doctors, and staff accounts from being removed.

**Relations** (model): `patient()` → `Patients\Models\Patient`; `doctor()` →
`Doctors\Models\Doctor`; `bookedBy()`, `rescheduledBy()`, `statusChangedBy()` →
`Staff\Models\User`. Casts: `date` → `date`, `status` → `AppointmentStatus`, timestamps →
`datetime`.

## Validation rules

| Field | Rule | Source |
|-------|------|--------|
| `patient_id` | required integer ≥ 1; must be an existing patient (service, after lock → 422) | FR-001, US1-7 |
| `doctor_id` | required integer ≥ 1; must be an existing doctor (service → 422) | FR-001, US1-7 |
| `date` | required `Y-m-d`; from clinic today to today + 90 days, else `slot_unavailable` | FR-002, R4 |
| `start` | required, `ClockTime::START_PATTERN` (`HH:MM`, 5-minute steps); must be a working-slot start that day (service → 422 `slot_unavailable`) | FR-002, R4 |
| `note` | optional string ≤ 500, trimmed, empty → null | FR-001, R11 |
| `reason` (cancel) | optional string ≤ 255, trimmed, empty → null | FR-012 |
| list `from`, `to` | required `Y-m-d`, `to ≥ from`, ≤ 92 days inclusive | FR-008, FR-015 |
| list `status` | optional, one of the three statuses | FR-008 |
| list `doctor_id`, `patient_id` | optional integer ≥ 1 | FR-008 |
| list `outside_availability` | optional boolean | FR-008, FR-018 |
| list `per_page` | optional 1–100, default 15 | R10 |

Conflicts (409, not validation): doctor already booked at an overlapping time → `slot_booked`;
patient already booked at an overlapping time → `patient_busy` (R4, R6).

## State transitions

```text
          book
 (none) ───────▶ scheduled ──reschedule (not started)──▶ scheduled
                    │
                    ├──cancel (not started)──▶ cancelled   final
                    └──no-show (started)─────▶ no_show     final
```

- Only `scheduled` appointments hold a slot (`scheduled_flag = 1`), count for overlap checks, or
  are hidden from feature 005's slots.
- Reschedule keeps `id`, `patient_id`, `doctor_id`, `status`, `note`, `booked_by`, `created_at`;
  replaces `date`, `start_minute`, `end_minute`; sets `rescheduled_by`, `rescheduled_at`.
- Cancel sets `status = cancelled`, `status_changed_by/at`, `cancellation_reason`.
- No-show sets `status = no_show`, `status_changed_by/at`.

## Derived: `outside_availability`

Not stored; computed by the repository on every read (R7). True only for a `scheduled`
appointment that has not started when, for its doctor: no working period of that weekday fully
covers `[start_minute, end_minute)`, or time off overlaps it, or the doctor is inactive.

## Changes to existing modules (code, not schema)

| Module | Change | Why |
|--------|--------|-----|
| Scheduling | New contract `BookedTimeRepository` (implemented by Appointments) | R2 |
| Scheduling | `SlotCalculator::slots(..., array $booked = [])` drops slots overlapping booked times | FR-006, R2 |
| Scheduling | `SlotService::workingSlots()`; `forDoctor()` subtracts booked times | FR-006, R2, R4 |
| Scheduling | `DoctorScheduleRepository::lockForDoctor(int $doctorId): ?DoctorSchedule` | R3 |
| Patients | `PatientRepository::lockById(int $id): ?Patient` | R3 |
