# Research: Audit Logs

**Feature**: 011-audit-logs | **Date**: 2026-11-05

Facts below were checked on 2026-11-05 against `origin/main` at `476e76a` and the running stack: Laravel Framework 13.38.0,
PHP 8.5.12, and MySQL 9.7.4. Checks changed no data. Spec clarifications Q1 and Q2 were answered on 2026-11-05 (spec,
Clarifications). Items marked *(approved — Dn)* were approved by the user on 2026-11-05 and are listed in the plan's Decisions table.

## R1 — New `Audit` module and one search endpoint *(approved — D1)*

- **Decision**: A new module, `app/Modules/Audit/`, with one endpoint, `GET /audit-entries`, and a service that other modules
  call to record. Filters are query parameters.
- **Rationale**: All the spec's questions (by record, by patient, by staff member) are filters on one list with one response
  shape (FR-009). There is deliberately no write, update, or delete route (FR-014).
- **Alternatives considered**: Nested routes such as `/patients/{id}/audit-entries` repeat one query under several URLs and
  leave entries about removed records with no parent to nest under.

## R2 — How actions are recorded *(approved — D2)*

- **Decision**: Each service calls `AuditTrail` explicitly, inside its own transaction, naming the business action.
- **Rationale**:
  - FR-007 requires a change and its entry to succeed or fail together, which means the same transaction.
  - The services already own the transactions and are where the business action is known (docs/architecture.md).
  - Forty-four explicit calls are easy to read and to test one by one.
- **Alternatives considered**:
  - *Route middleware*: runs after the transaction has committed, so it breaks FR-007. It also cannot see which fields changed.
  - *Model observers*: `DoctorFee` is written with `upsert` and schedule periods with `delete`, which fire no observers. Observers
    also see columns rather than actions, so a check-in looks like two unrelated updates, and they never see reads.
  - *Domain events*: an indirection with one listener, adding complexity with no benefit here (Constitution VIII).

## R3 — Services that need a transaction *(approved — D3)*

- **Finding**: These write without a transaction today, so their entry could not fail together with the change:
  `PatientService::{register,update}`; `StaffManagementService::{create,update (the non-demotion path),reactivate}`;
  `AuthenticationService::{signIn (token issue),signOut}`; `SpecialtyService::{create,rename,deactivate,reactivate}`;
  `ScheduleService::clear`; `TimeOffService::{record,remove}`; `FeeService::set`; `EncounterService::addAddendum`.
- **Decision**: Wrap each one in `DB::transaction`, keeping any uniqueness guard on the outside, as `DoctorService::create` does,
  so a duplicate still becomes the same validation error.
- **Rationale**: This is the least change that meets FR-007. The services' rules and responses are untouched (FR-015).

## R4 — Knowing what changed

- **Measured** (in memory, with no database writes):
  - An `encrypted` column reassigned the same text, and `encrypted:array` the same array, are not dirty; different text is.
  - A `decimal:2` column assigned `200` over `200.00` is not dirty.
  - `getChanges()` and `getPrevious()` both exist in this Laravel version.
  - All nine repository `update` methods use `fill()->save()` or `$model->update()` on the model (read in each repository), so changes are
    visible afterwards.
- **Decision**: `AuditChanges` builds an entry's details from the saved model: field names from `getChanges()`, or from the attributes
  of a newly created model. Values are read only for allowed fields (R5). `getChanges()` holds ciphertext for encrypted columns, but
  only its keys are used.
- **Not visible on a model, so handled in the service**:
  - `FeeService::set` uses `upsert`: read the old amount first, and record nothing when it is the same.
  - `ScheduleService::replace` deletes and re-creates periods: compare the old and new schedule, and record nothing when they are the same. A new schedule may have no periods
    (`SetScheduleRequest` allows an empty array) and is inserted before the model is read, so `lockOrCreateForDoctor` marks the model as created when it really inserted the row.
  - `DoctorService::update` syncs specialties: use the result of the sync to add `specialty_ids` to the fields.
  - `ScheduleService::clear` is idempotent: record only when a schedule existed.
  - `QueueService::undo` deletes the entry: record its identifiers before deleting.

## R5 — Which values may be kept *(FR-006, clarification Q2)*

- **Decision**: One allow-list per record type, in `AuditChanges`. Anything not listed keeps its name only, including fields added later.

