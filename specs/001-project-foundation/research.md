# Research: Project Foundation and Local Development Environment

**Feature**: 001-project-foundation | **Date**: 2026-10-27

All version facts below were verified on 2026-10-27 against the sources listed at the end.

## Environment Inspected

| Item | Found on host | Relevance |
|------|---------------|-----------|
| Docker Engine | 29.6.2 | Runs all services |
| Docker Compose | v5.3.1 (Compose Specification) | Orchestration; no `version:` key needed |
| Host PHP | 8.4.21 (Windows) | **Not used** — PHP runs only in containers (spec assumption) |
| Host Composer | 2.8.8 | **Not used** — Composer runs only in containers |
| Repository | No application code yet; Spec Kit files only | Laravel must be created into a temp dir and moved in |

## R1 — Laravel version

- **Decision**: Laravel **13.x** (`laravel/laravel` skeleton, constraint `laravel/framework ^13.17` as published in the 13.x skeleton).
- **Rationale**: Latest major, released 2026-03-17; bug fixes until Q3 2027, security fixes until 2028-03-17. Laravel 12 bug-fix support ended 2026-08-13 (security only until 2027-02-24), so starting a new project on 12 would mean an early upgrade.
- **Alternatives**: Laravel 12 — rejected (already security-only).

## R2 — PHP version

- **Decision**: PHP **8.5** via official image `php:8.5.12-fpm-trixie`. *(Requires approval — D1)*
- **Rationale**: Laravel 13 supports PHP 8.3–8.5. PHP 8.5 has active support until 2027-12-31 and security support until 2029-12-31. PHP 8.4's active support ends 2026-12-31 (about two months away). The default skeleton's dev dependencies (PHPUnit 12.5, Pint, Collision, Mockery) support PHP 8.3+.
- **Alternatives**: PHP 8.4 (`php:8.4.27-fpm-trixie`) — more mature ecosystem, but leaves active support sooner; acceptable fallback if any dependency fails on 8.5. PHP 8.3 — security-only, rejected.
- **Base OS**: Debian **trixie** rather than Alpine: glibc avoids musl-related extension and locale surprises; image size is not a concern for local development.

## R3 — MySQL version

- **Decision**: MySQL **9.7 LTS** via image `mysql:9.7.4`. *(Requires approval — D2)*
- **Rationale**: Laravel 13 supports MySQL 5.7+. 9.7 is the current LTS (GA April 2026; premier support to ~2031). MySQL upgrades must not skip an LTS series, so starting on 8.4 means a later 8.4 → 9.7 upgrade. Innovation releases (26.x, tagged `latest`/`innovation`) are rejected as short-lived; the constitution also forbids `latest`.
- **Alternatives**: MySQL 8.4.13 LTS — longest field history with Laravel (premier support to 2029-04-30); a good choice if the team prefers proven stability over a longer runway.
- **Note**: MySQL 9.x removed `mysql_native_password`; PHP `pdo_mysql` supports the default `caching_sha2_password`, so no change is needed.

## R4 — Composer

- **Decision**: Composer **2.10.3** (latest stable, 2026-08-27), copied into the PHP image from the official `composer:2.10.3` image.
- **Rationale**: Pinned and reproducible; no host Composer needed.
- **Verification step**: The `composer:2.10.3` image tag is confirmed to exist when the image is first built (task-level check).

## R5 — Container approach

- **Decision**: Custom Docker Compose with three services: `app` (PHP-FPM), `web` (nginx `1.30.6-alpine`, stable branch), `mysql`.
- **Rationale**: Full control over port exposure (FR-005), pinned images (FR-002), no extra Composer dependency (FR-008). nginx + PHP-FPM handles concurrent requests, which the concurrency tests in later booking features will need.
- **Alternatives**:
  - Laravel Sail — adds a dev dependency, publishes the database port by default (`FORWARD_DB_PORT`), and is generated rather than owned. Rejected.
  - `php artisan serve` in a single container — single-threaded, so it can't exercise concurrent booking later. Rejected.
  - FrankenPHP/Octane — adds a runtime model that isn't needed yet. Rejected (YAGNI).

