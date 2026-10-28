# Data Model: Doctors and Specialties

**Feature**: 004-doctors-specialties | **Date**: 2026-10-28

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Three new tables in three new migrations. No existing
migration is edited; the `users` table is read, not changed.

## `specialties` — Specialty

Model: `App\Modules\Doctors\Models\Specialty`.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `bigint unsigned` auto-increment | no | | Primary key |
| `name_en` | `varchar(100)` | no | | **Unique**; trimmed, repeated spaces collapsed (R5) |
| `name_ar` | `varchar(100)` | no | | **Unique**; cleaned like `name_en` |
| `is_active` | `boolean` | no | `true` | Only active specialties can be newly assigned (FR-008) |
| `created_at` | `timestamp` | yes | | |
| `updated_at` | `timestamp` | yes | | |

Indexes: `PRIMARY (id)`, `UNIQUE specialties_name_en_unique (name_en)`,
`UNIQUE specialties_name_ar_unique (name_ar)`. Uniqueness ignores Latin case through the collation.

Never deleted (FR-003).

## `doctors` — Doctor profile

Model: `App\Modules\Doctors\Models\Doctor`.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `bigint unsigned` auto-increment | no | | Primary key; the doctor id (R2) |
| `user_id` | `bigint unsigned` | no | | FK → `users.id`, `RESTRICT`; **unique** (FR-005) |
| `title` | `varchar(100)` | no | | Cleaned like names, otherwise as entered |
| `registration_number` | `varchar(30)` | yes | `NULL` | **Unique**; letters and digits (R8) |
| `created_at` | `timestamp` | yes | | |
| `updated_at` | `timestamp` | yes | | |

Indexes: `PRIMARY (id)`, `UNIQUE doctors_user_id_unique (user_id)` (also serves the foreign key),
`UNIQUE doctors_registration_number_unique (registration_number)` (many `NULL`s allowed).

Relations: `belongsTo User` (`App\Modules\Staff\Models\User`), `belongsToMany Specialty` through
`doctor_specialty`. Never deleted (FR-010).

Not stored: the doctor's name (from `users.name`, FR-011) and active status (derived, below).

## `doctor_specialty` — Doctor specialty assignment

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT` |
| `specialty_id` | `bigint unsigned` | no | FK → `specialties.id`, `RESTRICT` |

Indexes: `PRIMARY (doctor_id, specialty_id)`, `INDEX doctor_specialty_specialty_id_index
(specialty_id)` for the specialty filter. No timestamps.

Invariant: every doctor has at least one row (FR-009), enforced by validation and by writing the
profile and its rows in one transaction (R6).

## Derived state

| State | Rule | Where |
|-------|------|-------|
| Doctor active (FR-012) | `users.is_active = true` **and** `users.role = 'doctor'` | `Doctor::isActive()` on the loaded user; the same two conditions in the repository's `is_active` filter |

```text
            account active + role doctor
  ┌──────────┐  ───────────────────────▶  ┌────────┐
  │ inactive │                             │ active │
  └──────────┘  ◀───────────────────────  └────────┘
            account deactivated, or role changed (feature 002)
```

Specialty: `active ⇄ inactive` through deactivate / reactivate (both idempotent).

## Validation rules (from the spec)

Applied on create; on update each field is validated only when sent (partial update, `PATCH`); an
update with no updatable field is refused (`nothing_to_update`).

| Field | Rules | Spec |
|-------|-------|------|
| `name_en`, `name_ar` | required on create; string; 1–100 characters after cleaning; unique among specialties (ignoring the specialty itself on rename) | FR-001, FR-002 |
| `user_id` | required on create, not updatable; existing staff account; no profile yet; account active with the doctor role (service) | FR-005, FR-006 |
| `title` | required on create; string; 1–100 characters after cleaning | FR-007 |
| `registration_number` | optional, nullable; `^[A-Za-z0-9]{1,30}$`; unique among doctors (ignoring the doctor itself on update); `null` clears | FR-007 |
| `specialty_ids` | required on create; array of at least 1 distinct existing specialty ids; each active or already assigned to this doctor (service); replaces the set on update | FR-007–FR-009 |

List filters: `name` (string, 1–255), `specialty_id` (integer ≥ 1), `is_active` (boolean),
`page` (≥ 1), `per_page` (1–100, default 15). Specialty list: `is_active` (boolean).

## Response shapes

Field names are identical in every locale (FR-019); entered values are never translated.

- **Specialty**: `id`, `name_en`, `name_ar`, `is_active` — everywhere a specialty appears
  (FR-004a).
- **Doctor list item**: `id`, `name`, `title`, `is_active`, `specialties[]` (FR-015).
- **Doctor profile**: the list item plus `registration_number`, `created_at`, `updated_at`
  (FR-016).

Never returned: the account's `email`, `role`, or `user_id` (FR-015).
