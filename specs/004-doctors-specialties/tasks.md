---
description: "Task list for feature 004: Doctors and Specialties"
---

# Tasks: Doctors and Specialties

**Input**: Design documents from `specs/004-doctors-specialties/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/doctors.openapi.yaml](contracts/doctors.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US3)
- All `docker compose exec` commands run from the repository root with the stack up.
  PowerShell users: replace `$?`/`echo $?` with `$LASTEXITCODE`.
- Module root: `app/Modules/Doctors/`. Mirror the existing `app/Modules/Patients/` module (and
  `app/Modules/Staff/` for deactivate/reactivate) for structure, docblock style, and naming; read
  the matching file before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`, plus `->inactive()`), with `App\Modules\Staff\Models\User`, unless a
  task says to use a real token.

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/
`tinker`/`test`, `composer test`, Pint) are listed. If a command does not behave as written,
**stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [ ] T001 Confirm the baseline on branch `004-doctors-specialties` (no file changes)
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the five existing migrations `Ran`
    - `docker compose exec app composer test` → 294 passed (count observed on 2026-10-28)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The shared `ArabicName` helper (D2), schema, models, factories, module skeleton,
translations, exceptions, and policies that every story needs.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T002 Move `ArabicName` to `app/Support/ArabicName.php` (approved D2, research R4)
  - Scope:
    - Move `app/Modules/Patients/Support/ArabicName.php` to `app/Support/ArabicName.php`; namespace
      `App\Support`. Change `private const VARIANTS` to `public const VARIANTS` (the doctor
      repository builds its SQL from it). Update the class docblock: shared by Patients (R3 of
      feature 003) and Doctors (R4 of feature 004). No behaviour change.
    - Update the imports in `app/Modules/Patients/Models/Patient.php` and
      `app/Modules/Patients/Repositories/EloquentPatientRepository.php`.
    - Move `tests/Unit/Patients/ArabicNameTest.php` to `tests/Unit/Support/ArabicNameTest.php`
      (namespace `Tests\Unit\Support`, import `App\Support\ArabicName`); cases unchanged.
    - `grep -rn "Patients\\\\Support\\\\ArabicName" app tests database` → no matches.
  - Verify: `docker compose exec app composer test` → 294 passed (same count as T001)

- [ ] T003 [P] Add one line to `docs/architecture.md` about `app/Support/`
  - Scope: in the module layout tree, add `app/Support/` with the comment
    `# helpers shared by two or more modules (e.g. ArabicName)`; under "Core rules", extend the
    existing "Shared helpers are added only when two real repositories need the same thing"
    sentence with "; they live in `app/Support/`".
  - Depends: T002

- [ ] T004 Create the `specialties` migration in `database/migrations/<timestamp>_create_specialties_table.php`
  - Scope: `docker compose exec app php artisan make:migration create_specialties_table`, with a
    one-line comment pointing to `specs/004-doctors-specialties/data-model.md`:
    - `id` bigint unsigned auto-increment primary key
    - `name_en` `varchar(100)` not null, **unique index** `specialties_name_en_unique`
    - `name_ar` `varchar(100)` not null, **unique index** `specialties_name_ar_unique`
    - `is_active` `boolean` not null, default `true`
    - `timestamps()`
    - No soft deletes (FR-003).
  - Accept: matches data-model.md; `down()` drops the table.
  - Verify: covered by T011

- [ ] T005 Create the `doctors` migration in `database/migrations/<timestamp>_create_doctors_table.php`
  - Scope: generated after T004 so it runs later:
    - `id` bigint unsigned auto-increment primary key ("the doctor id", R2)
    - `user_id` `foreignId('user_id')->unique()->constrained('users')->restrictOnDelete()`
      (unique index `doctors_user_id_unique`)
    - `title` `varchar(100)` not null
    - `registration_number` `varchar(30)` nullable, **unique index** `doctors_registration_number_unique`
    - `timestamps()`
  - Accept: matches data-model.md; `down()` drops the table.
  - Depends: T004

- [ ] T006 Create the `doctor_specialty` migration in `database/migrations/<timestamp>_create_doctor_specialty_table.php`
  - Scope: generated after T005:
    - `doctor_id` `foreignId()->constrained('doctors')->restrictOnDelete()`
    - `specialty_id` `foreignId()->constrained('specialties')->restrictOnDelete()`
    - `primary(['doctor_id', 'specialty_id'])`; `index('specialty_id')`; no timestamps
  - Accept: matches data-model.md; `down()` drops the table.
  - Depends: T005

