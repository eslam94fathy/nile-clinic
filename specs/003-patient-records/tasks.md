---
description: "Task list for feature 003: Patient Registration and Records"
---

# Tasks: Patient Registration and Records

**Input**: Design documents from `specs/003-patient-records/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/patients.openapi.yaml](contracts/patients.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US3)
- All `docker compose exec` commands run from the repository root with the stack up.
  PowerShell users: replace `$?`/`echo $?` with `$LASTEXITCODE`.
- Module root: `app/Modules/Patients/`. Mirror the existing `app/Modules/Staff/` module for
  structure, docblock style, and naming; read the matching Staff file before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->receptionist()->create())` (or
  `administrator()`/`doctor()`), with `App\Modules\Staff\Models\User`, unless a task says to use a
  real token.

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/
`tinker`/`test`, `composer test`, Pint) are listed. If a command does not behave as written,
**stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Confirm the baseline on branch `003-patient-records` (no file changes)
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the four existing migrations `Ran`
    - `docker compose exec app composer test` → 173 passed (count observed on 2026-10-28)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, model, name/phone normalization, module skeleton, translations, and the
policy that every story needs.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T002 Create the `patients` migration in `database/migrations/<timestamp>_create_patients_table.php`
  - Scope: `docker compose exec app php artisan make:migration create_patients_table`, then exactly
    the columns in data-model.md, with a one-line comment pointing to
    `specs/003-patient-records/data-model.md` (as the `users` migration does):
    - `id` bigint unsigned auto-increment primary key ("the patient number", R2)
    - `full_name` `varchar(255)` not null
    - `name_search` `varchar(255)` not null
    - `date_of_birth` `date` not null
    - `sex` `varchar(10)` not null
    - `phone` `varchar(16)` not null
    - `national_id` `char(14)` nullable, **unique index** `patients_national_id_unique`
    - `address` `varchar(500)` nullable
    - `emergency_contact_name` `varchar(255)` nullable
    - `emergency_contact_relationship` `varchar(50)` nullable
    - `emergency_contact_phone` `varchar(16)` nullable
    - `timestamps()`
    - indexes `patients_phone_index (phone)` and `patients_name_search_index (name_search)`
    - No foreign keys, no soft deletes (FR-008).
  - Accept: matches data-model.md; `down()` drops the table.
  - Verify: covered by T009

- [ ] T003 [P] Create the sex enum in `app/Modules/Patients/Enums/Sex.php`
  - Scope: string-backed enum `Sex`: `Male = 'male'`, `Female = 'female'`. No other cases (FR-001).

- [ ] T004 [P] Write the failing unit test `tests/Unit/Patients/ArabicNameTest.php` (R3, SC-003)
  - Scope: extends `PHPUnit\Framework\TestCase` (pure function, no app). Data-provider cases for
    `ArabicName::normalize()`:
    - `'  أحمد   محمود '` → `'احمد محمود'`; `'إيمان'` → `'ايمان'`; `'آمال'` → `'امال'`
    - `'فاطمة'` → `'فاطمه'`; `'مصطفى'` → `'مصطفي'`
    - `'Mostafa  ALI'` → `'mostafa ali'`; `'احمد'` unchanged
    - and `ArabicName::clean('  أحمد   محمود ')` → `'أحمد محمود'` (spaces only; letters kept)
  - Verify: `docker compose exec app php artisan test --filter=ArabicNameTest` → fails (class missing)

- [ ] T005 Create `app/Modules/Patients/Support/ArabicName.php`
  - Scope: `final class ArabicName` with two static methods, the only definitions (R3):
    - `clean(string $name): string` — trim; collapse runs of whitespace (`/\s+/u`) to one space.
    - `normalize(string $name): string` — `clean()`, then `mb_strtolower()`, then replace
      `أ` `إ` `آ` → `ا`, `ة` → `ه`, `ى` → `ي` (`strtr` with a constant map).
  - Accept: T004 passes.
  - Verify: `docker compose exec app php artisan test --filter=ArabicNameTest` → pass
  - Depends: T004

