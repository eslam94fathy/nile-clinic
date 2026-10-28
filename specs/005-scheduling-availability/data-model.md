# Data Model: Scheduling and Availability

**Feature**: 005-scheduling-availability | **Date**: 2026-10-28

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Three new tables in three new migrations. No existing
migration is edited; `doctors` is referenced, not changed. One new configuration key,
`app.clinic_timezone` (R2).

Times of day are stored as minutes since midnight (R3): `0` = 00:00, `540` = 09:00, `1440` = 24:00
(end only). Dates and times are the clinic's wall clock (`Africa/Cairo`), never converted.

## `doctor_schedules` — Weekly schedule

Model: `App\Modules\Scheduling\Models\DoctorSchedule`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; not exposed |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT`; **unique** (FR-001) |
| `appointment_minutes` | `smallint unsigned` | no | 5–240, multiple of 5 (FR-002) |
| `created_at`, `updated_at` | `timestamp` | yes | `updated_at` returned as "last changed" |

Indexes: `PRIMARY (id)`, `UNIQUE doctor_schedules_doctor_id_unique (doctor_id)`.

Deleted when an administrator clears the schedule (R4).

## `schedule_periods` — Working period

Model: `App\Modules\Scheduling\Models\SchedulePeriod`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; not exposed |
| `doctor_schedule_id` | `bigint unsigned` | no | FK → `doctor_schedules.id`, `ON DELETE CASCADE` |
| `weekday` | `tinyint unsigned` | no | 0 = Sunday … 6 = Saturday |
| `start_minute` | `smallint unsigned` | no | 0–1435, multiple of 5 |
| `end_minute` | `smallint unsigned` | no | 5–1440, multiple of 5, > `start_minute` |

Indexes: `PRIMARY (id)`, `INDEX schedule_periods_schedule_weekday_index (doctor_schedule_id,
weekday)`. Replaced as a set on every save (R4); no timestamps.

## `doctor_time_off` — Time off

Model: `App\Modules\Scheduling\Models\DoctorTimeOff`.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `start_date` | `date` | no | |
| `end_date` | `date` | no | ≥ `start_date` |
| `start_minute` | `smallint unsigned` | yes | Null for whole days; set together with `end_minute` |
| `end_minute` | `smallint unsigned` | yes | Null for whole days; > `start_minute`; only when `start_date = end_date` |
| `reason` | `varchar(255)` | yes | Free text, returned as entered |
| `created_at`, `updated_at` | `timestamp` | yes | `created_at` returned as "recorded at" |

Indexes: `PRIMARY (id)`, `INDEX doctor_time_off_doctor_dates_index (doctor_id, start_date,
end_date)`. Rows are removed only before they end (FR-017).

## Derived: Slot (not stored)

`{date, start, end}` for one doctor, computed on request (R6). Rules in order:

1. The doctor exists (else `404`), is active (feature 004 FR-012), and has a schedule — otherwise
   no slots.
2. For each date in the range, the periods for that weekday, cut from the period start into
   `appointment_minutes` pieces; a shorter leftover is dropped.
3. Dropped: pieces on a whole-day time off date; pieces overlapping a partial time off window
   (`start < off_end && end > off_start`); on today, pieces starting at or before the clinic's
   now; pieces whose start does not exist on that date in the clinic zone (spring-forward gap).

## Validation rules (from the spec)

| Field | Rules | Spec |
|-------|-------|------|
| `appointment_minutes` | required; integer; 5–240; multiple of 5 | FR-002 |
| `periods` | present; array; at most 28 items (may be empty: a schedule with no working hours) | FR-001 |
| `periods.*.day` | required; one of `sunday`…`saturday` | FR-001 |
| `periods.*.start` | required; `HH:MM`, 00:00–23:55, minutes a multiple of 5 | FR-003 |
| `periods.*.end` | required; `HH:MM` like start, or `24:00`; after start; at least `appointment_minutes` after start | FR-003 |
| periods per day | not overlapping or touching; at most 4 | FR-004 |
| slots `from`, `to` | required dates; `from` ≥ clinic today; `to` ≥ `from`; ≤ 31 days inclusive; `to` ≤ today + 90 days | FR-010 |
| time off `start_date` | required date; ≥ clinic today | FR-015 |
| time off `end_date` | required date; ≥ `start_date` | FR-013 |
| time off `start_time`, `end_time` | both or neither; only when `start_date = end_date`; `HH:MM` on 5 minutes (end may be `24:00`); end after start | FR-013, FR-014 |
| time off `reason` | optional; string; at most 255 | FR-013 |
| time off list `from`, `to` | required dates; `to` ≥ `from`; at most 366 days apart | FR-016 |

## Response shapes

Field names are identical in every locale (FR-021). Day names are fixed English identifiers.

- **Schedule**: `doctor_id`, `appointment_minutes` (null when none), `days` — always seven items in
  order `sunday`…`saturday`, each `{day, periods: [{start, end}]}` — and `updated_at` (null when
  none).
- **Slot**: `date` (`YYYY-MM-DD`), `start`, `end` (`HH:MM`; `end` may be `24:00`).
- **Time off**: `id`, `doctor_id`, `start_date`, `end_date`, `start_time`, `end_time` (both null for
  whole days), `reason`, `created_at`.