- [ ] T007 [P] Create the model `app/Modules/Doctors/Models/Specialty.php`
  - Scope:
    - `class Specialty extends Model` using `HasFactory`; `newFactory()` returns `SpecialtyFactory::new()`
    - `$fillable = ['name_en', 'name_ar', 'is_active']`; cast `is_active` → `boolean`
    - `nameEn(): Attribute` and `nameAr(): Attribute` setters returning `ArabicName::clean($value)`
      (`App\Support\ArabicName`, R5)
    - `doctors(): BelongsToMany` → `Doctor::class`, table `doctor_specialty`
    - No query scopes or workflows (Constitution III).
  - Accept: `new Specialty(['name_en' => '  Internal   Medicine '])->name_en` is `'Internal Medicine'`.
  - Depends: T002, T004

- [ ] T008 [P] Create the model `app/Modules/Doctors/Models/Doctor.php`
  - Scope:
    - `class Doctor extends Model` using `HasFactory`; `newFactory()` returns `DoctorFactory::new()`
    - `$fillable = ['user_id', 'title', 'registration_number']`
    - `title(): Attribute` setter → `ArabicName::clean($value)`
    - `user(): BelongsTo` → `App\Modules\Staff\Models\User`; `specialties(): BelongsToMany` →
      `Specialty::class`, table `doctor_specialty`
    - `isActive(): bool` → `$this->user->is_active && $this->user->role === Role::Doctor` (FR-012,
      R2); docblock: derived, never stored, so feature 002 changes take effect at once.
  - Depends: T002, T005, T006

- [ ] T009 [P] Create the factory `database/factories/SpecialtyFactory.php` (synthetic only, R12)
  - Scope: model `Specialty`; `name_en` `'Specialty '.$n`, `name_ar` `'تخصص '.$n` with
    `$n = fake()->unique()->numberBetween(1, 1000000)`; `is_active` `true`; state `inactive()`.
  - Depends: T007

- [ ] T010 [P] Create the factory `database/factories/DoctorFactory.php` (synthetic only, R12)
  - Scope: model `Doctor`; `user_id` → `User::factory()->doctor()`; `title` from
    `['Consultant', 'Specialist', 'Resident']`; `registration_number` `null`; state
    `withRegistrationNumber()` → `fake()->unique()->bothify('??#####')` uppercased; state
    `withSpecialties(int $count = 1)` → `afterCreating` attaching `$count` new
    `Specialty::factory()` rows.
  - Accept: generated values pass the data-model.md rules.
  - Depends: T008, T009

- [ ] T011 Run the migrations and confirm the suite still passes
  - Verify:
    - `docker compose exec app php artisan migrate` → the three migrations `DONE`
    - `docker compose exec app php artisan tinker --execute="echo App\Modules\Doctors\Models\Doctor::factory()->withSpecialties(2)->create()->load('user', 'specialties')->toJson();"`
      → JSON with a doctor-role user and two specialties (development database; synthetic row)
    - `docker compose exec app composer test` → 294 passed
  - Depends: T004–T010

- [ ] T012 Create the module skeleton: `app/Modules/Doctors/Providers/DoctorsServiceProvider.php`, `app/Modules/Doctors/routes/api.php`, `bootstrap/providers.php`
  - Scope:
    - Provider `boot()`: load `routes/api.php` with `api` middleware and `api/v1` prefix (as
      `PatientsServiceProvider`); `loadTranslationsFrom(__DIR__.'/../lang', 'doctors')`.
    - `register()` empty for now (stories add repository bindings).
    - `routes/api.php`: comment header and an empty `Route::middleware('auth:sanctum')->group(...)`
      noting that `{specialty}` and `{doctor}` are plain ids in `whereNumber` groups.
    - Append `DoctorsServiceProvider::class` to `bootstrap/providers.php` after `PatientsServiceProvider`.
  - Verify: `docker compose exec app php artisan route:list --path=api/v1/doctors` → no routes yet, no error
  - Depends: T011