## R6 — Test runner and test database

- **Decision**: **PHPUnit 12** (skeleton default) through `php artisan test`. Tests use a separate MySQL database `clinic_testing` on the same `mysql` service. A Compose init script creates it on first volume initialization and grants the app user access to it.
- **Rationale**: PHPUnit is already in the skeleton, so no extra dependency (FR-008). The same engine as development is required (FR-014), and the default SQLite `:memory:` setting would not satisfy it or later concurrency tests.
- **Alternatives**: Pest — an extra dependency with no requirement driving it; rejected. A separate MySQL container for tests — more resources and wiring for no isolation benefit locally; rejected.
- **Caveat**: MySQL init scripts run only when the data volume is empty. The troubleshooting guide documents a non-destructive fix: create the database manually with a documented SQL command.

## R7 — API routing without an authentication package

- **Decision**: Do **not** run `php artisan install:api` (it installs Sanctum, an auth package that is out of scope per the spec). Each module's service provider loads its own `routes/api.php` under the `api` middleware group with prefix `api/v1`.
- **Rationale**: FR-008 forbids auth packages in this feature. Laravel's built-in `api` middleware group exists without Sanctum.
- **Default web routes**: Remove `routes/web.php`, the welcome view, and the built-in `/up` health route, so the only endpoint is the approved `GET /api/v1/health`. *(Requires approval — D5)*

## R8 — Module wiring (FR-023)

- **Decision**: **One service provider per module** (`app/Modules/<Domain>/Providers/<Domain>ServiceProvider.php`), listed in `bootstrap/providers.php`. Each provider binds repository interfaces to implementations, loads module routes, registers policies (`Gate::policy`), and loads module translations (`loadTranslationsFrom(..., '<domain>')`).
- **Rationale**: Uses plain Laravel service providers, with no custom module loader or package. Each module's wiring is explicit, so adding a module means adding one line to `bootstrap/providers.php`.
- **Alternatives**: A single auto-discovering `ModulesServiceProvider` — hides wiring behind directory scanning; rejected as an unnecessary abstraction. `nwidart/laravel-modules` — a large dependency; rejected (FR-008).
- **Migrations and factories**: Stay in root `database/migrations` and `database/factories` (Laravel default ordering and tooling). Models in modules link their factory explicitly.

## R9 — Locale selection (FR-017)

- **Decision**: A cross-cutting middleware `App\Http\Middleware\SetLocaleFromHeader`, appended to the `api` middleware group. It resolves the request's preferred language against the supported list `['en', 'ar']` (configured in `config/app.php` as `supported_locales`) and calls `App::setLocale()`. Missing, malformed, or unsupported values resolve to `en`.
- **Rationale**: Uses Symfony's `Request::getLanguages()` (ships with Laravel, q-value ordered) with explicit base-language reduction. Regional subtags (`ar-EG`, `en-US`) are reduced to their base language before matching; tests cover each case in SC-005.
- **Alternatives**: A third-party localization package — unnecessary; rejected.

## R10 — Arabic framework translations (FR-018)

- **Decision**: Run `php artisan lang:publish` for the English framework files (`lang/en/{auth,pagination,passwords,validation}.php`). Add matching `lang/ar/*.php` files copied from the MIT-licensed Laravel-Lang project, then reviewed. **No Composer package is added.** *(Requires approval — D4)*
- **Rationale**: The files are static; a runtime or dev package (`laravel-lang/lang`) would add a dependency for 4 files.
- **Alternatives**: Install `laravel-lang/lang` as a dev dependency — easier to update but a new dependency; hand-translate — error-prone.

## R11 — JSON-only errors (FR-019)

- **Decision**: In `bootstrap/app.php`, `->withExceptions(fn ($e) => $e->shouldRenderJsonWhen(fn ($request) => $request->is('api/*')))`. Framework-default JSON shapes are kept.
- **Rationale**: Guarantees JSON for API paths regardless of the `Accept` header, with no custom envelope (clarification Q1 = A).