- [ ] T006 [P] Create `app/Modules/Patients/Support/PhoneNumber.php` (R4)
  - Scope: `final class PhoneNumber`:
    - `public const PATTERN = '/^\+?[0-9]{8,15}$/';`
    - `normalize(mixed $value): mixed` — if `$value` is a string, remove spaces, dashes, and
      parentheses (`/[\s\-()]/u`); otherwise return it unchanged (so validation reports the type).
  - Accept: `normalize('010-0000 0001')` → `'01000000001'`; `normalize('(+20) 100')` → `'+20100'`.

- [ ] T007 Create the model `app/Modules/Patients/Models/Patient.php`
  - Scope:
    - `class Patient extends Model` using `HasFactory`; `newFactory()` returns `PatientFactory::new()`
    - `$fillable = ['full_name', 'date_of_birth', 'sex', 'phone', 'national_id', 'address',
      'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone']`
      (`name_search` is **not** fillable)
    - `$hidden = ['name_search']`
    - casts: `date_of_birth` → `date`, `sex` → `Sex::class`
    - `fullName(): Attribute` with a setter returning
      `['full_name' => ArabicName::clean($value), 'name_search' => ArabicName::normalize($value)]`,
      so the two columns cannot drift (R3)
    - No query scopes or business workflows (Constitution III).
  - Accept: `new Patient(['full_name' => ' فاطمة  علي '])` has `full_name` `'فاطمة علي'` and
    `name_search` `'فاطمه علي'`.
  - Depends: T002, T003, T005

- [ ] T008 [P] Create the factory `database/factories/PatientFactory.php` (synthetic only, R12)
  - Scope:
    - Model `App\Modules\Patients\Models\Patient`
    - `full_name`: half the time `fake('ar_EG')->firstName().' '.fake('ar_EG')->lastName()`, otherwise
      `fake()->firstName().' '.fake()->lastName()` (never `name()`, which adds titles)
    - `date_of_birth`: between 90 years ago and today; `sex`: random `Sex` case;
      `phone`: `'01'.fake()->numerify('#########')`; `national_id`, `address`, emergency contact: `null`
    - states: `withNationalId()` (`fake()->unique()->numerify('2#############')`, 14 digits),
      `withAddress()` (`fake()->streetAddress()`), `withEmergencyContact()` (name, relationship
      from `['father', 'mother', 'brother', 'sister', 'spouse']`, phone in the same `01…` shape)
  - Accept: no real people's data; every generated value passes the data-model.md rules.
  - Depends: T007

- [ ] T009 Run the migration and confirm the suite still passes
  - Verify:
    - `docker compose exec app php artisan migrate` → `create_patients_table` `DONE`
    - `docker compose exec app php artisan tinker --execute="echo App\Modules\Patients\Models\Patient::factory()->withNationalId()->withEmergencyContact()->make()->toJson();"` → JSON without `name_search`
    - `docker compose exec app composer test` → 173 + the ArabicNameTest cases pass
  - Depends: T002, T007, T008

- [ ] T010 Create the module skeleton: `app/Modules/Patients/Providers/PatientsServiceProvider.php`, `app/Modules/Patients/routes/api.php`, `bootstrap/providers.php`
  - Scope:
    - Provider `boot()`: load `routes/api.php` with `api` middleware and `api/v1` prefix (as
      `StaffServiceProvider` does); `loadTranslationsFrom(__DIR__.'/../lang', 'patients')`.
    - `register()` empty for now (US1 adds the repository binding).
    - `routes/api.php`: the comment header and an empty `Route::middleware('auth:sanctum')->group(...)`
      with a `whereNumber('patient')` note; routes are added per story.
    - Append `PatientsServiceProvider::class` to `bootstrap/providers.php` after `StaffServiceProvider`.
  - Verify: `docker compose exec app php artisan route:list --path=api/v1/patients` → no routes yet, no error
  - Depends: T009

