# Verification Results: Doctors and Specialties

**Feature**: 004-doctors-specialties | **Date**: 2026-10-28 | **Task**: T040, T041

Results observed while running [quickstart.md](quickstart.md) Q1–Q8 on the development stack
(project `nile-clinic`, `http://localhost:8080`). Failures are reported as observed
(Constitution VIII). All accounts, doctors, and specialties used are synthetic.

## Environment

- Windows 11 Pro, Docker Compose, Git Bash; branch `004-phase-6-polish`.
- Development database `clinic`; tests and Q7 run against `clinic_testing`.
- PHP 8.5.12, Laravel 13.38.0, MySQL 9.7.4.
- Arabic request bodies were sent from UTF-8 files with `--data-binary @file`, and Arabic query
  values URL-encoded, because Git Bash passes native arguments through the ANSI code page (see
  feature 003 verification).

## Results

| Step | Command (summary) | Observed | Result |
|------|-------------------|----------|--------|
| Q1 Migrate | `migrate:status` | `create_specialties_table`, `create_doctors_table`, `create_doctor_specialty_table` `Ran` (batch 4, applied in Phase 2); earlier migrations unchanged | Pass |
| Q2 Tokens for each role | created `qs4-admin`, `qs4-reception`, `qs4-doctor@clinic.test` (doctor named `أحمد مصطفى`, staff id 10) with `tinker`; signed in all three | Three tokens issued | Pass |
| Q3 Specialty catalog (US1) | add `"  Cardiology <n> "` / `أمراض القلب <n>`, add Pediatrics, add `cardiology <n>` with `Accept-Language: ar`; list `?is_active=1` as receptionist; deactivate/reactivate as administrator, then as receptionist and doctor | `201`, `201` (`name_en` stored as `Cardiology <n>`); duplicate → `422` on `name_en` with the Arabic message "قيمة حقل الاسم بالإنجليزية مُستخدمة من قبل."; list items exactly `id`, `name_en`, `name_ar`, `is_active`; deactivate → `200` `is_active: false`, reactivate → `200` `true`; receptionist and doctor → `403` | Pass |
| Q4 Doctor profile (US2) | `POST /doctors` for staff id 10 with both specialties and registration number; repeat; receptionist's staff id; deactivate Pediatrics then `PATCH` keeping it; new doctor account with Pediatrics; `specialty_ids: []`; doctor `PATCH` own profile | `201`: `name` `أحمد مصطفى`, two specialties, registration number set, no `email`/`user_id` in the body; repeat → `422` on `user_id` and `registration_number`; receptionist → `422` "The selected staff account must be active and have the doctor role."; kept deactivated specialty → `200` (shown `is_active: false`); new doctor with it → `422` "A deactivated specialty cannot be newly assigned to a doctor." (first attempt used a wrong account id from a script parsing error and got `422` on `user_id`; re-run with the right id gave the expected result); empty set → `422`; doctor self-edit → `403` | Pass |
| Q5 Browse (US3) | `?specialty_id=<cardiology>`; `?name=احمد مصطفي` (URL-encoded); `/doctors/me` as doctor and receptionist; `/doctors/2`; `/doctors/999999` | Both lists return doctor 2 with exactly `id`, `name`, `title`, `is_active`, `specialties` (the search found `أحمد مصطفى` from `احمد مصطفي`); `/me` → `200` for the doctor, `403` for the receptionist; show adds `registration_number`; unknown → `404 {"message":"No doctor was found."}` | Pass |
| Q6 Inactive doctors (FR-012) | deactivate staff account 10; list `?is_active=1` and `?is_active=0`; reactivate; list `?is_active=1` | Absent from the active list, present in the inactive list with `is_active: false`; after reactivation listed as active again with the same profile | Pass |
| Q7 Listing speed at 500 doctors and 100 specialties | 500 factory doctors (about half with `ar_EG` names) and 100 specialties in `clinic_testing`; `DoctorService::list()` timed (second run of each) | specialty filter 13 ms; `name=an` 65 matches 18 ms; `name=zzzz` 11 ms; `is_active=1` 500 matches 16 ms; all three filters 2 ms; unfiltered 11 ms; an Arabic first name taken from the data 2 matches 58 ms. All under 2 s (SC-002). Test database emptied afterwards with `migrate:fresh --force` (`clinic_testing` only) | Pass |
| Q8 Tests and style | `composer test`; `pint --test` (T039) | `486 passed (1328 assertions)`; Pint `PASS` (168 files). `grep` of `storage/logs/*.log` for the quickstart registration number and password → `0` | Pass |

## Notes

- `name=محمد` returned 0 in Q7 because no generated name contained it (a direct `LIKE` count on
  `users.name` also gave 0), not because of a search fault; the Arabic first-name check above
  covers the Arabic path.
- The quickstart was not edited.
- Synthetic rows created on the development database: three `qs4-*` accounts, one extra doctor
  account (id 12) and one created by T011's factory check (id 7), specialties 1–4, doctor profiles
  1–2.