## R12 — Default framework migrations and frontend scaffolding

- **Decision**: *(Requires approval — D3)*
  - Keep `create_cache_table` and `create_jobs_table` (framework infrastructure; `CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
  - Remove `create_users_table` (users, password_reset_tokens, sessions), the `User` model, and `UserFactory`. Set `SESSION_DRIVER=array` (a stateless API needs no sessions). The authentication feature will design these tables against its own spec.
  - Remove frontend scaffolding: `package.json`, `vite.config.js`, `resources/css`, `resources/js`, `resources/views/welcome.blade.php`. Drop the npm-based part of the `composer dev` script.
- **Rationale**: Constitution Principle X (no invented schema) and the spec's out-of-scope list (no users or roles yet; backend only).
- **Alternative**: Keep the default users migration — fewer edits now, but it commits to a users schema before the auth feature is specified.

## R13 — PHP extensions and image hardening

- **Decision**: Install only `pdo_mysql` and `pcntl` (needed by the skeleton's `laravel/pail` dev tool), plus `git` and `unzip` for Composer. OPcache is bundled with PHP 8.5. Run PHP-FPM as a non-root user whose UID/GID are build arguments (default 1000), so files on bind mounts are not root-owned.
- **Rationale**: The minimal footprint required by FR-008 and the security principle. `bcmath` and `intl` are deferred until a feature needs them (for example money formatting or reports).

## R14 — Windows host specifics

- **Decision**: Add `.gitattributes` forcing LF for text files (critical for `*.sh` init scripts and `docker/**`). Document that cloning into the WSL2 filesystem greatly improves bind-mount performance on Windows.
- **Rationale**: The current repo shows CRLF conversion warnings, and a CRLF shell script breaks the MySQL init step.

## R15 — Database GUI (user request, FR-026)

- **Decision**: Official image `phpmyadmin:5.2.3-apache` as Compose service `phpmyadmin`, under
  profile `tools`, published only on `127.0.0.1:${PMA_PORT:-8081}`.
  - `PMA_HOST=mysql`, so it connects over the internal network and the DB port stays unpublished.
  - No `PMA_USER`/`PMA_PASSWORD`: the cookie login form is required.
  - `PMA_ARBITRARY` unset: no other servers can be reached.
- **Rationale**: 5.2.3 is the newest supported tag. The Apache variant needs no extra web server
  (the FPM variants would need their own nginx). An opt-in profile keeps the default stack minimal
  (least privilege), and starting it is one documented command.
- **Compatibility**: The image runs a modern PHP whose `mysqli`/`mysqlnd` supports MySQL 9's
  default `caching_sha2_password`. No source found confirms 5.2.3 against 9.7 specifically, so
  login is verified at implementation (quickstart V9).
- **Alternatives**: Adminer — lighter, but the user asked for phpMyAdmin. Always-on service —
  rejected in favor of opt-in. Publishing the MySQL port for a desktop client — rejected (FR-005).

## Sources

- Laravel 13 release notes and support policy — https://laravel.com/docs/13.x/releases
- Laravel 13 supported databases — https://laravel.com/docs/13.x/database
- Laravel 13.x skeleton `composer.json` — https://github.com/laravel/laravel/blob/13.x/composer.json
- PHP supported versions — https://www.php.net/supported-versions.php
- PHP official image tags — https://github.com/docker-library/docs/blob/master/php/README.md
- MySQL official image tags — https://hub.docker.com/_/mysql
- MySQL release model — https://blogs.oracle.com/mysql/post/mysql-release-model
- nginx official image tags — https://hub.docker.com/_/nginx
- Composer download page — https://getcomposer.org/download/
- phpMyAdmin official image — https://hub.docker.com/_/phpmyadmin
- PHP and MySQL 9 (caching_sha2_password) — https://blogs.oracle.com/mysql/post/php-and-mysql-9
