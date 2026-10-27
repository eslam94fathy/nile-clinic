# Nile Clinic API

Backend-only REST API for the Nile Clinic Management System, a single medical center. Built with
Laravel 13 and MySQL 9.7, run locally with Docker Compose. Endpoints live under `/api/v1` and
messages are available in English (`en`, the fallback) and Arabic (`ar`), selected with the
`Accept-Language` header.

It currently provides a health check (`GET /api/v1/health`) and staff sign-in with three roles
(administrator, receptionist, doctor). See [docs/](#documentation) for the architecture and
troubleshooting guides.

## Prerequisites

- Docker Engine or Docker Desktop with Compose v2+ (verified with Docker 29.6.2, Compose v5.3.1).
- Free host ports `8080` (API) and, if you use phpMyAdmin, `8081`. Both are configurable.
- No PHP, Composer, or MySQL on the host. Everything runs inside the containers.

On Windows, clone with Git's default settings; `.gitattributes` keeps files LF. For faster file
access, clone inside the WSL2 filesystem (see [troubleshooting](docs/troubleshooting.md)).

## First-time setup

Run every command from the repository root.

```sh
# 1. Create your environment file (git-ignored), then set DB_PASSWORD and MYSQL_ROOT_PASSWORD
#    to local-only values.
cp .env.example .env              # PowerShell: Copy-Item .env.example .env

# 2. Build and start the stack: app (PHP-FPM), web (nginx), mysql.
docker compose up -d --build

# 3. Install PHP dependencies inside the app container.
docker compose exec app composer install

# 4. Generate the application key (written to .env only).
docker compose exec app php artisan key:generate

# 5. Create the database tables.
docker compose exec app php artisan migrate

# 6. Create the first administrator. You are asked for the password twice (hidden input).
docker compose exec app php artisan staff:create-admin --name="Your Name" --email=you@example.com
```

No default accounts or credentials ship with the system: the administrator you create in step 6
is the only account until they add staff through the API. Run the command again whenever another
administrator is needed from the command line.

Check it works:

```sh
docker compose ps                                 # app, web, mysql running; mysql healthy
curl http://localhost:8080/api/v1/health          # {"status":"ok","checks":{"database":"ok"}}
```

The first `up` creates the MySQL data volume, the `clinic` database, and the separate
`clinic_testing` database used by the tests. That data stays in the `mysql-data` volume between
restarts.

## Signing in

Only clinic staff have accounts; each has exactly one role: administrator, receptionist, or
doctor. Administrators manage staff accounts through `/api/v1/staff`.

```sh
curl -X POST http://localhost:8080/api/v1/auth/sign-in \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"<your password>"}'
```

The response holds `data.token`. Send it on every other request as
`Authorization: Bearer <token>`.

- A token stops working after **8 hours without a request**; each request restarts the 8 hours.
  `POST /api/v1/auth/sign-out` ends it at once.
- Deactivating an account or an administrator setting a new password ends all of that account's
  tokens. Changing your own password (`PUT /api/v1/auth/password`) ends your other tokens only.
- After 5 failed attempts for one email, or 20 attempts from one address, within a minute,
  sign-in is refused for up to a minute.
- Emails must be printable ASCII; letter case and surrounding spaces are ignored.

Every endpoint, field, and response is described in
[specs/002-staff-auth-roles/contracts/staff-auth.openapi.yaml](specs/002-staff-auth-roles/contracts/staff-auth.openapi.yaml).

## Daily use

```sh
docker compose up -d              # start
docker compose stop               # stop, keep containers and data
docker compose down               # remove containers, keep data
docker compose logs -f app        # follow a service's logs (app, web, mysql)
```

### Run commands inside the container

`vendor/` lives in a Docker volume, not on the host (approved decision D8: installing thousands of
files onto a Windows bind mount timed out). The empty `vendor/` folder you may see on the host is
only the mount point. Run Composer, Artisan, Pint, and the tests inside the `app` container:

```sh
docker compose exec app composer <command>
docker compose exec app php artisan <command>
docker compose exec app vendor/bin/pint
```

## Migrations

```sh
docker compose exec app php artisan migrate           # apply new migrations
docker compose exec app php artisan migrate:status    # list migrations and their state
```

- Change the schema only with a **new** migration. Never edit a migration that has already been
  applied anywhere.
- These commands **delete data** and are opt-in only, never part of a routine workflow:
  - `php artisan migrate:fresh` drops every table and re-runs all migrations.
  - `php artisan db:wipe` drops every table.
  - `docker compose down -v` deletes the MySQL volume, and with it all local data.

## Tests

```sh
docker compose exec app composer test
```

This clears cached config, then runs `php artisan test`. It exits `0` when everything passes and
non-zero on any failure.

- Tests run against `clinic_testing`, never against your development database `clinic`. A guard
  in `tests/TestCase.php` (`setUpTraits()`) aborts any test using a database trait whose connection
  is not `clinic_testing`.
- **Never run `php artisan config:cache` in development.** Cached config ignores the test
  settings in `phpunit.xml`; if you have done it, `composer test` clears it again.
- Use synthetic data only, never real patient data.

## Environment

All settings live in `.env`, copied from [`.env.example`](.env.example), where every variable is
documented with a safe placeholder. `.env` is git-ignored; never commit it or put real secrets in
`.env.example`. Compose refuses to start if `DB_USERNAME`, `DB_PASSWORD`, or
`MYSQL_ROOT_PASSWORD` is missing.

The database is reachable only inside the Compose network (host `mysql`, no published port).
The API and phpMyAdmin are bound to `127.0.0.1`, so they are not reachable from other machines.

## phpMyAdmin (optional, development only)

phpMyAdmin is opt-in: a plain `docker compose up` does not start it.

```sh
docker compose --profile tools up -d phpmyadmin       # start
docker compose --profile tools stop phpmyadmin        # stop
```

Open <http://localhost:8081> (`PMA_PORT`) and log in with `DB_USERNAME` / `DB_PASSWORD` from your
`.env`. Never use it with real patient data.

## Documentation

- [docs/architecture.md](docs/architecture.md): module layout, where each layer goes and what it
  must not do, API versioning, and how to register a module.
- [docs/troubleshooting.md](docs/troubleshooting.md): common setup and runtime problems.
- [specs/001-project-foundation/](specs/001-project-foundation/): the specification, plan, and
  tasks for this foundation.
