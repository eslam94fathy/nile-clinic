# Research: Reports

**Feature**: 010-reports | **Date**: 2026-11-05

Facts below were checked on 2026-11-05 against `main` at `ef43f37` and the running stack: Laravel Framework
13.38.0, PHP 8.5.12, MySQL 9.7.4, and the existing modules. Measurements were read-only and changed no data.
Spec clarifications Q1–Q3 were answered on 2026-11-05 (spec, Clarifications). Items marked *(approved — Dn)* were approved by the user on 2026-11-05
and are listed in the plan's Decisions table.

## R1 — New `Reports` module and URLs *(approved — D1)*

- **Decision**: A new module, `app/Modules/Reports/`, with four read-only endpoints:
  - `GET /reports/money-collected` (US1);
  - `GET /reports/billing` (US2);
  - `GET /reports/activity` (US3);
  - `GET /reports/times` (US4).
  All take `from`, `to`, and an optional `doctor_id`. The last three also take `page` and `per_page`.
- **Rationale**: Each report answers a different question over different records and has its own response shape,
  so one endpoint with a `type` parameter would have a response that changes with its input. `from` and `to` match
  `GET /charges` and the appointment lists.

## R2 — Where the queries live *(approved — D2)*

- **Decision**: One `ReportRepository` contract in the Reports module, implemented with the query builder over the
  other modules' tables. It selects only aggregates and has one purpose-named method per figure. `ReportService`
  turns its rows into reports. No Eloquent models, and no model of its own.
- **Rationale**:
  - Precedent: `EloquentChargeRepository::paginateVisits` already reads `queue_entries` directly.
  - The report is this module's own read-only aggregate (Constitution III); it is not a generic repository.
  - Without models, encrypted casts never run, and a query that selects no clinical column cannot read clinical
    content (FR-017).
- **Alternatives**: Adding a summary method to each module's repository — report concerns would spread through six
  modules, and joins across them (uncharged seen visits join visits and charges) have no natural owner.

## R3 — Live figures, and three new indexes *(approved — D3)*

- **Finding**: The date columns are already indexed: `charges_date_index`, `charges_doctor_index`,
  `queue_entries_date_index`, and `appointments_date_index`. But `payments`, `refunds`, and `patients` have **no index
  on `created_at`** (measured from `information_schema.statistics`), and those three reports filter by that column.
- **Decision**: Calculate every report when requested (FR-005). One new migration adds `payments_created_at_index`,
  `refunds_created_at_index`, and `patients_created_at_index`. No applied migration is edited, and no data changes.
- **Alternatives**: Stored daily summary tables — faster, but they need keeping in step, and billing changes
  retroactively (a late discount rewrites a past date). Add them only if SC-002 is missed, and with approval.

## R4 — Clinic days for money *(approved — D4)*

- **Finding**: `*_at` columns are UTC (the MySQL session zone is `SYSTEM`, the system zone `UTC`, and the offset from
  `UTC_TIMESTAMP()` is `00:00:00`, measured). Clinic days are in `Africa/Cairo`, which has daylight saving: the offset
  is +2 or +3, always whole hours.
- **Measured** — first instant of a clinic day, in UTC, using `CarbonImmutable::parse("{date} 00:00:00", zone)->utc()`:

  | Clinic date | UTC start | Note |
  |-------------|-----------|------|
  | 2026-04-24 | 2026-04-23 22:00 | Clocks go forward at midnight; 00:00 does not exist, and it resolves to the correct first instant |
  | 2026-04-25 | 2026-04-24 21:00 | |
  | 2026-10-29 | 2026-10-28 21:00 | |
  | 2026-10-30 | 2026-10-29 22:00 | The 23:00 hour of the 29th occurs twice before this; both belong to the 29th |

- **Decision**:
  - **Period**: convert `from` and the day after `to` to UTC instants with `ClinicClock`'s zone, and filter
    `created_at >= start AND created_at < end`.
  - **Days**: SQL groups by UTC hour (`DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')`), method, and staff member. PHP
    converts each hour to its clinic date and adds them up. Because the offset is in whole hours, no hour spans two
    clinic days.
- **Rationale**: PHP stays the only source of the clinic's zone, as `ClinicClock` documents. At most 8,784 hours a year
  multiplied by methods and staff, in practice far fewer because the clinic is closed at night.
- **Alternative rejected**: `CONVERT_TZ(created_at, '+00:00', 'Africa/Cairo')` in SQL. It is one expression and it gave
  the same answers as PHP for all seven instants tested, including both daylight-saving changes. But it depends on
  MySQL's time zone tables, and on a server without them it **returns NULL silently**, so money would vanish from the
  daily figures with no error. That risk is not acceptable in a financial report.

## R5 — Negative money *(approved — D5)*

- **Finding (a bug this feature would hit)**: `Money::fromPiastres` assumes amounts are never negative. Measured:
  `fromPiastres(-150)` returns `-1.-50`, `fromPiastres(-50)` returns `0.-50`, and `fromPiastres(-5)` returns `0.-5`. The
  spec allows a negative net (Edge Cases), so reports need it to work.
