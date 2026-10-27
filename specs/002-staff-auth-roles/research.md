# Research: Staff Authentication and Roles

**Feature**: 002-staff-auth-roles | **Date**: 2026-10-27

All facts below were checked on 2026-10-27 against the installed code (Laravel Framework 13.38.0
in the `app` container) or the sources listed at the end. Items marked *(approved — Dn)* were
approved on 2026-10-27 and are listed in the plan's Decisions table.

## R1 — Token authentication mechanism (FR-005, FR-008, FR-010) *(approved — D1)*

- **Decision**: Add **`laravel/sanctum` `^4.3`** (latest `v4.3.3`, released 2026-06-23; requires
  `illuminate/support ^11.0|^12.0|^13.0`, `php ^8.2`). Use only its **personal access tokens**:
  sign-in issues one token per sign-in; requests send `Authorization: Bearer <token>`; routes use
  the `auth:sanctum` middleware.
- **Rationale**: First-party Laravel package for exactly this case (API tokens for a separate
  client). It stores only a SHA-256 hash of each token, supports many tokens per account
  (one per device), deleting a single token (sign-out) or all of them (deactivation), and records
  `last_used_at`, which R2 needs.
- **Configuration**: `config/sanctum.php` with `guard => []` (no cookie/session authentication;
  this API is token-only), `expiration => null` (expiry is handled by R2), `token_prefix => ''`.
  `php artisan install:api` is **not** used: it would create `routes/api.php`, which the module
  convention replaces. Instead: `composer require`, then publish the config and the
  `personal_access_tokens` migration.
- **Alternatives**: Framework `TokenGuard` (one plain `api_token` column) — one token per account,
  so signing out on one device signs out all; no last-used tracking; stores the token in plain
  text. Laravel Passport — a full OAuth2 server, far more than a single clinic needs. Hand-written
  tokens — reimplements Sanctum with more risk.

## R2 — 8-hour inactivity expiry (FR-009, clarification Q2)

- **Finding** (Sanctum `v4.3.3` `src/Guard.php`): `isValidAccessToken()` computes
  `$isValid` (creation-time expiration, `expires_at`, provider), then calls
  `Sanctum::$accessTokenAuthenticationCallback($accessToken, $isValid)` if set. Only **after**
  a token passes does the guard update `last_used_at`. So the callback sees the **previous**
  request's time.
- **Decision**: In the Staff module provider, register
  `Sanctum::authenticateAccessTokensUsing(...)` that returns true only when `$isValid`, the
  account is active, and `(last_used_at ?? created_at)` is less than 8 hours ago. The 8 hours
  live in `config/sanctum.php` as `idle_timeout_minutes => 480`, so it is one value with no magic
  number.
- **Rationale**: Sanctum's built-in `expiration` counts from creation (a fixed lifetime), not
  inactivity. The callback is the documented extension point and also gives one place to refuse
  deactivated accounts (FR-010) on every request.
- **Housekeeping**: on each sign-in, the account's tokens idle for more than 8 hours are deleted
  (a repository method), so the table does not grow without bound. A global prune job is
  deferred: no scheduler runs in this project yet.

## R3 — Password hashing and the 72-character limit (FR-022) *(approved — D2)*

- **Finding**: Laravel 13's default hasher is bcrypt (`config/hashing.php` is not published;
  framework default `driver => env('HASH_DRIVER', 'bcrypt')`, `limit => env('BCRYPT_LIMIT', null)`).
  bcrypt uses only the first **72 bytes**; with `limit` null Laravel silently truncates, and with
  a limit set it throws `InvalidArgumentException` (a 500). Arabic letters are 2 bytes in UTF-8,
  so a 72-character Arabic password is up to 144 bytes and would be partly ignored.
- **Decision**: Publish `config/hashing.php` (`php artisan config:publish hashing`) and set the
  default driver to **`argon2id`**. The PHP image already supports it (`PASSWORD_ARGON2ID` is
  defined). Keep the spec's 12–72 **character** rule (FR-022) as validation. `phpunit.xml` sets
  low Argon2 cost (`ARGON_MEMORY`, `ARGON_TIME`) for fast tests, as it already does for
  `BCRYPT_ROUNDS`.