- [ ] T011 [P] Add module translations in `app/Modules/Patients/lang/en/` and `app/Modules/Patients/lang/ar/` (R11)
  - Scope: two files per locale, same keys in both:
    - `messages.php`:
      - `not_found` — en `No patient was found.`; ar `لم يتم العثور على المريض.`
      - `nothing_to_update` — en `Send at least one field to change.`; ar `أرسل حقلاً واحداً على الأقل لتعديله.`
    - `attributes.php` (flat keys; no dots inside keys, because Laravel reads dots as nesting):
      - `full_name` — `full name` / `الاسم الكامل`
      - `date_of_birth` — `date of birth` / `تاريخ الميلاد`
      - `sex` — `sex` / `النوع`
      - `phone` — `phone number` / `رقم الهاتف`
      - `national_id` — `national ID` / `الرقم القومي`
      - `address` — `address` / `العنوان`
      - `emergency_contact` — `emergency contact` / `جهة الاتصال في الطوارئ`
      - `emergency_contact_name` — `emergency contact name` / `اسم جهة الاتصال في الطوارئ`
      - `emergency_contact_relationship` — `relationship` / `صلة القرابة`
      - `emergency_contact_phone` — `emergency contact phone` / `هاتف جهة الاتصال في الطوارئ`
      - `name` — `name` / `الاسم`
      - `id` — `patient number` / `رقم المريض`
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass
  - Depends: T010

- [ ] T012 [P] Create `app/Modules/Patients/Exceptions/PatientNotFoundException.php`
  - Scope: copy the shape of `StaffMemberNotFoundException`: `render()` →
    `response()->json(['message' => __('patients::messages.not_found')], 404)`; empty `report()`
    (client error, never logged).
  - Depends: T011

- [ ] T013 [P] Write the failing policy test `tests/Feature/Patients/PatientPolicyTest.php` (FR-014, FR-015, R10)
  - Scope: data provider over roles (`administrator`, `receptionist`, `doctor`) × abilities
    (`viewAny`, `view`, `create`, `update`), checking
    `Gate::forUser($actor)->allows($ability, Patient::class)`: true for administrator and
    receptionist, false for doctor. An inactive administrator and an inactive receptionist are
    refused every ability.
  - Verify: `docker compose exec app php artisan test --filter=PatientPolicyTest` → fails
  - Depends: T009

- [ ] T014 Create `app/Modules/Patients/Policies/PatientPolicy.php` and register it in `app/Modules/Patients/Providers/PatientsServiceProvider.php`
  - Scope: four class-level abilities, each `(User $actor): bool` → `$actor->is_active &&
    in_array($actor->role, [Role::Administrator, Role::Receptionist], true)`, through one private
    helper. Class docblock: doctors are refused every ability before any lookup (clarification Q1),
    so they cannot probe which ids exist. `boot()`: `Gate::policy(Patient::class, PatientPolicy::class)`.
  - Accept: T013 passes.
  - Verify: `docker compose exec app php artisan test --filter=PatientPolicyTest` → pass
  - Depends: T010, T013

**Checkpoint**: Foundation ready — table, model, normalization, translations, and policy exist.

---

## Phase 3: User Story 1 – Receptionist registers a new patient (Priority: P1) 🎯 MVP

**Goal**: `POST /api/v1/patients` registers a patient and returns the full record with its patient
number.

**Independent Test**: As a receptionist, register a patient with only the required details,
receive `data.id`, and find the row in the database.

### Tests for User Story 1 (write first, must fail)

- [ ] T015 [US1] Write `tests/Feature/Patients/RegisterPatientTest.php`
  - Scope (receptionist unless stated):
    - required fields only → `201`; `data` has every `Patient` key from the contract with
      `national_id`, `address`, `emergency_contact` null; `full_name` with extra spaces is stored
      cleaned; `phone` `'010-0000 0001'` returned as `'01000000001'`; `name_search` absent
    - all fields including `emergency_contact` object → stored and returned nested
    - administrator also gets `201`
    - validation (`422`, error on the named field, nothing created): each required field missing;
      `sex` `'other'`; `date_of_birth` tomorrow (today passes) and 131 years ago (130 years ago
      passes); `phone` with 7 digits and 16 digits (8 and 15 pass); `national_id` with 13 digits,
      15 digits, letters, and first digit `1`; `address` of 501 characters; `emergency_contact`
      `{}` and with `phone` missing
    - `national_id` already used by another patient → `422` on `national_id`
    - race (SC-006): create a patient with a national ID directly through the factory, then call
      `app(PatientService::class)->register([...same national_id...])` (validation bypassed) →
      `ValidationException` with an error on `national_id`; still one row with that ID
  - Verify: `docker compose exec app php artisan test --filter=RegisterPatientTest` → fails
  - Depends: T014

