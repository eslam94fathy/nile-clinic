# Implementation Plan: Reports

**Branch**: `010-reports` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/010-reports/spec.md`

## Summary

Add a read-only `Reports` module with four endpoints for administrators: money collected, billing, activity, and
times and records. It adds no tables and no models. One repository selects only aggregates from the existing tables,
and a service turns them into reports, adding up exact whole piastres so that every breakdown equals its total. Money counts on the
clinic day it was recorded, including across daylight-saving changes. Encounter data is used only as counts of record status, and no
report selects a patient detail. Two changes reach outside the module: one migration adds three indexes, and `Money::fromPiastres` learns
to print negative amounts, which today it corrupts. No new dependencies. Evidence: [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5.12 (existing image)

**Primary Dependencies**: Laravel Framework 13.38.0, `laravel/sanctum` ^4.3. No new packages.

**Storage**: MySQL 9.7.4, InnoDB, `utf8mb4_unicode_ci`. No new tables; three new indexes on existing tables
([data-model.md](data-model.md)).

**Testing**: PHPUnit 12 via `composer test` against `clinic_testing`; Feature tests per report with `travelTo` in the clinic zone; Unit
tests for `Money` and the report calculations; earlier features' tests unchanged. No concurrency group, because nothing is written or
locked.

**Target Platform**: Existing Docker Compose stack (local development)

**Project Type**: Backend web service (REST API, modular monolith)

**Performance Goals**: A day's money collected in under 2 s; a 366-day report in under 5 s with 500 doctors and 500,000 charges (SC-001,
SC-002)

**Constraints**: Exact money, with breakdowns that add up (FR-019, SC-003); clinic days across daylight saving (FR-022, SC-004); no patient
identifiers or clinical content (FR-004, FR-017, SC-005); read-only (FR-005, SC-007); administrators only (FR-001); synthetic data only

**Scale/Scope**: Single clinic, ~500 doctors, a few thousand visits a day. 4 endpoints, 1 module, 1 migration; one small change in Payments

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | How this plan complies | Status |
|---|-----------|------------------------|--------|
| I | Security & privacy | All endpoints behind `auth:sanctum`; administrators only, refused before validation or lookup; only aggregates selected, so no patient detail, reason, or clinical text is read; encrypted casts never run | ✅ |
| II | Laravel/MySQL/Compose | Laravel 13 conventions; one new migration, none edited; `/api/v1` via the module provider; no portal or frontend | ✅ |
| III | Repository pattern | `ReportRepository` contract and implementation, bound in the provider, with one purpose-named method per figure; not generic | ✅ (D2 approved) |
| IV | Thin controllers | `ReportController`: Form Request → service → Resource | ✅ |
| V | Services own workflows | `ReportService` owns merging, calculation, and paging; the repository only queries | ✅ |
| VI | Form Requests/Resources/Policies | `ReportRequest`; one Resource per report; `ReportPolicy` through `Gate::define`, since Reports has no model | ✅ |
| VII | en/ar localization | `reports::messages` and `reports::attributes` in both locales; statuses and methods are fixed identifiers | ✅ |
| VIII | Tests & acceptance criteria | Feature tests per report; role × endpoint matrix (SC-006); exactness (SC-003); midnight and daylight saving (SC-004); planted values (SC-005); read-only (SC-007); both locales (SC-008) | ✅ |
| IX | Small tasks | See Implementation Outline; each step has its own test or command | ✅ |
| X | No invention | Endpoints, fields, and rules trace to the spec and contract; schema, indexes, time zone, and money behavior measured (R3, R4, R5); no new dependency | ✅ (D1–D5 approved) |
| XI | Integrity & concurrency | Reads only, so no transactions or locks are needed; money exact from `DECIMAL` sums (R6); totals derived from the same rows as their breakdowns | ✅ |
| XII | No real data | Factories only; no patient data or amounts in logs or exceptions; performance runs on `clinic_testing`, rolled back | ✅ |

**Gate result (pre-research)**: PASS (D1–D5 approved 2026-11-05).
**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Design

### Request flow

```text
 client ── Bearer token ──▶ api group + auth:sanctum   (feature 002, unchanged)
                              ▼
   ReportController (thin)
                              │   ReportRequest: view-reports gate (else 403) → validate (else 422)
                              ▼
   ReportService
     doctor filter   DoctorService::find (else 404)
     period          clinic dates → UTC instants (money, new patients)
     read            ReportRepository: aggregate rows, per doctor or per hour
     calculate       whole piastres; totals from the same rows as the breakdowns
     page            sort by doctor name, slice into a paginator
                              ▼
   Report Resources ── data, totals, links, meta
