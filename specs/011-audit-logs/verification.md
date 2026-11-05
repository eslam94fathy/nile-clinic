# Verification: Audit Logs

**Feature**: 011-audit-logs | **Date**: 2026-11-05

Commands were run on the development stack with Docker Compose (project `nile-clinic`). Failures are reported as observed
(Constitution VIII). All accounts, doctors, patients, visits, charges, and payments are synthetic.

| Step | What was run | Observed | Result |
|------|--------------|----------|--------|
| Q1 Migrate | `php artisan migrate`; `migrate:status`; `route:list --path=audit-entries` | `create_audit_entries_table` `DONE`, then `Ran`; 23 migrations `Ran`, 22 of them unchanged; only `GET` listed | Pass |
| Q2 Setup | Synthetic actors and records created with factories on the development database | Administrator, receptionist, two doctors, a patient, a finalized record, and a charge with 300.00 unpaid. The stack was published on port 8081, because another container holds 8080; the quickstart now says to check | Pass |
| Q3 Who changed a record (US1) | Discount with a planted reason; the same patient update twice | `200` three times; the discount entry names the administrator with `discount` from `0.00` to `50.00` and `discount_reason` in `fields`; one `patient.updated` with `phone` in `fields` and empty `values`; one `patient.opened` from the repeat; neither planted value in any response | Pass |
| Q4 Refused and failed changes | Discount as receptionist; a discount too large | `403`, `422`; still one `discount.set` entry | Pass |
| Q5 Who opened a record (US2) | Patient as receptionist; record as its author, another doctor, and a receptionist; an unknown id | `200`, `200`, `404`, `403`, `404`; `encounter.opened` 1 `succeeded` and 2 `refused`; nothing for the unknown id; no clinical text | Pass |
| Q6 A staff member's activity (US3) | Wrong password on an account; an unknown email; 6 more wrong attempts | `422`, `422`; a `staff` actor and an `unknown` actor; no planted value; 5 refused entries after 7 attempts, as the limit stops the rest | Pass |
| Q7 Cursor and searches | Two pages of 2; `action=audit.searched` | Page 1 ids 26, 25; page 2 ids 24, 23, although the searches added entries; filters shown. This step found empty filters returned as `[]`, against the contract, now `{}` with a test | Pass after the fix |
| Q8 Access and languages | Receptionist; doctor with an invalid filter; no token; `to` before `from`; Arabic; `DELETE` | `403`, `403`, `401`, `422`; an Arabic message with the same structure; `404` | Pass |
| Q9 Tests and style | `composer test`; `test --group=concurrency`; `pint --test`; `postman spec lint …  --issueType=syntax`; `git diff --stat --diff-filter=MD 476e76a -- tests/` | `1899 passed (7585 assertions)`, exit 0 (baseline 1663); concurrency `16 passed (428 assertions)`, exit 0 (baseline 13); Pint `PASS`; `No issues found`; the diff prints nothing, so no earlier test was changed or deleted | Pass |
| SC-007 Recording cost | `clinic_testing` only, 100 patient updates, three runs each, with the entry and with a repository that writes nothing | With: 1.99, 1.72, 1.94 ms per update; without: 1.26, 1.18, 1.63 ms; target no more than 100 ms added | Pass |

## SC-002 and T050: search at 5,000,000 entries

Run on `clinic_testing` only, in a transaction rolled back afterwards (0 rows left), with 100 staff and 2,000 patients over two years, through
`AuditSearchService::search`, 15 per page, three runs each. The spec's SC-002 covers searches by patient and by record. T050 also set the same
target for the other searches.

| Search | Runs (ms) | Target under 2,000 ms |
|--------|-----------|----------------------|
| By patient (SC-002) | 25, 3, 2 | Pass |
| By record (SC-002) | 4, 3, 2 | Pass |
| No filter | 3, 2, 2 | Pass |
| `outcome=refused` alone | 10, 9, 9 | Pass |
| `action` and `outcome` | 72, 18, 19 | Pass |
| **`staff_id` alone** | **9440, 7534, 7533** | **Miss** |
| **Date range alone, one month** | **4044, 3756, 3702** | **Miss** |

**SC-002 is met, and two searches outside it miss T050's target.** They return correct results, but slowly. Per the tasks, no index or query was changed
without approval. The likely cause in both is sorting every matching row by `id` before taking a page: about 50,000 rows for one staff member and
about 200,000 for one month. This was not confirmed with `EXPLAIN`. The figures also come from an uncommitted transaction, where the optimizer's statistics
may lag, so real plans could differ.

## Changes beyond the task list

- **A patient update that changes nothing** returned the whole record with no entry, so re-sending one known field read the rest of a patient's details unseen. A
  security review found this after T037. It now records `patient.opened` (spec FR-004, research R8). Medical records are not affected.
- **`composer test`** now disables Composer's 300-second process timeout, because the suite takes about 330 seconds.
- **Timestamps** use `toISOString()`, as the project's other resources do, rather than `toIso8601String()` as T019 first said. The contract and tasks were updated.
- **Malformed cursors** that are valid JSON are refused too, beyond what T017 listed.
- **Existing configuration**: `.env` says `APP_PORT=8080`, but the running stack is published on 8081, because a separate phpMyAdmin container holds 8080. Not changed.

The Postman collection update is a separate follow-up.
