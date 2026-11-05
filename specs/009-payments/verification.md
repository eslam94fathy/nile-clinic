# Verification Results: Payments

**Feature**: 009-payments | **Date**: 2026-11-05 | **Task**: T046–T049

Results observed while running [quickstart.md](quickstart.md) Q1–Q8 on the development stack (project
`nile-clinic`). Failures are reported as observed (Constitution VIII). All accounts, doctors, patients, visits,
and amounts used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `009-phase-8-polish`.
- Development database `clinic`; tests and the SC-006 measurement run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4. Real clinic date 2026-11-05 (Thursday), about 14:10 Africa/Cairo.
- The `web` service was already published on `127.0.0.1:8081` for this session (see the feature 007 record;
  `.env` is unchanged), so `B` was `http://127.0.0.1:8081/api/v1`.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate | `migrate`; `migrate:status`; `route:list` for `fee`, `charge`, and `api/v1/visits` | The four payment migrations `Ran`, earlier ones unchanged; 3 fee routes, 6 charge routes, and 2 visit routes (one shared with `charge`), 11 in all; health `200` | Pass |
| Q2 Tokens, doctor, patients | Temporary tokens (see Notes) | Administrator, receptionist, doctor with a profile, two patients | Pass |
| Q3 Fees (US1) | Read before set; set `300.00`; list; amounts `0`, `-5`, `1.999`, `100000.01`; set as receptionist and doctor; read as doctor; unknown doctor | `200` with `amount: null`; `200`; `200`; `422` on `amount` each; `403`, `403`; `403`; `404` | Pass |
| Q4 Charge and pay (US2) | Walk-in; charge; pay `100.10`; pay `500.00`; pay `199.90`; charge again; change fee to `400.00`; open; charge as doctor | `201`; `201` `unpaid`, `fee: "300.00"`; `201` `partially_paid`, `balance: "199.90"`; `422` `above_balance`; `201` `paid`, `balance: "0.00"`; `422` `already_charged`; `200`; still `fee: "300.00"`; `403` | Pass |
| Q5 Finding (US3) | Visits by `paid` and `none`; charges for the patient; open | The visit under `paid` and not `none`; the charge listed with no payments and no sensitive keys; the single view has both payments | Pass |
| Q6 Discount (US4) | Walk-in and charge at `400.00`; discount `150.00`; no reason; above the fee; as receptionist; whole fee | `201`; `200`, fee still shown, `balance: "250.00"`; `422` on `reason`; `422` `discount_too_large`; `403`; `200`, `balance: "0.00"`, `paid` | Pass |
| Q7 Refund, cancel, undo (US5) | Refund above paid; as receptionist; cancel with money held; refund `300.00`; cancel; pay on cancelled; charge again; then for a checked-in appointment: charge, undo, cancel, undo, open, appointment | `422` `above_paid`; `403`; `422` `money_held`; `201`, `unpaid`, payments unchanged; `200` `cancelled`; `422` `charge_cancelled`; `201` at `400.00`; `201`, `422` `has_active_charge`, `200`, `204`, `cancelled` with `visit_id: null` and its patient, doctor, and date, `scheduled` | Pass |
| Q8 Tests and style | `composer test`; `test --group=concurrency`; `pint --test`; `postman spec lint … --issueType=syntax` for the 009 and 007 contracts | `1476 passed (5457 assertions)`, exit 0 (baseline 1194); concurrency `13 passed (271 assertions)`, exit 0; Pint `PASS` (416 files), exit 0; both contracts `No issues found`. Run at T046; only documents changed afterwards | Pass |
| SC-006 List speed | `clinic_testing` only, in a transaction rolled back afterwards: 500 factory doctors, 2,000 patients, 525,000 visits, 500,000 charges bulk-inserted; repository calls, three runs each | Visits (one date): page of 15 486, 16, 16 ms; page of 100 34, 21, 21 ms; `none` 19, 16, 16 ms; `paid` 193, 18, 18 ms; one doctor 7, 4, 4 ms. Charges (92 days): page of 15 61, 21, 21 ms; page of 100 29, 27, 26 ms; `unpaid` 511, 268, 102 ms; one patient 7, 5, 4 ms; one doctor 4, 3, 4 ms. All under 2 s; 0 rows after the rollback | Pass |

## Notes

- **Q2–Q7 method**: run by a script that sent the quickstart's requests and compared each status and field with the
  expected value (0 failures), rather than by the quickstart's `curl` lines one at a time. Accounts and patients were
  created with factories, and tokens with `createToken('qs8')`, all deleted afterwards (0 remaining). The synthetic
  rows remain on the development database, as in earlier features.
- **Slowest SC-006 figure**: filtering charges by `status` has no dedicated index, because it scans a date range: 511,
  268, and 102 ms across 32,500 matches. It is within the target; Reports (010) may want to review it.
- **Not measured**: SC-006 times repository calls, as features 007–008 did, not whole HTTP requests. SC-001 (under 30
  seconds of work) was not timed with people; a charge and a payment are two requests.
- **Deviations from the task list**:
  - T015: the method is `forDoctors`, not `feesFor`. Same behavior.
  - T042: the child script prints `{"result":"not_found"}` for a removed visit, so the test does not need to allow an
    error line.
  - T045: the roadmap lists 009 as depending on `003, 004, 007`, which is what the module uses, rather than `006, 007`.
    Appointments are reached only through visits.
- **Security review during implementation**: an automated review flagged the gap between charging (T025) and the undo
  guard (T041) while they were on separate phase branches. The guard is in place, and no phase branch was merged before
  it. Its suggested `restrictOnDelete` was not taken, because a cancelled charge must outlive an undone visit (approved
  D4). A later review of the Phase 6 commit did not complete (HTTP 429).
- **Before production**: Egyptian tax and e-receipt obligations remain unverified and need legal review. They may
  require fields or documents this feature lacks (spec, Assumptions).
- **Follow-up**: the Postman collection update is separate, as for features 005–008.