### Implementation for User Story 1

- [ ] T016 [US1] Create `app/Modules/Patients/Repositories/Contracts/PatientRepository.php` and `app/Modules/Patients/Repositories/EloquentPatientRepository.php`; bind them in `app/Modules/Patients/Providers/PatientsServiceProvider.php`
  - Scope: `findById(int $id): ?Patient` and `create(array $attributes): Patient` only (US2 and
    US3 add methods). Eloquent only here. `register()`:
    `$this->app->bind(PatientRepository::class, EloquentPatientRepository::class)`.
  - Depends: T014

- [ ] T017 [US1] Create `app/Modules/Patients/Services/PatientService.php` with `register()` and `find()`
  - Scope:
    - `find(int $id): Patient` → repository or `throw new PatientNotFoundException`
    - `register(array $attributes): Patient` → map the input to columns with a private
      `toColumns(array $attributes): array`: copy the scalar fields; when the `emergency_contact`
      key is present, set the three `emergency_contact_*` columns from it, or all three to `null`
      when it is `null` (R9)
    - wrap the write in a private `guardUniqueNationalId(Closure $write)` that turns
      `UniqueConstraintViolationException` into `ValidationException::withMessages(['national_id' =>
      __('validation.unique', ['attribute' => __('patients::attributes.national_id')])])` — same
      pattern as `StaffManagementService::guardUniqueEmail()` (R5)
    - class docblock referencing US1–US3 and research R5, R9
  - Depends: T012, T016

- [ ] T018 [P] [US1] Create `app/Modules/Patients/Support/PatientRules.php` and `app/Modules/Patients/Http/Requests/StorePatientRequest.php`
  - Scope:
    - `PatientRules` (like `Staff/Support/PasswordRules.php`): `fields(): array` returning the rule
      list below, `attributes(): array` returning the translated attribute names, and
      `normalizePhones(array $input): array` applying `PhoneNumber::normalize()` to `phone` and,
      when `emergency_contact` is an array, `emergency_contact.phone`. US3's update request reuses
      all three.
    - `authorize()` → `$this->user()->can('create', Patient::class)`
    - `prepareForValidation()` → `$this->merge(PatientRules::normalizePhones($this->all()))`
    - `rules()` → `PatientRules::fields()` plus the create-only unique rule on `national_id`.
      The fields (quoted from data-model.md):
      - `full_name` → `required`, `string`, `max:255`
      - `date_of_birth` → `required`, `date_format:Y-m-d`, `before_or_equal:today`,
        `after_or_equal:` today − 130 years (`now()->subYears(130)->toDateString()`)
      - `sex` → `required`, `Rule::enum(Sex::class)`
      - `phone` → `required`, `string`, `regex:` `PhoneNumber::PATTERN`
      - `national_id` → `nullable`, `string`, `regex:/^[23][0-9]{13}$/` (the request adds
        `Rule::unique('patients', 'national_id')`)
      - `address` → `nullable`, `string`, `max:500`
      - `emergency_contact` → `nullable`, `array:name,relationship,phone`,
        `required_array_keys:name,relationship,phone`
      - `emergency_contact.name` → `required_with:emergency_contact`, `string`, `max:255`
      - `emergency_contact.relationship` → `required_with:emergency_contact`, `string`, `max:50`
      - `emergency_contact.phone` → `required_with:emergency_contact`, `string`, `regex:` `PhoneNumber::PATTERN`
    - `attributes()` → `PatientRules::attributes()`: every rule key →
      `__('patients::attributes.<key>')`, with `emergency_contact.name|relationship|phone` mapped
      to the `emergency_contact_*` keys
  - Depends: T006, T011

