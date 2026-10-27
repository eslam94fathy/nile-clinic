# Data Model: Staff Authentication and Roles

**Feature**: 002-staff-auth-roles | **Date**: 2026-10-27

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Two new tables, each in its own migration. No existing
migration is edited.

## `users` — Staff account

Model: `App\Modules\Staff\Models\User` (uses Sanctum `HasApiTokens`).

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | `bigint unsigned` auto-increment | no | | Primary key |
| `name` | `varchar(255)` | no | | Full name, trimmed, 1–255 chars |
| `email` | `varchar(255)` | no | | Trimmed and lower-cased before storing; **unique index**; the collation is case-insensitive (R9) |
| `password` | `varchar(255)` | no | | Argon2id hash (R3); never serialized (model `$hidden`) |
| `role` | `varchar(20)` | no | | Cast to enum `Role`: `administrator`, `receptionist`, `doctor` (R4) |
| `is_active` | `boolean` | no | `true` | Deactivated accounts keep their row (FR-004) |
| `created_at` | `timestamp` | yes | | |
| `updated_at` | `timestamp` | yes | | |

Indexes: `PRIMARY (id)`, `UNIQUE users_email_unique (email)`,
`INDEX users_role_is_active_index (role, is_active)`. The composite index serves the staff list
filters (FR-017) and the active-administrator count (FR-021).

There is no `remember_token` or `email_verified_at`: the API has no sessions and sends no email
(clarification Q3).

### Validation rules (from the spec)

| Field | Rule | Source |
|-------|------|--------|
| `name` | required, string, 1–255 after trimming | FR-001 |
| `email` | required, valid email, ≤ 255, unique among **all** accounts including deactivated | FR-001, edge cases |
| `role` | required, one of the three `Role` values | FR-002, FR-003 |
| `password` | required, string, 12–72 characters, confirmed | FR-022 |
| `current_password` | required, matches the signed-in account's password | FR-023 |

### State transitions

```text
          create (admin or staff:create-admin)
                      │
                      ▼
   ┌──────────── active (is_active = true) ◀────────────┐
   │                                                    │
   │ deactivate (admin)                                 │ reactivate (admin)
   │  • ends ALL tokens                                 │  • account can sign in again;
   │  • refused if it is the last active administrator  │    earlier tokens stay deleted
   ▼                                                    │
 deactivated (is_active = false) ───────────────────────┘
```

Other changes on an active account:

- Role change (admin): applies on the next request (role is read per request); refused if it would
  remove the last active administrator. Tokens are kept.
- Admin sets password: ends **all** tokens of that account (FR-020).
- Own password change: ends all tokens **except** the one used for the request (FR-023).
- There is no delete transition.

## `personal_access_tokens` — Access credential

Created by Sanctum's published migration (R1), unchanged:

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint unsigned` | Primary key; the part before `|` in the bearer token |
| `tokenable_type`, `tokenable_id` | morph to `users` | Indexed together |
| `name` | `text` | Fixed value `api` (no device naming in this feature) |
| `token` | `varchar(64)` | **Unique**; SHA-256 of the secret part; the plain token is shown only once at sign-in |
| `abilities` | `text` | `["*"]`; abilities are not used, roles are (R4) |
| `last_used_at` | `timestamp` | Updated on every authenticated request; drives the 8-hour expiry (R2) |
| `expires_at` | `timestamp` | Unused (null) |
| `created_at`, `updated_at` | `timestamp` | |

A token is valid when: it exists, its account is active, and `last_used_at` (or `created_at` if
never used) is less than 8 hours ago (R2). Deleted rows are the revocation mechanism.

## Enum `Role`

`App\Modules\Staff\Enums\Role` — string-backed: `Administrator = 'administrator'`,
`Receptionist = 'receptionist'`, `Doctor = 'doctor'`. API values are these lowercase strings in
every locale (Constitution VII).
