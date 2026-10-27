# Implementation Plan: Staff Authentication and Roles

**Branch**: `002-staff-auth-roles` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-staff-auth-roles/spec.md`

## Summary

Add staff accounts, token-based sign-in, and the three roles in one new module,
`app/Modules/Staff/`. Sign-in issues a Laravel Sanctum personal access token (new dependency,
D1), which expires after 8 idle hours through Sanctum's token-validation callback. The same
callback refuses deactivated accounts on every request. Passwords are hashed with Argon2id (D2) so
long Arabic passwords are not truncated. Roles are a fixed enum on the `users` table; Policies
enforce administrator-only staff management. A last-administrator rule runs in a locking
transaction. An Artisan command creates the first administrator with no seeded credentials. All
messages are in `en` and `ar`, including the 401 and 403 messages that the framework hard-codes in
English. Evidence for each choice is in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing `php:8.5.12-fpm-trixie` image, unchanged)

**Primary Dependencies**: Laravel Framework 13.38.0 (installed). **New: `laravel/sanctum ^4.3`**
(v4.3.3; approved D1). No other packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. New tables `users` and
`personal_access_tokens` ([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test`, against `clinic_testing`, Feature tests per user
story; `Carbon` time travel for expiry

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: Sign-in plus profile under 5 s (SC-001); Argon2id cost at framework
defaults in development, lowered only in `phpunit.xml`

**Constraints**: Token-only API (no cookies or sessions); no email; no seeders; passwords and
tokens never in responses or logs (FR-028)

**Scale/Scope**: Single clinic, tens of staff accounts. 11 endpoints, 1 Artisan command, 1 module,
2 migrations

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | Every endpoint except sign-in requires a token; Policies enforce least privilege; three separated roles; tokens stored hashed; generic sign-in failure and dummy-hash timing; rate limiting; passwords hidden from serialization; no credentials in code or seeds | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions (Sanctum, Policies, Form Requests); two new migrations, none edited; `/api/v1` via the module provider | ✅ |
| III | Repository pattern | `StaffMemberRepository` and `AccessTokenRepository` interfaces with Eloquent implementations, bound in `StaffServiceProvider`; route parameters are plain ids loaded through the repository (R10); no base repository | ✅ |
| IV | Thin controllers | Controllers: Form Request in → authorize → service call → Resource out | ✅ |
| V | Services own workflows | `AuthenticationService` (sign-in, rate limit, sign-out, own password) and `StaffManagementService` (create, update, deactivate, reactivate, set password, last-admin rule, transactions) | ✅ |
| VI | Form Requests/Resources/Policies | One Form Request per write and per list filter; `StaffMemberResource`, `SignInResource`; `StaffMemberPolicy` for every staff-management ability | ✅ |
| VII | en/ar localization | Module translations `staff::messages`; `auth.unauthenticated` and `auth.forbidden` added to `lang/{en,ar}/auth.php`; locale middleware prioritized ahead of authentication (R6); tests in both locales | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per user story, a full role × action matrix (SC-002), expiry, revocation, and both locales | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, tables, and rules trace to the spec and contract; the one new dependency is justified (R1) and approved (D1) | ✅ (D1 approved) |
| XI | Integrity & concurrency | Deactivation and role changes run in a transaction that locks the active-administrator rows (R8) | ✅ |
| XII | No real data | No seeders; factories with synthetic names and `*.test` emails; passwords and tokens never logged | ✅ |

**Gate result (pre-research)**: PASS (D1 approved 2026-10-27). **Post-design re-check**:
PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum
                              │  SetLocaleFromHeader runs first (priority, R6)
                              │  Sanctum Guard → findToken → TokenValidity callback:
                              │     valid && account active && idle < 8 h   (R2)
                              ▼
                     Controller (thin) ── Form Request (validation)
                              │          └─ $this->authorize() → StaffMemberPolicy
                              ▼
            AuthenticationService / StaffManagementService   (rules, transactions)
                              │
                              ▼
          StaffMemberRepository / AccessTokenRepository  (Eloquent only here)
