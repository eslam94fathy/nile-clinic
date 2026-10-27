# Quickstart Validation: Project Foundation

**Feature**: 001-project-foundation | **Date**: 2026-10-27

Run these steps from the repository root after implementation to prove every acceptance criterion.
Commands are POSIX-shell syntax (Git Bash/WSL/macOS/Linux); PowerShell equivalents are noted where
they differ.

## Prerequisites

- Docker Engine with Compose v2+ (verified: Docker 29.6.2, Compose v5.3.1).
- No host PHP, Composer, or MySQL needed.
- Host port `8080` free (or change `APP_PORT` in `.env`).

## V1 — Configure (AC-5)

```sh
cp .env.example .env          # PowerShell: Copy-Item .env.example .env
# edit DB_PASSWORD and MYSQL_ROOT_PASSWORD to local-only values
git check-ignore .env         # expected: prints ".env" (file is ignored)
```

Expected: `.env.example` documents every variable with placeholder values only; `.env` is ignored.

## V2 — Build and start (AC-1)

```sh
docker compose up -d --build
docker compose ps
```

Expected: `app`, `web`, `mysql` are `running`; `mysql` shows `healthy`.

```sh
docker compose exec app php artisan key:generate
```

Expected: `Application key set successfully.` (key written to `.env` only).

## V3 — Database over container network (AC-2, FR-004, FR-005)

```sh
docker compose exec app php artisan db:show
```

Expected: connection `mysql`, host `mysql`, database `clinic`, MySQL 9.7.x.

```sh
docker inspect $(docker compose ps -q mysql) --format '{{json .HostConfig.PortBindings}}'
```

Expected: `{}` (no published port), and connecting from the host to `127.0.0.1:3306` is refused.
The database is reachable only inside the Compose network. (Do not rely on
`docker compose port mysql 3306`: on Compose v5.3.1 it prints `invalid IP:0` and exits 0.)

## V4 — Migrations (AC-3, FR-011)

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

Expected: first run applies the cache and jobs migrations; second run prints `Nothing to migrate`;
status lists all as `Ran`.

## V5 — Health endpoint (FR-020, contract: [contracts/health.openapi.yaml](contracts/health.openapi.yaml))

```sh
curl -i http://localhost:8080/api/v1/health
```

Expected: `HTTP/1.1 200`, `Content-Type: application/json`, body `{"status":"ok","checks":{"database":"ok"}}`.

```sh
docker compose stop mysql
curl -i http://localhost:8080/api/v1/health
docker compose start mysql
```

Expected: `HTTP/1.1 503`, body `{"status":"unavailable","checks":{"database":"unavailable"}}`,
with no hostnames, credentials, or stack traces.

## V6 — Localization (AC-7, FR-017–FR-019)

```sh
curl -s -H "Accept-Language: ar" http://localhost:8080/api/v1/does-not-exist
curl -s -H "Accept-Language: fr" http://localhost:8080/api/v1/does-not-exist
```

Expected: both return JSON (never HTML) with an identical structure. Locale behavior on
translatable framework messages is proven by the automated locale tests (V7), since
this feature has no endpoint that accepts input.

## V7 — Automated tests (AC-4, FR-013–FR-015)

```sh
docker compose exec app php artisan test
echo $?                       # PowerShell: $LASTEXITCODE
```

Expected: all tests pass; exit code `0`. The suite includes health (ok and database-unavailable)
and locale tests (`en`, `ar`, `ar-EG`, `en-US;q=0.8`, unsupported, missing). Development data in
`clinic` is unchanged afterwards (tests use `clinic_testing`).

## V8 — Documentation (AC-6, AC-8)

Check that these exist and cover the listed topics:

- `README.md` — prerequisites, first-time setup, daily start/stop, migrations, tests, environment.
- `docs/architecture.md` — module layout, every layer's location/responsibility/prohibitions,
  `/api/v1` rule, module registration.
- `docs/troubleshooting.md` — port conflicts, DB not ready, missing `.env`/key, test DB missing,
  Windows line endings/permissions/performance, viewing logs.

## V9 — Database GUI (FR-026)

```sh
docker compose up -d && docker compose ps     # phpmyadmin NOT listed (opt-in)
docker compose --profile tools up -d phpmyadmin
```

Open `http://localhost:8081`. Expected: phpMyAdmin login form (no auto-login). Log in with
`DB_USERNAME` / `DB_PASSWORD`; the `clinic` database is visible. From another machine on the LAN,
`http://<host-ip>:8081` is unreachable. Stop it with `docker compose --profile tools stop phpmyadmin`.

## Stop / reset

```sh
docker compose down           # stops containers, keeps data
docker compose down -v        # DESTRUCTIVE: deletes the MySQL volume — opt-in only
```
