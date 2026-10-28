# Research: Patient Registration and Records

**Feature**: 003-patient-records | **Date**: 2026-10-28

Facts below were checked on 2026-10-28 against the running development stack (Laravel Framework
13.38.0, MySQL 9.7.4, `utf8mb4_unicode_ci`) and the existing `Staff` module. Items marked
*(approved — Dn)* were approved on 2026-10-28 and are listed in the plan's Decisions table.

## R1 — Module and reuse of feature 002

- **Decision**: One new module, `app/Modules/Patients/`, built like `Staff`: provider (bindings,
  routes, policy, translations), plain-id route parameters loaded through the repository, a
  `PatientNotFoundException` that renders `404` and is never reported, Laravel's default `data`
  wrapper and paginated shape.
- **Reuse**: authentication (`auth:sanctum`, idle expiry, deactivated accounts refused) and the
  translated 401/403 rendering in `bootstrap/app.php` apply unchanged. The policy reads
  `App\Modules\Staff\Enums\Role` from the signed-in `User`; this is the only cross-module
  dependency.
- **Alternatives**: Putting patients in the `Staff` module — mixes two domains, against the
  modular-monolith rule in `docs/architecture.md`.

## R2 — Patient number (FR-003) *(approved — D1)*

- **Decision**: The patient number **is** the record's auto-increment primary key, returned as
  `id`. No separate column.
- **Rationale**: It is unique, assigned by the system, cannot be chosen or edited by staff, and is
  never reused: InnoDB has persisted the auto-increment counter across restarts since MySQL 8.0,
  and records are never deleted (FR-008). Gaps after a failed insert are harmless. The
  constitution allows opaque internal ids in logs (XII), so logging the id is permitted.
- **Alternatives**: A separate `patient_number` column (for example `P-000123`) generated in the
  same transaction — a second unique value to keep in step, with no requirement asking for a
  format. Random numbers — need retry-on-collision logic and are harder to read aloud.

## R3 — Arabic-aware name search (FR-012, SC-003)

- **Finding** (checked in MySQL 9.7.4 with `utf8mb4_unicode_ci`): `'أحمد' = 'احمد'` → 0,
  `'فاطمة' = 'فاطمه'` → 0, `'مصطفى' = 'مصطفي'` → 0, `'Ahmed' = 'ahmed'` → 1. The collation
  ignores Latin case but **not** the Arabic variants the spec requires.