- [ ] T019 [P] [US1] Create `app/Modules/Patients/Http/Resources/PatientResource.php`
  - Scope: docblock "The full record; returned only for a single patient (FR-013)". Keys exactly:
    `id`, `full_name`, `sex` (`->value`), `date_of_birth` (`->toDateString()`), `phone`,
    `national_id`, `address`, `emergency_contact` (`null` when `emergency_contact_name` is null,
    otherwise `{name, relationship, phone}`), `created_at`, `updated_at` (ISO 8601, as
    `StaffMemberResource`). Never `name_search`.
  - Depends: T007

- [ ] T020 [US1] Add `store()` to `app/Modules/Patients/Http/Controllers/PatientController.php` and the route in `app/Modules/Patients/routes/api.php`
  - Scope: constructor-injected `PatientService`; `store(StorePatientRequest $request): PatientResource`
    → `PatientResource::make($this->patients->register($request->validated()))` (Laravel returns
    `201` for a newly created model). Route: `Route::post('/patients', …)->name('patients.store')`
    inside the `auth:sanctum` group.
  - Accept: T015 passes.
  - Verify:
    - `docker compose exec app php artisan test --filter=RegisterPatientTest` → pass
    - `docker compose exec app php artisan route:list --path=api/v1/patients` → `POST api/v1/patients`
  - Depends: T015, T017, T018, T019

**Checkpoint**: US1 complete — patients can be registered.

---

## Phase 4: User Story 2 – Staff find a patient and view their record (Priority: P1)

**Goal**: `GET /api/v1/patients` lists and searches with the five-field summary;
`GET /api/v1/patients/{patient}` shows the full record.

**Independent Test**: Register three synthetic patients, search by part of a name (including an
Arabic variant), by phone, by patient number, and by national ID; each finds the expected patient
and every list item has exactly five keys.

### Tests for User Story 2 (write first, must fail)

- [ ] T021 [P] [US2] Write `tests/Feature/Patients/SearchPatientsTest.php`
  - Scope (receptionist; patients from the factory):
    - no filters → all patients, ordered by normalized name then id, default 15 per page
    - `name`: part of a name (middle of the string) matches; `احمد` finds `أحمد`, `إيمان` is found by
      `ايمان`, `فاطمه` finds `فاطمة`, `مصطفي` finds `مصطفى`, and each in the opposite direction
      (SC-003); Latin case ignored; `_` and `%` match only literally (a search for `_` returns
      nothing when no name contains `_`)
    - `phone` `'010 0000 0001'` returns every patient sharing that number
    - `national_id` and `id` exact
    - two filters combine with AND
    - no match → `200` with empty `data`
    - `per_page=2` pages correctly; `per_page=101` → `422`; malformed `phone` or `national_id`
      filter → `422`
    - every item in `data` has exactly the keys `id`, `full_name`, `sex`, `date_of_birth`, `phone`
      (`assertEqualsCanonicalizing` on `array_keys`), even for patients with national ID, address,
      and emergency contact (SC-004)
  - Verify: `docker compose exec app php artisan test --filter=SearchPatientsTest` → fails
  - Depends: T020

- [ ] T022 [P] [US2] Write `tests/Feature/Patients/ShowPatientTest.php`
  - Scope: receptionist and administrator get `200` with the full `PatientResource` shape,
    including `national_id`, `address`, and nested `emergency_contact`; unknown id → `404` with
    `patients::messages.not_found`; non-numeric id → `404` (route constraint).
  - Verify: `docker compose exec app php artisan test --filter=ShowPatientTest` → fails
  - Depends: T020

### Implementation for User Story 2

