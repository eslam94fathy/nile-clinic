# Troubleshooting

Run commands from the repository root. None of the fixes below deletes data unless it says so.

## A port is already in use

`docker compose up` fails with `Bind for 127.0.0.1:8080 failed: port is already allocated` (or
`8081` for phpMyAdmin).

- Find what holds it: `netstat -ano | findstr :8080` (Windows) or `lsof -i :8080` (macOS/Linux).
- Or pick another port in `.env`: `APP_PORT` for the API (also update `APP_URL`), `PMA_PORT` for
  phpMyAdmin. Then `docker compose up -d`.

MySQL publishes no port, so it never conflicts with a MySQL installed on the host.

## The database is not ready or unhealthy

`app` waits for `mysql` to be healthy. The first start initializes the data volume and can take
a minute.

```sh
docker compose ps                 # mysql should show "healthy"
docker compose logs mysql         # look for "ready for connections" or errors
```

- Still `starting` after a few minutes: read `docker compose logs mysql` for the cause.
- `Access denied` errors after you changed `DB_USERNAME`, `DB_PASSWORD`, or
  `MYSQL_ROOT_PASSWORD`: MySQL only reads these when the volume is first created. Put the original
  values back in `.env`. Recreating the volume with `docker compose down -v` also works but
  **deletes all local data**.

## Missing `.env` or application key

- Compose warns `The "DB_DATABASE" variable is not set` or stops with a required-variable error:
  `.env` is missing. Run `cp .env.example .env` (PowerShell: `Copy-Item .env.example .env`) and set
  the passwords.
- A request or command fails with `No application encryption key has been specified`: `APP_KEY`
  is empty. Run `docker compose exec app php artisan key:generate`.
- `Failed to open stream: ... vendor/autoload.php`: dependencies are not installed yet. Run
  `docker compose exec app composer install`.

## Compose "required variable" errors

```text
required variable DB_PASSWORD is missing a value: DB_PASSWORD is required
```

`DB_USERNAME`, `DB_PASSWORD`, and `MYSQL_ROOT_PASSWORD` must be set in `.env`. Add the variable
named in the message (see `.env.example`), then re-run the command.

## The test database `clinic_testing` is missing

Tests fail with `Unknown database 'clinic_testing'`. The init script that creates it runs only
when the MySQL volume is first created, so an older volume may not have it. Create it without
touching other data (uses the root password from the container environment):

```sh
docker compose exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$DB_TEST_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$DB_TEST_DATABASE\`.* TO \`$MYSQL_USER\`@\`%\`;"'
```

Then run `docker compose exec app composer test`.

## Windows hosts

### Line endings (CRLF)

A shell script saved with CRLF fails inside Linux containers, for example the MySQL init script
with `/bin/sh^M: bad interpreter` or `$'\r': command not found`. `.gitattributes` forces LF on
checkout. If a file was converted anyway:

```sh
git add --renormalize .
git status                        # review the files that were fixed
```

Set your editor to LF for this repository.

### Slow file access

Files shared from the Windows filesystem into containers are slow. For much faster commands and
tests, clone the repository inside the WSL2 filesystem (for example `~/code/nile-clinic` in your
WSL distribution) and run Docker from there.

### `vendor/` and Composer timeouts

`vendor/` lives in a Docker volume (approved decision D8). Installing it onto a Windows bind mount
timed out (`unzip ... exceeded the timeout of 300 seconds`). So:

- An empty `vendor/` folder on the host is normal: it is only the mount point.
- Run Composer inside the container: `docker compose exec app composer install`.
- If `vendor/` is ever bind-mounted again and installs time out, go back to the volume rather
  than raising the timeout.

### Permissions

The `app` container runs as a non-root user (`app`, UID/GID 1000). If Laravel cannot write to
`storage/` or `bootstrap/cache/` (`Permission denied` in the logs), on Linux or WSL2 give those
folders to your user: `sudo chown -R "$(id -u):$(id -g)" storage bootstrap/cache`. On Docker
Desktop with a Windows path this is normally not needed.

## phpMyAdmin login issues

- The page does not load: it is opt-in. Start it with
  `docker compose --profile tools up -d phpmyadmin`, then open <http://localhost:8081> (or your
  `PMA_PORT`).
- `Access denied`: use `DB_USERNAME` / `DB_PASSWORD` from your `.env`. If you changed them after the
  volume was created, see [the database section](#the-database-is-not-ready-or-unhealthy).
- `Cannot log in to the MySQL server`: check `docker compose ps` shows `mysql` healthy.
- It only connects to the `mysql` service, by design; there is no server field.

## `401` after a while ("You are not signed in.")

A token that worked earlier now gets `401`. Sign in again. A token ends when:

- it went **8 hours without a request** (each request restarts the 8 hours);
- you signed out with it;
- an administrator **deactivated** the account or **set a new password** for it (all tokens end);
- you **changed your own password** from another session (your other tokens end).

Check that the header is exactly `Authorization: Bearer <token>`, with the whole token including
the part before `|`.

## `422` "Too many login attempts"

Sign-in is refused for up to a minute after 5 failed attempts for one email (from anywhere) or 20
attempts from one address (for any email). Wrong current passwords on `PUT /api/v1/auth/password`
count toward the same per-email limit. Wait the number of seconds in the message, then try again.
A clinic's staff usually share one address, so many typos at the front desk can reach the
20-attempt limit together.

## No administrator can sign in

There are no default accounts. Create one from the command line (the password is typed twice,
hidden):

```sh
docker compose exec app php artisan staff:create-admin --name="Your Name" --email=you@example.com
```

## Viewing logs

```sh
docker compose logs -f app        # PHP-FPM
docker compose logs -f web        # nginx access and errors
docker compose logs -f mysql      # database
```

Laravel's application log is `storage/logs/laravel.log`, on the host through the bind mount.