- [ ] T013 [P] Add module translations in `app/Modules/Doctors/lang/en/` and `app/Modules/Doctors/lang/ar/` (R11)
  - Scope: two files per locale, same keys in both:
    - `messages.php`:
      - `specialty_not_found` — en `No specialty was found.`; ar `لم يتم العثور على التخصص.`
      - `doctor_not_found` — en `No doctor was found.`; ar `لم يتم العثور على الطبيب.`
      - `not_an_active_doctor` — en `The selected staff account must be active and have the doctor role.`;
        ar `يجب أن يكون حساب الموظف المحدد نشطاً وله دور الطبيب.`
      - `specialty_inactive` — en `A deactivated specialty cannot be newly assigned to a doctor.`;
        ar `لا يمكن إسناد تخصص غير نشط إلى طبيب.`
      - `nothing_to_update` — en `Send at least one field to change.`; ar `أرسل حقلاً واحداً على الأقل لتعديله.`
    - `attributes.php` (flat keys):
      - `name_en` — `English name` / `الاسم بالإنجليزية`
      - `name_ar` — `Arabic name` / `الاسم بالعربية`
      - `user_id` — `staff account` / `حساب الموظف`
      - `title` — `professional title` / `اللقب المهني`
      - `registration_number` — `registration number` / `رقم القيد بالنقابة`
      - `specialty_ids` — `specialties` / `التخصصات`
      - `specialty_id` — `specialty` / `التخصص`
      - `name` — `name` / `الاسم`
      - `is_active` — `active status` / `حالة التفعيل`
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T012

- [ ] T014 [P] Create `app/Modules/Doctors/Exceptions/SpecialtyNotFoundException.php` and `app/Modules/Doctors/Exceptions/DoctorNotFoundException.php`
  - Scope: copy the shape of `PatientNotFoundException`: `render()` → `404` with
    `doctors::messages.specialty_not_found` / `doctors::messages.doctor_not_found`; empty `report()`.
  - Depends: T013

- [ ] T015 [P] Write the failing policy test `tests/Feature/Doctors/DoctorsPolicyTest.php` (R9, FR-003, FR-010, FR-017)
  - Scope: `Gate::forUser($actor)->allows($ability, <Model>::class)` for roles `administrator`,
    `receptionist`, `doctor`:
    - `Specialty`: `viewAny` true for all three; `create`, `update`, `deactivate`, `reactivate`
      true only for administrator
    - `Doctor`: `viewAny`, `view` true for all three; `create`, `update` true only for
      administrator; `viewOwn` true only for doctor
    - inactive actors (`->inactive()`) of every role refused every ability
  - Verify: `docker compose exec app php artisan test --filter=DoctorsPolicyTest` → fails
  - Depends: T011

- [ ] T016 Create `app/Modules/Doctors/Policies/SpecialtyPolicy.php` and `app/Modules/Doctors/Policies/DoctorPolicy.php`; register both in `app/Modules/Doctors/Providers/DoctorsServiceProvider.php`
  - Scope: class-level abilities `(User $actor): bool` as listed in T015, via private helpers
    (`isActiveStaff`: `$actor->is_active`; `isActiveAdministrator`: active + `isAdministrator()`;
    `isActiveDoctor`: active + `Role::Doctor`). Class docblocks as `PatientPolicy`: checked against
    the class before any lookup. `boot()`: `Gate::policy(Specialty::class, SpecialtyPolicy::class)`
    and `Gate::policy(Doctor::class, DoctorPolicy::class)`.
  - Accept: T015 passes.
  - Verify: `docker compose exec app php artisan test --filter=DoctorsPolicyTest` → pass
  - Depends: T012, T015

**Checkpoint**: Foundation ready — shared helper moved, tables, models, factories, translations,
exceptions, and policies exist.

---

## Phase 3: User Story 1 – Administrator maintains the specialty catalog (Priority: P1) 🎯 MVP

**Goal**: `GET/POST /api/v1/specialties`, `PATCH /api/v1/specialties/{specialty}`, and
`POST …/deactivate|reactivate` maintain the catalog.

**Independent Test**: As an administrator, add two specialties, rename one, deactivate the other,
and list the catalog with both names and statuses.

### Tests for User Story 1 (write first, must fail)

