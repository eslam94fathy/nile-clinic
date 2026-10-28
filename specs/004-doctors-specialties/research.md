# Research: Doctors and Specialties

**Feature**: 004-doctors-specialties | **Date**: 2026-10-28

Facts below were checked on 2026-10-28 against the running development stack (Laravel Framework
13.38.0, MySQL 9.7.4, `utf8mb4_unicode_ci`) and the existing `Staff` and `Patients` modules. Items
marked *(approved — Dn)* were approved on 2026-10-28 and are listed in the plan's Decisions table.

## R1 — One `Doctors` module for specialties and doctor profiles *(approved — D1)*

- **Decision**: One new module, `app/Modules/Doctors/`, holding both aggregates: `Specialty` and
  `Doctor` (the doctor profile), each with its own repository, service, policy, and resources. It
  is built like `Patients`: provider (bindings, routes, policies, translations), plain-id route
  parameters loaded through the repository, not-found exceptions that render `404` and are never
  reported, Laravel's default `data` wrapper.
- **Rationale**: The roadmap and constitution name one domain, "doctors and specialties". A
  specialty exists only to describe doctors, and later features (scheduling, appointments) will
  depend on both together.
- **Alternatives**: Two modules (`Specialties`, `Doctors`) — two providers and two translation
  namespaces for a catalog of four endpoints; the doctor module would depend on the other anyway.

## R2 — Doctor identity and the link to the staff account (FR-005, FR-011, FR-012)

- **Decision**: Table `doctors` with its own auto-increment `id` (the doctor id used in URLs and,
  later, by schedules and appointments) and `user_id` → `users.id`, **unique**, foreign key with
  `RESTRICT` on delete (accounts are never deleted, feature 002 FR-004). The doctor's name, active
  status, and role are read from the linked `users` row; nothing is copied.
- **Active** (FR-012): a doctor is active when `users.is_active = 1` and `users.role = 'doctor'`.
  The `Doctor` model exposes `isActive()` from its loaded `user`; the list filter applies the same
  two conditions in SQL through a join. No column stores it, so it can never go stale when
  feature 002 changes the account (clarification Q2).
- **Alternatives**: Using `user_id` as the primary key — saves a column but couples the doctor id
  to the account id; a doctor's later records would break if the clinic ever re-linked a profile.
  A stored `is_active` on `doctors` — must be kept in step by the Staff module, a dependency in
  the wrong direction.

## R3 — Reading staff accounts from the Doctors module

- **Decision**: `DoctorService` depends on the existing `StaffMemberRepository` contract
  (`findById`) from the Staff module to load the account named in `user_id`, then checks role and
  active status itself. The `Doctor` model declares `belongsTo(User::class)` for eager loading
  name and status in lists.
- **Rationale**: The Staff module already owns that query and contract; reusing it is the single
  cross-module dependency, in the same direction as `PatientPolicy` reading `Role`.
- **Alternatives**: A second query for users inside `DoctorRepository` — duplicates Staff's data
  access.

## R4 — Arabic-aware doctor name search (FR-014) *(approved — D2)*

- **Finding**: The doctor's name lives in `users.name`, which has no normalized copy. In MySQL
  9.7.4 the collation does not unify the Arabic variants (`'أحمد' = 'احمد'` → 0, checked
  2026-10-28). The SQL expression
  `REPLACE(…REPLACE(REGEXP_REPLACE(LOWER(TRIM(name)), '[[:space:]]+', ' '), 'أ', 'ا')…, 'ى', 'ي')`
  produces exactly what `ArabicName::normalize()` produces (checked on a mixed sample:
  `'  Dr  أحمد   مصطفى فاطمة إيمان آمال '` → `'dr احمد مصطفي فاطمه ايمان امال'`, binary-equal).
- **Decision**: Normalize at query time. `EloquentDoctorRepository` builds the expression from
  `ArabicName`'s variant map (one definition) and matches it with `LIKE %term%` against the
  normalized, `LIKE`-escaped search text. No schema change to `users`.
- **Shared helper**: `ArabicName` moves from `app/Modules/Patients/Support/` to `app/Support/`
  (namespace `App\Support`), its `VARIANTS` map becomes public, and the two Patients files that
  use it and its unit test are updated. Behaviour is unchanged. `docs/architecture.md`: "Shared
  helpers are added only when two real repositories need the same thing" — this is that case.
- **Performance**: the expression is evaluated per row, so it scans the doctors joined to users.
  At the SC-002 scale (500 doctors) that is a few hundred short strings; quickstart Q7 measures it.
- **Alternatives**: A `name_search` column on `users` — changes feature 002's table and makes the
  Staff module maintain a field only Doctors uses. A copy of the name on `doctors` — goes stale
  when an administrator renames the account. Importing `App\Modules\Patients\Support\ArabicName`
  from Doctors — makes Doctors depend on Patients for a string helper.

## R5 — Specialty names and uniqueness (FR-001, FR-002, FR-004a)

- **Decision**: Columns `name_en` and `name_ar`, `varchar(100)`, each with a **unique index**.
  The model's mutators store each name through `ArabicName::clean()` (trim, collapse repeated
  spaces), so " Cardiology  " and "Cardiology" are the same stored value. The collation already
  makes Latin case irrelevant for the unique index (`'Ahmed' = 'ahmed'` → 1, feature 003 R3).
  Arabic letter variants are **not** merged for uniqueness: the spec requires only case and
  spaces.
