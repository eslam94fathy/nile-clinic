# Verification Results: Project Foundation

**Feature**: 001-project-foundation | **Date**: 2026-10-27 | **Task**: T044, T045

Results observed while running [quickstart.md](quickstart.md) from a clean, isolated stack.
Failures and caveats are reported as observed (Constitution VIII).

## Environment

- Host: Windows 11 Pro, Docker 29.6.2, Compose v5.3.1, Git Bash.
- Fresh `git clone --branch 001-phase-9-polish` of `eslam94fathy/nile-clinic` into a temporary
  folder outside the repository; confirmed no `vendor/` and no `.env` before setup.
- Compose project `-p nileverify` with `APP_PORT=8090`, `PMA_PORT=8091`, and local-only
  passwords, so the development stack (`nile-clinic`) and its data were not touched.
- Images pulled first (`docker compose pull --ignore-buildable`); the PHP image was built during
  setup with the local Docker layer cache.

## Setup time (SC-001)

Following only `README.md` → First-time setup:

| Step | Command | Duration |
|------|---------|----------|
| 2 | `docker compose up -d --build` | 27 s |
| 3 | `docker compose exec app composer install` | 15 s |
| 4 | `docker compose exec app php artisan key:generate` | 1 s |
| 5 | `docker compose exec app php artisan migrate` | 2 s |
| | **Total, to a migrated, healthy stack** | **45 s** |

Clone and `.env` creation took a few seconds more. Target ≤ 15 min: **pass**. The PHP image build
reused cached layers; a machine with no cache will spend longer on step 2.

## Results

| Step | Command (with `-p nileverify`) | Observed | Result |
|------|--------------------------------|----------|--------|
| V1 Configure (AC-5) | `cp .env.example .env`; `git check-ignore .env` | `.env` printed (ignored); `.env.example` has placeholders only | Pass |
| V2 Build and start (AC-1) | `up -d --build`; `ps`; `key:generate` | `app`, `web`, `mysql` running; `mysql` healthy; `APP_KEY` written to `.env` | Pass |
| V3 DB over container network (AC-2) | `php artisan db:show` | MySQL 9.7.4, connection `mysql`, host `mysql`, database `clinic`, 6 tables; **then** `RuntimeException: The "intl" PHP extension is required to use the [format] method` and a non-zero exit | Pass with caveat (see below) |
| V3 No published DB port (FR-005) | `docker inspect … PortBindings`; host TCP to `127.0.0.1:3306` | `{}`; `Connection refused` | Pass |
| V4 Migrations (AC-3) | `migrate` ×2; `migrate:status` | First run: cache and jobs tables `DONE`; second: `Nothing to migrate`; status: both `Ran` | Pass |
| V5 Health ok (FR-020) | `curl -i …:8090/api/v1/health` | `200`, `application/json`, `{"status":"ok","checks":{"database":"ok"}}` | Pass |
| V5 Health unavailable | `stop mysql`; `curl -i …`; `start mysql` | `503`, `application/json`, `{"status":"unavailable","checks":{"database":"unavailable"}}`, no hostnames, credentials, or traces; `ok` again once mysql was healthy | Pass |
| V6 Localization / JSON errors (AC-7) | `curl -H "Accept-Language: ar\|fr" …/api/v1/does-not-exist` | Both `404 application/json` with identical keys (`message`, `exception`, `file`, `line`, `trace`) | Pass (see note) |
| V7 Automated tests (AC-4) | `php artisan test`; exit code; `clinic` snapshot before/after | `19 passed (56 assertions)`, exit `0`; `clinic` rows and checksums identical | Pass |
| V8 Documentation (AC-6, AC-8) | Check files and topics | `README.md`, `docs/architecture.md`, `docs/troubleshooting.md` present and cover the listed topics | Pass |
| V9 Database GUI (FR-026) | `up -d`; `ps`; `--profile tools up -d phpmyadmin` | Plain `up` runs only `app`, `mysql`, `web`; phpMyAdmin `200` with the login form (no auto-login); `port phpmyadmin 80` → `127.0.0.1:8091`; scripted login with `DB_USERNAME`/`DB_PASSWORD` lists `clinic` | Pass |

Teardown: `docker compose -p nileverify --profile tools down` (containers and network removed).
The volumes `nileverify_mysql-data` and `nileverify_vendor` remain until their removal is
approved.

### Caveats

- **V3 `db:show` exits with an error.** It prints every expected connection value, then fails while
  formatting table sizes, because the image deliberately omits the `intl` extension (research
  R13; the same limit was recorded for `db:show --counts` in T025). The connection facts V3 checks
  were all observed, but the command as written does not exit cleanly.
- **V6 debug detail.** With the local default `APP_DEBUG=true`, error bodies include the exception
  class, file paths, and a stack trace. The structure is identical in both locales, as V6
  requires, and the health endpoint never exposes internals. Any non-local environment must set
  `APP_DEBUG=false`.

## Not verified

- **SC-002, second machine**: only one developer machine was available. Repeatability on a second
  machine is **not verified**. Repeatability on this machine was verified in T025 and again in V7.
- **V9 from another machine on the LAN**: not attempted. The port is bound to `127.0.0.1` only,
  which is what prevents LAN access; the browser login on the development stack was confirmed by
  the developer in T040.