- [ ] T017 [US1] Write `tests/Feature/Doctors/SpecialtyCatalogTest.php`
  - Scope (administrator unless stated):
    - add `{"name_en":"  Cardiology ","name_ar":"أمراض القلب"}` → `201`; `data` exactly
      `id`, `name_en` (`'Cardiology'`), `name_ar`, `is_active` (`true`)
    - duplicate `name_en` `'cardiology'` and `'Cardiology  '` → `422` on `name_en`; duplicate
      `name_ar` → `422` on `name_ar`; duplicate of a **deactivated** specialty also refused
    - missing either name, or 101 characters → `422` on that field (100 passes)
    - rename: `PATCH` `name_en` only → `200`, `name_ar` unchanged; renaming to its own current name
      → `200`; to another specialty's name → `422`; empty body → `422` with error key `fields`;
      unknown id → `404`
    - deactivate → `200`, `is_active` false; again → `200` (idempotent); reactivate → `200`, true;
      again → `200`; unknown id → `404`
    - list as receptionist and as doctor → `200`; ordered by `name_en`; each item exactly four keys;
      `?is_active=1` only active, `?is_active=0` only deactivated; `?is_active=maybe` → `422`
    - race (SC-003): create a specialty through the factory, then call
      `app(SpecialtyService::class)->create([...same name_en, new name_ar...])` → `ValidationException`
      with an error on `name_en`; still one row with that name
  - Verify: `docker compose exec app php artisan test --filter=SpecialtyCatalogTest` → fails
  - Depends: T016

### Implementation for User Story 1