- **Concurrency** (spec edge case "Concurrent edits", SC-003): `Rule::unique` gives the message in
  the normal case; two simultaneous adds both pass validation and the unique index rejects the
  second. `SpecialtyService` catches `UniqueConstraintViolationException`, asks the repository
  which of the two names is taken, and throws the same `422` on that field — the
  `guardUniqueEmail()` pattern from feature 002.
- **Response** (clarification Q1): every specialty is serialized as
  `{id, name_en, name_ar, is_active}`, in catalog lists and nested in doctors alike.
- **Catalog list**: unpaginated, ordered by `name_en`, optional `is_active` filter. The catalog is
  small (SC-002 assumes 100) and clients use it for pick lists.

## R6 — Assigning specialties (FR-007–FR-009)

- **Decision**: Pivot table `doctor_specialty (doctor_id, specialty_id)`, composite primary key,
  both foreign keys `RESTRICT` (neither side is ever deleted), extra index on `specialty_id` for
  the specialty filter. Input is `specialty_ids: int[]`, at least one, distinct, each an existing
  specialty (validated in the Form Request). On update, the sent list **replaces** the doctor's
  set.
- **Active-or-kept rule**: a specialty in the list must be active **or** already assigned to that
  doctor. `DoctorService` checks this inside the transaction after loading the specialties with a
  shared lock (`SpecialtyRepository::lockByIds`), so a specialty deactivated at the same moment
  cannot be newly assigned. A failure is a `422` on `specialty_ids`
  (`doctors::messages.specialty_inactive`).
- **Transaction**: create and update write `doctors` and `doctor_specialty` together inside
  `DB::transaction` in `DoctorService` (Constitution XI; architecture "Transaction ownership").

## R7 — Creating a profile for a staff account (FR-005, FR-006)

- **Decision**: `POST /doctors` with `user_id`. The Form Request checks `user_id` exists and is
  not already used in `doctors` (`Rule::unique`). `DoctorService` loads the account through
  `StaffMemberRepository` and rejects it with `422` on `user_id`
  (`doctors::messages.not_an_active_doctor`) unless it is active and has the doctor role.
- **Concurrency**: two simultaneous creates for the same account, or with the same registration
  number, both pass validation; the unique indexes reject the second, and the service maps it to
  the same `422` on `user_id` or `registration_number` after asking the repository which one is
  taken.

## R8 — Registration number and title (FR-007)

- **Decision**: `registration_number` `varchar(30)`, nullable, **unique**, validated
  `^[A-Za-z0-9]{1,30}$`, stored as entered (the collation makes uniqueness case-insensitive);
  `null` clears it. `title` `varchar(100)`, required, cleaned like names, free text.
- **Not done**: checking the number against the Egyptian Medical Syndicate — no integration is in
  scope.

## R9 — Endpoints and authorization (FR-010, FR-013–FR-018, clarification Q3)

- **Decision**: Policies with class-level abilities, checked before any lookup, as in the existing
  modules:
  - `SpecialtyPolicy`: `viewAny` — any active staff member; `create`, `update`, `deactivate`,
    `reactivate` — active administrators only.
  - `DoctorPolicy`: `viewAny`, `view` — any active staff member; `create`, `update` — active
    administrators only; `viewOwn` — active staff with the doctor role.
- **Own profile**: `GET /doctors/me` returns the profile linked to the signed-in doctor, or `404`
  when none exists yet (US3 scenario 6). Other roles get `403`.
- **Deactivate/reactivate** use `POST …/deactivate` and `POST …/reactivate`, idempotent, as
  feature 002 does for staff accounts.
- **Empty `PATCH`** (no updatable field sent) returns `422` with `doctors::messages.nothing_to_update`,
  as feature 003 does.

## R10 — Doctor list (FR-013, FR-015, FR-016)

- **Decision**: `GET /doctors` with optional `name` (R4), `specialty_id` (doctors having it),
  `is_active` (R2), `page`, `per_page` (default 15, maximum 100). Filters combine with AND.
  Ordered by the account name, then doctor id. `user` and `specialties` are eager-loaded (no
  per-row queries).
- **Shapes**: list item `{id, name, title, is_active, specialties[]}`; single profile adds
  `registration_number`, `created_at`, `updated_at`. Neither shows the account email, role, or
  `user_id` (FR-015, FR-016).

## R11 — Localization (FR-019)

- **Decision**: Module translations `doctors::messages` (`doctor_not_found`,
  `specialty_not_found`, `not_an_active_doctor`, `specialty_inactive`, `nothing_to_update`) and
  `doctors::attributes` (field names for validation messages) in `en` and `ar`. The existing
  `TranslationParityTest` checks every `app/Modules/*/lang` file has the same keys in both
  locales.

## R12 — Synthetic data (FR-020)

- **Decision**: `SpecialtyFactory` (synthetic paired names, for example `Specialty 12` /
  `تخصص 12`, so uniqueness never collides) and `DoctorFactory` (creates a `UserFactory` account
  with the doctor role, a title, and an optional random registration number). No seeder.

## Sources

- Installed code: `app/Modules/Staff/` (`User`, `Role`, `StaffMemberRepository`,
  `StaffManagementService::guardUniqueEmail`), `app/Modules/Patients/` (provider, policy,
  repository, `Support/ArabicName`), `docs/architecture.md`, users and patients migrations.
- MySQL checks run on 2026-10-28 in the `mysql` container (R4).
- Feature 003 research R3, R5, R7 (collation behaviour, unique-race pattern, query-error logging).