- [ ] T023 [US2] Add `paginate()` to `app/Modules/Patients/Repositories/Contracts/PatientRepository.php` and `app/Modules/Patients/Repositories/EloquentPatientRepository.php`
  - Scope: `paginate(array $filters, int $perPage): LengthAwarePaginator` with optional keys:
    - `name` → `where('name_search', 'like', '%'.$escaped.'%')`, where `$escaped` is
      `ArabicName::normalize($name)` with `\`, `%`, `_` escaped by a backslash (R3)
    - `phone`, `national_id`, `id` → exact `where`
    - order by `name_search`, then `id`; `->paginate($perPage)->withQueryString()`
    - docblock listing the filter keys and that absent keys are not applied
  - Depends: T016

- [ ] T024 [US2] Add `list()` to `app/Modules/Patients/Services/PatientService.php`
  - Scope: `list(array $filters, int $perPage): LengthAwarePaginator` → repository `paginate()`
    (signature used by quickstart Q7).
  - Depends: T017, T023

- [ ] T025 [P] [US2] Create `app/Modules/Patients/Http/Requests/ListPatientsRequest.php`
  - Scope:
    - `authorize()` → `$this->user()->can('viewAny', Patient::class)`
    - `prepareForValidation()`: `PhoneNumber::normalize()` on `phone`
    - `rules()`: `name` → `nullable`, `string`, `min:1`, `max:255`; `phone` → `nullable`, `string`,
      `regex:` `PhoneNumber::PATTERN`; `national_id` → `nullable`, `string`,
      `regex:/^[23][0-9]{13}$/`; `id` → `nullable`, `integer`, `min:1`; `page` → `nullable`,
      `integer`, `min:1`; `per_page` → `nullable`, `integer`, `min:1`, `max:100`
    - `filters(): array` → the validated `name`, `phone`, `national_id`, `id` that are filled;
      `perPage(): int` → validated `per_page` or `15` (as `ListStaffRequest`)
    - `attributes()` from `patients::attributes`
  - Depends: T006, T011

- [ ] T026 [P] [US2] Create `app/Modules/Patients/Http/Resources/PatientSummaryResource.php`
  - Scope: docblock "List and search shape (FR-013): never national ID, address, or emergency
    contact". Keys exactly: `id`, `full_name`, `sex` (`->value`), `date_of_birth`
    (`->toDateString()`), `phone`.
  - Depends: T007

- [ ] T027 [US2] Add `index()` and `show()` to `app/Modules/Patients/Http/Controllers/PatientController.php` and their routes in `app/Modules/Patients/routes/api.php`
  - Scope:
    - `index(ListPatientsRequest $request): AnonymousResourceCollection` →
      `PatientSummaryResource::collection($this->patients->list($request->filters(), $request->perPage()))`
    - `show(int $patient): PatientResource` → `Gate::authorize('view', Patient::class)`, then
      `PatientResource::make($this->patients->find($patient))` (as `StaffMemberController::show`)
    - routes: `GET /patients` (`patients.index`); `GET /patients/{patient}` (`patients.show`)
      inside `Route::whereNumber('patient')->group(...)`
  - Accept: T021 and T022 pass; T015 still passes.
  - Verify: `docker compose exec app php artisan test --filter='SearchPatientsTest|ShowPatientTest|RegisterPatientTest'` → pass
  - Depends: T021, T022, T024, T025, T026

**Checkpoint**: US2 complete — staff can find and open patient records.

---

## Phase 5: User Story 3 – Staff update a patient's details (Priority: P2)

**Goal**: `PATCH /api/v1/patients/{patient}` changes any sent field, including clearing optional
ones, without changing the patient number.

**Independent Test**: As a receptionist, change a patient's phone and address, open the record,
and see the new values with the same `id`.

### Tests for User Story 3 (write first, must fail)

- [ ] T028 [US3] Write `tests/Feature/Patients/UpdatePatientTest.php`
  - Scope (receptionist unless stated):
    - change only `phone` → `200`, other fields unchanged, `id` unchanged, `updated_at` advanced
      (use `$this->travel(1)->minutes()`)
    - change `full_name` → `name_search` updated too (search by the new name finds it)
    - `national_id: null`, `address: null`, `emergency_contact: null` → each cleared (all three
      emergency columns null)
    - set `emergency_contact` on a patient without one → stored
    - keep the patient's own `national_id` (send the same value) → `200`
    - another patient's `national_id` → `422` on `national_id`, nothing changed
    - invalid values (`phone` `'12'`, future `date_of_birth`) → `422`, nothing changed
    - empty body `{}` and a body with only unknown keys → `422` with error key `fields` and
      `patients::messages.nothing_to_update`
    - attempt to send `id` or `name_search` → ignored (not in validated data); `id` unchanged
    - administrator also gets `200`; unknown id → `404`
  - Verify: `docker compose exec app php artisan test --filter=UpdatePatientTest` → fails
  - Depends: T027

### Implementation for User Story 3

- [ ] T029 [US3] Add `update()` to `app/Modules/Patients/Repositories/Contracts/PatientRepository.php` and `app/Modules/Patients/Repositories/EloquentPatientRepository.php`
  - Scope: `update(Patient $patient, array $attributes): Patient` → `fill()->save()`, return the model.
  - Depends: T016

- [ ] T030 [US3] Add `update()` to `app/Modules/Patients/Services/PatientService.php`
  - Scope: `update(Patient $patient, array $attributes): Patient` → `toColumns()` then repository
    `update()`, inside `guardUniqueNationalId()`.
  - Depends: T017, T029

- [ ] T031 [P] [US3] Create `app/Modules/Patients/Http/Requests/UpdatePatientRequest.php`
  - Scope:
    - `authorize()` → `$this->user()->can('update', Patient::class)`
    - `prepareForValidation()` and `attributes()` from `PatientRules`, as in `StorePatientRequest`
    - `rules()`: `PatientRules::fields()` with `sometimes` prepended to each top-level field's
      rules (required fields keep `required`, so sending `null` for them fails); nested
      `emergency_contact.*` rules unchanged; `national_id` adds
      `Rule::unique('patients', 'national_id')->ignore((int) $this->route('patient'))`
    - `after()`: when none of `full_name`, `date_of_birth`, `sex`, `phone`, `national_id`, `address`,
      `emergency_contact` is present in the input (`$this->hasAny([...])` is false), add the error
      `fields` → `__('patients::messages.nothing_to_update')`
  - Depends: T018

- [ ] T032 [US3] Add `update()` to `app/Modules/Patients/Http/Controllers/PatientController.php` and the route in `app/Modules/Patients/routes/api.php`
  - Scope: `update(UpdatePatientRequest $request, int $patient): PatientResource` →
    `PatientResource::make($this->patients->update($this->patients->find($patient), $request->validated()))`.
    Route: `PATCH /patients/{patient}` (`patients.update`) inside the `whereNumber('patient')` group.
  - Accept: T028 passes; T015, T021, T022 still pass.
  - Verify: `docker compose exec app php artisan test --filter=Patients` → pass
  - Depends: T028, T030, T031

**Checkpoint**: US3 complete — all four endpoints exist.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Patient data out of logs (D3), the endpoint authorization matrix, both locales,
documentation, and verification.

- [ ] T033 [P] Write the failing test `tests/Feature/Infrastructure/QueryErrorLoggingTest.php` (D3, R7, FR-017)
  - Scope: `Log::spy()`; build
    `new QueryException('mysql', 'insert into patients (full_name, national_id) values (?, ?)', ['Synthetic Name', '29001010100001'], new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '29001010100001'"))`
    and call `report($e)`. Assert exactly one `error` log call whose message and context, JSON-
    encoded, contain the placeholder SQL and contain neither `Synthetic Name` nor `29001010100001`.
  - Verify: `docker compose exec app php artisan test --filter=QueryErrorLoggingTest` → fails
  - Depends: T032

- [ ] T034 Report `QueryException` without bound values in `bootstrap/app.php` (approved D3)
  - Scope: in `withExceptions`, add with a comment citing research R7:
    `$exceptions->report(function (QueryException $e) { Log::error('Database query failed.', ['connection' => $e->getConnectionName(), 'sql' => $e->getSql(), 'sqlstate' => $e->errorInfo[0] ?? null, 'driver_code' => $e->errorInfo[1] ?? null]); })->stop();`
    Do **not** log `$e->getMessage()` or the previous exception's message: MySQL error text can
    contain values (for example `Duplicate entry '…'`). If `getConnectionName()` or `->stop()` is
    not available in Laravel 13.38, **stop and report**.
  - Accept: T033 passes; full suite still passes.
  - Verify: `docker compose exec app php artisan test --filter=QueryErrorLoggingTest` → pass
  - Depends: T033

- [ ] T035 [P] Write `tests/Feature/Patients/PatientAuthorizationTest.php` (SC-005)
  - Scope: endpoints `list`, `register`, `show`, `update` (with valid bodies):
    - doctor × each → `403` with `auth.forbidden`, and the patient row and count unchanged
    - administrator and receptionist × each → `2xx`
    - no token × each → `401`
    - a receptionist with a real token (`$user->createToken('t')->plainTextToken`) who is then
      deactivated (`is_active` false) → `401` on `list` (use `InteractsWithStaffTokens::usingToken()`)
    - doctor with an invalid body on `register` still gets `403`, not `422` (authorization first)
  - Verify: `docker compose exec app php artisan test --filter=PatientAuthorizationTest` → pass
  - Depends: T032

- [ ] T036 [P] Write `tests/Feature/Patients/PatientMessagesTest.php` (FR-016, SC-007)
  - Scope: for `en` and `ar` (`Accept-Language`): `404` → `patients::messages.not_found`; `422` on
    `phone` whose message contains the translated attribute `patients::attributes.phone`; `422`
    `nothing_to_update`; `403` (doctor) → `auth.forbidden`; JSON keys identical across locales.
  - Verify: `docker compose exec app php artisan test --filter=PatientMessagesTest` → pass
  - Depends: T032

- [ ] T037 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: roadmap: 003 `Done` with the spec link, 004 `Next`. README: a short "Patients" section
    after "Signing in": administrators and receptionists register, search, view, and update
    patients under `/api/v1/patients`; doctors have no patient access yet; name search ignores
    Arabic letter variants; link `specs/003-patient-records/contracts/patients.openapi.yaml`.

- [ ] T038 Run Pint (`vendor/bin/pint`) and the full suite (`tests/`)
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0
  - Depends: T034, T035, T036, T037

- [ ] T039 Run quickstart Q1–Q8 from `specs/003-patient-records/quickstart.md` on the development stack
  - Scope: synthetic data only; Q7 runs against `clinic_testing` only, never the development
    database.
  - Accept: every Q-step's expected outcome is observed, including both Q7 searches under 2 s.
  - Depends: T038

- [ ] T040 Record the results in `specs/003-patient-records/verification.md`
  - Scope: per Q-step: date, command, observed output summary, pass/fail; report failures as
    failures (Constitution VIII).
  - Depends: T039

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; blocks every story.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: needs US1 (registered patients come from the factory, but the controller,
  service, and repository files are created in US1).
- **US3 (Phase 5)**: needs US2 (`find()` and the `whereNumber` route group) and reuses the US1
  request rules.
- **Polish (Phase 6)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- T003, T004, T006 together; T011, T012, T013 together.
- US1: T018 and T019 together (after T016/T017 start).
- US2: tests T021 and T022 together; T025 and T026 together.
- Polish: T033, T035, T036, T037 together.

---

## Parallel Example: User Story 2

```text
Task: "Write tests/Feature/Patients/SearchPatientsTest.php"   (T021)
Task: "Write tests/Feature/Patients/ShowPatientTest.php"      (T022)

Task: "ListPatientsRequest"                                   (T025)
Task: "PatientSummaryResource"                                (T026)
```

## Parallel Example: Polish

```text
Task: "QueryErrorLoggingTest"          (T033)
Task: "PatientAuthorizationTest"       (T035)
Task: "PatientMessagesTest"            (T036)
Task: "Roadmap and README updates"     (T037)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 US1 → **stop and validate**: a receptionist registers a patient; a doctor is refused.

### Incremental Delivery

1. US1 → patients can be registered.
2. US2 → staff find returning patients and open records.
3. US3 → records can be corrected and kept current.
4. Polish → logs free of patient data, authorization matrix, both locales, docs, verification.

Following the project's convention, each phase is implemented on its own branch (e.g.
`003-phase-2-foundational`) and merged by pull request.

---

## Notes

- [P] = different files, no dependency on an unfinished task.
- Commit after each task (or a tightly related group), with the task ID in the message.
- Never weaken or delete a test to make a task pass; report failures as observed.
- Synthetic patients only (factory); no real names, phones, or national IDs anywhere.
