---

description: "Task list for Feature 001: Project Foundation and Local Development Environment"
---

# Tasks: Project Foundation and Local Development Environment

**Input**: Design documents from `specs/001-project-foundation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/health.openapi.yaml, quickstart.md

**Tests**: Required by the spec (FR-013–FR-015, Constitution Principle VIII). Test tasks are written
first and MUST fail before the implementation task that makes them pass.

**Organization**: Grouped by user story (spec priorities). Each task has an **Objective** (the
checklist line), plus **Scope**, **Accept** (acceptance criteria), **Verify** (exact commands) and
**Depends** lines.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
  PowerShell users: replace `$?`/`echo $?` with `$LASTEXITCODE`.

## Command Verification Rule

Only commands confirmed by the plan's research (Docker 29.6.2 / Compose v5.3.1 on the host;
Laravel 13 Artisan; PHPUnit via `php artisan test`; Pint from the skeleton) are listed. If a
command does not behave as written, **stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Branch, repository hygiene, container images, and the Laravel skeleton.

- [X] T001 Create and switch to the feature branch `001-project-foundation` (approved D6)
  - Scope: git only; no file changes.
  - Accept: current branch is `001-project-foundation`, created from `main` at the plan commit.
  - Verify: `git switch -c 001-project-foundation` then `git branch --show-current` → `001-project-foundation`
  - Depends: none

- [X] T002 Add LF line-ending rules in `.gitattributes`
  - Scope: `* text=auto eol=lf`; explicit `*.sh text eol=lf`; binary markers for images and fonts. No other files.
  - Accept: new or changed text files check out with LF; `*.sh` is always LF.
  - Verify: `git check-attr eol -- docker/mysql/init/01-create-test-database.sh` → `eol: lf`
  - Depends: T001

- [X] T003 [P] Create the PHP-FPM image in `docker/php/Dockerfile` and `docker/php/conf.d/app.ini`
  - Scope:
    - `FROM php:8.5.12-fpm-trixie`
    - apt `git`, `unzip`; `docker-php-ext-install pdo_mysql pcntl`
    - `COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer`
    - build args `UID`/`GID` (default 1000) create user `app`; `USER app`; `WORKDIR /var/www/html`
    - (added by D8) pre-create `/var/www/html/vendor` owned by `app`, so the named `vendor` volume is writable
    - `app.ini`: `expose_php=Off`, `memory_limit=256M`, `date.timezone=UTC`
    - No other extensions (research R13).
  - Accept: the image builds; PHP is 8.5.12; `pdo_mysql` and `pcntl` are loaded; Composer is 2.10.3; the process runs as non-root `app`.
  - Verify:
    - `docker build -t nile-clinic-app:dev docker/php`
    - `docker run --rm nile-clinic-app:dev php -v` → `PHP 8.5.12`
    - `docker run --rm nile-clinic-app:dev php -m` → lists `pdo_mysql`, `pcntl`
    - `docker run --rm nile-clinic-app:dev composer --version` → `Composer version 2.10.3`
    - `docker run --rm nile-clinic-app:dev whoami` → `app`
  - Depends: T001. If the `composer:2.10.3` tag does not exist, stop and report (research R4).

- [X] T004 Create the Laravel 13 skeleton inside the container and move it into the repository root
  - Scope (revised by D8 after the first attempt timed out unzipping `vendor/` onto the Windows bind mount):
    - Run `docker run --rm -v "${PWD}:/work" -w /work nile-clinic-app:dev composer create-project "laravel/laravel:^13" .skeleton --no-install --no-scripts --no-interaction` (skeleton files only; no `.env`, no SQLite file, no `vendor/`).
    - Move the contents of `.skeleton/` into the root, then delete `.skeleton/`.
    - Merge the skeleton `.gitignore` with the existing one, keeping `.idea/`.
    - Install dependencies into a temporary named volume and generate `composer.lock`: `docker run --rm -v "${PWD}:/var/www/html" -v nile-clinic-t004-vendor:/var/www/html/vendor nile-clinic-app:dev composer install --no-interaction`. The Compose `vendor` volume (T009) gets its own install in T010. Removing the temporary volume needs explicit approval.
    - Do NOT touch `CLAUDE.md`, `.claude/`, `.specify/`, `specs/`.
  - Accept:
    - `composer.json` requires `laravel/framework ^13.x` and PHP `^8.3`
    - `artisan` exists; `vendor/` and `.env` are git-ignored
    - no SQLite file remains; Spec Kit files are unchanged (`git status` shows no changes under `.specify/` or `specs/`)
    - `composer.lock` exists and is **not** git-ignored (it pins exact package versions, FR-002); docs use `composer install`, never `composer update`, for setup
  - Verify:
    - `docker run --rm -v "${PWD}:/var/www/html" -v nile-clinic-t004-vendor:/var/www/html/vendor nile-clinic-app:dev php artisan --version` → `Laravel Framework 13.`
    - `git check-ignore vendor .env` → prints both
    - `git check-ignore composer.lock` → no output (exit 1)
  - Depends: T003

- [X] T005 Remove frontend, web-route, and default user scaffolding (approved D3, D5)
  - Scope: delete
    - `package.json`, `vite.config.js`, `resources/css/`, `resources/js/`, `resources/views/welcome.blade.php`
    - `routes/web.php`
    - `database/migrations/0001_01_01_000000_create_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`
    - the `User` reference in `database/seeders/DatabaseSeeder.php` (leave an empty `run()`)
    - `tests/Feature/ExampleTest.php` (it asserts the removed `/` route; removed because the route is out of scope, not to make a test pass)
    - In `composer.json`: remove the npm/vite commands from the `dev` and `setup` scripts.
  - Accept:
    - no files above remain
    - `grep -rn "App\\\\Models\\\\User" app config database` finds nothing that breaks boot
    - `config/auth.php` provider references are left as framework config (no model class is loaded at boot)
  - Verify: `docker run --rm -v "${PWD}:/var/www/html" nile-clinic-app:dev php artisan about` exits 0
  - Depends: T004
  - Follow-up (approved 2026-10-27): `.npmrc` (npm-only config from the skeleton) deleted.

- [X] T006 [P] Create the nginx site config in `docker/nginx/default.conf`
  - Scope:
    - `root /var/www/html/public`; `index index.php`
    - `try_files $uri $uri/ /index.php?$query_string`
    - `fastcgi_pass app:9000` with `SCRIPT_FILENAME $realpath_root$fastcgi_script_name`
    - deny `/\.(?!well-known)`; `server_tokens off`
  - Accept: valid nginx syntax.
  - Verify: `docker run --rm -v "${PWD}/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" --add-host app:127.0.0.1 nginx:1.30.6-alpine nginx -t` → `syntax is ok`
  - Depends: T001

- [X] T007 [P] Create the test-database init script `docker/mysql/init/01-create-test-database.sh`
  - Scope:
    - POSIX `sh`, LF line endings
    - uses `$MYSQL_ROOT_PASSWORD`, `$MYSQL_USER`, `$DB_TEST_DATABASE`
    - `CREATE DATABASE IF NOT EXISTS \`$DB_TEST_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
    - `GRANT ALL PRIVILEGES ON \`$DB_TEST_DATABASE\`.* TO '$MYSQL_USER'@'%';`
    - No credentials hard-coded.
  - Accept: the script contains no literal passwords and uses only environment variables. It is verified at runtime in T010.
  - Verify: `git ls-files --eol docker/mysql/init/01-create-test-database.sh` → `i/lf`
  - Depends: T002

