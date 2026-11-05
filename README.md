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

## Patients

Administrators and receptionists register, search, view, and update patients under
`/api/v1/patients`. Doctors have no patient access yet; a later feature links them to their own
patients.

- The patient number is the record's `id`.
- Search by `name` (part of the name), `phone`, `national_id`, or `id`. Name search ignores letter
  case, extra spaces, and the Arabic variants أ/إ/آ = ا, ة = ه, ى = ي.
- Lists show only number, name, sex, date of birth, and phone; national ID, address, and emergency
  contact appear only when one patient is opened.
- Patients are never deleted; mistakes are corrected with `PATCH /api/v1/patients/{id}`.

Every endpoint, field, and response is described in
[specs/003-patient-records/contracts/patients.openapi.yaml](specs/003-patient-records/contracts/patients.openapi.yaml).

## Doctors and specialties

Administrators manage the specialty catalog under `/api/v1/specialties` and doctor profiles under
`/api/v1/doctors`. Every active staff member can list specialties and browse doctors; a doctor
fetches their own profile at `/api/v1/doctors/me`.

- A doctor profile belongs to one staff account with the doctor role. The doctor's name comes from
  that account, and the doctor counts as active only while the account is active and still has
  the doctor role.
- Specialties always carry both `name_en` and `name_ar`, whatever the request language. They are
  deactivated, never deleted; doctors already assigned keep a deactivated specialty.
- Filter doctors by `specialty_id`, `is_active`, or `name` (part of the name; the same Arabic
  letter handling as patient search).

Every endpoint, field, and response is described in
[specs/004-doctors-specialties/contracts/doctors.openapi.yaml](specs/004-doctors-specialties/contracts/doctors.openapi.yaml).

## Scheduling

Administrators set each doctor's weekly working hours at `/api/v1/doctors/{doctor}/schedule` and
record time off at `/api/v1/doctors/{doctor}/time-off`. Every active staff member can read them and
list a doctor's bookable slots at `/api/v1/doctors/{doctor}/slots?from=…&to=…`; doctors use
`/api/v1/doctors/me/schedule`, `/me/slots`, and `/me/time-off`.

- A weekly schedule is an appointment length (5–240 minutes) plus up to 4 periods per day; the gap
  between two periods is a break. Saving replaces the whole schedule.
- Slots are cut from the periods by the appointment length; slots in the past, during time off, or
  for an inactive doctor are not offered. One request covers up to 31 days, at most 90 days ahead.
- All dates and times are the clinic's wall clock, set by `CLINIC_TIMEZONE` (default
  `Africa/Cairo`); the application itself runs on UTC.

Every endpoint, field, and response is described in
[specs/005-scheduling-availability/contracts/scheduling.openapi.yaml](specs/005-scheduling-availability/contracts/scheduling.openapi.yaml).

## Appointments

Administrators and receptionists book a patient at `POST /api/v1/appointments` into a slot listed
by `/api/v1/doctors/{doctor}/slots`, and find appointments at `GET /api/v1/appointments?from=…&to=…`
(filters: `doctor_id`, `patient_id`, `status`, `outside_availability`). Reschedule, cancel, and
no-show are `POST /api/v1/appointments/{id}/reschedule`, `/cancel`, and `/no-show`. Doctors read
their own appointments at `/api/v1/doctors/me/appointments`.

- A booking must start on a slot the doctor currently offers; booked slots disappear from `/slots`
  and come back when the appointment is cancelled, moved, or marked as no-show.
- A time that is already taken returns `409` with a fixed `reason`: `slot_booked` (the doctor) or
  `patient_busy` (the patient). Simultaneous bookings of the same slot never both succeed.
- Schedule, time off, and account changes are never blocked by appointments; affected upcoming
  appointments are shown with `outside_availability: true` so reception can reschedule them.

Every endpoint, field, and response is described in
[specs/006-appointments/contracts/appointments.openapi.yaml](specs/006-appointments/contracts/appointments.openapi.yaml).

## Patient queues

Every queue is for the clinic's today. Administrators and receptionists check in a booked patient at
`POST /api/v1/appointments/{id}/check-in`, add a walk-in at `POST /api/v1/queue/walk-ins`, and see
the lists at `GET /api/v1/queue` and `GET /api/v1/doctors/{doctor}/queue`. They mark a waiting
patient as left at `POST /api/v1/queue/{entry}/leave` and undo a check-in at
`DELETE /api/v1/queue/{entry}`. Doctors use `GET /api/v1/doctors/me/queue`, then
`POST /api/v1/doctors/me/queue/call-next` and `/finish`.

- Lists are in queue order: booked patients by appointment time and walk-ins by arrival time, merged
  into one order; equal times go by queue number.
- Queue numbers start at 1 per doctor and day, and are never reused, even after an undo.
- Checked-in and completed appointments keep their slot and cannot be rescheduled, cancelled, or
  marked as no-show. Walk-ins never take a slot.

Every endpoint, field, and response is described in
[specs/007-patient-queues/contracts/queues.openapi.yaml](specs/007-patient-queues/contracts/queues.openapi.yaml).