| Record type | Before and after values kept for |
|-------------|----------------------------------|
| `staff_member` | `role`, `is_active` |
| `specialty` | `is_active` |
| `appointment` | `status`, `date`, `start_minute`, `end_minute`, `doctor_id` |
| `visit` | `status`, `doctor_id` |
| `encounter` | `status` |
| `charge` | `status`, `fee`, `discount`, `amount_paid` |
| `doctor` (fee, set explicitly) | `fee` |
| `patient`, `time_off`, and any other | none |

- **Rationale**: Default-deny means a mistake loses detail, not privacy. Reasons (`discount_reason`, `cancel_reason`, a refund's `reason`,
  `cancellation_reason`) are free text that may name a patient, so they keep their names only.
- **Left out of field names**: `id`, columns ending in `_at` or `_by`, and derived columns such as `name_search`. The entry already says who and when, and these would otherwise make a repeat
  look like a change.

## R6 — Who acted and from where

- **Decision**: `AuditTrail` reads the actor from the authenticated user and the address from the current request. An explicit actor is
  passed only for sign-in, where nobody is authenticated yet. `actor_type` is `staff`, `system`, or `unknown`.
- **Rationale**:
  - No service signature changes: `PatientService` has no actor parameter today.
  - `auth:sanctum` makes its guard the default for the request, so the lookup is the same everywhere, although `config/auth.php` defaults to `web`.
  - `staff:create-admin` calls the same `StaffManagementService::create` as the API (checked). With no authenticated user the entry is
    `system` with no address, so the command itself needs no change.
- **Failed sign-ins**: An existing account is recorded as both actor and subject, so filtering by that staff member finds it, with the outcome
  `refused`. That account is the one targeted, not necessarily the person who tried; the contract and README say so. For an email matching no account, `actor_type` is `unknown`, and neither the text nor the password is stored (FR-008).
- **Sign-in limit** (`AuthenticationService`, 002 FR-007): 5 attempts per email and 20 per address each minute. Stopped attempts throw before the
  password check and are not recorded (spec, Edge Cases).

## R7 — Recording refused openings *(approved — D4)*

- **Finding**: Refusal happens in three places: `Gate::authorize` in `EncounterController`, `ListPatientEncountersRequest::authorize`, which
  runs before the controller, and `EncounterNotFoundException` or `HistoryNotFoundException` deliberately matching an unknown id (008 FR-013).
- **Decision**: One route middleware, `RecordRefusedOpening`, on exactly the two routes `encounters.show` and `encounters.history`. After the response
  is built, if its status is `403` or `404`, it asks `EncounterService` whether the record exists and records the attempt if so.
- **Rationale**:
  - One place covers all three refusal paths, and it cannot change the response, satisfying FR-005 and 008 FR-013.
  - Validation still runs after authorization, so a receptionist with a bad `per_page` still gets `403`. Moving the check into the controller would have changed this to `422`.
  - A `422` is not recorded, and a `404` for an unknown id finds no record, so neither creates an entry (US2 scenario 3).
- **Existence checks**: `patientIdOfEncounter(id)` selects `patient_id` only, and `patientHasFinalizedRecord(id)` selects no content. `EncounterPrivacyTest` forbids
  clinical columns in listing queries, and these stay clear of them.
- **Failure handling**: Recording a refusal catches and reports any error, so the response stays as 008 requires. This differs from opening and changing, where failure stops
  the action (FR-007), because here nothing is being disclosed.
- **Scope**: Opening only. Refused saves, finalizations, and addenda are not recorded (FR-005).
- **Alternatives considered**: `failedAuthorization()` plus `Gate::denies` branches in the controller reach the same result in three places.

## R8 — Recording openings

- **Decision**: New methods `PatientService::open` and `EncounterService::open` find the record and write the entry, and `EncounterService::history` records once
  a page is about to be returned. The shared `find` methods stay unchanged, because other modules and write paths call them.
- **Rationale**: Only the `show` routes count as an opening (FR-004). No transaction is needed: an entry is the only write, and if it fails the record is not returned
  (FR-007, US4 scenario 3).
- **Updates that change nothing**: `PatientService::update` returns the whole record even when nothing changed, so re-sending one known field reads the rest, and it records `patient.opened`. An empty body is already refused by feature 003. Found by a security review after implementation. `EncounterService::save` is not affected: it replaces all five fields, so a no-op returns only text the author just sent, and only the author may write to that draft, which cannot have addenda.
- **History**: Recorded on every page requested, and when a doctor's list is empty but the request succeeds (`200`). Subject is the patient.
- **Trade-off, timing**: A refused doctor's request does one more insert when a record exists, which in principle could reveal existence through timing, despite 008 FR-013.
  It is one small insert against network noise, and the request already needed lookups. Accepted and noted in the plan's risks.