```

### Endpoints ([contract](contracts/staff-auth.openapi.yaml))

| Method | Path (`/api/v1`) | Who | Spec |
|--------|------------------|-----|------|
| POST | `/auth/sign-in` | anyone | FR-005–FR-007 |
| POST | `/auth/sign-out` | any signed-in | FR-008 |
| GET | `/auth/me` | any signed-in | FR-011 |
| PUT | `/auth/password` | any signed-in | FR-023 |
| GET | `/staff` | administrator | FR-017 |
| POST | `/staff` | administrator | FR-016 |
| GET | `/staff/{id}` | administrator | FR-014 |
| PATCH | `/staff/{id}` | administrator | FR-018, FR-021 |
| POST | `/staff/{id}/deactivate` | administrator | FR-019, FR-021 |
| POST | `/staff/{id}/reactivate` | administrator | FR-019 |
| PUT | `/staff/{id}/password` | administrator | FR-020 |

Status codes: `401` unauthenticated, `403` forbidden, `404` unknown id, `422` validation, failed
sign-in, throttling, or the last-administrator rule. Resources use Laravel's default `data`
wrapper; the list uses the default paginated shape.

### Module layout (new files)

```text
app/Modules/Staff/
├── Console/CreateAdministratorCommand.php        # staff:create-admin (R12)
├── Enums/Role.php
├── Exceptions/LastAdministratorException.php     # renders 422, staff::messages.last_administrator
├── Exceptions/StaffMemberNotFoundException.php   # renders 404, staff::messages.not_found
├── Http/
│   ├── Controllers/AuthController.php            # signIn, signOut, me, changePassword
│   ├── Controllers/StaffMemberController.php     # index, store, show, update
│   ├── Controllers/StaffMemberStatusController.php   # deactivate, reactivate
│   ├── Controllers/StaffMemberPasswordController.php # invokable: set password
│   ├── Requests/{SignIn,ChangeOwnPassword,ListStaff,StoreStaffMember,UpdateStaffMember,SetStaffMemberPassword}Request.php
│   └── Resources/{StaffMember,SignIn}Resource.php
├── Models/User.php                               # HasApiTokens, HasFactory, role cast, password hidden
├── Policies/StaffMemberPolicy.php
├── Providers/StaffServiceProvider.php            # bindings, routes, policy, translations, command, token callback
├── Repositories/
│   ├── Contracts/{StaffMember,AccessToken}Repository.php
│   └── {EloquentStaffMember,SanctumAccessToken}Repository.php
├── Services/{Authentication,StaffManagement}Service.php
├── Support/PasswordRules.php                     # one definition of the 12–72 rule, used by requests and the command
├── routes/api.php
└── lang/{en,ar}/messages.php
database/migrations/<date>_create_users_table.php
database/migrations/<date>_create_personal_access_tokens_table.php   # published by Sanctum
database/factories/UserFactory.php                # synthetic data; states: administrator(), receptionist(), doctor(), inactive()
config/sanctum.php  config/hashing.php            # published, then edited (R1, R3)
```

Changed existing files: `composer.json`/`composer.lock` (Sanctum), `config/auth.php` (users
provider model), `bootstrap/app.php` (middleware priority, 401/403 rendering),
`bootstrap/providers.php` (StaffServiceProvider), `lang/{en,ar}/auth.php` (two keys),
`phpunit.xml` (Argon2 test cost), `README.md` (first-administrator step and sign-in), and
`docs/architecture.md` (route parameters loaded through repositories, R10).

### Key rules

- **Token validity** (R2): one callback, registered in `StaffServiceProvider::boot()`. It reads the
  idle timeout from `config('sanctum.idle_timeout_minutes')`.
- **Revocation** (`AccessTokenRepository`): `deleteCurrent($token)`, `deleteAllFor($user)`,
  `deleteAllExcept($user, $token)`, `deleteIdleFor($user, $cutoff)`.
- **Transactions**: `StaffManagementService` owns `DB::transaction()` for deactivate, role change,
  and set-password plus revocation. `StaffMemberRepository::lockActiveAdministrators()` runs
  `SELECT … FOR UPDATE` inside it (R8).
- **Normalization**: Form Requests trim and lower-case `email` in `prepareForValidation()`.
- **Never logged or serialized**: `password` (model `$hidden`), plain tokens (returned once by
  sign-in only), and request passwords (Laravel's default `dontFlash` list already covers
  `password`, `password_confirmation`, `current_password`).

## Testing Strategy

| Test (`tests/Feature/Staff/`) | Covers |
|-------------------------------|--------|
| `SignInTest` | success; email case and spaces; one generic message for unknown, wrong, and deactivated; throttling after 5; counter reset on success |
| `SignOutTest` | current token revoked; another token of the same account still works |
| `OwnProfileTest` | profile fields; no password; 401 without a token |
| `TokenExpiryTest` | valid at 7 h 59 m idle; refused after 8 h; each request restarts the period (time travel) |
| `AuthorizationMatrixTest` | data provider: 3 roles × 7 staff-management actions → only administrator allowed; 401 for no token |
| `StaffManagementTest` | create, duplicate email (including deactivated owner), list filters and paging, show, update, 404 |
| `DeactivationTest` | all tokens revoked; sign-in refused; reactivation allows sign-in |
| `LastAdministratorTest` | deactivating or demoting the only active administrator refused; allowed when another exists |
| `PasswordTest` | admin set-password revokes all; own change keeps the current token, revokes the others; wrong current password; 12/72 boundaries |
| `CreateAdministratorCommandTest` | creates an active administrator; duplicate email and short password fail and create nothing |
| `StaffMessagesTest` | 401, 403, 404, 422 (failed, throttle, last-admin) in `en` and `ar` with identical structure |

`TranslationParityTest` (feature 001) also covers the new `lang/*/auth.php` keys. Module
translations get the same parity check, extended to `app/Modules/*/lang`.

## Implementation Outline (input for `/speckit-tasks`)

1. Install Sanctum (D1); publish its config and migration; set `guard => []`, `expiration => null`,
   `idle_timeout_minutes => 480`.
2. Publish `config/hashing.php` and set Argon2id (D2); lower the Argon2 cost in `phpunit.xml`.
3. `users` migration, `Role` enum, `User` model, `UserFactory`; point `config/auth.php` at the model.
4. Staff module skeleton: provider (bindings, routes, translations), registered in
   `bootstrap/providers.php`.
5. Localization groundwork: middleware priority (R6), 401/403 rendering (R5), new `auth` keys
   (en/ar), `staff::messages` (en/ar), and extended parity test.
6. US1: repositories, `AuthenticationService`, sign-in/sign-out/me, token callback (R2), tests.
7. US2: `StaffMemberPolicy`, authorization matrix test.
8. US3: `StaffManagementService`, staff endpoints, last-administrator rule, deactivation
   revocation, tests.
9. US4: `staff:create-admin` command and test; README section.
10. US5: own password change and test.
11. Polish: docs updates, Pint, full quickstart run, verification record.

## Approved Decisions (2026-10-27)

All four were approved by the user on 2026-10-27.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | Token authentication package | **Add `laravel/sanctum ^4.3`** (first-party; hashed tokens, per-token revocation, `last_used_at`) — R1 | Framework `TokenGuard`: one plain-text token per account, no last-used tracking |
| D2 | Password hashing | **Argon2id** via published `config/hashing.php` (no 72-byte truncation of Arabic passwords) — R3 | Keep bcrypt and validate a 72-*byte* maximum |
| D3 | Module and model names | **Module `Staff`**, model `User`, table `users`, Laravel's `users` auth provider — R11 | Separate `Auth` and `Users` modules; a `staff_members` table |
| D4 | Failed sign-in status | **422** with `errors.email` (Laravel convention), same for throttling — R7 | 401 for wrong credentials and 429 for throttling |

## Risks, Assumptions, and Rollback

- **Global 401/403 messages change** (R5): every later module inherits the translated messages.
  This is intended; the JSON structure is unchanged.
- **Token rows** of accounts that never sign in again stay until the account signs in again (R2).
  A scheduled prune is deferred until the project has a scheduler.
- **Argon2id cost** at framework defaults (64 MB, time 4) is per sign-in; acceptable for tens of
  staff. Tune via `ARGON_MEMORY`/`ARGON_TIME` if needed.
- **Rollback**: `php artisan migrate:rollback` for the two migrations (destructive for staff
  data; opt-in only), `composer remove laravel/sanctum`, and revert the commit range.

## Project Structure

### Documentation (this feature)

```text
specs/002-staff-auth-roles/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── staff-auth.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/
├── Http/Middleware/SetLocaleFromHeader.php     # existing; now prioritized ahead of auth
└── Modules/
    ├── System/                                 # existing (health)
    └── Staff/                                  # new (see Module layout above)
bootstrap/app.php  bootstrap/providers.php      # changed
config/auth.php  config/sanctum.php  config/hashing.php
database/migrations/  database/factories/UserFactory.php
lang/{en,ar}/auth.php                           # two keys added
tests/Feature/Staff/                            # new tests listed above
```

**Structure Decision**: The existing modular monolith from feature 001. One new domain module,
`Staff`, following `docs/architecture.md`; no new top-level directories.

## Complexity Tracking

No Constitution violations; nothing to justify.