## Medical encounters

Only doctors use medical records; administrators and receptionists are refused. A doctor lists their
visits at `GET /api/v1/doctors/me/visits`, starts a visit's record at
`POST /api/v1/doctors/me/visits/{visit}/encounter`, and opens or saves it at
`GET` and `PUT /api/v1/doctors/me/encounters/{encounter}`. They lock it at
`POST /api/v1/doctors/me/encounters/{encounter}/finalize` and correct it afterwards at
`POST /api/v1/doctors/me/encounters/{encounter}/addenda`. A patient's history is at
`GET /api/v1/doctors/me/patients/{patient}/encounters`.

- Each visit has at most one record, for booked patients and walk-ins alike. Finishing a visit does
  not need one, and it can be written later.
- A draft is visible only to its author. A finalized record cannot be changed, only corrected with
  addenda, which cannot be changed or deleted either.
- A doctor reads their own records at any time, and another doctor's finalized records only while the
  patient is on their list today as waiting, with the doctor, or seen. Anything else is reported as
  not found.
- Clinical content is encrypted in the database and returned only when one record is opened, never in
  a list or a log.

Every endpoint, field, and response is described in
[specs/008-medical-encounters/contracts/encounters.openapi.yaml](specs/008-medical-encounters/contracts/encounters.openapi.yaml).
After pulling this feature, run `docker compose build app` and `docker compose up -d app`: the image
gained a PHP setting that keeps call arguments out of stack traces.

## Payments

This records money handled at the clinic; it moves none, and no card details are stored. Doctors have no
access. Administrators set a doctor's consultation fee at `PUT /api/v1/doctors/{doctor}/fee`, and
receptionists read fees at `GET /api/v1/fees` and `GET /api/v1/doctors/{doctor}/fee`. Reception sees a date's
visits and what is owed at `GET /api/v1/visits?date=…`, charges a visit at `POST /api/v1/visits/{visit}/charge`,
records money at `POST /api/v1/charges/{charge}/payments` (`cash`, `card`, or `wallet`), finds charges at
`GET /api/v1/charges`, and cancels one with no money held at `POST /api/v1/charges/{charge}/cancel`. Only
administrators discount at `PUT /api/v1/charges/{charge}/discount` and refund at
`POST /api/v1/charges/{charge}/refunds`.

- A charge covers the doctor's fee at that moment and keeps it, whatever happens to the fee later.
- A visit has at most one active charge. Paying never affects the queue or medical records.
- Payments and refunds cannot be changed or deleted. A charge takes several payments, up to what is owed.
- A check-in cannot be undone while its visit has an active charge; cancel the charge first.
- Amounts are in Egyptian pounds, returned as strings such as `"150.00"`, and accepted as a string or number
  with at most two decimals.

Every endpoint, field, and response is described in
[specs/009-payments/contracts/payments.openapi.yaml](specs/009-payments/contracts/payments.openapi.yaml).

## Reports

Read-only summaries, for administrators only. Each report takes a period of up to 366 days (`from` and `to`,
both included) and an optional `doctor_id`.

- `GET /api/v1/reports/money-collected`: money received and refunded, by method, staff member, and day.
- `GET /api/v1/reports/billing`: what each doctor's visits were charged, what is owed, and seen visits never charged.
- `GET /api/v1/reports/activity`: appointments by status, the no-show rate, visits, and new patients.
- `GET /api/v1/reports/times`: average wait and consultation, and counts of medical records.

Reports never identify a patient or contain medical content. Money collected counts on the clinic day it was
recorded, so a past period never changes, and its net can be negative when refunds exceed the money received. Billing
and activity show records as they stand now, so a late discount or payment changes billing for a past period.

Every endpoint, field, and response is described in
[specs/010-reports/contracts/reports.openapi.yaml](specs/010-reports/contracts/reports.openapi.yaml).

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
- The `concurrency` group starts real parallel PHP processes to prove that simultaneous bookings
  cannot double-book; run it on its own with
  `docker compose exec app php artisan test --group=concurrency`.

## Environment

All settings live in `.env`, copied from [`.env.example`](.env.example), where every variable is
documented with a safe placeholder. `.env` is git-ignored; never commit it or put real secrets in
`.env.example`. Compose refuses to start if `DB_USERNAME`, `DB_PASSWORD`, or
`MYSQL_ROOT_PASSWORD` is missing.

The database is reachable only inside the Compose network (host `mysql`, no published port).
The API and phpMyAdmin are bound to `127.0.0.1`, so they are not reachable from other machines.

> **`APP_KEY` protects medical records.** Clinical content is encrypted with it, so losing it
> permanently destroys that content, and changing it makes the content unreadable. Back it up
> separately from the database before any production use. To rotate it, move the old key to
> `APP_PREVIOUS_KEYS`; never just replace it.

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
- [docs/roadmap.md](docs/roadmap.md): feature build order, dependencies, and status.
- [specs/001-project-foundation/](specs/001-project-foundation/): the specification, plan, and
  tasks for this foundation.