**Checkpoint**: Images build, the skeleton is in place, and the API-only cleanup is done.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Compose stack, base configuration, and test-DB wiring that every story depends on.

**⚠️ CRITICAL**: No user story work begins until T010 passes.

- [X] T008 Write the initial `.env.example` for the Docker stack
  - Scope:
    - Replace the skeleton values with:
      - `APP_NAME="Nile Clinic"`, `APP_ENV=local`, `APP_KEY=`, `APP_DEBUG=true`, `APP_URL=http://localhost:8080`, `APP_PORT=8080`
      - `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`
      - `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_DATABASE=clinic`, `DB_USERNAME=clinic`, `DB_PASSWORD=change-me`, `DB_TEST_DATABASE=clinic_testing`, `MYSQL_ROOT_PASSWORD=change-me-root`
      - `SESSION_DRIVER=array`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `LOG_CHANNEL=stack`, `LOG_LEVEL=debug`
    - Remove the Redis, mail, AWS, and Vite keys.
    - Per-variable comments come in T026.
  - Accept: only placeholder or local-only values; no real secret; `DB_HOST=mysql`.
  - Verify: `git diff --stat .env.example`; manual review against plan §Environment Configuration
  - Depends: T005

- [X] T009 Create `compose.yaml` with the `app`, `web`, and `mysql` services
  - Scope (per plan §Compose responsibilities):
    - `app`: build `docker/php`; bind-mount `./:/var/www/html` plus named volume `vendor:/var/www/html/vendor` (D8); **no `env_file` and no DB/APP variables in `environment`** (Laravel reads `.env` from the mount; real env vars would block `phpunit.xml` overrides — analysis C1); `depends_on: mysql: condition: service_healthy`.
    - `web`: `nginx:1.30.6-alpine`; ports `"127.0.0.1:${APP_PORT:-8080}:80"`; mounts `./public:/var/www/html/public:ro` and `docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro`; depends on `app`.
    - `mysql`: `mysql:9.7.4`; **no `ports`**; `command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci"]` (MySQL 8+ defaults to `utf8mb4_0900_ai_ci`; this aligns server, `clinic`, `clinic_testing`, and Laravel's connection collation); environment `MYSQL_DATABASE=${DB_DATABASE}`, `MYSQL_USER=${DB_USERNAME}`, `MYSQL_PASSWORD=${DB_PASSWORD}`, `MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}`, `DB_TEST_DATABASE=${DB_TEST_DATABASE}`; volumes `mysql-data:/var/lib/mysql` and `./docker/mysql/init:/docker-entrypoint-initdb.d:ro`; healthcheck `mysqladmin ping -h localhost`.
    - Top-level `volumes: mysql-data: {}` and `vendor: {}`.
    - No `version:` key; no `latest` tags.
  - Accept: the config is valid; only `web` publishes a port, and only on `127.0.0.1`.
  - Verify:
    - `cp .env.example .env`
    - `docker compose config --quiet` → exit 0
    - `docker compose config | grep -n "published"` → exactly one entry (web)
    - `docker compose config | grep -A3 "app:"` → no `env_file` entry
  - Depends: T003, T006, T007, T008

- [X] T010 Start the stack and verify container networking to MySQL (AC-1, AC-2)
  - Scope: runtime verification only; fix earlier tasks if this fails.
  - Accept:
    - all 3 services are running and `mysql` is healthy
    - the app reaches MySQL via host `mysql`
    - MySQL has no host port
    - the `clinic_testing` database exists and the app user can use it
  - Verify:
    - `docker compose up -d --build` then `docker compose ps` → app/web/mysql up; mysql `healthy`
    - `docker compose exec app composer install` → exit 0
    - `docker compose exec app php artisan key:generate` → `Application key set successfully.`
    - `docker compose exec app php artisan db:show` → host `mysql`, database `clinic`, MySQL `9.7.4`
    - `docker inspect $(docker compose ps -q mysql) --format '{{json .HostConfig.PortBindings}}'` → `{}`, and a TCP connect from the host to `127.0.0.1:3306` is refused (revised: on Compose v5.3.1 `docker compose port mysql 3306` prints `invalid IP:0` with exit 0, so it is not a reliable check)
    - `docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -e "SHOW DATABASES"'` → lists `clinic` and `clinic_testing`
    - `docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -N -e "SELECT SCHEMA_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (\"clinic\",\"clinic_testing\")"'` → both `utf8mb4_unicode_ci`
  - Depends: T009

- [X] T011 [P] Configure API routing and JSON-only errors in `bootstrap/app.php` (FR-016, FR-019)
  - Scope:
    - `withRouting(commands: __DIR__.'/../routes/console.php')` only (no `web`, no `health: '/up'`)
    - `withExceptions`: `$exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));`
    - No custom response envelope.
  - Accept: `/up` and `/` are gone; any `api/*` error renders JSON.
  - Verify:
    - `curl -s -o /dev/null -w "%{http_code} %{content_type}" http://localhost:8080/api/v1/nope` → `404 application/json`
    - `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/up` → `404`
  - Depends: T010
  - Follow-up (approved 2026-10-27): `config/filesystems.php` local disk `'serve' => false`, removing the
    framework's public `GET`/`PUT storage/{path}` routes (not approved endpoints, D5).
    Verify: `php artisan route:list` → "Your application doesn't have any routes." (until T021).

- [X] T012 [P] Add supported locales to `config/app.php`
  - Scope: add `'supported_locales' => ['en', 'ar'],` next to `locale` and `fallback_locale`. Leave the env-driven `locale`/`fallback_locale` as they are.
  - Accept: `config('app.supported_locales') === ['en','ar']`; fallback `en`.
  - Verify: `docker compose exec app php artisan config:show app.supported_locales` → `en`, `ar`
  - Depends: T010

- [X] T013 Point the test suite at the MySQL test database in `phpunit.xml`
  - Scope:
    - Remove the commented SQLite lines.
    - Set `<env name="DB_CONNECTION" value="mysql" force="true"/>` and `<env name="DB_DATABASE" value="clinic_testing" force="true"/>`.
    - Keep the skeleton's `APP_ENV=testing`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.
    - No passwords in this file (the credentials come from `.env`).
    - In `tests/TestCase.php`, override `setUpTraits()`: if the test uses `RefreshDatabase`,
      `DatabaseMigrations`, `DatabaseTruncation`, or `DatabaseTransactions`, call
      `ensureTestingDatabase()` **before** `parent::setUpTraits()`. `ensureTestingDatabase()` throws a
      `RuntimeException` unless `DB::connection()->getDatabaseName() === 'clinic_testing'`
      (protects `clinic` even if config is cached — analysis R1).
      (Revised 2026-10-27, approved: the original `beforeRefreshingDatabase()` hook is declared
      inside the `RefreshDatabase` trait, so a test using the trait would run the trait's empty
      version instead of the guard — verified in the container.)
  - Accept: the isolation guard (T023) passes. No test that uses `RefreshDatabase` may run before T023 passes.
  - Verify: `docker compose exec app php artisan test` → passes, exit 0
  - Depends: T010

- [X] T023 Write the isolation guard test in `tests/Feature/Infrastructure/TestDatabaseIsolationTest.php` (moved into Phase 2 — analysis C1)
  - Scope:
    - assert `config('database.default') === 'mysql'`
    - assert `DB::connection()->getDatabaseName() === 'clinic_testing'`
    - the test exists to stop the suite ever running against development data
    - it must NOT use `RefreshDatabase`
    - a second test proves the guard: set `config(['database.connections.mysql.database' => 'clinic'])`,
      call `DB::purge('mysql')`, assert `$this->ensureTestingDatabase()` throws `RuntimeException`,
      then restore the config and purge again (the guard throws before any migration, so `clinic` is never touched)
    - a third test, in a class that **uses `RefreshDatabase`**, proves the real trait path: after its normal
      setup on `clinic_testing`, point the connection at `clinic`, purge, call `$this->setUpTraits()`, and assert
      `RuntimeException`; then restore the config and purge
  - Accept: both tests pass; the suite cannot refresh any database other than `clinic_testing`, on any run.
  - Verify: `docker compose exec app php artisan test --filter=TestDatabaseIsolationTest` → pass
  - Depends: T013

**Checkpoint**: Stack runs, DB reachable over the Compose network, and T023 passes, so tests can't
touch `clinic`.

---

## Phase 3: User Story 1 – Start the system locally (Priority: P1) 🎯 MVP

**Goal**: A migrated, running environment with a working `GET /api/v1/health` built through the
mandated layers.

**Independent Test**: Follow quickstart V2–V5. Health returns 200 when the DB is up and 503 when it is down.

### Migrations (AC-3)

- [X] T014 [US1] Verify the development migration workflow (FR-011)
  - Scope: run the migrations; no new migrations are written.
  - Accept: only the skeleton `cache` and `jobs` migrations apply; a re-run is a no-op.
  - Verify:
    - `docker compose exec app php artisan migrate` → runs `create_cache_table` and `create_jobs_table`
    - `docker compose exec app php artisan migrate` → `Nothing to migrate`
    - `docker compose exec app php artisan migrate:status` → both `Ran`
  - Depends: T010

### Tests for User Story 1 (write first, must fail)

- [X] T015 [US1] Write the health endpoint feature test in `tests/Feature/System/HealthEndpointTest.php`
  - Scope (per `contracts/health.openapi.yaml`, enums `status: ok | unavailable`, `checks.database: ok | unavailable`, `additionalProperties: false`):
    - (a) `GET /api/v1/health` → 200, `assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok']])`
    - (b) bind a fake `DatabaseStatusRepository` returning `false` → 503, `assertExactJson(['status' => 'unavailable', 'checks' => ['database' => 'unavailable']])`
    - (c) the response `Content-Type` is `application/json`
    - (d) the same body with `Accept-Language: ar`
    - Synthetic data only.
  - Accept: the test exists and fails (route missing) before T021.
  - Verify: `docker compose exec app php artisan test --filter=HealthEndpointTest` → fails before implementation
  - Depends: T013, T023

### Implementation for User Story 1

- [X] T016 [P] [US1] Create the repository interface `app/Modules/System/Repositories/Contracts/DatabaseStatusRepository.php`
  - Scope: namespace `App\Modules\System\Repositories\Contracts`; one method `isReachable(): bool`. No base interface.
  - Accept: the interface is autoloadable (PSR-4 `App\` → `app/`).
  - Verify: `docker compose exec app composer dump-autoload` → exit 0
  - Depends: T013

- [ ] T017 [US1] Implement `app/Modules/System/Repositories/MySqlDatabaseStatusRepository.php`
  - Scope:
    - Implements the interface.
    - Runs `DB::connection()->select('select 1')` and returns `true`.
    - Catches `\Throwable` and returns `false`, with no exception text propagated or returned.
    - This is the only file in the module that uses `DB`.
  - Accept: returns `false` when the DB is down and does not throw.
  - Verify: covered by T022
  - Depends: T016

- [ ] T018 [P] [US1] Create `app/Modules/System/Http/Resources/HealthResource.php`
  - Scope:
    - Extends `JsonResource`; `public static $wrap = null;`
    - `toArray()` returns exactly `status` and `checks.database` from the service result.
  - Accept: the output matches the contract shape with no `data` wrapper.
  - Verify: covered by T015
  - Depends: T013

- [ ] T019 [US1] Implement `app/Modules/System/Services/HealthCheckService.php`
  - Scope:
    - Constructor-injects `DatabaseStatusRepository` (the interface).
    - `check(): array` returns `['status' => 'ok'|'unavailable', 'checks' => ['database' => 'ok'|'unavailable']]`.
    - No DB calls.
  - Accept: depends only on the interface.
  - Verify: `grep -n "Facades\\\\DB" app/Modules/System/Services` → no matches
  - Depends: T016

- [ ] T020 [US1] Implement the thin invokable `app/Modules/System/Http/Controllers/HealthController.php`
  - Scope:
    - `__invoke(HealthCheckService $service): JsonResponse`
    - calls `$service->check()`; returns `HealthResource` with status 200 for `ok` and 503 for `unavailable`
    - no queries and no business logic (Constitution IV)
    - no Form Request (no input) and no Policy (public, approved Q2)
  - Accept: the controller only orchestrates.
  - Verify: `grep -nE "DB::|::query\(|Eloquent" app/Modules/System/Http/Controllers/HealthController.php` → no matches
  - Depends: T018, T019

- [ ] T021 [US1] Register the module: `app/Modules/System/routes/api.php`, `app/Modules/System/Providers/SystemServiceProvider.php`, `bootstrap/providers.php`
  - Scope:
    - Route: `Route::get('/health', HealthController::class)->name('system.health');`
    - Provider `register()`: `$this->app->bind(DatabaseStatusRepository::class, MySqlDatabaseStatusRepository::class);`
    - Provider `boot()`: `Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../routes/api.php');`
    - Add `App\Modules\System\Providers\SystemServiceProvider::class` to `bootstrap/providers.php`.
  - Accept: the route is listed under `api/v1`.
  - Verify: `docker compose exec app php artisan route:list --path=api` → `GET|HEAD api/v1/health … system.health`
  - Depends: T017, T020

- [ ] T022 [US1] Verify the health behavior end to end (quickstart V5)
  - Accept: T015 passes; live 200/503 behavior; no internals in the 503 body.
  - Verify:
    - `docker compose exec app php artisan test --filter=HealthEndpointTest` → pass
    - `curl -i http://localhost:8080/api/v1/health` → `200`, `{"status":"ok","checks":{"database":"ok"}}`
    - `docker compose stop mysql`
    - `curl -i http://localhost:8080/api/v1/health` → `503`, `{"status":"unavailable","checks":{"database":"unavailable"}}`
    - `docker compose start mysql`
  - Depends: T021

**Checkpoint**: US1 complete. MVP: the environment starts, migrates, and reports health.

---

## Phase 4: User Story 2 – Run the automated tests reproducibly (Priority: P2)

**Goal**: One documented command runs an isolated, repeatable suite.

**Independent Test**: Run the suite twice; it passes both times with exit 0, and the `clinic` database is unchanged.

*(The isolation guard T023 now lives in Phase 2; it is a prerequisite for this story.)*

- [ ] T024 [US2] Verify the single test command and its exit codes (FR-013)
  - Scope:
    - Confirm the skeleton `composer.json` `test` script (`config:clear` + `php artisan test`) works.
    - Then temporarily add a failing assertion locally, confirm a non-zero exit, and revert it (never committed).
  - Accept: exit 0 when passing, non-zero when failing.
  - Verify:
    - `docker compose exec app composer test; echo $?` → `0`
    - with the temporary failure: same command → non-zero
    - `git status` → no leftover change
  - Depends: T022, T023

- [ ] T025 [US2] Verify development data is untouched and results are repeatable (SC-002)
  - Accept: dev table counts and migration status are identical before and after two test runs.
  - Verify:
    - Snapshot `clinic` only, via MySQL as the app user (scratch file, not committed):
      `echo "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='clinic' ORDER BY TABLE_NAME; CHECKSUM TABLE <each clinic table>;" | docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" -N' > before.txt`
    - `docker compose exec app php artisan test` (×2, both pass)
    - Same snapshot `> after.txt`; `diff before.txt after.txt` → no differences
    - (Revised: `db:show --counts` needs the `intl` extension, which the image deliberately omits, and `db:show` lists tables from every visible database, so it cannot isolate `clinic`.)
  - Depends: T024

**Checkpoint**: US2 complete.

---

## Phase 5: User Story 3 – Configure the environment safely (Priority: P2)

**Goal**: Every setting is documented with safe placeholders; secrets can't be committed by accident.

**Independent Test**: Review `.env.example` and the ignore rules; Compose refuses to start without the required secrets.

- [ ] T026 [US3] Document every variable in `.env.example`
  - Scope: one comment line per variable or group (purpose, local default, "change before use" for passwords). No new variables beyond T008, except those later tasks add (T039).
  - Accept: 100% of the variables used by `compose.yaml` and `config/*` are present and commented (SC-003).
  - Verify:
    - `docker compose config --quiet` → exit 0
    - manual check that each `${VAR}` in `compose.yaml` appears in `.env.example`
  - Depends: T010

- [ ] T027 [US3] Fail fast on missing secrets in `compose.yaml`
  - Scope: use `${DB_PASSWORD:?DB_PASSWORD is required}`, `${MYSQL_ROOT_PASSWORD:?…}`, and `${DB_USERNAME:?…}` in the `mysql` service.
  - Accept: Compose errors clearly when they are unset.
  - Verify:
    - `docker compose --env-file /dev/null config` → error naming the missing variable (PowerShell: use an empty temp file)
    - `docker compose config --quiet` with `.env` → exit 0
  - Depends: T026

- [ ] T028 [US3] Verify that no secrets or real data are tracked (FR-010, Constitution XII)
  - Accept: `.env` is ignored and untracked; no password other than the placeholders appears in tracked files.
  - Verify:
    - `git check-ignore .env` → `.env`
    - `git ls-files .env` → empty
    - `git grep -nE "PASSWORD=.+" -- . ':!.env.example'` → no matches
  - Depends: T027

**Checkpoint**: US3 complete.

---

## Phase 6: User Story 4 – Architecture conventions (Priority: P3)

**Goal**: A developer can find where each layer lives and what it must not do.

**Independent Test**: Using only `docs/architecture.md`, a reviewer answers "where does X go and what may it do?" for every FR-021 layer.

- [ ] T029 [P] [US4] Write `docs/architecture.md`
  - Scope (per plan §Directory Conventions):
    - the module tree `app/Modules/<Domain>/…`
    - for each layer (routes, controllers, Form Requests, API Resources, Policies/Gates, Services/Actions, repository contracts and implementations, models, migrations, factories, translations, tests): its location, responsibility, and prohibitions
    - the `/api/v1` rule and how a future `v2` would be added
    - module registration (provider steps; adding to `bootstrap/providers.php`)
    - the transaction ownership rule; "no generic base repository"
    - the System module as the worked example
  - Accept: all FR-021 and FR-022 items are covered; consistent with Constitution III–VI.
  - Verify: manual checklist against FR-021's layer list (SC-006)
  - Depends: T021

- [ ] T030 [US4] Verify the System module follows the documented rules
  - Accept: no DB access outside repositories; the controller depends on the service only; the service depends on the interface only.
  - Verify:
    - `grep -rnE "Facades\\\\DB|DB::" app/Modules --include=*.php` → matches only `MySqlDatabaseStatusRepository.php`
    - `grep -n "MySqlDatabaseStatusRepository" -r app/Modules` → only the repository file and `SystemServiceProvider.php`
  - Depends: T029

**Checkpoint**: US4 complete.

---

## Phase 7: User Story 5 – en/ar localization foundation (Priority: P3)

**Goal**: The locale comes from a validated `Accept-Language` with an English fallback; framework messages exist in both languages.

**Independent Test**: The locale tests pass for `en`, `ar`, `ar-EG`, `en-US;q=0.8`, `fr`, malformed, and missing values.

### Tests for User Story 5 (write first, must fail)

- [ ] T031 [P] [US5] Write `tests/Feature/Localization/LocaleSelectionTest.php`
  - Scope:
    - Register a **test-only** route inside the test (`Route::middleware('api')->post('api/v1/_test/locale', …)` validating a `required` field; not shipped).
    - Data provider:
      - `ar` → `ar`
      - `ar-EG` → `ar`
      - `en` → `en`
      - `en-US;q=0.8` → `en`
      - `fr` → `en`
      - `!!invalid` → `en`
      - missing header → `en`
    - Assert `app()->getLocale()` and the validation message text from `lang/{locale}/validation.php` (`required`).
    - Assert the JSON keys are identical in both locales.
  - Accept: fails before T033/T034.
  - Verify: `docker compose exec app php artisan test --filter=LocaleSelectionTest` → fails
  - Depends: T012, T013

- [ ] T032 [P] [US5] Write `tests/Feature/Api/JsonErrorsTest.php`
  - Scope: `GET /api/v1/does-not-exist` with `Accept: text/html` → 404 JSON; same structure under `ar` and `en`.
  - Accept: passes once T011 is in place (a regression guard for FR-019).
  - Verify: `docker compose exec app php artisan test --filter=JsonErrorsTest` → pass
  - Depends: T011, T013

- [ ] T033 [P] [US5] Write `tests/Feature/Localization/TranslationParityTest.php` (Feature, because it needs the booted app for `lang_path()`)
  - Scope:
    - For each file in `lang/en/*.php`, assert `lang/ar/<same>.php` exists.
    - Assert its keys, flattened with dot notation, equal the English keys.
  - Accept: fails until T036 and T037 are done.
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest`
  - Depends: T013

### Implementation for User Story 5

- [ ] T034 [US5] Implement `app/Http/Middleware/SetLocaleFromHeader.php`
  - Scope (deterministic; does not rely on how Symfony matches regions):
    1. Go through `$request->getLanguages()` in order (already sorted by q-value).
    2. For each entry, take the base language: lowercase, keep the part before the first `_` or `-`.
    3. Use the first base language that is in `config('app.supported_locales')`.
    4. If none matches (or the header is missing or malformed), use `config('app.fallback_locale')`.
    5. Call `App::setLocale($locale)`.
    - No headers are echoed back; no logging; no DB.
  - Accept: pure HTTP concern; no DB.
  - Verify: covered by T031
  - Depends: T012

- [ ] T035 [US5] Append the middleware to the `api` group in `bootstrap/app.php`
  - Scope: `->withMiddleware(fn (Middleware $m) => $m->api(append: [SetLocaleFromHeader::class]))`
  - Accept: middleware is registered; the locale-resolution cases in T031 pass. The Arabic message-text cases may fail until T037; the full pass is checked in T038.
  - Verify: `docker compose exec app php artisan route:list --path=api -v` shows `SetLocaleFromHeader` in the api middleware
  - Depends: T011, T031, T034

- [ ] T036 [P] [US5] Publish the English framework translations to `lang/en/`
  - Scope: `php artisan lang:publish`; commit only the generated `lang/en/*.php`; no business messages.
  - Accept: `lang/en/{auth,pagination,passwords,validation}.php` exist.
  - Verify:
    - `docker compose exec app php artisan lang:publish`
    - `ls lang/en` → the four files
  - Depends: T010

- [ ] T037 [US5] Add the Arabic framework translations to `lang/ar/` (approved D4)
  - Scope:
    - Create `lang/ar/{auth,pagination,passwords,validation}.php` from the MIT-licensed Laravel-Lang `lang` project.
    - Header comment recording the source repository and commit/version.
    - Keys must match `lang/en` exactly.
    - Review the text; no business messages; no Composer package added.
  - Accept: T033 passes; `git diff composer.json` shows no new dependency.
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T033, T036

- [ ] T038 [US5] Verify the localization story (quickstart V6, SC-005)
  - Verify: `docker compose exec app php artisan test --filter="LocaleSelectionTest|JsonErrorsTest|TranslationParityTest"` → all pass
  - Depends: T032, T035, T037

**Checkpoint**: US5 complete. All stories are functional.

---

## Phase 8: Database GUI (FR-026, approved D7)

**Purpose**: An opt-in, localhost-only phpMyAdmin.

- [ ] T039 Add the `phpmyadmin` service to `compose.yaml` and `PMA_PORT` to `.env.example`
  - Scope:
    - `image: phpmyadmin:5.2.3-apache`; `profiles: [tools]`
    - ports `"127.0.0.1:${PMA_PORT:-8081}:80"`
    - `environment: PMA_HOST: mysql`
    - **no** `PMA_USER`/`PMA_PASSWORD`/`PMA_ARBITRARY`
    - `depends_on: mysql: condition: service_healthy`
    - `.env.example`: `PMA_PORT=8081` with a "development only, never with real patient data" comment
  - Accept: a plain `up` does not start it; with the profile it does.
  - Verify:
    - `docker compose config --services` → `app web mysql`
    - `docker compose --profile tools config --services` → also `phpmyadmin`
  - Depends: T027

- [ ] T040 Verify phpMyAdmin login and exposure (quickstart V9)
  - Accept: the login form shows (no auto-login); `DB_USERNAME`/`DB_PASSWORD` log in and show `clinic`; it is bound only to `127.0.0.1`.
  - Verify:
    - `docker compose --profile tools up -d phpmyadmin`
    - `curl -s -o /dev/null -w "%{http_code}" http://localhost:8081` → `200`
    - browser login succeeds
    - `docker compose port phpmyadmin 80` → `127.0.0.1:8081`
  - Depends: T039. If login against MySQL 9.7 fails (`caching_sha2_password`), **stop and report**; don't change versions without approval.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [ ] T041 [P] Write `README.md` (FR-024)
  - Scope:
    - project summary
    - prerequisites (Docker + Compose v2+, no host PHP)
    - first-time setup: copy `.env`, `up -d --build`, `composer install`, `key:generate`, `migrate`
    - daily start/stop
    - migrations: the "never edit applied migrations" rule; destructive commands (`migrate:fresh`, `db:wipe`, `down -v`) marked opt-in
    - tests: `composer test` (the documented command; it clears cached config first). Warn: never run `php artisan config:cache` in development; the `setUpTraits()` guard in `tests/TestCase.php` aborts any database-trait test whose connection is not `clinic_testing`
    - environment variables (pointer to `.env.example`)
    - phpMyAdmin usage
    - `vendor/` lives in a Docker volume (D8): it is not visible on the host; run Composer, Artisan, Pint, and tests inside the `app` container
    - links to `docs/`
  - Accept: a new developer can follow it alone (SC-001).
  - Verify: manual walk-through in T044
  - Depends: T038, T040

- [ ] T042 [P] Write `docs/troubleshooting.md` (FR-025)
  - Scope:
    - port in use (`APP_PORT`, `PMA_PORT`)
    - DB not ready or unhealthy (`docker compose ps`, `logs mysql`)
    - missing `.env` or `APP_KEY`
    - `clinic_testing` missing because the volume predates the init script (non-destructive `CREATE DATABASE` + `GRANT` via `docker compose exec mysql`)
    - CRLF in shell scripts
    - Windows bind-mount performance (clone into WSL2) and permissions; Composer unzip timeouts if `vendor/` is ever bind-mounted (why D8 uses a volume); an empty `vendor/` folder on the host is the volume mount point
    - Compose "required variable" errors
    - phpMyAdmin login issues
    - viewing logs (`docker compose logs -f <service>`, `storage/logs`)
  - Accept: every FR-025 item is covered.
  - Verify: manual checklist against FR-025
  - Depends: T038, T040

- [ ] T043 Run code style checks with the skeleton's Pint
  - Verify: `docker compose exec app vendor/bin/pint --test` → exit 0 (if it fails, run `vendor/bin/pint` and re-run the test suite)
  - Depends: T038

- [ ] T044 Full quickstart validation from a clean, isolated stack (V1–V9)
  - Scope:
    - `git clone` the branch into a new temp folder outside the repo (a real fresh checkout: no `vendor/`, no `.env`).
    - From that clone, follow **only** `README.md` with project name `-p nileverify`, `APP_PORT=8090`, `PMA_PORT=8091`, timing from clone to a migrated, healthy stack (excluding image downloads; pull images first with `docker compose pull`).
    - Then run V2–V9 with `-p nileverify`, so development data is untouched.
    - Tear down with `docker compose -p nileverify down`. Removing its volume (`down -v`) deletes only the verification volume, but still **requires explicit approval** at that time.
  - Accept: every V-step's expected outcome is observed (AC-1 to AC-8, FR-026); setup time ≤ 15 min (SC-001).
  - Verify: the commands in `specs/001-project-foundation/quickstart.md`
  - Depends: T041, T042, T043

- [ ] T045 Record the actual verification results in `specs/001-project-foundation/verification.md`
  - Scope:
    - For each V-step, record the date, command, actual output summary, and pass/fail. Report failures as failures (Constitution VIII).
    - Record the measured setup duration from T044.
    - If a second developer machine is available, repeat T044 there and record it; otherwise record SC-002's second-machine part as **not verified** (never as passed).
  - Accept: the file exists and matches the observed results.
  - Depends: T044

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: T001 first; then T002/T003/T006 in parallel; T004→T005; T007 after T002.
- **Phase 2 (Foundational)**: T008→T009→T010 (gate); then T011, T012, T013 (T011 and T012 parallel); T013→T023 (test-isolation gate before any `RefreshDatabase` test).
- **US1 (Phase 3)**: after T010 (T014) and T013 (T015+). The MVP.
- **US2 (Phase 4)**: after T023; T024 needs T022 so the full suite exists.
- **US3 (Phase 5)**: after T010; independent of US1/US2.
- **US4 (Phase 6)**: after T021 (needs the worked example).
- **US5 (Phase 7)**: after T011–T013; independent of US1.
- **Phase 8**: after T027 (fail-fast secrets in place).
- **Phase 9**: after all stories and Phase 8.

### Graph

```text
T001 → T002 → T007 ┐
T001 → T003 → T004 → T005 → T008 ┐
T001 → T006 ───────────────────── T009 → T010 ─┬→ T011 ─┬→ T032
                                              │        └→ T035
                                              ├→ T012 → T034 → T035
                                              ├→ T013 → T023 → T015/T016/T018 → … → T021 → T022 → T024 → T025
                                              │        └→ T031, T033
                                              ├→ T014
                                              ├→ T026 → T027 → T028 ; T027 → T039 → T040
                                              └→ T036 → T037
T021 → T029 → T030 ;  T038 + T040 → T041/T042 ; T038 → T043 ; → T044 → T045
```

### Parallel Opportunities

- Setup: T003, T006, and T002 (then T007) after T001.
- Foundational: T011 ∥ T012 after T010.
- US1: T016 ∥ T018 (different files); T015 is written alongside them.
- US5: T031 ∥ T032 ∥ T033 (tests); T036 alongside the tests.
- Stories US3 and US5 can proceed in parallel with US1 after Phase 2.
- Polish: T041 ∥ T042.

## Parallel Example: User Story 1

```text
Task: "T016 Create DatabaseStatusRepository interface"
Task: "T018 Create HealthResource"
(T015 test written first in parallel; T017/T019 follow T016)
```

## Parallel Example: User Story 5

```text
Task: "T031 LocaleSelectionTest"
Task: "T032 JsonErrorsTest"
Task: "T033 TranslationParityTest"
Task: "T036 lang:publish (en)"
```

---

## Implementation Strategy

### First task to implement

**T001 — create the `001-project-foundation` branch.** It is the smallest task, with no file
changes and no dependencies, and every other task depends on it (approved D6). The first task
that changes files is **T002** (`.gitattributes`): one small file that prevents CRLF breakage in
every later script.

### MVP First (User Story 1)

1. Phase 1 (T001–T007) → Phase 2 (T008–T013) → US1 (T014–T022).
2. **Stop and validate**: quickstart V2–V5.

### Incremental Delivery

US1 → US2 (tests reproducible) → US3 (secrets hardened) → US5 (localization) → US4 (docs on the
worked example) → Phase 8 (phpMyAdmin) → Phase 9 (docs, full validation). Commit after each task
or logical group, on `001-project-foundation`.

## Notes

- No business modules, auth packages, frontend tooling, or production deployment (spec Out of Scope).
- Never run `docker compose down -v`, `migrate:fresh`, or `db:wipe` on the dev stack without explicit approval.
- If any verification fails, fix it within that task's scope or stop and report; never weaken tests.