- **Rationale**: No truncation and no byte-counting rule for multilingual passwords; Argon2id is
  the current OWASP first choice. A configuration change only, no new package.
- **Alternatives**: Keep bcrypt and validate a 72-**byte** maximum — keeps the default but gives
  Arabic users a confusing, smaller limit. Keep bcrypt and ignore truncation — silently weakens
  long passwords.

## R4 — Roles (FR-002, FR-003, clarification Q1)

- **Decision**: A PHP backed enum `Role` (`administrator`, `receptionist`, `doctor`) cast on a
  `role` string column (`varchar(20)`, indexed). Authorization uses Laravel **Policies**.
- **Rationale**: The set is fixed by the Constitution and the spec; one role per account. A roles
  table or a permissions package (for example spatie/laravel-permission) would add tables, a
  dependency, and many-to-many logic the spec rules out.
- **Alternatives**: MySQL `ENUM` column — adding a value later needs an `ALTER` on the column
  type; a varchar with an application enum changes only code. Roles table — speculative.

## R5 — Authorization and refusal responses (FR-012–FR-015, FR-027)

- **Decision**: `StaffMemberPolicy` with `viewAny`, `view`, `create`, `update`, `deactivate`,
  `reactivate`, `setPassword`, each returning true only for administrators; registered with
  `Gate::policy()` in the module provider. Controllers call `$this->authorize()` /
  `Gate::authorize()`.
- **Finding**: `Handler::unauthenticated()` returns the exception message, `Unauthenticated.`,
  which is hard-coded English. A failed policy becomes `AccessDeniedHttpException` with
  `This action is unauthorized.`
- **Decision**: In `bootstrap/app.php` `withExceptions`, render `AuthenticationException` as
  `401 {"message": __('auth.unauthenticated')}` and `AccessDeniedHttpException` as
  `403 {"message": __('auth.forbidden')}` for API requests. The two keys are added to
  `lang/{en,ar}/auth.php`, so the existing `TranslationParityTest` covers them.

## R6 — Locale must be set before authentication (FR-027)

- **Finding**: `Illuminate\Foundation\Http\Kernel::$middlewarePriority` lists
  `AuthenticatesRequests` before `SubstituteBindings`. Route middleware are sorted by that list, so
  `auth:sanctum` moves ahead of the `api` group's `SubstituteBindings`, and with it ahead of
  `SetLocaleFromHeader`, which is not in the list. A 401 would then always use the default locale.
- **Decision**: `->withMiddleware(fn ($m) => $m->prependToPriorityList(
  before: AuthenticatesRequests::class, prepend: SetLocaleFromHeader::class))`. A test asserts
  the Arabic 401 message.

## R7 — Sign-in failures and rate limiting (FR-006, FR-007)

- **Decision** (revised after a security review during US1): `AuthenticationService::signIn()`
  uses two `RateLimiter` keys with a 60 s window:
  - `sign-in:account:<user id>` (or `sign-in:account:unknown:<email>` when no account matches):
    more than **5** attempts → refused. Keyed on the matched account, not the typed text, because
    the `utf8mb4_unicode_ci` collation matches variants such as full-width letters to the same
    account (found by a second security review; `SignInTest` covers it). A successful sign-in
    clears it, so it effectively counts failures for that account from any address.
  - `sign-in:ip:<client IP>`: more than **20** attempts for any email → refused. Never cleared on
    success, so signing in to one account cannot reset an address that is guessing others.
  - Both keys are incremented **before** the credentials are checked (`RateLimiter::hit` returns
    the new count), so simultaneous requests cannot slip past a check-then-count gap.
  - When either limit is exceeded it throws a `ValidationException` on `email` with
    `__('auth.throttle', ['seconds' => …])` (the longer of the two waits). Any failure (unknown
    email, wrong password, deactivated account) throws `ValidationException` on `email` with
    `__('auth.failed')`. Both messages already exist in `lang/en/auth.php` and `lang/ar/auth.php`.
