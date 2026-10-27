# Implementation Plan: Project Foundation and Local Development Environment

**Branch**: `001-project-foundation` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-project-foundation/spec.md`

## Summary

Create a clean, API-only Laravel 13 application that runs entirely in Docker Compose (PHP-FPM,
nginx, and a MySQL service that is never published outside the Compose network). Add a safe
`.env.example`, a documented migration and test workflow against an isolated MySQL test database,
`/api/v1` routing through self-contained modules under `app/Modules/<Domain>/`, `en`/`ar` locale
selection with an English fallback, and JSON-only API errors. The only endpoint is
`GET /api/v1/health`, built through the full controller → service → repository-interface chain.
It is the reference example of the architecture conventions. Version choices and their evidence
are in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (container image `php:8.5.12-fpm-trixie`), pending approval D1

**Primary Dependencies**: Laravel 13.x skeleton (`laravel/framework ^13.17`, `laravel/tinker`);
skeleton dev defaults only (PHPUnit 12.5, Pint, Collision, Mockery, Faker, Pail, Pao). No
additional packages.

**Storage**: MySQL 9.7.4 LTS (`mysql:9.7.4`), pending approval D2; InnoDB, `utf8mb4_unicode_ci`;
named volume `mysql-data`

**Testing**: PHPUnit 12 via `php artisan test`, run inside the `app` container against the
`clinic_testing` database

**Target Platform**: Linux containers on Docker Desktop (Windows/macOS) or Docker Engine (Linux),
local development only

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: Health endpoint responds in under 1 s locally; first-time setup under
15 min excluding image downloads (SC-001)

**Constraints**: No public database port; pinned images; no frontend tooling; no auth or business
packages; secrets only in the untracked `.env`

**Scale/Scope**: Single medical center; this feature has 1 endpoint, 1 middleware, 1 module
(`System`), and 2 infrastructure migrations

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | DB port not published; app and phpMyAdmin ports bound to `127.0.0.1`; phpMyAdmin is opt-in and requires a login; secrets only in ignored `.env`; health response exposes no internals; PHP-FPM runs as non-root | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13, MySQL 9.7 LTS, Compose; pinned full-patch tags; named volume; DB host = `mysql`; `/api/v1` | ✅ |
| III | Repository pattern | `DatabaseStatusRepository` interface + `MySqlDatabaseStatusRepository`, bound in the module provider; no base repository | ✅ |
| IV | Thin controllers | `HealthController` only calls `HealthCheckService` and returns `HealthResource` | ✅ |
| V | Services own workflows | `HealthCheckService` decides status; the repository only probes the DB | ✅ |
| VI | Form Requests/Resources/Policies | `HealthResource` serializes. No Form Request (no input) and no Policy (public endpoint, approved in clarification Q2); the conventions doc defines all three for future modules | ✅ |
| VII | en/ar localization | `SetLocaleFromHeader` middleware; `lang/en` + `lang/ar` framework files; fallback `en` | ✅ |
| VIII | Tests & acceptance criteria | Feature tests for health (ok/unavailable) and locale cases; the spec's AC traceability table is used for verification | ✅ |
| IX | Small tasks | The work breaks into independent steps (see Implementation Outline) | ✅ |
| X | No invention | Only the approved endpoint; versions verified with sources; default users schema removed rather than invented (D3) | ✅ |
| XI | Appointment integrity | N/A to this feature; nginx + PHP-FPM chosen so later concurrency tests can run truly in parallel | ✅ |
| XII | No real data | No seeders; tests use synthetic data only; logs contain no patient data | ✅ |

**Gate result (pre-research)**: PASS. **Post-design re-check**: PASS. No violations, so Complexity
Tracking is empty.

## Application and Container Architecture

```text
 host (127.0.0.1:${APP_PORT:-8080})
        │
        ▼
 ┌──────────────┐  FastCGI :9000  ┌──────────────────┐  TCP :3306  ┌──────────────────┐
 │ web (nginx)  │ ──────────────▶ │ app (PHP-FPM)    │ ──────────▶ │ mysql            │
 │ 1.30.6-alpine│                 │ php 8.5.12 +     │  host=mysql │ 9.7.4 LTS        │
 └──────────────┘                 │ Laravel 13       │             │ volume mysql-data│
                                  └──────────────────┘             └──────────────────┘
                                                                            ▲
 host (127.0.0.1:${PMA_PORT:-8081}) ──▶ phpmyadmin 5.2.3 (profile: tools) ───┘ host=mysql
        └──── Compose default network (internal; only web and phpmyadmin publish, to localhost) ────┘