## R9 — Paging *(approved — D5)*

- **Finding**: Offset paging (`paginate`), used by every other list, has two problems here. Each search itself adds an entry (FR-011), so with newest-first order page 2 repeats the end
  of page 1, which breaks FR-010. It also runs `COUNT(*)` on each request, which grows with the trail.
- **Decision**: Cursor paging by descending `id`, with `per_page` 1–100, default 15, as elsewhere. The response has `links` and `meta` from Laravel's cursor paginator, without `total`.
  This is the one list that differs from the convention.
- **Rationale**: `id` always increases and never repeats, so order is stable under new inserts, including entries within the same second, with no total to count.
- **Alternatives considered**: Offset paging with a `before_id` snapshot adds a parameter and keeps the count.

## R10 — Table and indexes *(approved — D6)*

- **Decision**: One table, `audit_entries` ([data-model.md](data-model.md)). `subject_type` and `subject_id` have no foreign key. `actor_id` and `patient_id` do, with `restrict`.
- **Rationale**:
  - Entries must outlive removed records, such as an undone check-in or removed time off (FR-014), and one column cannot reference nine tables. This departs from "foreign keys on all
    relations" and is justified in the plan's Complexity Tracking.
  - Accounts and patients are never deleted (002, 003; no such route exists), so `restrict` protects entries and changes nothing today.
- **Locking**: The new foreign keys take a shared lock on the acting user's row and the patient's row. Transactions that insert visits, charges, or appointments already take these. Transactions
  that only update, such as saving an encounter, cancelling, or setting a discount, take them **for the first time**. Tracing the main lock paths found no cycle, because the entry is written last and
  nothing waits on those transactions while holding the user or patient row. This is reasoning, and `ConcurrentAuditTest` tests it. The existing child scripts in `tests/Support/` never authenticate, so
  `actor_id` is null there and they cannot exercise the user row. The concurrency test group must still pass.
- **Indexes**: Each MySQL secondary index ends in the primary key, so `patient_id` alone serves "by patient, newest first". Added: `actor_id`, `patient_id`, (`subject_type`, `subject_id`),
  `action`, `created_at`. `outcome` has too few values to be useful alone and filters within the others.
- **Staff filter**: `staff_id` matches entries they did, or that were done to their account: `actor_id = ? OR (subject_type = 'staff_member' AND subject_id = ?)`, as US3's independent test requires.
- **Retention**: None (spec, Assumptions). Growth estimate: a few thousand visits a day at about ten entries each is about 10 million a year, so SC-002 is measured at 5,000,000, and
  retention needs legal review.

## R11 — Network addresses behind a proxy

- **Finding**: No trusted proxies are configured (`bootstrap/app.php`), and sign-in limits already use `$request->ip()`.
- **Decision**: Use `$request->ip()` the same way. In local Docker this is the gateway address.
- **Risk**: Behind a load balancer in production, every entry would show its address until trusted proxies are configured, and the per-address sign-in limit has the same problem. Noted for deployment.

## R12 — No new dependencies

- **Decision**: No package. Audit packages such as `owen-it/laravel-auditing` and `spatie/laravel-activitylog` work through model events and so have the gaps in R2, and they store old and new
  values by default, against FR-006. Constitution X requires justification for a new dependency, and there is none here.

## R13 — Translating the general error *(approved — D7)*

- **Measured** (no files changed): with debug off, a request in Arabic that hits an unexpected error returns the framework's fixed English `{"message":"Server Error"}`. Nothing in the project translates it.
  This feature makes that response a promised path (FR-007, US4 scenarios 2 and 3), so Constitution VII applies.
- **Decision**: One `$exceptions->respond(...)` callback in `bootstrap/app.php` replaces the body of a `500` on `api/*` with `__('auth.server_error')` when debug is off.
- **Rationale**: Filtering on the status of the finished response touches nothing else. Run against the framework's handler, a runtime exception became the replacement, while a validation error (`422`), an
  authentication error (`401`), a forbidden (`403`), a route not found (`404`), and a domain exception's own `404` kept their status and body. A `render` callback for `Throwable` would also see validation
  and authentication exceptions. Debug output stays, since `.env.example` has debug on for development.
- **Scope**: This changes the message on unexpected errors in every module (FR-015). The status does not change. The one existing test asserting a `500`, in `EncounterPrivacyTest`, does not depend on the old text.