```

### Endpoints ([contract](contracts/reports.openapi.yaml))

All are administrators only, and all take `from` and `to` (required, at most 366 days) and `doctor_id`.

| Method | Path (`/api/v1`) | Extra parameters | Spec |
|--------|------------------|------------------|------|
| GET | `/reports/money-collected` | | US1, FR-007, FR-008 |
| GET | `/reports/billing` | `page`, `per_page` | US2, FR-009–FR-011 |
| GET | `/reports/activity` | `page`, `per_page` | US3, FR-012–FR-014 |
| GET | `/reports/times` | `page`, `per_page` | US4, FR-015–FR-017 |

Status codes: `200`, `401`, `403`, `404` (unknown doctor), `422` validation.

### Module layout (new files)

```text
app/Modules/Reports/
├── Http/
│   ├── Controllers/ReportController.php
│   ├── Requests/ReportRequest.php                 # gate, from, to, doctor_id, page, per_page; at most 366 days
│   └── Resources/{MoneyCollected,BillingRow,ActivityRow,TimesRow}Resource.php
├── Policies/ReportPolicy.php                      # active administrator
├── Providers/ReportsServiceProvider.php           # binds the repository; Gate::define('view-reports', …)
├── Repositories/
│   ├── Contracts/ReportRepository.php
│   └── QueryReportRepository.php                  # query builder only; aggregates only
├── Services/ReportService.php
├── Support/ReportMath.php                         # average minutes and no-show rate, in integers
├── routes/api.php
└── lang/{en,ar}/{messages,attributes}.php
database/migrations/<date>_add_report_indexes.php
```

`ReportRepository` methods:

- `moneyReceivedByHour(start, end, ?doctorId)` and `moneyRefundedByHour(start, end, ?doctorId)`;
- `billingByDoctor(from, to, ?doctorId)` and `unchargedSeenVisitsByDoctor(from, to, ?doctorId)`;
- `appointmentsByDoctor(from, to, ?doctorId)`, `visitsByDoctor(from, to, ?doctorId)`, and `patientsRegistered(start, end)`;
- `visitTimesByDoctor(from, to, ?doctorId)` and `recordsByDoctor(from, to, ?doctorId)`;
- `doctorsByName(ids)`.

Changed existing files:

- `app/Modules/Payments/Support/Money.php` (`fromPiastres` handles a sign)
- `tests/Unit/Payments/MoneyTest.php` (new cases added; existing cases unchanged)
- `bootstrap/providers.php` (register `ReportsServiceProvider`)
- `docs/roadmap.md`, `README.md` (short "Reports" section)

### Key rules

- **Read only** (FR-005): the repository has no method that writes, and takes no locks.
- **Nothing to leak** (FR-004, FR-017): no patient or clinical column is ever selected, and no model is loaded.
- **Add up** (FR-019): totals are always calculated from the rows of their breakdowns (R6).
- **No floats in money** (R5, R6): SQL exact sums → whole piastres → strings; the same for time averages and rates (R8).
- **PHP owns the clinic zone** (R4): no `CONVERT_TZ`.

## Testing Strategy

| Test | Covers |
|------|--------|
| `tests/Unit/Payments/MoneyTest` (extended) | Negative `fromPiastres`: `-1.50`, `-0.50`, `-0.05`; zero; positive cases unchanged |
| `tests/Unit/Reports/ReportMathTest` | Average minutes round half up; zero count → `null`; no-show rate to one decimal, `0.0`, `100.0`, and `null` |
| `tests/Feature/Reports/ReportIndexesTest` | The three indexes exist |
| `tests/Feature/Reports/MoneyCollectedReportTest` | US1: totals, methods, staff within methods, days; a refund on a later day; negative net; doctor filter; empty period; breakdowns add up |
| `tests/Feature/Reports/ClinicDayBoundaryTest` | SC-004: one minute either side of midnight on an ordinary day and on both 2026 daylight-saving changes; period edges; new patients |
| `tests/Feature/Reports/MoneyExactnessReportTest` | SC-003: over 1,000 payments and refunds; every breakdown equals the total to the piastre |
| `tests/Feature/Reports/BillingReportTest` | US2: figures per doctor and overall; cancelled left out and counted; full discount; uncharged seen visits, not `left` or `waiting`, and cancelled-only counts as uncharged; later changes reflected; a doctor with only uncharged visits appears; paging keeps totals |
| `tests/Feature/Reports/ActivityReportTest` | US3: every status; no-show rate and `null`; booked and walk-in; undone check-in not counted; rescheduled counted once on the new date; new patients left out with a doctor filter |
| `tests/Feature/Reports/TimesReportTest` | US4: averages per doctor; overall is not an average of averages; visits missing a time left out; `null` with none; record counts add up to seen visits; a draft on an unseen visit not counted |
| `tests/Feature/Reports/ReportPrivacyTest` | SC-005: planted patient values and reasons appear in no report; undecryptable clinical columns still succeed, proving they are not read |
| `tests/Feature/Reports/ReportReadOnlyTest` | SC-007: counts and `updated_at` unchanged; every statement is a `SELECT` |
| `tests/Feature/Reports/ReportValidationTest` | Missing or malformed dates, `to` before `from`, 367 days refused and 366 accepted; bad `doctor_id` and `per_page`; unknown doctor 404; known doctor with no activity gives zeros; inactive doctors and staff included |
| `tests/Feature/Reports/ReportsAuthorizationTest` | SC-006: every role × every endpoint; no token 401; deactivated account 401; non-administrators 403 before validation and lookup |
| `tests/Feature/Reports/ReportsPolicyTest` | Roles × ability; inactive actors refused |
| `tests/Feature/Reports/ReportsMessagesTest` | SC-008: every new message in `en` and `ar`, with identical structure |

`TranslationParityTest` checks the new language files. Earlier features' tests must keep passing unchanged.

## Implementation Outline (input for `/speckit-tasks`)

1. Baseline: run the suite.
2. Foundation: signed `Money::fromPiastres` and its tests; the indexes migration and test; module skeleton: provider, routes, translations,
   `ReportPolicy` and its test, `ReportRequest` and validation test, `ReportMath` and its unit test.
3. US1: repository money methods, `ReportService::moneyCollected`, route, Resource, boundary and exactness tests.
4. US2: billing.
5. US3: activity.
6. US4: times and records.
7. Polish: authorization matrix, privacy, read-only, and message tests; README and roadmap; Pint; SC-001 and SC-002 measurement; quickstart;
   verification record. Postman collection update as a separate follow-up.

## Approved Decisions (2026-11-05)

All five were approved by the user on 2026-11-05.

| # | Decision | Approved | Rejected alternative |
|---|----------|----------|----------------------|
| D1 | URLs | **Four endpoints, `/reports/{money-collected,billing,activity,times}`** — R1 | One `/reports?type=…`, whose response shape changes with its input |
| D2 | Where queries live | **One read-only `ReportRepository` in the Reports module, using the query builder over other modules' tables** — R2 | A summary method on each module's repository, which spreads report concerns through six modules |
| D3 | Live figures and indexes | **Calculate when requested; one migration adds `created_at` indexes to `payments`, `refunds`, and `patients`** — R3 | Stored daily summaries, which need keeping in step and go stale when a discount changes a past date |
| D4 | Clinic days | **Group by UTC hour in SQL, convert to clinic dates in PHP** — R4 | `CONVERT_TZ`, which returns NULL silently on a server without time zone tables, so money would vanish from daily figures |
| D5 | Negative money | **Fix `Money::fromPiastres` to handle a sign; positive results unchanged** — R5 | A second formatter in Reports, which leaves the shared helper printing `-1.-50` |

## Risks, Assumptions, and Rollback

- **Change to feature 009**: `fromPiastres` gains negative handling. Positive output is unchanged, and all of 009's tests must still pass.
- **Performance**: A 366-day report scans most rows in the period, since it adds them all up. SC-002 has not been measured yet. It will be, with
  synthetic rows, and a miss will be reported rather than hidden. Stored summaries would then need separate approval, since D3 chose live figures.
- **Whole-hour assumption**: Grouping by UTC hour assumes that the clinic's zone is a whole number of hours from UTC, which is true for
  `Africa/Cairo`. A different zone with a part-hour offset would need this revisited.
- **Index writes**: Three more indexes add a small cost to each insert into `payments`, `refunds`, and `patients`.
- **Sensitive summaries**: Staff-level money figures are personal performance data, limited to administrators. Applicable Egyptian obligations need
  legal review before production (spec, Assumptions).
- **Rollback**: Remove the provider line and the module, roll back the indexes migration (no data lost), and revert the commit range. The `Money` change is
  safe to keep.

## Project Structure

### Documentation (this feature)

```text
specs/010-reports/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── reports.openapi.yaml
├── checklists/
│   └── requirements.md  # From /speckit-specify
└── tasks.md             # Phase 2 output (/speckit-tasks; not created here)
```

### Source Code (repository root)

```text
app/Modules/
├── Reports/                                    # new (see Module layout above)
├── Payments/                                   # Money::fromPiastres handles a sign
└── Doctors/                                    # read only (DoctorService)
bootstrap/providers.php                         # changed
database/migrations/
tests/Feature/Reports/  tests/Unit/Reports/  tests/Unit/Payments/MoneyTest.php
```

**Structure Decision**: The existing modular monolith. One new domain module, `Reports`, following `docs/architecture.md`.

## Complexity Tracking

No Constitution violations; nothing to justify.