```

### Dockerfile responsibilities (`docker/php/Dockerfile`)

- `FROM php:8.5.12-fpm-trixie`; install `git` and `unzip`; enable extensions `pdo_mysql` and `pcntl`.
- `COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer`.
- Build args `UID`/`GID` (default 1000) create user `app`; FPM runs as `app`.
- Dev PHP config `docker/php/conf.d/app.ini` (memory limit, `expose_php=Off`, timezone UTC).
- Source is **bind-mounted** (`./:/var/www/html`), not copied, for local development; `vendor/`
  lives in the named volume `vendor` (D8) and is installed by `composer install` inside the
  container. The image pre-creates `/var/www/html/vendor` owned by `app` so a fresh volume is writable.

### Compose responsibilities (`compose.yaml`)

| Service | Image/Build | Ports | Volumes | Notes |
|---------|-------------|-------|---------|-------|
| `app` | build `docker/php` | none | `./:/var/www/html`, `vendor:/var/www/html/vendor` (named volume, D8) | `depends_on: mysql (service_healthy)`; **no `env_file`/`environment` for app settings** (Laravel reads `.env` via the bind mount, so `phpunit.xml` can override DB settings) |
| `web` | `nginx:1.30.6-alpine` | `127.0.0.1:${APP_PORT:-8080}:80` | `./public` (read-only), `docker/nginx/default.conf` | Forwards `*.php` to `app:9000` |
| `mysql` | `mysql:9.7.4` | **none** | `mysql-data:/var/lib/mysql`, `docker/mysql/init` (read-only) | Healthcheck `mysqladmin ping`; `command` sets server charset `utf8mb4` / collation `utf8mb4_unicode_ci`; env `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` mapped from `.env`, with `${VAR:?}` so a missing secret fails fast |

| `phpmyadmin` | `phpmyadmin:5.2.3-apache` | `127.0.0.1:${PMA_PORT:-8081}:80` | none | `profiles: [tools]` (starts only with `--profile tools`); `PMA_HOST=mysql`; no `PMA_USER`/`PMA_PASSWORD`, so a login form is required; `PMA_ARBITRARY` unset, so only `mysql` can be reached; `depends_on: mysql (service_healthy)` |

The `mysql-data` volume is a named volume. `docker compose down` keeps it; `down -v` is documented
as destructive and opt-in (FR-012).

## Environment Configuration and Secret Handling

- `.env.example` (committed) holds every variable, each with a comment and only placeholder or
  local-only values:
  - `APP_NAME`, `APP_ENV=local`, `APP_KEY=` (empty), `APP_DEBUG=true`, `APP_URL`, `APP_PORT=8080`
  - `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`
  - `PMA_PORT=8081` (phpMyAdmin local port)
  - `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_DATABASE=clinic`,
    `DB_USERNAME=clinic`, `DB_PASSWORD=change-me`, `DB_TEST_DATABASE=clinic_testing`,
    `MYSQL_ROOT_PASSWORD=change-me-root`
  - `SESSION_DRIVER=array`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `LOG_CHANNEL`,
    `LOG_LEVEL`
  - Unused skeleton keys (mail, Redis, AWS, Vite) are removed.
- `.env` is git-ignored (skeleton default). `APP_KEY` is generated inside the container.
- `.gitattributes` forces LF line endings so shell and config files work on Windows checkouts.
- `phpunit.xml` overrides `DB_CONNECTION`/`DB_DATABASE` with `force="true"` (so a stray container env var can never redirect tests to `clinic`) and holds no secrets.

## MySQL Connection and Persistent Storage

- The app connects with Laravel's default `mysql` connection: host `mysql` (the service name), port
  3306, charset `utf8mb4`, collation `utf8mb4_unicode_ci`, strict mode on.
- `docker/mysql/init/01-create-test-database.sh` (LF line endings) creates `${DB_TEST_DATABASE}`
  and grants `${MYSQL_USER}` access to it. It runs only on first volume initialization;
  troubleshooting covers recreating the database manually without wiping data.
- Data persists in `mysql-data` across restarts and rebuilds.

## Laravel Application Setup

1. Create the skeleton with `composer create-project laravel/laravel:^13` inside the built `app`
   image, into a temporary directory. Move it into the repository root, merging the skeleton's
   `.gitignore` with the existing one (keep `.idea/`). Spec Kit files are untouched. Commit
   `composer.lock`.
2. Remove frontend and session scaffolding (D3 and D5): `package.json`, `vite.config.js`,
   `resources/css`, `resources/js`, `welcome.blade.php`, `routes/web.php`, the `/up` route, the
   users migration, `User` model, `UserFactory`, and the npm part of the `composer dev` script.
3. `bootstrap/app.php`:
   - `withRouting` keeps `commands` only (module providers load API routes).
   - `withMiddleware` appends `SetLocaleFromHeader` to the `api` group.
   - `withExceptions` calls `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`.
4. `config/app.php`: add `'supported_locales' => ['en', 'ar']`.
5. Run `php artisan lang:publish` (en files) and add `lang/ar/{auth,pagination,passwords,validation}.php` (D4).
6. Create the `System` module (health) per the conventions below and register its provider in
   `bootstrap/providers.php`.
7. Configure `phpunit.xml` for MySQL `clinic_testing` (replacing the default SQLite `:memory:`
   lines).

## Directory Conventions

```text
app/
├── Http/
│   └── Middleware/SetLocaleFromHeader.php     # cross-cutting HTTP concerns only
├── Modules/
│   └── <Domain>/                              # e.g. System (this feature), Patients (later)
│       ├── Http/
│       │   ├── Controllers/                   # thin: Form Request in → Service/Action → Resource out
│       │   ├── Requests/                      # Form Requests: validation (+ authorize() → Policy)
│       │   └── Resources/                     # API Resources: the only response serialization
│       ├── Actions/                           # single-purpose business operations
│       ├── Services/                          # multi-step business workflows, transactions
│       ├── Repositories/
│       │   ├── Contracts/<Name>Repository.php # interfaces; services depend on these
│       │   └── <Impl><Name>Repository.php     # e.g. EloquentPatientRepository; all queries here
│       ├── Models/                            # Eloquent models: relations, casts; no business workflows
│       ├── Policies/                          # authorization per model/ability
│       ├── Providers/<Domain>ServiceProvider.php  # bindings, routes, policies, translations
│       ├── routes/api.php                     # loaded with prefix api/v1 + api middleware
│       └── lang/{en,ar}/*.php                 # module messages: __('<domain>::file.key')
├── Providers/AppServiceProvider.php
bootstrap/providers.php                        # lists each module provider explicitly
database/migrations/                           # all migrations (root, Laravel ordering)
database/factories/                            # model factories (synthetic data only)
lang/{en,ar}/                                  # framework messages (validation, auth, …)
tests/Feature/<Domain>/   tests/Unit/<Domain>/
docker/php/  docker/nginx/  docker/mysql/init/
docs/architecture.md  docs/troubleshooting.md  README.md
```

**Layer rules** (written in full in `docs/architecture.md`):

- **Controllers**: no business logic, no queries, and no `DB`/Eloquent usage. They authorize through
  Policies (Form Request `authorize()` or `$this->authorize`).
- **Services/Actions**: hold the business rules. They depend only on repository **interfaces** and
  own transactions. They never build queries.
- **Repositories**: the only place with Eloquent, query builder, or `DB` calls. Methods are
  explicit and purpose-named. There is no generic or base repository.
- **Models**: persistence mapping only.
- **Policies**: every protected resource has one, registered in the module provider.
- **Localization**: no hard-coded user-facing strings. API field names are the same in every locale.
- **Empty folders**: not created; a folder appears in a module when the module first needs it.

**System module (this feature only)**:

| Location | File | Role |
|----------|------|------|
| `Http/Controllers` | `HealthController` | Single-action controller |
| `Services` | `HealthCheckService` | Builds the health status |
| `Repositories/Contracts` | `DatabaseStatusRepository` | Interface with `isReachable(): bool` |
| `Repositories` | `MySqlDatabaseStatusRepository` | Runs a trivial query; returns `false` on any connection exception, with no message passed on |
| `Http/Resources` | `HealthResource` | `$wrap = null`, so the shape matches the contract |
| `Providers` | `SystemServiceProvider` | Bindings and routes |
| `routes` | `api.php` | Defines `GET /health` |

## Migration and Testing Workflow

- **Migrate**: `docker compose exec app php artisan migrate`. New migrations only; applied migrations
  are never edited. `migrate:fresh`, `db:wipe`, and `down -v` are documented as destructive.
- **Test**: `docker compose exec app composer test` (clears config, then `php artisan test`) runs
  against `clinic_testing` with `RefreshDatabase`. A non-zero exit means failure. A
  guard in `tests/TestCase.php` (a `setUpTraits()` override calling `ensureTestingDatabase()` before
  any `RefreshDatabase`/`DatabaseMigrations`/`DatabaseTruncation`/`DatabaseTransactions` setup) aborts
  any test whose connection is not `clinic_testing`. (Laravel's `beforeRefreshingDatabase()` hook was
  rejected: it lives inside the `RefreshDatabase` trait, so the trait's empty version would win.)
- **Foundation tests**:
  - `tests/Feature/System/HealthEndpointTest`: 200 with the contract body; 503 when the
    container binds a fake `DatabaseStatusRepository` that returns `false`; no internal details
    in the body.
  - `tests/Feature/Localization/LocaleSelectionTest`: `en`, `ar`, `ar-EG`, `en-US;q=0.8`,
    `fr`, malformed, and missing headers. Each asserts the resolved locale and a translated
    framework validation message in both locales, using a test-only route registered inside the
    test (not shipped).
  - `tests/Feature/Api/JsonErrorsTest`: an unknown `/api/v1/*` path returns a JSON 404 with an
    identical structure across locales.
  - `tests/Feature/Infrastructure/TestDatabaseIsolationTest`: suite targets `clinic_testing` and the
    `ensureTestingDatabase()` guard throws for any other database, including through the real
    `RefreshDatabase` setup path (runs first, in Phase 2).
  - `tests/Feature/Localization/TranslationParityTest`: every `lang/en/*.php` file has a matching
    `lang/ar/*.php` file with identical keys.

## Verification Commands and Expected Outcomes

See [quickstart.md](quickstart.md) (V1–V9). In summary:

| Check | Command | Expected |
|-------|---------|----------|
| Start | `docker compose up -d --build && docker compose ps` | 3 services running; mysql healthy |
| DB link | `docker compose exec app php artisan db:show` | host `mysql`, MySQL 9.7.x |
| No DB port | `docker inspect $(docker compose ps -q mysql) --format '{{json .HostConfig.PortBindings}}'` | `{}`; host TCP to 3306 refused |
| Migrate | `php artisan migrate` (twice) | applies, then "Nothing to migrate" |
| Health | `curl -i localhost:8080/api/v1/health` | 200 `{"status":"ok","checks":{"database":"ok"}}` |
| Health down | stop mysql, then curl | 503 `unavailable`, no internals |
| Tests | `docker compose exec app php artisan test` | all pass, exit 0 |
| Secrets | `git check-ignore .env`; review `.env.example` | ignored; placeholders only |
| DB GUI | `docker compose --profile tools up -d phpmyadmin`; open `http://localhost:8081` | Login form; `DB_USERNAME`/`DB_PASSWORD` log in to `clinic`; not started by a plain `up` |

## Implementation Outline (input for `/speckit-tasks`)

1. Repo hygiene: `.gitattributes`; create the feature branch.
2. Docker: Dockerfile, PHP ini, nginx config, MySQL init script, `compose.yaml`.
3. Laravel skeleton created in a container and moved into the repo; frontend and users removed.
4. `.env.example` rewritten; `phpunit.xml` pointed at MySQL test DB.
5. JSON errors and locale middleware with `en`/`ar` framework translations, plus tests.
6. System module health endpoint through the repository chain, plus tests.
7. phpMyAdmin service (profile `tools`) and its docs section.
8. Docs: README, architecture, troubleshooting.
9. Full quickstart run-through and results recorded.

## Approved Decisions (2026-10-27)

| ID | Decision | Approved |
|----|----------|----------|
| D1 | PHP version | **8.5.12** (`php:8.5.12-fpm-trixie`) |
| D2 | MySQL version | **9.7.4 LTS** (`mysql:9.7.4`) |
| D3 | Default users/password_reset_tokens/sessions migration and `User` model | **Removed**; deferred to the auth feature |
| D4 | Arabic framework translations | **Copied** from Laravel-Lang (MIT) and reviewed; no package |
| D5 | Default web routes, welcome view, `/up` | **Removed**; the only endpoint is `/api/v1/health` |
| D6 | Working branch | **`001-project-foundation`** created before implementation |
| D8 | `vendor/` location (after T004 bind-mount timeout) | **Named volume `vendor`** mounted at `/var/www/html/vendor` (research R16) |
| D7 | Database GUI (user request) | **phpMyAdmin `5.2.3-apache`**, opt-in profile `tools`, `127.0.0.1` only (FR-026, research R15) |

## Risks, Assumptions, and Rollback

**Risks**

| Risk | Mitigation |
|------|------------|
| PHP 8.5 or MySQL 9.7 incompatibility in a skeleton dependency | Full test run at step 3; fall back to 8.4.27 / 8.4.13 by changing one image tag (approved alternatives) |
| Slow bind mounts on Windows (repo at `C:\Eslam\...`) | **Materialized in T004** (Composer unzip timeout). Mitigated by D8: `vendor/` in a named volume. WSL2 clone still documented as the fastest option |
| `vendor/` not visible on the Windows host (D8) | Documented in README; run PHP tooling inside the container |
| CRLF breaks the MySQL init script | `.gitattributes` LF rule; troubleshooting entry |
| Test DB missing because the volume pre-existed the init script | Documented non-destructive `CREATE DATABASE` + `GRANT` command |
| Host port 8080 in use | `APP_PORT` variable |
| phpMyAdmin used beyond local dev, or with real data | Opt-in profile, localhost-only binding, login required; documented as development-only (FR-026) |
| phpMyAdmin 5.2.3 login fails against MySQL 9.7 (`caching_sha2_password`) | No source confirms or rules this out; verified at implementation (V9). Fallback: use the newest 5.2.x tag available, or record the incompatibility and ask before changing anything |
| `APP_DEBUG=true` exposing traces in error responses | Development only; health endpoint never includes exception text; production is out of scope |

**Assumptions**: Docker Desktop/Engine with Compose v2+ is present; developers don't need host PHP;
the image tags above remain available (pinned to full patch versions).

**Rollback**: All changes are files in git on the feature branch. To roll back:
- run `docker compose down` (keeps data) and discard or revert the branch;
- `docker compose down -v` removes the database volume and needs explicit approval;
- no external systems, production, or shared resources are touched.

## Project Structure

### Documentation (this feature)

```text
specs/001-project-foundation/
├── plan.md              # This file
├── research.md          # Phase 0: version/approach decisions with sources
├── data-model.md        # Phase 1: infrastructure tables only, health shape
├── quickstart.md        # Phase 1: validation run guide (V1–V8)
├── contracts/
│   └── health.openapi.yaml
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
compose.yaml
.env.example  .gitattributes  .gitignore  phpunit.xml  composer.json
docker/
├── php/Dockerfile
├── php/conf.d/app.ini
├── nginx/default.conf
└── mysql/init/01-create-test-database.sh
app/
├── Http/Middleware/SetLocaleFromHeader.php
└── Modules/System/
    ├── Http/Controllers/HealthController.php
    ├── Http/Resources/HealthResource.php
    ├── Services/HealthCheckService.php
    ├── Repositories/Contracts/DatabaseStatusRepository.php
    ├── Repositories/MySqlDatabaseStatusRepository.php
    ├── Providers/SystemServiceProvider.php
    └── routes/api.php
bootstrap/app.php  bootstrap/providers.php
config/app.php
lang/en/*.php  lang/ar/*.php
database/migrations/   (skeleton cache + jobs only)
tests/TestCase.php                       (setUpTraits / ensureTestingDatabase guard)
tests/Feature/Infrastructure/TestDatabaseIsolationTest.php
tests/Feature/System/HealthEndpointTest.php
tests/Feature/Localization/LocaleSelectionTest.php
tests/Feature/Localization/TranslationParityTest.php
tests/Feature/Api/JsonErrorsTest.php
README.md  docs/architecture.md  docs/troubleshooting.md
```

**Structure Decision**: A single Laravel application at the repository root with self-contained
domain modules under `app/Modules/<Domain>/` (clarification Q3). Cross-cutting HTTP concerns
stay in `app/Http`. Migrations, factories, and framework translations stay at Laravel's default
root locations.

## Complexity Tracking

No constitution violations; nothing to justify.
