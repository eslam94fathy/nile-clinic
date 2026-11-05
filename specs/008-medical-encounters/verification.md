# Verification Results: Medical Encounters

**Feature**: 008-medical-encounters | **Date**: 2026-11-05 | **Task**: T047–T050

Results observed while running [quickstart.md](quickstart.md) Q1–Q9 on the development stack (project
`nile-clinic`). Failures are reported as observed (Constitution VIII). All accounts, doctors, patients,
visits, and clinical text used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `008-phase-8-polish`.
- Development database `clinic`; tests and the SC-005 measurement run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4. Real clinic date 2026-11-05 (Thursday).
- The `web` service was already published on `127.0.0.1:8081` for this session (see the feature 007
  record; `.env` is unchanged), so `B` was `http://127.0.0.1:8081/api/v1`.

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Rebuild and migrate | `docker compose build app`; `up -d app`; read the PHP setting; `migrate`; `migrate:status`; `route:list --path=doctors/me` | Setting prints `1`; both encounter migrations `Ran` (batch 8), earlier ones unchanged; the seven contract routes listed; health `200` | Pass |
| Q2 Two doctors, one visit | Temporary tokens (see Notes); walk-in `P1` for `DOC` (entry 8); start while waiting; call next | `201`; `422` `visit_not_started`; `200` `with_doctor` | Pass |
| Q3 Start and save (US1) | Start; save; open; start again; `PUT` without `diagnosis`; open; as `DOCTOR2` start and open; open as receptionist and administrator | `201` draft (`E1` = 1); `200`; `200` with the content and 1 line; `422` `already_started`; `422` on `diagnosis`, draft unchanged; `404`, `404`; `403`, `403` | Pass |
| Q4 Finalize (US2) | Finalize incomplete; complete; finalize; save again; finalize again; own queue; finish | `422` on `diagnosis` and 3 line fields (4 errors); `200`; `200` `finalized` with `finalized_at`; `422` `not_editable`; `422` `not_editable`; visit still `with_doctor`; `200` `seen` | Pass |
| Q5 Visit list (US3) | `record_status=finalized`; a 93-day range | `200`: entry 8, `finalized`, `encounter_id` 1, planted text absent; `422` on `to` | Pass |
| Q6 Another doctor's record (US4) | As `DOCTOR2` off the list: history, open `E1`, open unknown, history of unknown patient; walk-in for `DOC2`; history; open; `PUT`; mark as left; history; open | `404` each, refused and unknown bodies **identical** for both records and history; `201`; `200` with date, name, and specialties and no planted text; `200` with clinical content; `403`; `200`; `404`; `404` | Pass |
| Q7 Addendum (US5) | Add; open; empty text; as `DOCTOR2` off the list | `201`; the addendum shown, content unchanged; `422`; `404` | Pass |
| Q8 Nothing readable outside the API | Raw `chief_complaint`, `diagnosis`, and addendum `text`; search `storage/logs/*.log` | All three begin `eyJpdiI6` (ciphertext), not the planted text; `SYNTHETIC-PLANTED-TEXT` count `0` in `laravel.log` | Pass |
| Q9 Tests and style | `composer test`; `test --group=concurrency`; `pint --test`; `postman spec lint … --issueType=syntax` | `1194 passed (3995 assertions)`, exit 0 (baseline 1039); concurrency `9 passed (178 assertions)`, exit 0; Pint `PASS` (349 files), exit 0; lint `No issues found`. Run at T047; only documents changed afterwards | Pass |
| SC-005 List speed | `clinic_testing` only, in a transaction rolled back afterwards: 500 factory doctors, 2,000 patients, 525,000 visits, 500,000 records bulk-inserted; repository calls, three runs each | Visits (92 days): page of 15 87, 6, 4 ms; page of 100 33, 18, 19 ms; `none` 64, 6, 8 ms; `draft` 6, 6, 8 ms; patient filter 319, 1, 1 ms. History: page of 15 303, 5, 5 ms; page of 100 36, 12, 12 ms; one doctor 28, 2, 2 ms. All under 2 s; 0 rows after the rollback | Pass |

## Notes

- **Q2 tokens**: this run created new synthetic accounts and patients with factories on the development
  database, and issued tokens with `createToken('qs8')`, rather than repeating the earlier features'
  setup steps. All `qs8` tokens were deleted afterwards (0 remaining). The synthetic rows remain, as in
  earlier features.
- **Slowest SC-005 figures are first runs**: 319 ms and 303 ms are the first call of those queries; later
  runs took 1–5 ms. The "patient filter" and "one doctor" cases returned no rows for the sampled doctor
  and patient, so they measure the lookup, not a full page. The list queries do not select clinical
  columns, so the short fixed ciphertext used for the bulk rows does not affect these timings.
- **Not measured**: SC-005 times repository calls, as feature 007's SC-004 did, not whole HTTP requests.
- **Not tested**: the 1 MB nginx default request limit. Only text the spec allows in extreme escaped
  form could approach it (about 1 MB when every one of the longest fields is four-byte text sent as
  JSON escapes); ordinary Arabic and English content is well below it.
- **Change from the approved plan, found while writing tasks**: refused records do not use
  `Response::denyAsNotFound`, because debug mode adds exception keys to that response and would make a
  refused record differ from an unknown one (research R4). The behavior is what FR-013 requires.
- **Before production**: back up `APP_KEY` separately (README, Environment), and complete the legal
  review of Egyptian health-data obligations, including retention (Constitution I).
- **Follow-up**: the Postman collection update is separate, as for features 005–007.
