# Data Model: Patient Registration and Records

**Feature**: 003-patient-records | **Date**: 2026-10-28

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. One new table in one new migration. No existing
migration is edited.

## `patients` — Patient

Model: `App\Modules\Patients\Models\Patient`.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `bigint unsigned` auto-increment | no | | Primary key; **the patient number** (R2) |
| `full_name` | `varchar(255)` | no | | Trimmed, repeated spaces collapsed, as entered otherwise |
| `name_search` | `varchar(255)` | no | | Normalized copy of `full_name` (R3); set only by the model mutator; never in responses |
| `date_of_birth` | `date` | no | | |
| `sex` | `varchar(10)` | no | | Cast to enum `Sex`: `male`, `female` |
| `phone` | `varchar(16)` | no | | Normalized: digits with optional leading `+` (R4) |
| `national_id` | `char(14)` | yes | `NULL` | **Unique**; sensitive (R5) |
| `address` | `varchar(500)` | yes | `NULL` | Sensitive |
| `emergency_contact_name` | `varchar(255)` | yes | `NULL` | Sensitive (R9) |
| `emergency_contact_relationship` | `varchar(50)` | yes | `NULL` | Sensitive |
| `emergency_contact_phone` | `varchar(16)` | yes | `NULL` | Sensitive; normalized like `phone` |
| `created_at` | `timestamp` | yes | | Registered at (FR-007) |
| `updated_at` | `timestamp` | yes | | Last changed at (FR-007) |

Indexes: `PRIMARY (id)`, `UNIQUE patients_national_id_unique (national_id)`,
`INDEX patients_phone_index (phone)`, `INDEX patients_name_search_index (name_search)`. The
`name_search` index serves the list ordering; contains-search scans it (R3).

No foreign keys: the table refers to nothing yet. Later features (appointments, encounters,
payments) will reference `patients.id`. No soft-delete column: records are never deleted (FR-008).

**Sensitive fields** (never in lists, errors, or logs — FR-013, FR-017): `national_id`, `address`,
and the three `emergency_contact_*` columns.

## Validation rules (from the spec)

Applied on create; on update each field is validated only when sent (partial update, `PATCH`).

| Field | Rule | Source |
|-------|------|--------|
| `full_name` | required, string, 1–255 after trimming and collapsing spaces | FR-001 |
| `date_of_birth` | required, date `Y-m-d`, not after today, not before today − 130 years | FR-005 |
| `sex` | required, `male` or `female` | FR-001 |
| `phone` | required; spaces, dashes, parentheses removed; then `^\+?[0-9]{8,15}$` | FR-006 |
| `national_id` | nullable; `^[23][0-9]{13}$`; unique among all patients (ignoring the patient being updated) | FR-004, R5 |
| `address` | nullable, string, ≤ 500 | FR-002 |
| `emergency_contact` | nullable object; when present, `name` (≤ 255), `relationship` (≤ 50), and `phone` (rule as `phone`) are all required | FR-002, R9 |

`id`, `name_search`, `created_at`, and `updated_at` are never accepted from requests.

## List filters (`GET /patients`)

| Parameter | Rule | Match |
|-----------|------|-------|
| `name` | string, 1–255 | `name_search` contains the normalized text (R3) |
| `phone` | normalized as above, `^\+?[0-9]{8,15}$` | exact |
| `national_id` | `^[23][0-9]{13}$` | exact |
| `id` | integer ≥ 1 | exact |
| `page` | integer ≥ 1 | |
| `per_page` | integer 1–100, default 15 | |

Filters combine with AND; ordering is `name_search`, then `id` (R8).

## Name normalization (R3)

`ArabicName::normalize(string $name): string`, applied to stored names and search text:

1. Trim; collapse runs of whitespace to one space.
2. Lower-case (multibyte-safe).
3. Replace `أ` `إ` `آ` with `ا`; `ة` with `ه`; `ى` with `ي`.

Examples: `"  أحمد   محمود "` → `"احمد محمود"`; `"فاطمة"` → `"فاطمه"`; `"Mostafa Ali"` →
`"mostafa ali"`.

## State transitions

None. A patient is created, then updated any number of times; there is no status and no deletion
in this feature.

## Factory (synthetic data only — FR-018, R12)

`database/factories/PatientFactory.php`: Faker names (partly `ar_EG`, built from `firstName()` and
`lastName()`), dates of birth 0–90 years ago, random `sex`, phones in the `01XXXXXXXXX` shape,
`national_id` null by default. States: `withNationalId()`, `withEmergencyContact()`,
`withAddress()`. No seeder.