- **Why it was revised**: the original single key `email|IP` let someone with many addresses get
  5 tries per address against one account. The 20-per-address limit is looser than the per-email
  one because a clinic's staff usually share one public address.
- **Timing**: an unknown email still runs one hash check against a fixed dummy hash, so response
  time does not reveal whether the account exists (SC-004).
- **Rationale**: This is the pattern of Laravel's own starter kits (`LoginRequest`), moved into a
  service as the Constitution requires. The status is 422, Laravel's convention for credential
  errors, so the response shape matches other validation errors.

## R8 — Last active administrator (FR-021) and concurrency

- **Decision**: Deactivation and role changes run in `DB::transaction()` owned by
  `StaffManagementService`. Inside it, the repository locks the active administrator rows
  (`SELECT … FOR UPDATE`) and counts them. If the change would leave zero, it throws
  `LastAdministratorException`, which renders as 422 with a translated message.
- **Rationale**: Without the lock, two administrators deactivating each other at the same moment
  could both pass a plain count and leave none.

## R9 — Email uniqueness and case (FR-001, edge cases)

- **Decision**: Emails are trimmed and lower-cased before validation and storage. The column uses
  the database's `utf8mb4_unicode_ci` collation (case-insensitive) with a **unique index**. Form
  Requests use `Rule::unique('users', 'email')` (with `->ignore($id)` on edit), which
  `docs/architecture.md` permits ("queries beyond what validation rules need" are prohibited). A
  `UniqueConstraintViolationException` from a race is turned into the same validation error by the
  service.

## R10 — Looking up a staff member by id (Constitution III)

- **Decision**: Routes take `{staffMember}` as a plain integer id. The service loads it through
  `StaffMemberRepository::findById()` and throws `StaffMemberNotFoundException`, which renders as
  `404 {"message": __('staff::messages.not_found')}`. Implicit route-model binding is not used.
- **Rationale**: Implicit binding runs an Eloquent query outside the repository, and its 404
  message names the model class (`No query results for model [App\…]`), an internal detail.

## R11 — Module, model, and table names

- **Decision**: One module **`Staff`** (`app/Modules/Staff/`) holds sign-in, own-account actions,
  and staff management. The model is `App\Modules\Staff\Models\User` on table **`users`**, and
  `config/auth.php` points the `users` provider to it.
- **Rationale**: Every account is a staff account; the domains "authentication" and "users and
  roles" share one aggregate and are too small to split. Keeping Laravel's `User`/`users`/`users`
  provider names avoids reconfiguring the auth system and Sanctum's morph type.
- **Alternatives**: Two modules (`Auth`, `Users`) — they would share the model and repository, so
  one module stays simpler. A `staff_members` table — a non-default name for no benefit.

## R12 — First administrator (FR-025, FR-026)

- **Decision**: An Artisan command `staff:create-admin` with `--name` and `--email` options. It
  asks for the password twice with hidden input (never as an option, so it never lands in shell
  history), validates with the same rules as the API, and calls the same service. There are no
  seeders. Tests use model factories with synthetic data.

## Sources

- Sanctum releases — https://repo.packagist.org/p2/laravel/sanctum.json (v4.3.3, 2026-06-23)
- Sanctum guard — https://github.com/laravel/sanctum/blob/v4.3.3/src/Guard.php
- Sanctum docs — https://laravel.com/docs/13.x/sanctum
- Hashing — `vendor/laravel/framework/src/Illuminate/Hashing/BcryptHasher.php`,
  `vendor/laravel/framework/config/hashing.php`; https://laravel.com/docs/13.x/hashing
- Middleware priority — `vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php`,
  `src/Illuminate/Foundation/Configuration/Middleware.php` (`prependToPriorityList`)
- 401 rendering — `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php`
- OWASP Password Storage Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html
- NIST SP 800-63B §5.1.1 (length over composition rules) — https://pages.nist.gov/800-63-3/sp800-63b.html