- **Decision**: Store a second column, `name_search`, holding a normalized copy of `full_name`:
  trimmed, repeated spaces collapsed, lower-cased, `أ إ آ → ا`, `ة → ه`, `ى → ي`. The search text
  goes through the same function, and the query is `name_search LIKE %term%` with `%`, `_`, and
  `\` in the term escaped. One small class, `Support/ArabicName::normalize()`, is the only
  definition; the model's `full_name` mutator sets both columns, so they cannot drift.
- **Performance**: a leading-wildcard `LIKE` cannot use an index, so it scans `name_search`. For
  the SC-002 target (100,000 patients, under 2 s) this is a scan of one short column; the
  quickstart (Q7) measures it with 100,000 synthetic rows.
- **Alternatives**: Relying on the collation — fails two of the three variants (above). A MySQL
  `FULLTEXT` ngram index — needs a minimum token length and different query syntax; not needed at
  this scale. Normalizing only the search term — cannot match stored variants.

## R4 — Phone numbers (FR-006)

- **Decision**: Form Requests remove spaces, dashes, and parentheses in `prepareForValidation()`,
  then validate `^\+?[0-9]{8,15}$`. The normalized value is stored (`varchar(16)`) and indexed. The
  phone filter normalizes the same way and matches exactly, returning every patient sharing the
  number.
- **Alternatives**: Treating `010…` and `+2010…` as the same number — country-code rules are not
  in the spec; deferred. Partial phone search — not required.

## R5 — National ID (FR-004, SC-006)

- **Decision**: Optional (clarification Q2). When given: exactly 14 digits, first digit `2` or `3`
  (the century digit of the Egyptian format), validated as `^[23][0-9]{13}$`, stored as
  `char(14)` with a **unique index**. MySQL unique indexes allow many `NULL`s, so patients without
  one do not collide.
- **Concurrency**: `Rule::unique` gives the friendly message in the normal case; two simultaneous
  registrations with the same ID both pass validation, and the unique index rejects the second.
  The service catches `UniqueConstraintViolationException` and turns it into the same `422` on
  `national_id`, the pattern `StaffManagementService::guardUniqueEmail()` already uses.
- **Not done**: cross-checking the birth date or sex encoded in the ID against the entered values —
  the spec does not require it, and a mismatch can be a data-entry error in either field.

## R6 — Lists versus the full record (FR-013, FR-017)

- **Decision**: Two API Resources. `PatientSummaryResource` (list and search): `id`, `full_name`,
  `sex`, `date_of_birth`, `phone`. `PatientResource` (create, show, update): all fields. The list
  endpoint only ever uses the summary resource, so the sensitive fields cannot appear in it.
- **Test**: the list response is asserted to have exactly the five keys per item (SC-004).

## R7 — Keeping patient data out of logs (FR-017, Constitution XII) *(approved — D3)*

- **Finding**: Laravel's `QueryException` message includes the SQL **with bindings substituted**
  (for example the inserted name and phone), and the default handler writes it to the log. Feature
  002 stored no such data; this feature does.
- **Decision**: In `bootstrap/app.php`, report `QueryException` with only `getSql()` (placeholders,
  no values), the error code, and the connection name, and stop the default report. Validation
  errors and `PatientNotFoundException` are not reported at all.
- **Alternatives**: Leaving the default — an unexpected database error on registration would log a
  patient's name, phone, or national ID. Turning off query-exception logging — loses the signal
  that something failed. Laravel 13.38's per-connection `mask_bindings_in_exception_messages`
  option (found during implementation, T034) — keeps placeholders in the SQL part of the message,
  but the message still starts with the driver's text (MySQL's `Duplicate entry '…'` includes the
  value), and the default report adds a stack trace with arguments (`zend.exception_ignore_args`
  is `Off` in the development image). So it is not enough on its own.

## R8 — Search parameters (FR-011) *(approved — D2)*

- **Decision**: Separate, optional query parameters on `GET /patients`: `name` (contains,
  normalized), `phone` (exact, normalized), `national_id` (exact), `id` (exact), plus `page` and
  `per_page` (default 15, maximum 100, as in the staff list). Given filters combine with AND;
  none lists all patients. Results are ordered by `name_search`, then `id`.
- **Rationale**: Each value is validated by its own rule, and there is no guessing whether a run
  of digits is a phone number, an ID, or a national ID.
- **Alternatives**: One free-text `q` that is matched against every field — friendlier for a
  single search box, but ambiguous for digit strings; a client can still offer one box and pick
  the parameter.

## R9 — Emergency contact and clearing values (FR-002, FR-010)

- **Decision**: Three nullable columns on `patients` (`emergency_contact_name`,
  `emergency_contact_relationship`, `emergency_contact_phone`), sent and returned as one nested
  object `emergency_contact: {name, relationship, phone}`. When the object is sent, all three are
  required; `emergency_contact: null` clears it. `national_id` and `address` are cleared by sending
  `null`.
- **Alternatives**: A separate `emergency_contacts` table — only one contact is in scope; a table
  adds a join and a second repository for no requirement.

## R10 — Authorization (FR-014, FR-015, clarification Q1)

- **Decision**: `PatientPolicy` with class-level abilities `viewAny`, `view`, `create`, `update`
  (checked against `Patient::class`, like `StaffMemberPolicy`), each true only for an **active**
  administrator or receptionist. Doctors are refused every ability before any lookup, so they
  cannot learn which ids exist.

## R11 — Localization (FR-016)

- **Decision**: Module translations `patients::messages` (`not_found`) and
  `patients::attributes` (field names used in validation messages, for example `national_id` →
  "الرقم القومي"), supplied through each Form Request's `attributes()`. The existing
  `TranslationParityTest` already checks every `app/Modules/*/lang` file has the same keys in
  both locales.

## R12 — Synthetic data (FR-018)

- **Decision**: `PatientFactory` with Faker. `ar_EG` is available in the installed
  `fakerphp/faker` (checked) and is used for a share of names, so tests cover Arabic text;
  `firstName()`/`lastName()` are used instead of `name()`, which adds titles such as "الآنسة".
  National IDs are random strings matching R5. No seeder.

## Sources

- Installed code: `app/Modules/Staff/` (provider, policy, repository, `guardUniqueEmail`),
  `bootstrap/app.php`, `docs/architecture.md`, `tests/Feature/Localization/TranslationParityTest.php`.
- MySQL checks run on 2026-10-28 in the `mysql` container (R3).
- MySQL Reference Manual, "AUTO_INCREMENT Handling in InnoDB" (counter persisted since 8.0) and
  "Unique Indexes" (multiple `NULL` values allowed).
