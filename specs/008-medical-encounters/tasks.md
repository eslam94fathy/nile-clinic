---
description: "Task list for feature 008: Medical Encounters"
---

# Tasks: Medical Encounters

**Input**: Design documents from `specs/008-medical-encounters/` — [plan.md](plan.md), [spec.md](spec.md),
[research.md](research.md), [data-model.md](data-model.md),
[contracts/encounters.openapi.yaml](contracts/encounters.openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Required (Constitution VIII and XI, and the plan's Testing Strategy). Each story's tests are
written first and must fail before its implementation, unless a task says otherwise.

**Organization**: Grouped by user story so each story can be implemented and verified on its own.
Phase order follows priority: US1 (P1), US2 (P1), US3 (P2), US4 (P2), US5 (P3).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: User story from spec.md (US1–US5)
- All `docker compose exec` commands run from the repository root with the stack up.
- Module root: `app/Modules/Encounters/`. Mirror `app/Modules/Queues/` for structure, docblock style,
  and naming; read the matching file there before writing each new one.
- Signed-in actors in tests: `Sanctum::actingAs(User::factory()->administrator()->create())` (or
  `receptionist()`/`doctor()`/`inactive()`), with `App\Modules\Staff\Models\User`; a doctor with a
  profile signs in as `$doctor->user`.
- **Fixed clock in tests**: every Feature test calls
  `$this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'))` in `setUp()`, and
  visits for "today" use `ClinicClock::today()`. **Exception**: the concurrency tests run child
  processes that do not see `travelTo`, so they use the real clinic date (feature 006 R9).
- **Visits in tests**: `QueueEntry::factory()` with its `withDoctor()`, `seen()`, and `left()` states
  (read `database/factories/QueueEntryFactory.php`). It defaults to queue number 1, so use
  `number(int $n)` for a second entry of the same doctor and day.
- **Synthetic data only** (Constitution XII). Clinical text in tests is made up, such as
  `SYNTHETIC-PLANTED-TEXT`.
- **Never log clinical content**: no new log lines, and no clinical text in exception messages.
- **Branch per phase**: create a new git branch for each phase from the previous one (for example
  `008-phase-2-foundational`, `008-phase-3-us1-record`).

## Command Verification Rule

Only commands already used in this project (Artisan `make:migration`/`migrate`/`route:list`/`tinker`/
`test`, `composer test`, Pint, `docker compose build`) are listed. If a command does not behave as
written, **stop and report**; don't substitute an unverified one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the starting point. No new dependencies (plan: Technical Context).

- [X] T001 Create branch `008-medical-encounters` and confirm the baseline
  - Scope: the branch starts from `main` once feature 007 is merged, or from the tip of
    `007-phase-8-polish` if the user chooses that. If neither is decided, **stop and ask**.
  - Verify:
    - `docker compose exec app php artisan migrate:status` → the fifteen existing migrations `Ran`
    - `docker compose exec app composer test` → 1039 passed (recorded in
      `specs/007-patient-queues/verification.md`)
  - Accept: if the count differs or anything fails, **stop and report** before continuing.

**Checkpoint**: Baseline recorded.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Stack trace protection (D4), the schema and encrypted models (D1, D2), the module skeleton,
translations, exceptions, and the access policy.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Write `tests/Feature/Encounters/EncounterPrivacyTest.php` with the stack trace case — failing first (FR-021, R6, approved D4)
  - Scope: assert `ini_get('zend.exception_ignore_args')` is `'1'`; call a closure with a string
    argument `SYNTHETIC-PLANTED-TEXT` that throws, and assert `getTraceAsString()` does not contain
    `SYNTHETIC`. More cases are added in T044.
  - Verify: `docker compose exec app php artisan test --filter=EncounterPrivacyTest` → fails

- [X] T003 Add `zend.exception_ignore_args = On` to `docker/php/conf.d/app.ini` and rebuild
  - Scope: one line, with a short comment that stack traces must not carry patient data (research R6).
  - Verify: `docker compose build app`, then `docker compose up -d app`; T002 passes.
  - Depends: T002

- [X] T004 Create the migrations `database/migrations/<timestamp>_create_encounters_table.php` and `<timestamp>_create_encounter_addenda_table.php` (data-model.md, approved D1, D2)
  - Scope: two `make:migration` calls, in this order; each with a one-line comment pointing to
    `specs/008-medical-encounters/data-model.md`. `encounters`, exactly:
    - `id()`; `foreignId('queue_entry_id')->constrained('queue_entries')->restrictOnDelete()`;
      `foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()`;
      `foreignId('patient_id')->constrained('patients')->restrictOnDelete()`
    - `date('date')`; `string('status', 20)` — "`draft` \| `finalized`"
    - `mediumText('chief_complaint')->nullable()`; `mediumText('examination_findings')->nullable()`;
      `mediumText('diagnosis')->nullable()`; `mediumText('treatment_plan')->nullable()`;
      `mediumText('prescriptions')->nullable()`
    - `timestamp('finalized_at')->nullable()`; `timestamps()`
    - `unique(['queue_entry_id'], 'encounters_queue_entry_unique')`
    - `index(['patient_id', 'status', 'date', 'id'], 'encounters_patient_history_index')`
    `encounter_addenda`, exactly:
    - `id()`; `foreignId('encounter_id')->constrained('encounters')->restrictOnDelete()`;
      `mediumText('text')`; `timestamp('created_at')->nullable()`
  - Verify: `docker compose exec app php artisan migrate` → both `DONE`.

- [X] T005 [P] Create `app/Modules/Encounters/Enums/EncounterStatus.php`
  - Scope: `enum EncounterStatus: string` with `Draft = 'draft'`, `Finalized = 'finalized'`;
    `static values(): list<string>`. Docblock: draft → finalized; finalized is final.

- [X] T006 Create `app/Modules/Encounters/Models/Encounter.php` and `app/Modules/Encounters/Models/EncounterAddendum.php` (R6, R7)
  - Scope (persistence mapping only, as `Queues\Models\QueueEntry`):
    - `Encounter` fillable: every column but `id` and the timestamps. Casts: `date` → `date`, `status`
      → `EncounterStatus`, `finalized_at` → `datetime`, the four text columns → `encrypted`,
      `prescriptions` → `encrypted:array`. `$hidden`: the same five clinical attributes. Relations:
      `queueEntry()`, `doctor()`, `patient()`, `addenda()` (ordered by `id`). `HasFactory`.
    - `EncounterAddendum`: `$table = 'encounter_addenda'`; `const UPDATED_AT = null`; fillable
      `encounter_id`, `text`; cast `text` → `encrypted`; `$hidden` `text`; relation `encounter()`.
      `HasFactory`.
  - Depends: T004, T005

- [X] T007 [P] Create `database/factories/EncounterFactory.php` and `database/factories/EncounterAddendumFactory.php` (synthetic only)
  - Scope: `EncounterFactory` defaults to an empty draft of a new `QueueEntry::factory()->seen()`,
    copying `doctor_id`, `patient_id`, and `date` from that entry. States: `forEntry(QueueEntry $e)`;
    `complete()` (all four text fields and one full prescription line, status still draft);
    `finalized()` (`complete()` plus status and `finalized_at`). `EncounterAddendumFactory`: synthetic
    `text`, for a `finalized()` encounter.
  - Depends: T006

- [X] T008 Write `tests/Feature/Encounters/EncounterPersistenceTest.php` (FR-002, FR-004, R6)
  - Scope: `RefreshDatabase`:
    - a second encounter for one queue entry → `UniqueConstraintViolationException`
    - deleting a queue entry, doctor, or patient referenced by an encounter, or an encounter with an
      addendum → `QueryException`
    - raw values (`DB::table`) of all five clinical columns and addendum `text` do not contain the
      planted text, and the model returns it decrypted
    - round trip of the worst cases, unchanged: `str_repeat("\u{1F600}", 10000)` in every text column
      and addendum, and 20 lines of 5 fields of 500 four-byte characters in `prescriptions`
    - `toArray()` of both models contains no clinical attribute
  - Verify: `docker compose exec app php artisan test --filter=EncounterPersistenceTest` → pass
  - Depends: T007

- [X] T009 Create `app/Modules/Encounters/Repositories/Contracts/EncounterRepository.php` and `app/Modules/Encounters/Repositories/EloquentEncounterRepository.php` with the core methods
  - Scope (more methods are added in later stories), each with a docblock:
    - `find(int $id): ?Encounter` — with `doctor.user`, `patient`, `addenda`
    - `lockById(int $id): ?Encounter` — `lockForUpdate()->find($id)`, no relations
    - `create(array $attributes): Encounter`
    - `update(Encounter $encounter, array $attributes): Encounter`
    - `existsForVisit(int $queueEntryId): bool`
  - Depends: T006

- [X] T010 Add `patientOnList` to `app/Modules/Queues/Repositories/Contracts/QueueRepository.php` and `app/Modules/Queues/Repositories/EloquentQueueRepository.php` (R4)
  - Scope: `patientOnList(int $doctorId, int $patientId, string $date): bool` — an entry of the patient
    with that doctor that date with status `waiting`, `with_doctor`, or `seen`. Plain `exists()`, as
    `patientActive`. Nothing else in the Queues module changes.

- [X] T011 Create the module skeleton: `app/Modules/Encounters/Providers/EncountersServiceProvider.php`, `app/Modules/Encounters/routes/api.php`, and register in `bootstrap/providers.php`
  - Scope: provider mirrors `QueuesServiceProvider`: `register()` binds `EncounterRepository` →
    `EloquentEncounterRepository`; `boot()` loads `routes/api.php` with `api` middleware and `api/v1`
    prefix and translations namespace `encounters` (the policy line is added in T014). Routes file:
    header comment plus an empty `Route::middleware('auth:sanctum')->group(...)`. Add
    `EncountersServiceProvider::class` after `QueuesServiceProvider::class`.
  - Verify: `docker compose exec app php artisan route:list --path=api` still lists every existing route.
  - Depends: T009

- [X] T012 [P] Create the translations `app/Modules/Encounters/lang/en/messages.php`, `lang/ar/messages.php`, `lang/en/attributes.php`, `lang/ar/attributes.php`
  - Scope: messages (`__('encounters::messages.<key>')`), en / ar:
    - `encounter_not_found`: "No medical record was found." / "لم يتم العثور على السجل الطبي."
    - `history_not_found`: "No medical records were found for this patient." / "لم يتم العثور على سجلات طبية لهذا المريض."
    - `visit_not_found`: "No visit was found." / "لم يتم العثور على الزيارة."
    - `visit_not_started`: "A record can start only when the patient is with the doctor or has been seen." / "لا يمكن بدء السجل إلا عندما يكون المريض مع الطبيب أو بعد الكشف عليه."
    - `already_started`: "This visit already has a medical record." / "هذه الزيارة لها سجل طبي بالفعل."
    - `not_editable`: "A finalized medical record cannot be changed." / "لا يمكن تعديل سجل طبي تم اعتماده."
    - `required_to_finalize`: "The :attribute is required to finalize this record." / "حقل :attribute مطلوب لاعتماد هذا السجل."
    - `not_finalized`: "Only a finalized medical record can have addenda." / "لا يمكن إضافة ملحق إلا إلى سجل طبي تم اعتماده."
    - attributes en / ar: `chief_complaint` "chief complaint" / "الشكوى الرئيسية"; `examination_findings`
      "examination findings" / "نتائج الفحص"; `diagnosis` "diagnosis" / "التشخيص"; `treatment_plan`
      "treatment plan" / "خطة العلاج"; `prescriptions` "prescriptions" / "الوصفات الطبية"; `medication`
      "medication" / "الدواء"; `dosage` "dosage" / "الجرعة"; `frequency` "frequency" / "عدد المرات";
      `duration` "duration" / "المدة"; `instructions` "instructions" / "التعليمات"; `text` "addendum
      text" / "نص الملحق"; `from` "from date" / "تاريخ البداية"; `to` "to date" / "تاريخ النهاية";
      `patient_id` "patient number" / "رقم المريض"; `record_status` "record status" / "حالة السجل";
      `encounter` "medical record" / "السجل الطبي"; `visit` "visit" / "الزيارة".
  - Verify: `docker compose exec app php artisan test --filter=TranslationParityTest` → pass

- [X] T013 [P] Create `app/Modules/Encounters/Exceptions/EncounterNotFoundException.php`, `VisitNotFoundException.php`, and `HistoryNotFoundException.php` (R4, R5)
  - Scope: exactly like `QueueEntryNotFoundException`: 404 `{message}` with
    `encounters::messages.encounter_not_found`, `visit_not_found`, and `history_not_found`; each with an
    empty `report()`.

- [X] T014 Write `tests/Feature/Encounters/EncountersPolicyTest.php`, then create `app/Modules/Encounters/Policies/EncounterPolicy.php` (FR-012–FR-014, R4)
  - Scope: inject `QueueRepository` and `DoctorRepository`. Abilities, all returning `bool`:
    - `access(User)` (class-level on `Encounter::class`): active doctor only
    - `view(User, Encounter)`: the actor's doctor profile (`findByUserId`; none → false) is the
      record's doctor → true; a draft → false; `patientOnList(doctor, patient, ClinicClock::today())`
    - `write(User, Encounter)`: `$encounter->doctor->user_id === $actor->id`
    Docblock: why `view` and `write` are record-level, and that a false `view` becomes the same 404 as
    an unknown id (research R4). Then add `Gate::policy(Encounter::class, EncounterPolicy::class)` to
    the provider's `boot()`.
  - Test: every role (active and inactive) × `access`; `view` for own draft, own finalized, another's
    draft, another's finalized with the patient `waiting`, `with_doctor`, `seen`, and `left` on the
    actor's list today, with no entry, and with an entry yesterday; `view` for a doctor without a
    profile; `write` for author and non-author.
  - Verify: `--filter=EncountersPolicyTest` → fails before the policy exists, passes after.
  - Depends: T007, T010, T011

- [X] T015 Run the Queues, Appointments, and Patients suites
  - Verify: `docker compose exec app php artisan test tests/Feature/Queues tests/Feature/Appointments tests/Feature/Patients` → all pass unchanged.
  - Depends: T003, T010, T011

**Checkpoint**: Schema, encrypted models, policy, and module skeleton exist; all earlier tests pass.

---

## Phase 3: User Story 1 — A doctor records the visit (Priority: P1) 🎯 MVP

**Goal**: `POST /doctors/me/visits/{visit}/encounter`, `GET` and `PUT /doctors/me/encounters/{encounter}`.

**Independent Test**: As a doctor with a patient with them, start a record, save clinical content, open
it again, and confirm the content is there and the record is a draft.

### Tests for User Story 1 (write first, must fail)

- [X] T016 [P] [US1] Write `tests/Feature/Encounters/StartEncounterTest.php` (US1-1, 3–7, FR-001, FR-002, FR-005)
  - Scope: clock fixed; doctor actor (`$doctor->user`).
    - start for a `with_doctor` entry and a `seen` entry → `201`; `data` is exactly the contract's
      `Encounter` keys: `status: draft`, `visit_id`, `date`, `doctor {id, name, title}`, `patient {id,
      full_name, sex, date_of_birth}` with no other patient keys, clinical fields null, `prescriptions:
      []`, `addenda: []`, `finalized_at: null`
    - a walk-in entry and an appointment entry behave the same
    - a `seen` entry dated yesterday → `201` (later-day start)
    - `waiting` and `left` → `422` `visit_not_started` on `visit`; nothing saved
    - another doctor's entry and an unknown id → `404` `visit_not_found`; nothing saved
    - second start → `422` `already_started` on `visit`; one row
    - the entry's status and the appointment's status are unchanged after start
    - `POST /doctors/me/queue/finish` for a visit with no record → `200`, and start afterwards → `201`
    - a doctor without a profile → `404`; administrator and receptionist → `403`, nothing saved
  - Verify: `--filter=StartEncounterTest` → fails

- [X] T017 [P] [US1] Write `tests/Feature/Encounters/SaveEncounterTest.php` (US1-2, FR-003, FR-004, FR-006, FR-009, R8)
  - Scope: clock fixed; author actor; draft from the factory.
    - `PUT` with all keys → `200` with the content, including Arabic text returned as written; `status`
      still `draft`; `last_saved_at` is set; `GET` returns the same
    - a second `PUT` replaces everything: omitted lines are gone, a null field is cleared
    - a partly filled draft is accepted (one text field and a line with only `medication`)
    - each of the five keys missing → `422` on that key, and the draft is unchanged
    - `max:10000` boundary: 10,000 characters in Arabic and in four-byte text → `200`; 10,001 → `422`
    - line fields: 500 → `200`, 501 → `422`; line without `medication` → `422` on
      `prescriptions.0.medication`; 20 lines → `200`, 21 → `422`; a line's unknown key is not stored
    - `GET` and `PUT` of another doctor's draft → `404` `encounter_not_found`, with a body identical to
      an unknown id's; the draft is unchanged
    - administrator and receptionist → `403`; a doctor with an invalid body on an unknown id → `422`
  - Verify: `--filter=SaveEncounterTest` → fails

### Implementation for User Story 1

- [X] T018 [US1] Create `app/Modules/Encounters/Services/EncounterService.php` with `start()`, `find()`, and `save()` (R2, R3, R8)
  - Scope: inject `EncounterRepository`, `QueueRepository`, `DoctorService`.
    - `start(User $user, int $visitId): Encounter`: `$doctorId = DoctorService::findOwn($user)->id`
      **before** the transaction (feature 007 T035: no plain read may precede the lock). Transaction:
      `QueueRepository::lockById($visitId)`; null or another doctor's → `VisitNotFoundException`; status
      not `with_doctor` or `seen` → `ValidationException(['visit' => visit_not_started])`;
      `existsForVisit` → `['visit' => already_started]`; `create` with `queue_entry_id`, `doctor_id`,
      `patient_id`, `date` from the entry, status `draft`. Return `find($id)`.
    - `find(int $id): Encounter`: repository `find`, or `EncounterNotFoundException`.
    - `save(int $id, array $content): Encounter`: transaction: `lockById`; null →
      `EncounterNotFoundException`; not draft → `['encounter' => not_editable]`; `update`. Return `find($id)`.
  - Depends: T009, T013

- [X] T019 [P] [US1] Create `app/Modules/Encounters/Http/Resources/EncounterResource.php` and `AddendumResource.php` (R10)
  - Scope: `EncounterResource` is exactly the contract's `Encounter`: `id`, `visit_id`
    (`queue_entry_id`), `status` (`->value`), `date` (`toDateString()`), `doctor` `{id, name:
    doctor->user->name, title}`, `patient` `{id, full_name, sex: ->value, date_of_birth}`, the four text
    fields, `prescriptions` (`?? []`), `addenda` (`AddendumResource::collection`), `started_at`
    (`created_at`), `last_saved_at` (`updated_at`), `finalized_at` (`toISOString()`, nullable).
    `AddendumResource`: `id`, `text`, `created_at`. Never the patient's national ID, address, phone, or
    emergency contact (FR-015).

- [X] T020 [P] [US1] Create `app/Modules/Encounters/Http/Requests/SaveEncounterRequest.php` (FR-003, FR-004, R8)
  - Scope: `authorize()` → `can('access', Encounter::class)`. Rules, from data-model.md:
    - `chief_complaint`, `examination_findings`, `diagnosis`, `treatment_plan`: `present`, `nullable`,
      `string`, `max:10000`
    - `prescriptions`: `present`, `array`, `max:20`; `prescriptions.*`: `array`
    - `prescriptions.*.medication`: `required`, `string`, `max:500`
    - `prescriptions.*.dosage`, `.frequency`, `.duration`, `.instructions`: `nullable`, `string`, `max:500`
    Attributes from `encounters::attributes`, including the `prescriptions.*.<field>` keys. `content()`
    returns the validated values with each line rebuilt with exactly the five keys, missing ones null.
    No custom message uses `:input` (R6).

- [X] T021 [US1] Create `app/Modules/Encounters/Http/Controllers/OwnVisitController.php` and `EncounterController.php`, and the routes (R4)
  - Scope:
    - `OwnVisitController::start(Request, int $visit)`: `Gate::authorize('access', Encounter::class)`,
      then `EncounterResource::make($service->start(...))` with status `201`.
    - `EncounterController` has a private `readable(int $id): Encounter`: `$service->find($id)`, then
      `Gate::denies('view', $encounter)` → `throw new EncounterNotFoundException`. Not
      `Response::denyAsNotFound` (research R4).
    - `show(Request, int $encounter)`: `Gate::authorize('access', Encounter::class)`, `readable`, Resource.
    - `update(SaveEncounterRequest, int $encounter)`: `readable`, `Gate::authorize('write', $found)`,
      `$service->save($id, $request->content())`, Resource.
    - Routes, inside `whereNumber('visit')` and `whereNumber('encounter')`:
      `POST /doctors/me/visits/{visit}/encounter` `encounters.start`;
      `GET /doctors/me/encounters/{encounter}` `encounters.show`;
      `PUT /doctors/me/encounters/{encounter}` `encounters.update`.
  - Accept: T016 and T017 pass; `route:list --path=doctors/me` shows the three.
  - Depends: T018, T019, T020

- [X] T022 [US1] Create `tests/Support/encounter-action.php` and `tests/Feature/Encounters/ConcurrentEncounterTest.php` `#[Group('concurrency')]` with the start case (SC-004, R3)
  - Scope: the script is like `tests/Support/queue-action.php`, with the same bootstrap and **exit 2
    unless** `DB::connection()->getDatabaseName() === 'clinic_testing'`. Arguments: `action actorId
    targetId startAt [text]`, where `action` is `start` (target = visit id), `save` (target = encounter
    id; complete content with `chief_complaint` = `text`), `finalize` (target = encounter id), or
    `addendum` (target = encounter id). Only `start` works until T026 and T042. It calls
    `EncounterService` directly and prints one JSON line: `{"result":"ok","id":N}`,
    `{"result":"refused","errors":{...}}` (a `ValidationException`), or
    `{"result":"error","class":"..."}` (the class only, so no text can leak); exit 0.
  - Test: `use DatabaseTruncation`, truncate again in `tearDown()`; no `travelTo`. One `seen` entry today;
    `Process::pool` of 8 `start` processes with the same `startAt` (`microtime(true) + 1.5`),
    `->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'clinic_testing'])`, `->path(base_path())`. Assert:
    one `ok`, seven `refused` on `visit`, one row. On any `error` line, fail with its class.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T021

**Checkpoint**: Doctors can start, save, and reopen a record, safely under concurrency (MVP).

---

## Phase 4: User Story 2 — A doctor finalizes the record (Priority: P1)

**Goal**: `POST /doctors/me/encounters/{encounter}/finalize`.

**Independent Test**: Finalize a complete draft, confirm the finalization time is recorded, then try to
save over it and confirm it is refused and the content is unchanged.

### Tests for User Story 2 (write first, must fail)

- [X] T023 [US2] Write `tests/Feature/Encounters/FinalizeEncounterTest.php` (US2-1…3, 5, 6, FR-007, FR-008, R8)
  - Scope: clock fixed; author actor.
    - a `complete()` draft → `200`, `status: finalized`, `finalized_at` now
    - a draft with a chief complaint, a diagnosis, and no lines → `200`
    - a line without `instructions` → `200`
    - no chief complaint, no diagnosis → `422` `required_to_finalize` on each, using the translated
      attribute; still a draft
    - line 1 (the second) without `dosage`, `frequency`, `duration` → `422` on `prescriptions.1.dosage`,
      `.frequency`, `.duration`; still a draft
    - finalize again, and `PUT` after finalizing → `422` `not_editable` on `encounter`; raw database
      values identical before and after
    - the entry's status and the appointment's status are unchanged, including an entry still `with_doctor`
    - another doctor's draft → `404`; another doctor's finalized record the actor may read → `403`, and
      `404` if they may not; administrator and receptionist → `403`
  - Verify: `--filter=FinalizeEncounterTest` → fails

### Implementation for User Story 2

- [X] T024 [US2] Add `finalize()` to `app/Modules/Encounters/Services/EncounterService.php` (R3, R8)
  - Scope: `finalize(int $id): Encounter`: transaction: `lockById`; null → `EncounterNotFoundException`;
    not draft → `['encounter' => not_editable]`; collect errors for blank `chief_complaint`, `diagnosis`,
    and each line's blank `dosage`, `frequency`, `duration`, keyed `prescriptions.N.<field>`, with
    `encounters::messages.required_to_finalize` and the attribute from `encounters::attributes`; any
    error → `ValidationException`; otherwise `update` with status `finalized` and `finalized_at now()`.
    Return `find($id)`.

- [X] T025 [US2] Add `finalize()` to `app/Modules/Encounters/Http/Controllers/EncounterController.php` and the route
  - Scope: `Gate::authorize('access', Encounter::class)`, `readable`, `Gate::authorize('write', $found)`,
    service, Resource. Route: `POST /doctors/me/encounters/{encounter}/finalize` `encounters.finalize`.
  - Accept: T023 passes.
  - Depends: T024

- [X] T026 [US2] Extend `tests/Feature/Encounters/ConcurrentEncounterTest.php` with the save-against-finalize case and enable `save` and `finalize` in `tests/Support/encounter-action.php` (US2-4, SC-004)
  - Scope: a `complete()` draft (real date) with chief complaint `BASE`; at the same `startAt`, one
    `finalize` and 6 `save` processes with texts `SAVE-1`…`SAVE-6`. Assert: `finalize` is `ok`; each
    `save` is `ok` or `refused` on `encounter`; the final `chief_complaint` is `BASE` or the text of a
    save that was `ok`, never of a refused one; a further `save` → `refused`, and the content is
    identical. Repeat the setup 5 times within the test.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T022, T025

**Checkpoint**: US1 + US2 — the minimum usable slice: records can be written and locked.

---

## Phase 5: User Story 3 — A doctor finds their visits and records (Priority: P2)

**Goal**: `GET /doctors/me/visits`.

**Independent Test**: Give a doctor three visits, one with no record, one with a draft, and one finalized.
Filter by each record status and confirm the expected visit is returned with no clinical content.

### Tests for User Story 3 (write first, must fail)

- [X] T027 [US3] Write `tests/Feature/Encounters/OwnVisitListTest.php` (US3-1…3, FR-016, FR-018, R9)
  - Scope: clock fixed; doctor D with visits: no record, draft, finalized (`seen` or `with_doctor`), plus
    a `waiting` and a `left` entry, plus another doctor's visit.
    - `GET /doctors/me/visits?from&to` → `200`, the three visits in date then queue order; each exactly
      the contract's `Visit` keys, `record_status` and `encounter_id` correct
    - `record_status=none`, `draft`, `finalized` → one each; `record_status=done` → `422`
    - `patient_id` filter → only that patient; an unknown id → empty page
    - `waiting`, `left`, and the other doctor's visit never appear
    - `from` or `to` missing → `422`; `to` before `from` → `422`; 92 days → `200`; 93 → `422`
    - `per_page=2` → two items, `per_page=101` → `422`
    - the JSON contains no planted clinical text and no clinical key
    - a doctor without a profile → `404`; administrator and receptionist → `403`
  - Verify: `--filter=OwnVisitListTest` → fails

### Implementation for User Story 3

- [X] T028 [US3] Add `paginateVisits` to `EncounterRepository` / `EloquentEncounterRepository` (R9)
  - Scope: `paginateVisits(int $doctorId, array $filters, int $perPage): LengthAwarePaginator<int,
    QueueEntry>`: `QueueEntry::query()`, `select('queue_entries.*', 'encounters.id as encounter_id',
    'encounters.status as encounter_status')`, left join `encounters` on `queue_entry_id`; the doctor;
    `date` between `from` and `to`; status in `with_doctor`, `seen`; optional `patient_id`;
    `record_status` `none` → `encounters.id` null, otherwise `encounters.status` =; ordered `date`,
    `order_minute`, `queue_number`; `with('patient')`; `withQueryString()`. **No clinical column is
    selected** (FR-018).

- [X] T029 [US3] Create `app/Modules/Encounters/Http/Requests/ListOwnVisitsRequest.php` and add `listVisits()` to `EncounterService`
  - Scope: request mirrors `ListOwnAppointmentsRequest`: `authorize()` → `can('access', Encounter::class)`;
    `from`, `to` required `date_format:Y-m-d`, `to` `after_or_equal:from`; `patient_id` nullable integer
    min 1; `record_status` nullable, in `none`, `draft`, `finalized`; `page` nullable integer min 1;
    `per_page` nullable integer 1–100; `after()` calls `ListAppointmentsRequest::validateRange` (at most
    92 days); attributes from `encounters::attributes`; `filters()`; `perPage()` default 15. Service:
    `listVisits(User $user, array $filters, int $perPage)` → `paginateVisits(findOwn($user)->id, ...)`.
  - Depends: T028

- [X] T030 [P] [US3] Create `app/Modules/Encounters/Http/Resources/VisitResource.php`
  - Scope: exactly the contract's `Visit`: `id`, `date`, `walk_in` (`appointment_id === null`),
    `arrived_at`, `called_at`, `finished_at` (`toISOString()`, nullable), `record_status`
    (`encounter_status ?? 'none'`), `encounter_id`, `patient` `{id, full_name, sex, date_of_birth}`.

- [X] T031 [US3] Add `index()` to `app/Modules/Encounters/Http/Controllers/OwnVisitController.php` and the route
  - Scope: `index(ListOwnVisitsRequest)` → `VisitResource::collection(...)`. Route:
    `GET /doctors/me/visits` `encounters.visits`.
  - Accept: T027 passes.
  - Depends: T029, T030

**Checkpoint**: Doctors can find visits still missing a finalized record.

---

## Phase 6: User Story 4 — A doctor reads a patient's earlier visits (Priority: P2)

**Goal**: `GET /doctors/me/patients/{patient}/encounters`, and opening another doctor's record under the
access rule.

**Independent Test**: Have other doctors finalize records for two patients, only one of whom is on the
reading doctor's list today. The reading doctor gets that patient's finalized records with no clinical
content in the list, and the other patient is reported as not found.

### Tests for User Story 4

- [X] T032 [P] [US4] Write `tests/Feature/Encounters/EncounterAccessTest.php` (US4-2, 4, 5, FR-012, FR-013, SC-003)
  - Scope: clock fixed; through `GET /doctors/me/encounters/{id}`. Another doctor's `finalized()` record
    of patient P with an addendum:
    - P `waiting`, `with_doctor`, or `seen` on the reader's list today → `200` with clinical content and
      the addendum
    - no entry, `left`, an undone entry (`DELETE /queue/{entry}` as a receptionist), an entry dated
      yesterday, and a `with_doctor` entry dated yesterday → `404`
    - access ends: `200` while `waiting`; after `POST /queue/{entry}/leave` → `404`; after travelling to
      the next day → `404`
    - another doctor's draft with P on the list → `404`
    - own draft and own finalized record with no list entry → `200`
    - the refused `404` body is **identical** to an unknown id's (`assertSame` on decoded JSON)
    - `PUT` by a reader → `403`, and `404` when not readable
  - Verify: `--filter=EncounterAccessTest` → **passes without new code** (`view` is in T014 and the route
    in T021). This test proves the rule end to end; if it fails, fix the policy, not the test.

- [X] T033 [P] [US4] Write `tests/Feature/Encounters/PatientHistoryTest.php` — failing first (US4-1, 3, FR-017, FR-018, R5)
  - Scope: clock fixed; the reader, and other doctors with specialties.
    - P on the reader's list today, finalized records from two doctors on three dates → `200`, newest
      first, each exactly `{id, date, doctor {id, name, specialties}}`; no planted text and no clinical
      key; other doctors' drafts and own drafts excluded
    - P on the list with no records → `200` empty
    - P not on the list, with the reader's own finalized record and another doctor's → only their own
    - P not on the list with nothing readable, and an unknown patient → `404` `history_not_found`, with
      identical bodies; `page=2` of a history that has records → `200` empty
    - `per_page=2` → two items, `per_page=101` → `422`
    - a doctor without a profile → `404`; administrator and receptionist → `403`
  - Verify: `--filter=PatientHistoryTest` → fails

### Implementation for User Story 4

- [X] T034 [US4] Add `paginateHistory` to `EncounterRepository` / `EloquentEncounterRepository` (R5)
  - Scope: `paginateHistory(int $patientId, ?int $onlyDoctorId, int $perPage): LengthAwarePaginator<int,
    Encounter>`: `select('id', 'doctor_id', 'date')`; the patient; status `finalized`; optional doctor;
    `orderByDesc('date')`, `orderByDesc('id')`; `with('doctor.user', 'doctor.specialties')`;
    `withQueryString()`. **No clinical column is selected** (FR-018).

- [X] T035 [US4] Create `app/Modules/Encounters/Http/Requests/ListPatientEncountersRequest.php` and add `history()` to `EncounterService` (R5)
  - Scope: request: `authorize()` → `can('access', Encounter::class)`; `page`, `per_page` as T029;
    `perPage()` default 15. Service: `history(User $user, int $patientId, int $perPage)`: `findOwn`;
    `$onList = patientOnList($doctorId, $patientId, ClinicClock::today())`; `paginateHistory($patientId,
    $onList ? null : $doctorId, $perPage)`; when `! $onList` and `total() === 0` →
    `HistoryNotFoundException`. No separate patient lookup (FR-013).
  - Depends: T034

- [X] T036 [P] [US4] Create `app/Modules/Encounters/Http/Resources/EncounterHistoryItemResource.php` (R10)
  - Scope: exactly the contract's `EncounterHistoryItem`: `id`, `date`, `doctor` `{id, name:
    doctor->user->name, specialties: SpecialtyResource::collection}`. Not `DoctorSummaryResource`, which
    adds fields US4-1 does not allow.

- [X] T037 [US4] Create `app/Modules/Encounters/Http/Controllers/PatientEncounterController.php` and the route
  - Scope: `index(ListPatientEncountersRequest, int $patient)` →
    `EncounterHistoryItemResource::collection(...)`. Route, inside `whereNumber('patient')`:
    `GET /doctors/me/patients/{patient}/encounters` `encounters.history`.
  - Accept: T033 passes.
  - Depends: T035, T036

**Checkpoint**: The access rule is proven end to end, and doctors can read a patient's history.

---

## Phase 7: User Story 5 — A doctor corrects a finalized record (Priority: P3)

**Goal**: `POST /doctors/me/encounters/{encounter}/addenda`.

**Independent Test**: Add an addendum to a finalized record as its author; the original content is
unchanged, the addendum appears when the record is opened, and another doctor cannot add one.

### Tests for User Story 5 (write first, must fail)

- [X] T038 [US5] Write `tests/Feature/Encounters/AddendumTest.php` (US5-1…5, FR-011)
  - Scope: clock fixed; author actor; a `finalized()` record.
    - add → `201`, exactly `{id, text, created_at}`; raw clinical columns and `updated_at` unchanged
    - two addenda appear oldest first when opened, and to a permitted reader
    - a draft → `422` `not_finalized` on `encounter`
    - no `text`, blank `text`, and 10,001 characters → `422`; 10,000 → `201`
    - another doctor who may read → `403`; who may not → `404`; administrator and receptionist → `403`
    - no route changes or deletes an addendum: `PUT` and `DELETE` on
      `/doctors/me/encounters/{id}/addenda/{addendum}` → `404` or `405`
  - Verify: `--filter=AddendumTest` → fails

### Implementation for User Story 5

- [X] T039 [US5] Add `addAddendum` to `EncounterRepository` / `EloquentEncounterRepository` (R8)
  - Scope: `addAddendum(Encounter $encounter, string $text): EncounterAddendum`. There is no method to
    update or delete an addendum, and none may be added (FR-011).

- [X] T040 [US5] Create `app/Modules/Encounters/Http/Requests/AddAddendumRequest.php` and add `addAddendum()` to `EncounterService`
  - Scope: request: `authorize()` → `can('access', Encounter::class)`; `text` `required`, `string`,
    `max:10000`; attribute from `encounters::attributes`. Service: `addAddendum(Encounter $encounter,
    string $text): EncounterAddendum`: not finalized → `['encounter' => not_finalized]`; otherwise insert.
    A plain insert: finalized never reverts, so no lock is needed (R3).
  - Depends: T039

- [X] T041 [US5] Add `addAddendum()` to `app/Modules/Encounters/Http/Controllers/EncounterController.php` and the route
  - Scope: `readable`, `Gate::authorize('write', $found)`, service, `AddendumResource` with status `201`.
    Route: `POST /doctors/me/encounters/{encounter}/addenda` `encounters.addenda`.
  - Accept: T038 passes.
  - Depends: T040

- [X] T042 [US5] Extend `tests/Feature/Encounters/ConcurrentEncounterTest.php` with the addendum case and enable `addendum` in `tests/Support/encounter-action.php` (US5-6)
  - Scope: a `finalized()` record (real date); 4 `addendum` processes at the same `startAt` → four `ok`,
    four rows, and the clinical columns unchanged.
  - Verify: `docker compose exec app php artisan test --group=concurrency` → pass; run it 3 times.
  - Depends: T026, T041

**Checkpoint**: All five stories done.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T043 [P] Write `tests/Feature/Encounters/EncountersAuthorizationTest.php` (SC-003)
  - Scope: data providers over all seven endpoints with valid input (clock fixed): administrator and
    receptionist × every endpoint → `403` (`auth.forbidden`) and no row changed; doctor × every endpoint →
    success code; no token × every endpoint → `401`; a doctor with a real token then deactivated → `401`
    (`InteractsWithStaffTokens::usingToken()`); a receptionist with an invalid body or an unknown id →
    `403`, not `422` or `404`; existing queue, appointment, and patient responses contain no record data.

- [ ] T044 [P] Extend `tests/Feature/Encounters/EncounterPrivacyTest.php` with the planted-text cases (SC-002, FR-021)
  - Scope: plant `SYNTHETIC-PLANTED-TEXT` in every clinical field, a prescription line, and an addendum.
    Absent from:
    - `GET /doctors/me/visits` and the patient history, and the same with `DB::listen`: no query in
      either selects a clinical column
    - every validation error: a `PUT` with planted text over a limit, and an over-long addendum
    - a forced failure: bind a fake `EncounterRepository` whose `update` throws `RuntimeException`, send a
      `PUT` with planted text → `500`; point the log channel to a temporary file with `config()`, and
      neither the response nor that file contains the text
    - raw values of the `encounters` and `encounter_addenda` tables

- [ ] T045 [P] Write `tests/Feature/Encounters/EncountersMessagesTest.php` (FR-024, SC-006)
  - Scope: for `en` and `ar`: `encounter_not_found`, `history_not_found`, `visit_not_found`,
    `visit_not_started`, `already_started`, `not_editable`, `required_to_finalize` with its translated
    attribute, `not_finalized`, a translated attribute name in a `required` message, a `max` message on
    `prescriptions.0.dosage`, `403` → `auth.forbidden`; JSON keys identical across locales; `status` and
    `record_status` values identical across locales; Arabic clinical text returned unchanged under `en`.

- [ ] T046 [P] Update `docs/roadmap.md` and `README.md`
  - Scope: Roadmap: 008 `Done` with the spec link and depends on `003, 004, 006, 007`; 009 `Next`.
    README: a short "Medical encounters" section after "Patient queues": the seven endpoints, doctors
    only; one record per visit; draft, finalized, and addenda; the access rule; clinical content is
    encrypted and never in lists or logs; link the contract. In "Environment", a warning: **losing
    `APP_KEY` permanently destroys medical records**, so back it up separately, and rotate only with
    `APP_PREVIOUS_KEYS`. Note that `docker compose build app` is needed after pulling this feature.
  - Verify: `postman spec lint specs/008-medical-encounters/contracts/encounters.openapi.yaml --issueType=syntax` → no issues.

- [ ] T047 Run Pint and the full suite
  - Verify:
    - `docker compose exec app vendor/bin/pint --test` → exit 0 (if not, run `vendor/bin/pint`, then re-run tests)
    - `docker compose exec app composer test` → all pass, exit 0, count above the T001 baseline
    - `docker compose exec app php artisan test --group=concurrency` → pass
  - Depends: T043, T044, T045, T046

- [ ] T048 Measure SC-005 on `clinic_testing` only
  - Scope: as feature 007's SC-004: in a transaction rolled back afterwards, 500 factory doctors, and
    500,000 queue entries and encounters bulk-inserted (one short fixed synthetic ciphertext; the clinical
    columns need not be realistic because the list queries do not select them); `paginateVisits` and
    `paginateHistory`, three runs each. Refuse any database but `clinic_testing`. Target: under 2 s. A miss
    is reported, not hidden.
  - Depends: T047

- [ ] T049 Run quickstart Q1–Q9 from `specs/008-medical-encounters/quickstart.md` on the development stack
  - Scope: synthetic data only. Record what was run.
  - Accept: every Q-step's expected outcome is observed.
  - Depends: T047

- [ ] T050 Record the results in `specs/008-medical-encounters/verification.md`
  - Scope: per Q-step and SC-005: date, command, observed output summary, pass/fail; failures reported as
    failures (Constitution VIII). Postman collection update is a separate follow-up (as for 005, 006).
  - Depends: T048, T049

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none. Needs the decision on where the branch starts.
- **Foundational (Phase 2)**: depends on Setup; blocks every story. T002 → T003; T004 → T006 →
  T007/T009; T009 → T011; T007, T010, T011 → T014; T015 last.
- **US1 (Phase 3)**: after Foundational. T022 needs T021.
- **US2 (Phase 4)**: after US1 (the save route and resource). T026 extends T022.
- **US3 (Phase 5)**: after Foundational; factories provide the records, so it does not need US2's route.
  Scheduled after US2 by priority.
- **US4 (Phase 6)**: T032 needs US1's `GET` and `PUT` routes. The history (T033–T037) needs only
  Foundational.
- **US5 (Phase 7)**: after US1 (`readable`, `AddendumResource`). T042 extends T026.
- **Polish (Phase 8)**: after all stories.

### Within Each Story

Tests first and failing → repository → service → request/resource → controller and routes.

### Parallel Opportunities

- Foundational: T005 alongside T004; T007, T012, T013 together after T006.
- US1: T016 and T017 together; T019 and T020 alongside T018.
- US3: T030 alongside T028–T029.
- US4: T032 and T033 together; T036 alongside T034–T035.
- Polish: T043, T044, T045, T046 together.

---

## Parallel Example: User Story 1

```text
Task: "StartEncounterTest"                (T016)
Task: "SaveEncounterTest"                 (T017)

Task: "EncounterResource, AddendumResource" (T019)
Task: "SaveEncounterRequest"              (T020)
```

## Parallel Example: Polish

```text
Task: "EncountersAuthorizationTest"       (T043)
Task: "EncounterPrivacyTest cases"        (T044)
Task: "EncountersMessagesTest"            (T045)
Task: "Roadmap and README"                (T046)
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (encrypted schema, policy, stack trace setting).
2. Phase 3 US1 → **stop and validate**: a doctor can start, save, and reopen a record; one record per
   visit under concurrency, three runs in a row.

### Incremental Delivery

1. US1 → write a record (MVP).
2. US2 → lock it. Together with US1, this is the minimum usable slice.
3. US3 → find visits missing a record.
4. US4 → read a patient's history under the access rule.
5. US5 → correct a finalized record with addenda.
6. Polish → authorization matrix, privacy, messages, docs, measurement, quickstart, verification.
