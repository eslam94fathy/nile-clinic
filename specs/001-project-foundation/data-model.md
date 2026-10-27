# Data Model: Project Foundation

**Feature**: 001-project-foundation | **Date**: 2026-10-27

This feature introduces **no business entities** (spec: out of scope). The only schema it applies is
framework infrastructure, needed for configured drivers and to prove the migration workflow (AC-3).

## Databases

| Database | Purpose | Created by |
|----------|---------|-----------|
| `clinic` (`DB_DATABASE`) | Development data | MySQL image on first volume init (`MYSQL_DATABASE`) |
| `clinic_testing` (`DB_TEST_DATABASE`) | Isolated automated tests (FR-014) | Init script `docker/mysql/init/01-create-test-database.sh` on first volume init |

Both use server defaults `utf8mb4` / `utf8mb4_unicode_ci` (matching Laravel's MySQL connection
defaults), InnoDB engine (FR-007).

## Tables (framework infrastructure only)

| Table | Source migration | Why kept |
|-------|------------------|----------|
| `migrations` | Created by the migrator | Tracks applied migrations |
| `cache`, `cache_locks` | Skeleton `create_cache_table` | `CACHE_STORE=database` |
| `jobs`, `job_batches`, `failed_jobs` | Skeleton `create_jobs_table` | `QUEUE_CONNECTION=database` |

**Removed from skeleton (pending approval D3)**: `users`, `password_reset_tokens`, `sessions`.
These are deferred to the authentication feature.

## Non-persistent structures

### Health status (response of `GET /api/v1/health`)

| Field | Type | Values |
|-------|------|--------|
| `status` | string | `ok` \| `unavailable` |
| `checks.database` | string | `ok` \| `unavailable` |

Derived per request; never stored. No versions, hosts, credentials, timings, or error text are
included (FR-020). Full contract: [contracts/health.openapi.yaml](contracts/health.openapi.yaml).

### Supported locales (configuration)

| Key | Value |
|-----|-------|
| `app.locale` / `APP_LOCALE` | `en` |
| `app.fallback_locale` / `APP_FALLBACK_LOCALE` | `en` |
| `app.supported_locales` | `['en', 'ar']` |
