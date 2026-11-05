# Verification Results: Patient Queues

**Feature**: 007-patient-queues | **Date**: 2026-11-05 | **Task**: T045, T046

Results observed while running [quickstart.md](quickstart.md) Q1–Q7 on the development stack
(project `nile-clinic`). Failures are reported as observed (Constitution VIII). All accounts,
doctors, patients, schedules, appointments, and queue entries used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `007-phase-8-polish`.
- Development database `clinic`; tests and the SC-004 measurement run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4. Real clinic date 2026-11-05 (Thursday), from about
  11:43 Africa/Cairo.
- Port 8080 was held by an unrelated `phpmyadmin-container8.0` container, so this run started the
  `web` service with `APP_PORT=8081` for the session only (no file changed); `B` was
  `http://127.0.0.1:8081/api/v1`.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate | `migrate`; `migrate:status`; `route:list --path=queue`; `--path=check-in` | The three new migrations `Ran` (batch 7, applied in Phase 2); earlier migrations unchanged; 8 queue routes and the check-in route | Pass |
| Q2 Tokens, hours, appointments | Tokens for the synthetic `qs4-*` accounts (8, 9, 10; doctor 2); doctor 2 given Thursday 13:00–20:00 and Friday 10:00–11:00 in addition to its hours; patients `P1`–`P3` = 10, 11, 12; `A1` = 6 at 13:00, `A2` = 7 at 13:20, tomorrow's appointment = 8 | Schedule `200`; 21 slots today; all bookings created | Pass |
| Q3 Check in (US1) | Check in `A2`, `A1`; show `A1`; `A1` again; cancel `A1`; slots; tomorrow's; as doctor | `201` number 1, `201` number 2, both `waiting`, `walk_in: false`; `A1` `checked_in`; `422` `not_checkable`; `422` `not_changeable`; slots start at 13:40 (both held); `422` `not_checkable`; `403` | Pass |
| Q4 Walk-in and lists (US2, US4) | Walk-in `P3`; slots; doctor's list; `?status=waiting`; walk-in again; doctor 999999 | `201` number 3, `walk_in: true`, `appointment: null`; slots unchanged; list order walk-in (arrived 11:43), 13:00, 13:20; no note or sensitive keys; total 3; `422` `already_in_queue`; `404` | Pass |
| Q5 Call next and finish (US3) | Own list; call next ×2; finish; `A1`; call next as reception; call next; finish; `A1` | Patient `{id, full_name, sex, date_of_birth}`, no `doctor`; walk-in `with_doctor`; `422` `visit_in_progress`; `200` `seen`; `A1` still `checked_in`; `403`; `A1`'s entry `with_doctor`; `200` `seen`; `A1` `completed` | **Pass after the quickstart correction below** |
| Q6 Leave and undo (US5) | New walk-in; leave; check in and undo a fresh appointment (`A3` = 9); show it; call next; check it in again | `201`; `200` `left`; `204`; `scheduled`; `422` `nobody_waiting`; `201` with a new number, the undone one not reused | **Pass after the quickstart correction below** |
| Q7 Tests and style | `composer test`; `test --group=concurrency`; `pint --test` | `1039 passed (2996 assertions)`, exit 0 (baseline 879); concurrency `6 passed (69 assertions)`, exit 0; Pint `PASS` (304 files), exit 0. Run at T044; only documents changed afterwards | Pass |
| SC-004 List speed | `clinic_testing` only, in a transaction rolled back afterwards: 500 factory doctors, 200 patients, 5,000 entries for today bulk-inserted; repository calls, three runs each | One doctor 36 ms, 5 ms, 5 ms; clinic page of 15: 12 ms, 10 ms, 10 ms; clinic page of 100: 19 ms, 18 ms, 18 ms. All under 2 s; 0 entries after the rollback | Pass |

## Notes

- **Quickstart correction (Q5, Q6)**: as first written, Q5 expected `A1` to be called first and Q6
  expected the walk-in to still be waiting. The first run did not show this: the walk-in was called
  first, and leaving it returned `422 not_changeable`. The code follows FR-008 and research R4: Q2
  can book only upcoming slots, so a walk-in always arrives before those appointment times. The
  quickstart was wrong and was corrected.
- **Q5–Q6 were not rerun from a clean start**: the corrected steps were observed continuing from
  the first run's state, on `A3`, so the numbers were 5, 6, and 7 rather than the 4 and 5 the
  corrected Q6 gives on a clean run. Non-reuse of numbers after an undo is also covered by
  `QueueCorrectionsTest`.
- **Q2 tokens**: issued with `tinker` (`createToken('qs7')`) for the `qs4-*` accounts, whose
  passwords were not recorded. All `qs7` tokens were deleted afterwards (0 remaining).
- **Deviations from the task list, found by running it**, all recorded in `tasks.md`:
  - T004: the `MODIFY` statement had `NULL` before `AS`, which MySQL rejects; it is now
    `AS (...) STORED NULL`.
  - T011: `lockDay` uses a no-op upsert, not `insertOrIgnore`. On a duplicate, `insertOrIgnore`
    keeps a shared lock, so simultaneous first check-ins of a day deadlocked while upgrading to
    `FOR UPDATE`: `ConcurrentQueueTest` failed 5 of 5 runs, then passed 10 of 10 (research R3).
  - T035: the doctor is read before the transaction, not inside it. A plain read inside fixes the
    InnoDB read view before the locks: 8 simultaneous walk-ins of one patient made 8 entries in 3 of
    3 runs. After the change, the concurrency group passed 5 of 5 runs.
- **Added beyond the task list**: a walk-in case in `ConcurrentQueueTest` (with a `walk-in` action in
  `queue-action.php`), the check that an undone appointment is changeable again, the
  `QueueConflictException` messages in both locales (rendered directly, as the locks make them
  unreachable through the API), and the SC-004 measurement above.
- **Contract**: `POST /queue/walk-ins` lists a `409`, but no code path returns it, since a walk-in
  touches no index that the queue day lock leaves open. It is unreachable rather than wrong.
- Synthetic rows left on the development database: doctor 2's extra Thursday and Friday hours;
  patients 10–12; appointments 6 and 7 (`completed`), 8 (tomorrow), and 9 (`checked_in`); queue
  entries for 2026-11-05 numbered 2, 3, 4 (`seen`), 6 (`left`), and 7 (`waiting`).
- Postman collection update for the queue endpoints is a separate follow-up (as for 005, 006).
