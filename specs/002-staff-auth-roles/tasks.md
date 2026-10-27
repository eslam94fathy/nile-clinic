---
description: "Task list for feature 002: Staff Authentication and Roles"
---

# Tasks: Staff Authentication and Roles

**Input**: Design documents from `specs/002-staff-auth-roles/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/staff-auth.openapi.yaml](contracts/staff-auth.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
  PowerShell users: replace `$?`/`echo $?` with `$LASTEXITCODE`.
- Module root: `app/Modules/Staff/` (abbreviated `Staff/` below only inside a task's Scope list;
  task titles use full paths).

## Command Verification Rule

Only commands confirmed in research.md (Laravel 13.38 Artisan, Composer in the `app` container,
Sanctum v4.3.x publishing, PHPUnit via `php artisan test`/`composer test`, Pint) are listed. If a
command does not behave as written, **stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add the approved dependency and configuration (D1, D2).

- [X] T001 Install Sanctum in `composer.json` and `composer.lock` (approved D1)
  - Scope: `docker compose exec app composer require laravel/sanctum:^4.3`. Do **not** run
    `php artisan install:api` (it would create `routes/api.php`; research R1).
  - Accept: `composer.json` requires `"laravel/sanctum": "^4.3"`; no other new package.
  - Verify:
    - `docker compose exec app composer show laravel/sanctum` → version `v4.3.x`
    - `git diff composer.json` → only the Sanctum line added

- [X] T002 Publish and configure `config/sanctum.php` and the `personal_access_tokens` migration (R1, R2)
  - Scope:
    - `docker compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`
    - In `config/sanctum.php` set `'guard' => []` (token-only, no cookie/session auth),
      `'expiration' => null`, `'token_prefix' => ''`.
    - Add `'idle_timeout_minutes' => 480` with a comment: "Tokens expire after this many minutes
      without a request (FR-009); enforced by the Staff module's token callback."
    - Keep the published migration unchanged.
  - Accept: config values as listed; one new migration `*_create_personal_access_tokens_table.php`.
  - Verify: `docker compose exec app php artisan tinker --execute="var_dump(config('sanctum.guard'), config('sanctum.expiration'), config('sanctum.idle_timeout_minutes'));"` → `array(0)`, `NULL`, `int(480)`
  - Depends: T001

- [X] T003 [P] Publish `config/hashing.php` and switch to Argon2id; lower its cost in `phpunit.xml` (approved D2, R3)
  - Scope:
    - `docker compose exec app php artisan config:publish hashing`
    - Set `'driver' => env('HASH_DRIVER', 'argon2id')`; leave the `argon` block's env keys
      (`ARGON_MEMORY`, `ARGON_THREADS`, `ARGON_TIME`) as published.
    - `phpunit.xml`: add `<env name="ARGON_MEMORY" value="1024"/>` and `<env name="ARGON_TIME" value="1"/>`
      next to `BCRYPT_ROUNDS`.
    - If the published file does not contain an `argon` block reading those env keys, **stop and report**.
  - Accept: new hashes are Argon2id; tests run with the low cost.
  - Verify:
    - `docker compose exec app php artisan tinker --execute="echo Hash::info(Hash::make('x'))['algoName'];"` → `argon2id`
    - `docker compose exec app composer test` → existing 19 tests pass

**Checkpoint**: Dependency and configuration in place.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, model, module skeleton, and localized refusal messages that every story needs.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Create the `users` migration in `database/migrations/<timestamp>_create_users_table.php`
  - Scope: `docker compose exec app php artisan make:migration create_users_table`, then exactly
    the columns in data-model.md:
    - `id` bigint unsigned auto-increment primary key
    - `name` `varchar(255)` not null
    - `email` `varchar(255)` not null, **unique index** `users_email_unique`
    - `password` `varchar(255)` not null
    - `role` `varchar(20)` not null
    - `is_active` `boolean` not null, default `true`
    - `timestamps()`
    - composite index `users_role_is_active_index (role, is_active)`
    - No `remember_token`, no `email_verified_at`.
  - Accept: the migration matches data-model.md; `down()` drops the table.
  - Verify: covered by T009
  - Depends: T002

- [X] T005 [P] Create the role enum in `app/Modules/Staff/Enums/Role.php`
  - Scope: string-backed enum `Role`: `Administrator = 'administrator'`, `Receptionist = 'receptionist'`,
    `Doctor = 'doctor'`. No other cases (FR-002).
  - Accept: three cases with those exact values.
  - Verify: covered by T008/T009

- [X] T006 Create the model in `app/Modules/Staff/Models/User.php`
  - Scope:
    - `class User extends Authenticatable` using `HasApiTokens` (Sanctum) and `HasFactory`
    - `$fillable = ['name', 'email', 'password', 'role', 'is_active']`
    - `$hidden = ['password']`
    - casts: `role` → `Role::class`, `is_active` → `boolean`, `password` → `hashed`
    - `newFactory()` returns `UserFactory::new()`
    - `isAdministrator(): bool` (role === `Role::Administrator`)
    - No business workflows, no query scopes (Constitution III).
  - Accept: `password` never appears in `toArray()`.
  - Depends: T004, T005

- [X] T007 [P] Create the factory in `database/factories/UserFactory.php` (synthetic data only, Constitution XII)
  - Scope:
    - Model `App\Modules\Staff\Models\User`
    - defaults: `name` = `fake()->name()`, `email` = `fake()->unique()->userName().'@clinic.test'`,
      `password` = `'password-for-tests'` (hashed once and reused), `role` = `Role::Receptionist`,
      `is_active` = `true`
    - states: `administrator()`, `receptionist()`, `doctor()`, `inactive()`
  - Accept: no real names or real domains; emails end with `@clinic.test`.
  - Depends: T005, T006

- [X] T008 Point the auth provider at the new model in `config/auth.php`
  - Scope: replace `use App\Models\User;` with `use App\Modules\Staff\Models\User;`; keep
    `env('AUTH_MODEL', User::class)`. No other change.
  - Verify: `docker compose exec app php artisan tinker --execute="echo config('auth.providers.users.model');"` → `App\Modules\Staff\Models\User`
  - Depends: T006

- [X] T009 Run the migrations in `database/migrations/` and confirm the existing suite still passes
  - Verify:
    - `docker compose exec app php artisan migrate` → `create_users_table` and `create_personal_access_tokens_table` `DONE`
    - `docker compose exec app php artisan migrate:status` → all `Ran`
    - `docker compose exec app composer test` → 19 passed
  - Depends: T002, T004, T007, T008

- [X] T010 Create the module skeleton: `app/Modules/Staff/Providers/StaffServiceProvider.php`, `app/Modules/Staff/routes/api.php`, `bootstrap/providers.php`
  - Scope:
    - Provider `boot()`: load `routes/api.php` with `api` middleware and `api/v1` prefix (same as
      `SystemServiceProvider`); `loadTranslationsFrom(__DIR__.'/../lang', 'staff')`.
    - `register()` is empty for now; each story adds its bindings.
    - `routes/api.php`: the comment header only (routes are added per story).
    - Append `StaffServiceProvider::class` to `bootstrap/providers.php`.
  - Accept: app boots; health endpoint unaffected.
  - Verify: `docker compose exec app php artisan route:list --path=api` → still only `api/v1/health`
  - Depends: T009

- [X] T011 [P] Write the failing refusal-message test in `tests/Feature/Api/AuthErrorsTest.php`
  - Scope:
    - Register two **test-only** routes in `setUp()`:
      `Route::middleware(['api', 'auth:sanctum'])->get('api/v1/_test/protected', fn () => 'ok')` and
      `Route::middleware('api')->get('api/v1/_test/forbidden', fn () => Gate::authorize('_test-deny'))`
      with `Gate::define('_test-deny', fn () => false)`.
    - For `en` and `ar`: no token → `401` with `message` = `lang/{locale}/auth.php` `unauthenticated`;
      a malformed `Bearer abc` → `401` same message; forbidden route → `403` with `auth.forbidden`.
    - JSON keys identical in both locales; no `exception`/`file`/`trace` keys required to be absent
      (local debug mode), only that `message` matches.
  - Accept: fails before T012–T014.
  - Verify: `docker compose exec app php artisan test --filter=AuthErrorsTest` → fails
  - Depends: T009

- [X] T012 [P] Add the refusal keys to `lang/en/auth.php` and `lang/ar/auth.php`
  - Scope: add, in both files, after `throttle`:
    - en: `'unauthenticated' => 'You are not signed in.'`, `'forbidden' => 'You are not allowed to perform this action.'`
    - ar: `'unauthenticated' => 'لم تقم بتسجيل الدخول.'`, `'forbidden' => 'غير مسموح لك بتنفيذ هذا الإجراء.'`
  - Accept: `TranslationParityTest` passes.
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [X] T013 Render localized 401/403 and run the locale middleware before authentication in `bootstrap/app.php` (R5, R6)
  - Scope:
    - `withMiddleware`: keep the `api(append: …)` line and add
      `$middleware->prependToPriorityList(before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, prepend: SetLocaleFromHeader::class);`
    - `withExceptions`: for requests matching `api/*`, render
      `AuthenticationException` → `response()->json(['message' => __('auth.unauthenticated')], 401)` and
      `AccessDeniedHttpException` → `response()->json(['message' => __('auth.forbidden')], 403)`.
  - Accept: T011 passes.
  - Verify: `docker compose exec app php artisan test --filter=AuthErrorsTest` → pass
  - Depends: T010, T011, T012

- [X] T014 [P] Add module translations `app/Modules/Staff/lang/en/messages.php` and `app/Modules/Staff/lang/ar/messages.php`
  - Scope: exactly these keys:
    - `not_found` — en `No staff member was found.`; ar `لم يتم العثور على الموظف.`
    - `last_administrator` — en `This change would leave the clinic without an active administrator.`;
      ar `هذا التغيير سيترك العيادة بدون مسؤول نظام نشط.`
    - `administrator_created` — en `Administrator :email created.`; ar `تم إنشاء حساب المسؤول :email.`
  - Accept: same keys and placeholders in both files.
  - Depends: T010

- [X] T015 Extend `tests/Feature/Localization/TranslationParityTest.php` to module translations
  - Scope: also compare every `app/Modules/*/lang/en/*.php` with its `ar` sibling (same flattened
    keys); keep the existing `lang/` check and its non-empty assertion.
  - Accept: passes with T014; fails if an `ar` key is removed (check locally, do not commit that).
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T014

**Checkpoint**: Foundation ready — 401/403 are translated, the `users` table and model exist.

---

## Phase 3: User Story 1 – Staff member signs in and out (Priority: P1) 🎯 MVP

**Goal**: Sign in with email and password, see the own profile, sign out; tokens expire after 8 idle hours.

**Independent Test**: With a factory-made active account: sign in, `GET /auth/me`, sign out, and
the old token gets `401`.

### Tests for User Story 1 (write first, must fail)

- [X] T016 [P] [US1] Write `tests/Feature/Staff/SignInTest.php`
  - Scope (`POST /api/v1/auth/sign-in`, contract `signIn`):
    - success → `200`, `data.token` non-empty, `data.token_type` = `Bearer`, `data.user` matches
      `StaffMember` (no `password` key)
    - `" Sara@Clinic.test "` signs in to `sara@clinic.test`
    - unknown email, wrong password, deactivated account → `422`, identical body with
      `errors.email.0` = `__('auth.failed')` (SC-004)
    - 6th failed attempt within a minute for the same email and IP → `422` with the
      `auth.throttle` text (seconds filled in); a success before the limit clears the counter
    - missing `email`/`password` → `422` validation errors
    - messages checked in `en` and `ar`
  - Verify: `docker compose exec app php artisan test --filter=SignInTest` → fails
  - Depends: T013, T015

- [X] T017 [P] [US1] Write `tests/Feature/Staff/SignOutTest.php`
  - Scope: two sign-ins for one account (two tokens); sign out with the first → `204`; first token
    → `401` on `GET /auth/me`; second token → `200`. Without a token → `401`.
  - Verify: `docker compose exec app php artisan test --filter=SignOutTest` → fails
  - Depends: T013

- [X] T018 [P] [US1] Write `tests/Feature/Staff/OwnProfileTest.php`
  - Scope: `GET /api/v1/auth/me` → `200`, `data` has exactly `id`, `name`, `email`, `role`,
    `is_active`, `created_at`, `updated_at`; each role returns its own value; no token → `401`.
  - Verify: `docker compose exec app php artisan test --filter=OwnProfileTest` → fails
  - Depends: T013

- [X] T019 [P] [US1] Write `tests/Feature/Staff/TokenExpiryTest.php`
  - Scope (use `$this->travel()`):
    - signed in, then 7 h 59 m idle → `200`
    - signed in, then 8 h 1 m idle → `401`
    - requests every 5 h for 15 h → all `200` (each request restarts the period)
    - token of an account deactivated directly in the database → `401` (FR-010)
  - Verify: `docker compose exec app php artisan test --filter=TokenExpiryTest` → fails
  - Depends: T013

### Implementation for User Story 1

- [X] T020 [P] [US1] Create `app/Modules/Staff/Repositories/Contracts/StaffMemberRepository.php` and `app/Modules/Staff/Repositories/EloquentStaffMemberRepository.php`
  - Scope: method `findByEmail(string $email): ?User` (exact match on the stored lower-cased value).
    Later stories add methods to the same pair.
  - Depends: T006

- [X] T021 [P] [US1] Create `app/Modules/Staff/Repositories/Contracts/AccessTokenRepository.php` and `app/Modules/Staff/Repositories/SanctumAccessTokenRepository.php`
  - Scope:
    - `issueFor(User $user): string` → `$user->createToken('api')->plainTextToken`
    - `deleteCurrent(User $user): void` → deletes `$user->currentAccessToken()`
    - `deleteIdleFor(User $user, CarbonInterface $cutoff): void` → deletes that user's tokens whose
      `COALESCE(last_used_at, created_at) < $cutoff`
  - Depends: T006

- [X] T022 [US1] Bind both repositories in `app/Modules/Staff/Providers/StaffServiceProvider.php`
  - Scope: `register()`: bind each contract to its implementation.
  - Depends: T020, T021

- [X] T023 [US1] Implement `app/Modules/Staff/Services/AuthenticationService.php` (R2, R7)
  - Scope:
    - `signIn(string $email, string $password, string $ip): array{token: string, user: User}`:
      keys `sign-in:email:<email>` (max 5) and `sign-in:ip:<ip>` (max 20), both
      `RateLimiter::hit(…, 60)` **before** checking; if either count is over its max throw
      `ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => <longer wait>])])`;
      look up via `findByEmail`; if missing, still run `Hash::check` against a fixed dummy Argon2id
      hash; on any failure (missing, wrong password, inactive) throw
      `['email' => __('auth.failed')]`; on success clear only the email key, delete idle tokens
      (`now()->subMinutes(config('sanctum.idle_timeout_minutes'))`), issue a token.
    - (Revised after a security review: the original single `email|ip` key allowed guessing one
      account from many addresses; see research R7. `SignInTest` covers both limits.)
    - `signOut(User $user): void` → `deleteCurrent`.
    - `isTokenUsable(PersonalAccessToken $token, bool $isValid): bool` → `$isValid` and
      tokenable is active and `($token->last_used_at ?? $token->created_at)` is after the idle cutoff.
    - No queries here; no logging of emails or passwords.
  - Depends: T022

- [X] T024 [US1] Register the token callback in `app/Modules/Staff/Providers/StaffServiceProvider.php`
  - Scope: in `boot()`:
    `Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $this->app->make(AuthenticationService::class)->isTokenUsable($token, $isValid));`
  - Depends: T023

- [X] T025 [P] [US1] Create `app/Modules/Staff/Http/Requests/SignInRequest.php`
  - Scope: `prepareForValidation()` trims and lower-cases `email`; rules `email` → `required|string|email|max:255`,
    `password` → `required|string`. `authorize()` → `true`.

- [X] T026 [P] [US1] Create `app/Modules/Staff/Http/Resources/StaffMemberResource.php` and `app/Modules/Staff/Http/Resources/SignInResource.php`
  - Scope:
    - `StaffMemberResource`: exactly `id`, `name`, `email`, `role` (enum value), `is_active`,
      `created_at`, `updated_at` (ISO 8601). Default `data` wrapper.
    - `SignInResource` (built from the service's array): `token`, `token_type` = `'Bearer'`,
      `user` = `StaffMemberResource`.
  - Depends: T006

- [X] T027 [US1] Create `app/Modules/Staff/Http/Controllers/AuthController.php` and add routes to `app/Modules/Staff/routes/api.php`
  - Scope:
    - `signIn(SignInRequest)` → `SignInResource` (200); `signOut(Request)` → `204`;
      `me(Request)` → `StaffMemberResource`. No logic beyond calling the service.
    - Routes: `POST auth/sign-in` → `staff.auth.sign-in` (no auth); group `auth:sanctum`:
      `POST auth/sign-out` → `staff.auth.sign-out`, `GET auth/me` → `staff.auth.me`.
  - Accept: T016–T019 pass.
  - Verify: `docker compose exec app php artisan test --filter="SignInTest|SignOutTest|OwnProfileTest|TokenExpiryTest"` → pass
  - Depends: T023, T024, T025, T026

**Checkpoint**: US1 complete; sign-in, profile, sign-out, and expiry work on their own (MVP).

---

## Phase 4: User Story 2 – Roles restrict what each staff member can do (Priority: P1)

**Goal**: One policy decides every staff-management ability; only administrators pass.

**Independent Test**: The policy matrix test passes for all 3 roles × 7 abilities.

### Tests for User Story 2 (write first, must fail)

- [ ] T028 [US2] Write `tests/Feature/Staff/StaffMemberPolicyTest.php`
  - Scope: data provider over roles (`administrator`, `receptionist`, `doctor`) × abilities
    (`viewAny`, `view`, `create`, `update`, `deactivate`, `reactivate`, `setPassword`), checking
    `Gate::forUser($actor)->allows($ability, User::class)`: true only for administrator. An inactive
    administrator is also refused.
  - Verify: `docker compose exec app php artisan test --filter=StaffMemberPolicyTest` → fails
  - Depends: T009

### Implementation for User Story 2

- [ ] T029 [US2] Create `app/Modules/Staff/Policies/StaffMemberPolicy.php` and register it in `app/Modules/Staff/Providers/StaffServiceProvider.php`
  - Scope: the seven abilities, each `(User $actor): bool` → `$actor->is_active && $actor->isAdministrator()`
    (class-level, so non-administrators get `403` before any lookup and cannot probe ids).
    `boot()`: `Gate::policy(User::class, StaffMemberPolicy::class)`.
  - Accept: T028 passes.
  - Verify: `docker compose exec app php artisan test --filter=StaffMemberPolicyTest` → pass
  - Depends: T010, T028

**Checkpoint**: US2 complete; later features can authorize by role through policies.

---

## Phase 5: User Story 3 – Administrator manages staff accounts (Priority: P2)

**Goal**: Create, list, view, edit, deactivate, reactivate, and set passwords, with the last-administrator rule.

**Independent Test**: As an administrator: create a receptionist, they sign in; deactivate them,
their token and sign-in fail; reactivate, they sign in again.

### Tests for User Story 3 (write first, must fail)

- [ ] T030 [P] [US3] Write `tests/Feature/Staff/StaffManagementTest.php`
  - Scope:
    - `POST /staff` → `201`, account active, the new member can sign in
    - duplicate email (also when the existing owner is deactivated, and with different case) → `422` `errors.email`
    - password 11 chars → `422`; 12 → ok; 72 → ok; 73 → `422`; unconfirmed → `422`
    - invalid `role` → `422`
    - `GET /staff` → paginated `data`/`links`/`meta`, ordered by name then id; filters `role` and
      `is_active`; `per_page` 101 → `422`; no `password` in any item
    - `GET /staff/{id}` → `200`; unknown id → `404` with `staff::messages.not_found`
    - `PATCH /staff/{id}` name/email/role → `200`; empty body → `422`
    - role change applies on the member's next request
  - Verify: `docker compose exec app php artisan test --filter=StaffManagementTest` → fails
  - Depends: T027, T029

- [ ] T031 [P] [US3] Write `tests/Feature/Staff/DeactivationTest.php`
  - Scope: member with two tokens → deactivate → `200`, both tokens `401`, sign-in → generic
    `auth.failed`; deactivate again → `200` (idempotent); reactivate → `200`, sign-in works, old
    tokens stay invalid.
  - Verify: `docker compose exec app php artisan test --filter=DeactivationTest` → fails
  - Depends: T027, T029

- [ ] T032 [P] [US3] Write `tests/Feature/Staff/LastAdministratorTest.php`
  - Scope: the only active administrator: deactivating self → `422`
    `staff::messages.last_administrator` (en and ar); changing own role → same; with a second active
    administrator both are allowed; an inactive administrator does not count.
  - Verify: `docker compose exec app php artisan test --filter=LastAdministratorTest` → fails
  - Depends: T027, T029

- [ ] T033 [P] [US3] Write `tests/Feature/Staff/AdminSetPasswordTest.php`
  - Scope: `PUT /staff/{id}/password` → `204`; old password fails, new works; every earlier token
    of that member → `401`; length and confirmation rules as in T030.
  - Verify: `docker compose exec app php artisan test --filter=AdminSetPasswordTest` → fails
  - Depends: T027, T029

- [ ] T034 [P] [US3] Write `tests/Feature/Staff/AuthorizationMatrixTest.php` (SC-002)
  - Scope: data provider over the 7 staff endpoints × `receptionist`, `doctor` → `403` with
    `auth.forbidden`, and nothing changed in the database; administrator → not `403`; no token → `401`.
  - Verify: `docker compose exec app php artisan test --filter=AuthorizationMatrixTest` → fails
  - Depends: T027, T029

### Implementation for User Story 3

- [ ] T035 [P] [US3] Create `app/Modules/Staff/Support/PasswordRules.php`
  - Scope: `public static function rules(): array` → `['required', 'string', Password::min(12)->max(72), 'confirmed']` (FR-022).

- [ ] T036 [P] [US3] Create `app/Modules/Staff/Exceptions/StaffMemberNotFoundException.php` and `app/Modules/Staff/Exceptions/LastAdministratorException.php`
  - Scope: each with `render()` returning JSON `{"message": …}`: `404` `__('staff::messages.not_found')`;
    `422` `__('staff::messages.last_administrator')`.

- [ ] T037 [US3] Add staff-management methods to `app/Modules/Staff/Repositories/Contracts/StaffMemberRepository.php` and `app/Modules/Staff/Repositories/EloquentStaffMemberRepository.php`
  - Scope:
    - `findById(int $id): ?User`
    - `create(array $attributes): User`
    - `update(User $user, array $attributes): User`
    - `paginate(?Role $role, ?bool $isActive, int $perPage): LengthAwarePaginator` ordered by `name`, then `id`
    - `lockActiveAdministrators(): int` → count of active administrators with `lockForUpdate()`
      (must be called inside a transaction)
  - Depends: T020

- [ ] T038 [US3] Add `deleteAllFor(User $user): void` to `app/Modules/Staff/Repositories/Contracts/AccessTokenRepository.php` and `app/Modules/Staff/Repositories/SanctumAccessTokenRepository.php`
  - Depends: T021

- [ ] T039 [US3] Implement `app/Modules/Staff/Services/StaffManagementService.php` (R8, R9)
  - Scope:
    - `list(?Role, ?bool, int)`, `find(int $id): User` (throws `StaffMemberNotFoundException`)
    - `create(array): User` — active by default; a `UniqueConstraintViolationException` becomes a
      `ValidationException` on `email` with `__('validation.unique', ['attribute' => 'email'])`
    - `update(User, array): User` — if the role changes away from administrator, inside
      `DB::transaction()`: `lockActiveAdministrators()`; if the member is an active administrator
      and the count is 1, throw `LastAdministratorException`
    - `deactivate(User)` — same lock-and-check, then `is_active = false` and `deleteAllFor()`, all in
      one transaction; already inactive → no change
    - `reactivate(User)` — `is_active = true`
    - `setPassword(User, string $password)` — update and `deleteAllFor()` in one transaction
  - Depends: T036, T037, T038

- [ ] T040 [P] [US3] Create Form Requests in `app/Modules/Staff/Http/Requests/`: `ListStaffRequest.php`, `StoreStaffMemberRequest.php`, `UpdateStaffMemberRequest.php`, `SetStaffMemberPasswordRequest.php`
  - Scope:
    - `authorize()` → `$this->user()->can(<ability>, User::class)` (`viewAny`, `create`, `update`, `setPassword`)
    - List: `role` → `nullable|`enum `Role`; `is_active` → `nullable|boolean`; `page` → `nullable|integer|min:1`;
      `per_page` → `nullable|integer|min:1|max:100` (default 15)
    - Store: `name` → `required|string|max:255` (trimmed); `email` → `required|string|email|max:255|`
      `Rule::unique('users', 'email')` (trimmed, lower-cased in `prepareForValidation()`);
      `role` → `required|`enum `Role`; `password` → `PasswordRules::rules()`
    - Update: same fields with `sometimes`, email unique `->ignore($id)`; at least one of `name`, `email`, `role`
    - SetPassword: `password` → `PasswordRules::rules()`
  - Depends: T029, T035

- [ ] T041 [US3] Create controllers `app/Modules/Staff/Http/Controllers/StaffMemberController.php`, `StaffMemberStatusController.php`, `StaffMemberPasswordController.php` and their routes in `app/Modules/Staff/routes/api.php`
  - Scope:
    - `StaffMemberController`: `index`, `store` (201), `show` (`Gate::authorize('view', User::class)` first), `update`
    - `StaffMemberStatusController`: `deactivate`, `reactivate` (`Gate::authorize(...)` first)
    - `StaffMemberPasswordController` (invokable): `204`
    - Routes under `auth:sanctum`, `{staffMember}` constrained `whereNumber`, names
      `staff.members.index|store|show|update|deactivate|reactivate|password`, paths per the contract.
    - Controllers resolve the member via `StaffManagementService::find()` (no route-model binding, R10).
  - Accept: T030–T034 pass.
  - Verify: `docker compose exec app php artisan test --filter="StaffManagementTest|DeactivationTest|LastAdministratorTest|AdminSetPasswordTest|AuthorizationMatrixTest"` → pass
  - Depends: T039, T040

**Checkpoint**: US3 complete; administrators can onboard and offboard staff.

---

## Phase 6: User Story 4 – First administrator without seeded credentials (Priority: P2)

**Goal**: A command creates an administrator with a hidden, typed password.

**Independent Test**: On an empty `users` table, the command creates an administrator who can sign in.

### Tests for User Story 4 (write first, must fail)

- [ ] T042 [US4] Write `tests/Feature/Staff/CreateAdministratorCommandTest.php`
  - Scope (`$this->artisan(...)->expectsQuestion(...)`):
    - `staff:create-admin --name="Test Admin" --email=Admin@Clinic.test`, password and confirmation
      answered → exit `0`, output contains the `administrator_created` text, one active administrator
      with email `admin@clinic.test`, sign-in works
    - duplicate email → non-zero exit, no new row
    - 11-char password or mismatched confirmation → non-zero exit, no row
    - missing `--name`/`--email` → asked interactively
  - Verify: `docker compose exec app php artisan test --filter=CreateAdministratorCommandTest` → fails
  - Depends: T039

### Implementation for User Story 4

- [ ] T043 [US4] Create `app/Modules/Staff/Console/CreateAdministratorCommand.php` and register it in `app/Modules/Staff/Providers/StaffServiceProvider.php`
  - Scope:
    - Signature `staff:create-admin {--name=} {--email=}`; asks for missing options; password via
      `secret()` twice (never an option, R12).
    - Validates with `Validator::make` using the Store rules for `name`/`email` and `PasswordRules::rules()`;
      prints each error and returns `FAILURE` without creating anything.
    - Calls `StaffManagementService::create()` with `role` = administrator; prints
      `__('staff::messages.administrator_created', ['email' => …])`.
    - Provider: `$this->commands([...])` when running in console.
  - Accept: T042 passes.
  - Verify: `docker compose exec app php artisan test --filter=CreateAdministratorCommandTest` → pass
  - Depends: T042

- [ ] T044 [US4] Document the first-administrator step in `README.md`
  - Scope: in "First-time setup", add step 6 `docker compose exec app php artisan staff:create-admin`
    with a note that the password is typed (hidden) and no default accounts exist.
  - Depends: T043

**Checkpoint**: US4 complete; a fresh install can reach a signed-in administrator.

---

## Phase 7: User Story 5 – Staff member changes their own password (Priority: P3)

**Goal**: Change own password with the current one; other sessions end, this one stays.

**Independent Test**: Sign in twice, change the password with one token; that token still works,
the other gets `401`, the old password fails.

### Tests for User Story 5 (write first, must fail)

- [ ] T045 [US5] Write `tests/Feature/Staff/OwnPasswordChangeTest.php`
  - Scope: `PUT /api/v1/auth/password` → `204`; current token `200`, other token `401`; old password
    fails, new works; wrong `current_password` → `422` `validation.current_password` (en and ar);
    11-char or unconfirmed new password → `422`; no token → `401`.
  - Verify: `docker compose exec app php artisan test --filter=OwnPasswordChangeTest` → fails
  - Depends: T027

### Implementation for User Story 5

- [ ] T046 [US5] Add `deleteAllExcept(User $user, PersonalAccessToken $keep): void` to `app/Modules/Staff/Repositories/Contracts/AccessTokenRepository.php` and `app/Modules/Staff/Repositories/SanctumAccessTokenRepository.php`
  - Depends: T021

- [ ] T047 [US5] Add `changeOwnPassword(User $user, string $password): void` to `app/Modules/Staff/Services/AuthenticationService.php`
  - Scope: in one `DB::transaction()`: update the password (via `StaffMemberRepository::update`) and
    `deleteAllExcept($user, $user->currentAccessToken())`.
  - Depends: T037 (or add `update()` to the repository here if US3 is not done), T046

- [ ] T048 [US5] Create `app/Modules/Staff/Http/Requests/ChangeOwnPasswordRequest.php`, add `AuthController::changePassword`, and route `PUT auth/password` → `staff.auth.password` in `app/Modules/Staff/routes/api.php`
  - Scope: rules `current_password` → `required|string|current_password:sanctum`; `password` →
    `PasswordRules::rules()`; controller returns `204`.
  - Accept: T045 passes.
  - Verify: `docker compose exec app php artisan test --filter=OwnPasswordChangeTest` → pass
  - Depends: T035, T047

**Checkpoint**: US5 complete. All stories functional.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T049 [P] Write `tests/Feature/Staff/StaffMessagesTest.php` (SC-007)
  - Scope: for `en` and `ar`: 401, 403, 404, 422 sign-in failure, 422 throttle, 422 last-administrator,
    each `message` equal to its translation file value, with identical JSON keys across locales.
  - Verify: `docker compose exec app php artisan test --filter=StaffMessagesTest` → pass
  - Depends: T041, T048

- [ ] T050 [P] Update `docs/architecture.md`
  - Scope: under Layers/Routes, add "Route parameters are plain ids loaded through the repository;
    implicit route-model binding is not used (it queries outside the repository and its 404 names
    the model class)"; add the `Staff` module and its translations as a second example in one short
    paragraph; mention `prependToPriorityList` for locale-before-auth.

- [ ] T051 [P] Update `README.md` and `docs/troubleshooting.md`
  - Scope: README: short "Signing in" section (sign-in, `Authorization: Bearer`, 8-hour idle expiry,
    roles, link to the contract). Troubleshooting: "401 after a while" (idle expiry, deactivation,
    password reset) and "422 too many attempts" (wait the given seconds).

- [ ] T052 Run Pint (`vendor/bin/pint`) and the full suite (`tests/`)
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0
  - Depends: T049, T050, T051

- [ ] T053 Run quickstart Q1–Q7 from `specs/002-staff-auth-roles/quickstart.md` on the development stack
  - Scope: follow `specs/002-staff-auth-roles/quickstart.md`; synthetic `*.clinic.test` accounts only.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T052

- [ ] T054 Record the results in `specs/002-staff-auth-roles/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; report failures as
    failures (Constitution VIII).
  - Depends: T053

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story.
- **US1 (Phase 3)** and **US2 (Phase 4)**: both start after Foundational; independent of each other.
- **US3 (Phase 5)**: needs US1 (sign-in for its tests) and US2 (policy).
- **US4 (Phase 6)**: needs `StaffManagementService::create()` (T039).
- **US5 (Phase 7)**: needs US1; uses `PasswordRules` (T035) and `StaffMemberRepository::update()` (T037).
- **Polish (Phase 8)**: after all stories.

### Within Each Story

Tests first and failing → repositories → service → requests/resources → controller and routes.

### Parallel Opportunities

- T003 with T002 (different files).
- T005 and T007 alongside the model work; T011, T012, T014 in parallel.
- US1 tests T016–T019 together; T020, T021, T025, T026 together.
- US1 and US2 phases in parallel after Foundational.
- US3 tests T030–T034 together; T035, T036, T040 together.
- Polish T049–T051 together.

---

## Parallel Example: User Story 1

```text
Task: "Write tests/Feature/Staff/SignInTest.php"        (T016)
Task: "Write tests/Feature/Staff/SignOutTest.php"       (T017)
Task: "Write tests/Feature/Staff/OwnProfileTest.php"    (T018)
Task: "Write tests/Feature/Staff/TokenExpiryTest.php"   (T019)

Task: "StaffMemberRepository contract + Eloquent impl"  (T020)
Task: "AccessTokenRepository contract + Sanctum impl"   (T021)
Task: "SignInRequest"                                   (T025)
Task: "StaffMemberResource + SignInResource"            (T026)
```

## Parallel Example: User Story 3

```text
Task: "StaffManagementTest"       (T030)
Task: "DeactivationTest"          (T031)
Task: "LastAdministratorTest"     (T032)
Task: "AdminSetPasswordTest"      (T033)
Task: "AuthorizationMatrixTest"   (T034)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 US1 → **stop and validate**: sign in, profile, sign out, expiry.

### Incremental Delivery

1. US1 → sign-in works (accounts from factories in tests).
2. US2 → role checks available to every later feature.
3. US3 → administrators manage staff.
4. US4 → fresh installs get their first administrator.
5. US5 → own password change.
6. Polish → docs, style, quickstart, verification record.

Following the project's convention, each phase is implemented on its own branch off `main`
(e.g. `002-phase-1-setup`) and merged by pull request.

---

## Notes

- [P] = different files, no dependency on an unfinished task.
- Commit after each task (or a tightly related group), with the task ID in the message.
- Never weaken or delete a test to make a task pass; report failures as observed.
- No real names, emails, or passwords anywhere; use `@clinic.test` synthetic data.
