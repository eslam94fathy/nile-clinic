# Data Model: Medical Encounters

**Feature**: 008-medical-encounters | **Date**: 2026-11-05

MySQL 9.7 / InnoDB / `utf8mb4_unicode_ci`. Two new tables in new migrations; no applied migration is
edited and no existing table changes. Dates are the clinic's wall-clock date (`Africa/Cairo`); `*_at`
columns are UTC instants, as in features 005–007.

## `encounters` — Medical record

Model: `App\Modules\Encounters\Models\Encounter`. Every clinical attribute is encrypted by its cast
and listed in `$hidden` (research R6).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; the encounter id in the API |
| `queue_entry_id` | `bigint unsigned` | no | FK → `queue_entries.id`, `RESTRICT`; the visit (research R2) |
| `doctor_id` | `bigint unsigned` | no | FK → `doctors.id`, `RESTRICT`; copied from the entry; the author |
| `patient_id` | `bigint unsigned` | no | FK → `patients.id`, `RESTRICT`; copied from the entry |
| `date` | `date` | no | The visit's clinic date; copied from the entry |
| `status` | `varchar(20)` | no | `draft` \| `finalized` (`EncounterStatus` enum) |
| `chief_complaint` | `mediumtext` | yes | Encrypted; up to 10,000 characters before encryption |
| `examination_findings` | `mediumtext` | yes | Encrypted; same limit |
| `diagnosis` | `mediumtext` | yes | Encrypted; same limit |
| `treatment_plan` | `mediumtext` | yes | Encrypted; same limit |
| `prescriptions` | `mediumtext` | yes | Encrypted JSON array of lines (research R7) |
| `finalized_at` | `timestamp` | yes | Set once, when finalized |
| `created_at` | `timestamp` | yes | When the record was started; `started_at` in the API |
| `updated_at` | `timestamp` | yes | Last save; `last_saved_at` in the API |

**Indexes**

- `PRIMARY (id)`
- `UNIQUE encounters_queue_entry_unique (queue_entry_id)` — one record per visit (FR-002).
- `INDEX encounters_patient_history_index (patient_id, status, date, id)` — a patient's history, newest
  first (FR-017).
- FK index on `doctor_id` (created by `foreignId`).

**Relations** (model): `queueEntry()` → `Queues\Models\QueueEntry`; `doctor()` →
`Doctors\Models\Doctor`; `patient()` → `Patients\Models\Patient`; `addenda()` → `EncounterAddendum`.

**Prescription line** (one item of `prescriptions`; not a table):

| Key | Rule when saved | Rule when finalized |
|-----|-----------------|---------------------|
| `medication` | required, up to 500 characters | required |
| `dosage` | optional, up to 500 characters | required |
| `frequency` | optional, up to 500 characters | required |
| `duration` | optional, up to 500 characters | required |
| `instructions` | optional, up to 500 characters | optional |

## `encounter_addenda` — Addendum

Model: `App\Modules\Encounters\Models\EncounterAddendum`, with `UPDATED_AT = null`. `text` is encrypted
by its cast and listed in `$hidden`. Rows are only ever inserted (research R8).

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned` auto-increment | no | Primary key; also the display order |
| `encounter_id` | `bigint unsigned` | no | FK → `encounters.id`, `RESTRICT`; indexed |
| `text` | `mediumtext` | no | Encrypted; up to 10,000 characters before encryption |
| `created_at` | `timestamp` | yes | When it was added |

The author is the record's author (`encounters.doctor_id`), so it is not stored again.

## Read but not changed

- `queue_entries` (feature 007): the visit list, starting a record, and the access rule.
- `doctors`, `users`, `specialties`: names and specialties in responses.
- `patients`: the patient summary only.

## Validation rules

| Field | Rule | Source |
|-------|------|--------|
| list `from`, `to` | required `Y-m-d`; `to` ≥ `from`; at most 92 days | FR-016 |
| list `patient_id` | optional integer ≥ 1 | FR-016 |
| list `record_status` | optional, one of `none`, `draft`, `finalized` | FR-016 |
| list `per_page` | optional 1–100, default 15 | FR-016, FR-017 |
| start `{visit}` | the doctor's own entry (404 `visit_not_found`); status `with_doctor` or `seen` (422 `visit_not_started`); no record yet (422 `already_started`) | FR-001, FR-002 |
| save `chief_complaint`, `examination_findings`, `diagnosis`, `treatment_plan` | key required (`present`); null or string up to 10,000 characters | FR-003, FR-004 |
| save `prescriptions` | key required (`present`); array of at most 20 lines, each as above | FR-003, FR-004 |
| save, finalize status | `draft`, otherwise 422 `not_editable` | FR-008 |
| finalize content | `chief_complaint`, `diagnosis`, and each line's required keys filled, otherwise 422 `required_to_finalize` on each field | FR-007 |
| addendum `text` | required string up to 10,000 characters | FR-011 |
| addendum status | `finalized`, otherwise 422 `not_finalized` | FR-011 |

## State transitions

```text
 encounter
          start             finalize
 (none) ─────────▶ draft ────────────▶ finalized ──┐
                   │  ▲                    ▲       │ add addendum
                   └──┘                    └───────┘ (appends; content unchanged)
                   save

 queue entry (feature 007, unchanged)
   a record can start only while with_doctor or seen
```

- `finalized` is final. Records and addenda are never deleted (FR-010).
- Starting, saving, and finalizing do not change the queue entry or appointment (FR-005).