- **Decision**: Make `fromPiastres` handle a sign: `-1.50`, `-0.50`, `-0.05`. Positive results are unchanged, so feature
  009 behaves as before. Add cases to `MoneyTest`; existing cases stay as they are. `toPiastres` does not change, because
  only net figures are negative, and those are calculated from non-negative sums.
- **Alternatives**: A separate formatter in Reports — two helpers for one money format, and the broken case would stay
  in the shared one.

## R6 — Exact totals that always add up

- **Decision**: SQL returns `SUM()` of `DECIMAL` columns, grouped at the finest level a report needs. `SUM` of a decimal
  comes back as an exact string. PHP converts each to whole piastres with `Money::toPiastres`, and adds up every
  breakdown and total **from those same rows**, as integers. Results are returned as strings with two decimals.
- **Rationale**: Because totals are derived from the breakdown rows, they cannot disagree, even if a payment is recorded
  during the request (FR-019, SC-003). So no transaction is needed, and a request takes no locks.

## R7 — Per-doctor pages

- **Decision**: For each report, aggregate per doctor across the whole period (at most ~500 rows per query), merge by
  doctor id in the service, and read the matching doctors' names sorted by account name then id. Totals are calculated
  across all rows, then the rows are sliced into a `LengthAwarePaginator`. `per_page` is 1–100, default 15, as elsewhere.
- **Rationale**: A doctor can appear from any of several queries (for example, only uncharged visits), so SQL paging over
  one table would miss some. Rows are bounded by the number of doctors, so holding them in memory is safe. Order by account
  name matches `GET /fees`.
- **Doctor filter**: Applied to every query. An unknown doctor → 404 through `DoctorService::find`. A known doctor with no
  activity → empty `data` and zero totals.

## R8 — Calculated figures, without floats

- **Averages**: `SUM(TIMESTAMPDIFF(SECOND, …))` and `COUNT(*)` per doctor. Average minutes =
  `intdiv(2 * seconds + 60 * count, 120 * count)` (nearest minute, half up). Overall is total seconds over total count, never
  an average of averages. With count 0, the average is `null`.
- **Wait**: rows with `called_at` set. **Consultation**: rows with `called_at` and `finished_at` set (FR-016).
- **No-show rate**: a percentage. In tenths of a percent, `intdiv(2000 * no_show + base, 2 * base)`, where `base` is
  `no_show + checked_in + completed`. Returned as a number such as `12.5`, and `null` when `base` is 0.
- **Uncharged seen visits**: `queue_entries` with status `seen`, left-joined to `charges` on `queue_entry_id` and
  `active_flag = 1`, where no charge matched. The existing unique index serves the join.
- **Record counts**: `queue_entries` with status `seen`, left-joined to `encounters` on `queue_entry_id`, grouped by doctor
  and `encounters.status`; a null status means no record. Selects only the status. `encounters_queue_entry_unique` serves the
  join.
- **New patients**: count of `patients.created_at` in the period's UTC instants. Left out of the response when a doctor filter
  is sent (FR-014).

## R9 — Authorization

- **Finding**: Every module registers `Gate::policy(Model::class, …)`, and nothing uses `Gate::define` yet. Reports has no model.
- **Decision**: `ReportPolicy::view(User)` (active administrator), registered as `Gate::define('view-reports', [ReportPolicy::class,
  'view'])` in the provider. `ReportRequest::authorize()` calls `can('view-reports')`, so receptionists and doctors get 403 before
  validation or any lookup, and cannot probe which doctor ids exist.
- **Alternatives**: Attaching the policy to another module's model — misleading.

## R10 — Validation

- `from`, `to`: required, `date_format:Y-m-d`; `to` is `after_or_equal:from`.
- At most 366 days including both ends. `ListAppointmentsRequest::validateRange` is fixed at 92 days, so `ReportRequest` has its own
  check with `reports::messages.range_too_long`, on `to`.
- `doctor_id`: nullable integer, at least 1. `page`, `per_page`: as in `ListChargesRequest`.

## R11 — Privacy

- **Responses**: Doctors appear as `{id, name, title}` and staff as `{id, name}`, like the rest of the API. Nothing about a patient is
  ever selected, apart from counts.
- **Reasons**: Discount, refund, and cancellation reasons are free text and are never selected.
- **Logs**: No new log lines.
- **Proof**: Tests plant unique synthetic values in patient and clinical fields and assert that they appear in no response. A test also
  stores clinical columns as undecryptable text and confirms that reports still succeed, which proves that they are never decrypted.

## R12 — Testing

- Feature tests per report with `travelTo` and factories (`Payment`, `Refund`, `Charge`, `QueueEntry`, `Appointment`, `Encounter`,
  `Patient`, `Doctor`, and `User` all exist).
- No concurrency group: reports take no locks and change nothing (R6).
- SC-003: over 1,000 small payments and refunds, every breakdown equals the total.
- SC-004: instants one minute either side of clinic midnight, on an ordinary day and on both daylight-saving changes in 2026.
- SC-007: record counts and `updated_at` values unchanged, and every SQL statement a report runs is a `SELECT` (`DB::listen`).
- SC-001 and SC-002 are measured once with synthetic rows in `clinic_testing`, in a transaction rolled back afterwards, as in feature 009,
  and recorded in `verification.md`.