- [ ] T018 [US1] Create `app/Modules/Doctors/Repositories/Contracts/SpecialtyRepository.php` and `app/Modules/Doctors/Repositories/EloquentSpecialtyRepository.php`; bind them in the provider
  - Scope (Eloquent only here):
    - `all(?bool $isActive): Collection` — ordered by `name_en`, then `id`
    - `findById(int $id): ?Specialty`
    - `lockByIds(array $ids): Collection` — `whereKey($ids)->sharedLock()->get()` (used by US2 inside
      the service's transaction, R6)
    - `create(array $attributes): Specialty`; `update(Specialty $specialty, array $attributes): Specialty`
    - `nameTaken(string $column, string $name): bool` — `$column` is `name_en` or `name_ar`
      (cleaned value; collation handles case)
  - Depends: T016

- [ ] T019 [US1] Create `app/Modules/Doctors/Services/SpecialtyService.php`
  - Scope: `list(?bool $isActive)`, `find(int $id)` (→ `SpecialtyNotFoundException`),
    `create(array $attributes)` (with `is_active` true), `rename(Specialty, array)`,
    `deactivate(Specialty)`, `reactivate(Specialty)` (both idempotent: return unchanged when
    already in that state). Creates and renames go through a private `guardUniqueNames(Closure)`
    that catches `UniqueConstraintViolationException`, checks `nameTaken('name_en', …)` then
    `nameTaken('name_ar', …)` for the values being written, and throws
    `ValidationException::withMessages([<field> => __('validation.unique', ['attribute' => __('doctors::attributes.<field>')])])`
    (R5; same pattern as `StaffManagementService::guardUniqueEmail()`).
  - Depends: T014, T018

- [ ] T020 [P] [US1] Create `ListSpecialtiesRequest`, `StoreSpecialtyRequest`, `UpdateSpecialtyRequest` in `app/Modules/Doctors/Http/Requests/`
  - Scope:
    - `authorize()`: `viewAny` / `create` / `update` on `Specialty::class`
    - `prepareForValidation()` (store and update): clean `name_en` and `name_ar` with
      `ArabicName::clean()` when they are strings, so length and uniqueness see the stored value
    - List: `is_active` → `nullable`, `boolean`; method `isActive(): ?bool`
    - Store: `name_en`, `name_ar` → `required`, `string`, `min:1`, `max:100`,
      `Rule::unique('specialties', <column>)`
    - Update: same rules with `sometimes` instead of `required` and `->ignore($this->route('specialty'))`;
      an `after()` hook adding `fields` → `doctors::messages.nothing_to_update` when neither name was
      sent (as `UpdatePatientRequest`)
    - `attributes()` → `doctors::attributes.<key>`
  - Depends: T013

- [ ] T021 [P] [US1] Create `app/Modules/Doctors/Http/Resources/SpecialtyResource.php`
  - Scope: keys exactly `id`, `name_en`, `name_ar`, `is_active` (FR-004a). Docblock: the one
    specialty shape, used in the catalog and nested in doctors.
  - Depends: T007

- [ ] T022 [US1] Create `SpecialtyController` and `SpecialtyStatusController` in `app/Modules/Doctors/Http/Controllers/`; add the five routes in `app/Modules/Doctors/routes/api.php`
  - Scope:
    - `SpecialtyController`: `index(ListSpecialtiesRequest)` → collection (unpaginated);
      `store(StoreSpecialtyRequest)` → `201`; `update(UpdateSpecialtyRequest, int $specialty)` →
      `find` then `rename`
    - `SpecialtyStatusController`: `deactivate(int $specialty)` and `reactivate(int $specialty)`,
      each `Gate::authorize('<ability>', Specialty::class)` before `find` (as
      `StaffMemberStatusController`)
    - Routes: `specialties.index|store|update|deactivate|reactivate`, `{specialty}` in a
      `whereNumber('specialty')` group
  - Accept: T017 passes.
  - Verify:
    - `docker compose exec app php artisan test --filter=SpecialtyCatalogTest` → pass
    - `docker compose exec app php artisan route:list --path=api/v1/specialties` → five routes
  - Depends: T017, T019, T020, T021

**Checkpoint**: US1 complete — the specialty catalog can be maintained and listed.

---

## Phase 4: User Story 2 – Administrator creates and maintains doctor profiles (Priority: P1)

**Goal**: `POST /api/v1/doctors` and `PATCH /api/v1/doctors/{doctor}` create and update profiles
linked to doctor-role staff accounts.

**Independent Test**: Create a doctor-role account, create its profile with two specialties, then
replace one specialty and change the title.

### Tests for User Story 2 (write first, must fail)

- [ ] T023 [P] [US2] Write `tests/Feature/Doctors/CreateDoctorTest.php`
  - Scope (administrator unless stated):
    - active doctor-role account + `title` + two active specialties → `201`; `data` has every
      `Doctor` key from the contract; `name` equals the account name; `is_active` true; body
      contains no `email`, `role`, or `user_id`; one `doctors` row and two `doctor_specialty` rows
    - with `registration_number` `'A12345'` → stored; another doctor with `'a12345'` → `422` on
      `registration_number`; `'A-123'` and 31 characters → `422`
    - `user_id` of a receptionist, an administrator, an inactive doctor → `422` on `user_id` with
      `doctors::messages.not_an_active_doctor`; unknown `user_id` → `422`; account that already has
      a profile → `422` on `user_id`
    - `specialty_ids` missing, `[]`, duplicate ids, unknown id → `422`; a deactivated specialty →
      `422` on `specialty_ids` with `doctors::messages.specialty_inactive`; nothing written
    - `title` missing or 101 characters → `422` (100 passes); `'  Senior   Consultant '` stored as
      `'Senior Consultant'`
    - races (SC-003), validation bypassed through `app(DoctorService::class)->create([...])`: an
      account that already has a profile → `ValidationException` on `user_id`; a taken
      registration number → on `registration_number`; row counts unchanged
  - Verify: `docker compose exec app php artisan test --filter=CreateDoctorTest` → fails
  - Depends: T022

- [ ] T024 [P] [US2] Write `tests/Feature/Doctors/UpdateDoctorTest.php`
  - Scope (administrator unless stated):
    - change `title` only → `200`; specialties unchanged
    - `registration_number` set; `null` clears it; another doctor's number → `422`; its own number
      → `200`
    - `specialty_ids` replaces the set; a specialty already assigned and since deactivated may stay
      (`200`, shown with `is_active` false); a deactivated specialty not yet assigned → `422`;
      `[]` → `422`
    - `user_id` in the body is ignored (account unchanged); empty body → `422` with key `fields`;
      unknown doctor → `404`
  - Verify: `docker compose exec app php artisan test --filter=UpdateDoctorTest` → fails
  - Depends: T022

### Implementation for User Story 2

- [ ] T025 [US2] Create `app/Modules/Doctors/Repositories/Contracts/DoctorRepository.php` and `app/Modules/Doctors/Repositories/EloquentDoctorRepository.php`; bind them in the provider
  - Scope (US3 adds `paginate`):
    - `findById(int $id): ?Doctor` and `findByUserId(int $userId): ?Doctor` — both eager-load
      `user` and `specialties`
    - `create(array $attributes): Doctor`; `update(Doctor $doctor, array $attributes): Doctor`
    - `syncSpecialties(Doctor $doctor, array $specialtyIds): void` — `sync()` on the relation
    - `registrationNumberTaken(string $number): bool`
  - Depends: T016

- [ ] T026 [US2] Create `app/Modules/Doctors/Services/DoctorService.php` with `find()`, `create()`, `update()`
  - Scope (constructor: `DoctorRepository`, `SpecialtyRepository`, Staff's `StaffMemberRepository`):
    - `find(int $id): Doctor` → `DoctorNotFoundException`
    - `create(array $attributes): Doctor` — inside `DB::transaction`: load the account with
      `StaffMemberRepository::findById`; unless `is_active` and `Role::Doctor` →
      `ValidationException` on `user_id` with `doctors::messages.not_an_active_doctor` (R7);
      `ensureAssignable($specialtyIds, [])`; create; `syncSpecialties`; return reloaded
    - `update(Doctor $doctor, array $attributes): Doctor` — inside `DB::transaction`: when
      `specialty_ids` is sent, `ensureAssignable($ids, <current ids>)` then `syncSpecialties`;
      update the other sent fields (`title`, `registration_number`); never `user_id`
    - private `ensureAssignable(array $ids, array $currentIds)`: `SpecialtyRepository::lockByIds`;
      every id must be active or in `$currentIds`, else `ValidationException` on `specialty_ids`
      with `doctors::messages.specialty_inactive` (R6)
    - private `guardUniqueness(Closure)`: on `UniqueConstraintViolationException`, if
      `findByUserId` finds a profile for the account (create) → `422` `validation.unique` on
      `user_id`; else if `registrationNumberTaken` → on `registration_number` (R7)
    - class docblock referencing US2–US3 and R2, R6, R7
  - Depends: T014, T018, T025

- [ ] T027 [P] [US2] Create `StoreDoctorRequest` and `UpdateDoctorRequest` in `app/Modules/Doctors/Http/Requests/`
  - Scope (rules quoted from data-model.md):
    - `authorize()`: `create` / `update` on `Doctor::class`
    - `prepareForValidation()`: clean `title` with `ArabicName::clean()` when it is a string
    - Store:
      - `user_id` → `required`, `integer`, `Rule::exists('users', 'id')`, `Rule::unique('doctors', 'user_id')`
      - `title` → `required`, `string`, `min:1`, `max:100`
      - `registration_number` → `nullable`, `string`, `regex:/^[A-Za-z0-9]{1,30}$/`,
        `Rule::unique('doctors', 'registration_number')`
      - `specialty_ids` → `required`, `array`, `min:1`; `specialty_ids.*` → `integer`, `distinct`,
        `Rule::exists('specialties', 'id')`
    - Update: `title`, `registration_number`, `specialty_ids` (+ `.*`) with `sometimes`; unique
      registration number `->ignore($this->route('doctor'))`; no `user_id` rule (so it is not in
      `validated()`); `after()` hook → `fields` with `doctors::messages.nothing_to_update` when none
      of the three was sent
    - `attributes()` → `doctors::attributes.<key>`, `specialty_ids.*` → `specialty_id`
  - Depends: T013

- [ ] T028 [P] [US2] Create `app/Modules/Doctors/Http/Resources/DoctorResource.php`
  - Scope: keys exactly `id`, `name` (`$this->user->name`), `title`, `is_active`
    (`$this->isActive()`), `specialties` (`SpecialtyResource::collection`), `registration_number`,
    `created_at`, `updated_at` (ISO 8601). Never `user_id`, `email`, or `role` (FR-015, FR-016).
  - Depends: T008, T021

- [ ] T029 [US2] Create `app/Modules/Doctors/Http/Controllers/DoctorController.php` with `store()` and `update()`; add the routes in `app/Modules/Doctors/routes/api.php`
  - Scope: `store(StoreDoctorRequest)` → `DoctorResource` (`201`); `update(UpdateDoctorRequest, int $doctor)`
    → `find` then `update`. Routes `doctors.store` (`POST /doctors`) and `doctors.update`
    (`PATCH /doctors/{doctor}` in a `whereNumber('doctor')` group).
  - Accept: T023 and T024 pass.
  - Verify: `docker compose exec app php artisan test --filter="CreateDoctorTest|UpdateDoctorTest"` → pass
  - Depends: T023, T024, T026, T027, T028

**Checkpoint**: US2 complete — doctor profiles can be created and maintained.

---

## Phase 5: User Story 3 – Staff browse doctors by specialty (Priority: P2)

**Goal**: `GET /api/v1/doctors`, `GET /api/v1/doctors/{doctor}`, and `GET /api/v1/doctors/me`.

**Independent Test**: Create three profiles across two specialties; filter by one specialty and
search by part of a name; only the expected doctors appear.

### Tests for User Story 3 (write first, must fail)

- [ ] T030 [US3] Write `tests/Feature/Doctors/BrowseDoctorsTest.php`
  - Scope (receptionist unless stated; doctor and administrator also get `200` on reads):
    - `?specialty_id=` lists only doctors with it (including when the specialty is deactivated)
    - `?name=` matches part of the account name: case and repeated spaces ignored; `احمد` finds
      `أحمد` and `أحمد` finds `احمد`; `فاطمه` finds `فاطمة`; `مصطفي` finds `مصطفى`; `%` and `_`
      match literally
    - `?is_active=1` excludes a doctor whose account is deactivated and one whose role was changed
      to receptionist; `?is_active=0` lists both with `is_active` false; restoring the account
      brings the doctor back (SC-005)
    - filters combine with AND; ordered by name then id; `per_page` 101 → `422`; default 15
    - each list item has exactly `id`, `name`, `title`, `is_active`, `specialties` (no `email`,
      `user_id`, `registration_number`)
    - `GET /doctors/{id}` → the `Doctor` shape with `registration_number`; unknown → `404`
    - `GET /doctors/me` as the doctor → their profile; a doctor without a profile → `404`;
      receptionist → `403`
  - Verify: `docker compose exec app php artisan test --filter=BrowseDoctorsTest` → fails
  - Depends: T029

### Implementation for User Story 3

- [ ] T031 [US3] Add `paginate(array $filters, int $perPage): LengthAwarePaginator` to `DoctorRepository` and `EloquentDoctorRepository`
  - Scope (R4, R10):
    - join `users` on `users.id = doctors.user_id`; `select('doctors.*')`
    - `name`: `whereRaw(<expr> LIKE ?)` where `<expr>` is built from `ArabicName::VARIANTS`:
      `REGEXP_REPLACE(LOWER(TRIM(users.name)), '[[:space:]]+', ' ')` wrapped in one
      `REPLACE(…, '<from>', '<to>')` per variant, and the binding is
      `'%'.escapeLike(ArabicName::normalize($name)).'%'` (copy `escapeLike` from
      `EloquentPatientRepository`). Bind the variant letters as parameters, not string-concatenated.
    - `specialty_id`: `whereHas('specialties', …)`
    - `is_active` true: `users.is_active = 1 and users.role = 'doctor'`; false: the negation
    - order by `users.name`, then `doctors.id`; eager-load `user`, `specialties`; `withQueryString()`
  - Depends: T025

- [ ] T032 [US3] Add `list(array $filters, int $perPage)` and `findOwn(User $user)` to `app/Modules/Doctors/Services/DoctorService.php`
  - Scope: `list` → repository `paginate`; `findOwn` → `findByUserId($user->id)` or
    `DoctorNotFoundException`.
  - Depends: T026, T031

- [ ] T033 [P] [US3] Create `app/Modules/Doctors/Http/Requests/ListDoctorsRequest.php`
  - Scope: `authorize()` → `viewAny` on `Doctor::class`; rules: `name` `nullable|string|min:1|max:255`,
    `specialty_id` `nullable|integer|min:1`, `is_active` `nullable|boolean`, `page` `nullable|integer|min:1`,
    `per_page` `nullable|integer|min:1|max:100`; `filters()` (only sent, non-empty; `is_active` via
    `$this->boolean()`, `specialty_id` cast to int) and `perPage()` (default 15), as
    `ListPatientsRequest`; `attributes()` from `doctors::attributes`.
  - Depends: T013

- [ ] T034 [P] [US3] Create `app/Modules/Doctors/Http/Resources/DoctorSummaryResource.php`
  - Scope: keys exactly `id`, `name`, `title`, `is_active`, `specialties` (FR-015).
  - Depends: T008, T021

- [ ] T035 [US3] Add `index()`, `show()`, `me()` to `DoctorController`; add the routes
  - Scope: `index(ListDoctorsRequest)` → `DoctorSummaryResource::collection(...)`;
    `show(int $doctor)` → `Gate::authorize('view', Doctor::class)` then `find`;
    `me(Request $request)` → `Gate::authorize('viewOwn', Doctor::class)` then `findOwn($request->user())`.
    Routes `doctors.index`, `doctors.me` (`GET /doctors/me`, declared **before** the
    `whereNumber('doctor')` group), `doctors.show`.
  - Accept: T030 passes.
  - Verify:
    - `docker compose exec app php artisan test --filter=BrowseDoctorsTest` → pass
    - `docker compose exec app php artisan route:list --path=api/v1/doctors` → five routes
  - Depends: T030, T032, T033, T034

**Checkpoint**: US3 complete — staff can browse doctors by specialty, name, and status.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Authorization matrix, both locales, documentation, and verification.

- [ ] T036 [P] Write `tests/Feature/Doctors/DoctorsAuthorizationTest.php` (SC-004)
  - Scope: data provider over all ten endpoints with valid bodies:
    - receptionist and doctor × the seven change endpoints (specialty add, rename, deactivate,
      reactivate; doctor create, update — including a doctor updating **their own** profile) →
      `403` with `auth.forbidden`, and nothing changed
    - every role × the read endpoints → `200`, except `/doctors/me` → `403` for administrator and
      receptionist
    - no token × every endpoint → `401`
    - an administrator with a real token who is then deactivated → `401`
      (use `InteractsWithStaffTokens::usingToken()`)
    - receptionist with an invalid body on `POST /specialties` still gets `403`, not `422`
  - Verify: `docker compose exec app php artisan test --filter=DoctorsAuthorizationTest` → pass
  - Depends: T035

- [ ] T037 [P] Write `tests/Feature/Doctors/DoctorsMessagesTest.php` (FR-019, SC-006)
  - Scope: for `en` and `ar`: `404` specialty and doctor messages; `422` `name_en` duplicate whose
    message contains `doctors::attributes.name_en`; `not_an_active_doctor`; `specialty_inactive`;
    `nothing_to_update`; `403` → `auth.forbidden`; JSON keys identical across locales; specialty
    `name_en`/`name_ar` values identical across locales (FR-004a).
  - Verify: `docker compose exec app php artisan test --filter=DoctorsMessagesTest` → pass
  - Depends: T035

- [ ] T038 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: roadmap: 004 `Done` with the spec link, 005 `Next`. README: a short "Doctors and
    specialties" section after "Patients": administrators manage specialties and doctor profiles
    under `/api/v1/specialties` and `/api/v1/doctors`; all staff can browse; doctors fetch
    `/api/v1/doctors/me`; link `specs/004-doctors-specialties/contracts/doctors.openapi.yaml`.
  - Depends: T035

- [ ] T039 Run Pint (`vendor/bin/pint`) and the full suite (`tests/`)
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0
  - Depends: T003, T036, T037, T038

- [ ] T040 Run quickstart Q1–Q8 from `specs/004-doctors-specialties/quickstart.md` on the development stack
  - Scope: synthetic data only; Q7 runs against `clinic_testing` only, never the development
    database.
  - Accept: every Q-step's expected outcome is observed, including each Q7 list under 2 s.
  - Depends: T039

- [ ] T041 Record the results in `specs/004-doctors-specialties/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; report failures as
    failures (Constitution VIII).
  - Depends: T040

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 (the `ArabicName` move)
  comes first and must leave the suite at 294 passing.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: needs US1 (`SpecialtyRepository::lockByIds`, `SpecialtyResource`).
- **US3 (Phase 5)**: needs US2 (`DoctorRepository`, `DoctorService`, `DoctorController`).
- **Polish (Phase 6)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- T003 alongside T004–T006; T007 and T008 together; T009 and T010 together; T013, T014, T015 together.
- US1: T020 and T021 together.
- US2: tests T023 and T024 together; T027 and T028 together.
- US3: T033 and T034 together.
- Polish: T036, T037, T038 together.

---

## Parallel Example: User Story 2

```text
Task: "Write tests/Feature/Doctors/CreateDoctorTest.php"   (T023)
Task: "Write tests/Feature/Doctors/UpdateDoctorTest.php"   (T024)

Task: "StoreDoctorRequest + UpdateDoctorRequest"           (T027)
Task: "DoctorResource"                                     (T028)
```

## Parallel Example: Polish

```text
Task: "DoctorsAuthorizationTest"       (T036)
Task: "DoctorsMessagesTest"            (T037)
Task: "Roadmap and README updates"     (T038)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 US1 → **stop and validate**: an administrator maintains the catalog; other roles can
   only read it.

### Incremental Delivery

1. US1 → specialty catalog.
2. US2 → doctor profiles linked to staff accounts.
3. US3 → staff browse doctors by specialty, name, and status.
4. Polish → authorization matrix, both locales, docs, verification.

Following the project's convention, each phase is implemented on its own branch (e.g.
`004-phase-2-foundational`) and merged by pull request.

---

## Notes

- [P] = different files, no dependency on an unfinished task.
- Commit after each task (or a tightly related group), with the task ID in the message.
- Never weaken or delete a test to make a task pass; report failures as observed.
- Synthetic doctors and specialties only (factories); no real names or registration numbers.
