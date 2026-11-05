# Verification: Reports

**Feature**: 010-reports | **Date**: 2026-11-05

Commands were run on the development stack with Docker Compose (project `nile-clinic`). Failures are reported as observed
(Constitution VIII). All accounts, doctors, patients, visits, charges, and payments are synthetic.

| Step | What was run | Observed | Result |
|------|--------------|----------|--------|
| Q1 Migrate | `php artisan migrate`; `migrate:status`; `route:list --path=reports` | `add_report_indexes` `DONE`, then `Ran`; 22 migrations `Ran`, 21 of them unchanged; the four routes listed | Pass |
| Q2 Setup | Fee `300.00`; walk-in; charge; pay `100.10` cash and `199.90` card; refund `150.00` cash | `200`, `201`, `201`, `201` | Pass |
| Q3 Money collected (US1) | One day, one doctor; a period with no money | `200`; totals `300.00`, `150.00`, `150.00`; cash net `-49.90`, card net `199.90`; each staff member under the right method; `by_day` equal to the totals; the empty period gives `0.00` and empty lists | Pass |
| Q4 Billing (US2) | Two seen visits, one uncharged; a third visit charged, then cancelled | One row; before cancelling 2 active, fees `600.00`, paid `150.00`, balance `450.00`, 1 uncharged; after cancelling 1 active, fees `300.00`, balance `150.00`, 1 cancelled | Pass |
| Q5 Activity (US3) | With and without a doctor filter | Visits `waiting` 1, `seen` 2, `walk_in` 3; `no_show_rate` null; `new_patients` present overall and absent with the filter | Pass |
| Q6 Times and records (US4) | A draft on one seen visit, none on the other | Whole-minute averages, each covering 2 visits; records `finalized` 0, `draft` 1, `none` 1 | Pass |
| Q7 Refusals | Each of the four reports: receptionist and doctor, also with invalid dates and an unknown doctor; no token; `to` missing, an impossible date, `to` before `from`, 367 days; 366 days; unknown doctor; Arabic | `403` in every case for both roles, `401`, `422`, `200`, `404`; Arabic message with the same structure; the same figures in both locales | Pass |
| Q8 Tests and style | `composer test`; `test --group=concurrency`; `pint --test`; `postman spec lint … --issueType=syntax` | `1663 passed (6168 assertions)`, exit 0 (baseline 1476); concurrency `13 passed (279 assertions)`, exit 0; Pint `PASS` (448 files); `No issues found`. Run at T035; only documents changed afterwards | Pass |
| SC-001 One day | `clinic_testing` only, in a transaction rolled back afterwards, with the rows below; `ReportService::moneyCollected`, three runs | 306, 8, 7 ms; target under 2 s | Pass |
| SC-002 366 days | The same rows and method; `per_page` 100 for the paged reports | Money collected 1792, 1579, 1768 ms; billing 3723, 3370, 3005 ms; activity 903, 867, 866 ms; times 3871, 3225, 3041 ms; target under 5 s | Pass |

## Notes

- **Measurement rows**: 500 doctors, 2,000 patients, 510 accounts, 600,000 appointments, 525,000 visits, 392,000 encounters, 500,000 charges,
  500,000 payments, and 5,000 refunds over 366 days. All tables held 0 rows after the rollback.
- **Measured totals**: received `150000000.00` (500,000 × 300) and refunded `1500000.00` (5,000 × 300), which match by hand. Peak memory was 58 MB.
- **One doctor, 366 days**: after the first run, each report took 9–16 ms (first runs 174–325 ms).
- **Slowest figures**: billing and times are the closest to the target, at up to 3.9 s of the 5 s. They add up most of a year's rows, so they grow with the number of records. A larger clinic may need
  stored summaries, which would need separate approval (plan: Risks).
- **Not measured**: Times are for `ReportService` calls, which include the database and PHP calculation, but not whole HTTP requests. `ANALYZE TABLE` was not run, because it would commit the transaction. The
  development machine is not production hardware.
- **Q2–Q7 method**: run by a script that sent the quickstart's requests over HTTP to the running stack and compared each status and field with the expected value (95 checks, 0 failures), rather than by the
  quickstart's `curl` lines one at a time. Accounts and patients were created with factories, and tokens with `createToken('qs10')`, all deleted afterwards (0 remaining). The synthetic rows remain on the
  development database, as in earlier features.

## Deviations and corrections

- **A broken commit on the way**: `f5992f9` (US3) was committed with 10 of the 11 activity tests failing and a Pint failure, because a scripted edit dropped the `QueueStatus` import and the commit was chained to the
  test run instead of waiting for its result. `f01956f` fixes it, and later commits are built on the fix. Anyone reviewing commit by commit should treat those two as one change.
- **T013**: The 366-day test as first written was off by one (2026 has 365 days). The correct cases are `2026-01-01` to `2027-01-01` for 366 days, and to `2027-01-02` for 367. The code was right.
- **T026**: One expected value was wrong in the first draft: the overall wait for the fixture is 620 s, which rounds to 10 minutes, not 11. The code was right.
- **T031**: The patient number is the patient's id, so it cannot hold a unique planted value. The test instead asserts that no response has a `patient` field apart from `new_patients`. It also has one test the task did not list: no statement names
  a clinical, patient-detail, or reason column.
- **T012**: Also covers an ordinary summer day, which the task did not list.
- **T033**: Responses are identical across locales except for Laravel's `Previous` and `Next` paging labels in `meta.links`, which it translates on every paged list in the API. The test excludes only those labels.
- **Postman**: The collection update is a separate follow-up, as in earlier features.
