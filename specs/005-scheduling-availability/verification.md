# Verification Results: Scheduling and Availability

**Feature**: 005-scheduling-availability | **Date**: 2026-10-28 | **Task**: T046, T047

Results observed while running [quickstart.md](quickstart.md) Q1–Q8 on the development stack
(project `nile-clinic`, `http://localhost:8080`). Failures are reported as observed
(Constitution VIII). All accounts, doctors, schedules, and time off used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `005-phase-7-polish`.
- Development database `clinic`; tests and Q7 run against `clinic_testing`.
- PHP 8.5.12 (tzdata 2026.5), Laravel 13.38.0, MySQL 9.7.4. Real date 2026-10-28 (Wednesday);
  "next Sunday" is 2026-11-01.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate and configure | `migrate:status`; `config('app.clinic_timezone')` | `create_doctor_schedules_table`, `create_schedule_periods_table`, `create_doctor_time_off_table` `Ran` (batch 5, applied in Phase 2); earlier migrations unchanged; `Africa/Cairo` | Pass |
| Q2 Tokens and a doctor | signed in `qs4-admin`, `qs4-reception`, `qs4-doctor@clinic.test` (feature 004 verification); doctor id from `/doctors/me` | Three tokens; doctor id 2 | Pass |
| Q3 Weekly schedule (US1) | `PUT` 20 min, Sunday 17:00–21:00 and 09:00–10:00 (out of order), Tuesday 10:00–14:00; `GET` as receptionist; four invalid `PUT`s; `PUT` as receptionist and doctor | `200`; seven days `sunday`…`saturday`, Sunday periods sorted `09:00–10:00`, `17:00–21:00`. Touching periods → `422` "On Monday, periods must not overlap or touch." on `periods.1`; 09:00–09:10 → `422` on `periods.0`; length 7 → `422`; `09:03` → `422` on `periods.0.start`; `GET` afterwards byte-identical to before; receptionist and doctor → `403` | Pass |
| Q4 Slots (US2) | `slots?from=2026-11-01&to=2026-11-01`; from yesterday; 45-day range; range past the horizon | 15 slots `09:00–09:20` … `20:40–21:00` in order; `422` on `from` ("after or equal to 2026-10-28"); `422` "The date range can be at most 31 days."; `422` "Slots can be requested at most 90 days ahead." | Pass |
| Q5 Time off (US3) | partial 09:20–09:40 on Sunday with reason; slots; list; whole-day Tuesday; Tuesday slots; `DELETE` as receptionist then administrator; `start_date` yesterday | `201` with `start_time` `09:20`; 14 slots, no `09:20`; list shows the entry; Tuesday `201` and its slots `[]`; receptionist `DELETE` → `403`; administrator → `204` and 15 slots again; yesterday → `422` on `start_date` | Pass |
| Q6 Own schedule and inactive doctor (US4) | `/doctors/me/schedule` and `/me/slots` as doctor; `/me/schedule` as receptionist; deactivate staff account 10, list slots, reactivate | Doctor gets the same schedule and 15 slots; receptionist `403`; while deactivated `200` with `data: []`; after reactivation 15 slots again | Pass |
| Q7 Slot speed (SC-002) | 500 factory doctors with `weekdays()` schedules in `clinic_testing`; `SlotService::forDoctor()` for the last doctor, 2026-10-29…2026-11-28 (31 days), three runs | 504 slots each run: 51 ms, 18 ms, 13 ms — under 2 s. Test database emptied afterwards with `migrate:fresh --force` (`clinic_testing` only) | Pass |
| Q8 Tests and style | `composer test`; `pint --test` (T045) | `682 passed (1772 assertions)`; Pint exit 0 after one `yoda_style` fix in `WeeklyScheduleRules` | Pass |

## Notes

- The quickstart was not edited. Its Q2 placeholders were filled with the synthetic `qs4-*`
  accounts created during feature 004 verification.
- Synthetic rows left on the development database: doctor 2's weekly schedule (Sunday and
  Tuesday). Both time off entries created in Q5 were removed in Q5.
- Daylight saving is covered by `SlotCalculatorTest` (2026-04-24 spring forward, 2026-10-29 fall
  back) rather than by the quickstart, since neither date can be requested through the API on
  2026-10-28 without moving the clock.
