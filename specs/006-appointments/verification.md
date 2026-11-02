# Verification Results: Appointments

**Feature**: 006-appointments | **Date**: 2026-11-02 | **Task**: T049, T050

Results observed while running [quickstart.md](quickstart.md) Q1–Q9 on the development stack
(project `nile-clinic`). Failures are reported as observed (Constitution VIII). All accounts,
doctors, patients, schedules, time off, and appointments used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `006-phase-8-polish`.
- Development database `clinic`; tests and Q8 run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4. Real date 2026-11-02 (Monday): `SUN` = 2026-11-08,
  `TUE` = 2026-11-10, yesterday = 2026-11-01.
- Port 8080 was held by an unrelated `phpmyadmin-container8.0` container, so this run started the
  `web` service with `APP_PORT=8081` for the session only (no file changed); `B` was
  `http://127.0.0.1:8081/api/v1`.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate | `migrate`; `migrate:status`; `route:list --path=appointments` | `create_appointments_table` `Ran` (batch 6, applied in Phase 2); earlier migrations unchanged; 8 routes (six `/appointments…`, two `/doctors/me/appointments…`) | Pass |
| Q2 Tokens, schedules, patients | Tokens for the synthetic `qs4-admin` (8), `qs4-reception` (9), `qs4-doctor` (10, doctor 2) from feature 004; doctor 2 set to Sunday 09:00–10:00, Tuesday 10:00–11:00, 20 min; doctor 3 Sunday 09:00–10:00; two patients registered | Schedules `200`; patients `P1` = 6, `P2` = 7 | Pass |
| Q3 Book (US1) | `book` P1 SUN 09:20; slots; P2 same slot; P1 TUE 10:00; P2 09:30, 08:00, yesterday; patient 999999; as doctor; P1 with doctor 3 at SUN 09:20 | `201` `scheduled`, `09:20–09:40`, patient `{id, full_name, phone}`, doctor; slots `09:00`, `09:40` only; `409 slot_booked`; `201`; `422` on `start` ×3 ("This time is not an available slot for this doctor."); `422` on `patient_id`; `403`; `409 patient_busy` | Pass |
| Q4 Find and view (US2) | list by `doctor_id`; by `patient_id` + `status=scheduled`; show; 93-day range; unknown id | Both lists in date/time order, `outside_availability: false`, no note or sensitive patient fields; show adds `note`, `booked_by`, `booked_at`; `422` "The date range can be at most 92 days."; `404` "No appointment was found." | Pass |
| Q5 Outside availability (FR-018) | whole-day time off on SUN; filter `outside_availability=1`; remove time off; filter again; length 30; show; SUN slots; length back to 20 | Time off `201`; SUN 09:20 listed with times unchanged and `true`; `DELETE` `204`; filter empty; with 30 minutes the appointment stays `false` and SUN slots are `[]` (both 30-minute slots overlap 09:20–09:40) | Pass |
| Q6 Reschedule, cancel, no-show (US3, US4) | reschedule to TUE 10:20; SUN slots; cancel with reason; TUE slots; cancel again; no-show on a future appointment | `200` with `10:20–10:40`, `rescheduled_by`/`rescheduled_at`; SUN 09:20 offered again; `200` `cancelled`, reason kept, TUE 10:20 offered again; `422 not_changeable`; `422 not_started` | Pass |
| Q7 Doctor's own (US5) | own list SUN..TUE; own show of doctor 3's appointment; `/appointments` as doctor; `/patients/6` as doctor | `200` with only doctor 2's two appointments, patient `{id, full_name, sex, date_of_birth}`; `404`; `403`; `403` | Pass |
| Q8 Listing speed (SC-005) | `clinic_testing` only: 500 factory doctors, 10,000 factory patients, synthetic appointment rows bulk-inserted (100,854 rows, about 10 % cancelled); `AppointmentRepository::paginate()` three runs each | Doctor day 39 ms, 5 ms, 5 ms; patient 3-month history 5 ms, 4 ms, 4 ms; whole-clinic day (293 rows, first page of 100) 17 ms, 14 ms, 13 ms — all under 2 s. Then `migrate:fresh --force` on `clinic_testing` (target checked first) | Pass |
| Q9 Tests and style | `composer test`; `test --group=concurrency`; `pint --test` | `879 passed (2398 assertions)`, exit 0 (baseline 682); concurrency `2 passed` (also 3/3 in T027); Pint `PASS` (265 files) | Pass |

## Notes

- **Quickstart edit**: Q5 used `outside_availability=true`. The implementation follows the
  existing `is_active=1/0` convention for boolean query filters (feature 004; Laravel's `boolean`
  rule rejects the string `true`), so Q5 was changed to `outside_availability=1` in Phase 4.
- **Q2 tokens**: issued with `tinker` (`createToken('qs6')`) for the synthetic `qs4-*` accounts,
  whose passwords were not recorded; sign-in itself is covered by feature 002. The `qs6` tokens
  were deleted after the run.
- **Q8 adaptation**: `Appointment::factory()->count(100000)` would create about 400,000 related
  rows one by one, so related rows came from factories and the appointment rows were inserted in
  chunks with `insertOrIgnore` (random duplicates of the doctor-slot unique index were skipped).
  `paginate()` takes the clinic's `now` as its second argument (T030).
- **Concurrency check**: during T027, replacing the patient lock with a plain read made the
  one-patient/eight-doctors case fail (8 bookings instead of 1), confirming the test detects a
  broken lock order (research R3, R9).
- Synthetic rows left on the development database: doctors 2 and 3 have the Q2 schedules;
  patients 6 and 7; appointments 1 (cancelled), 2 (TUE 10:00), and 3 (doctor 3, SUN 09:40). The
  Q5 time off was removed in Q5.
- Postman collection update for the appointments endpoints is a separate follow-up (as for 005).
