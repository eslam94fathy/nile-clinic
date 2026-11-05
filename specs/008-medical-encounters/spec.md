# Feature Specification: Medical Encounters

**Feature Branch**: `008-medical-encounters`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Medical encounters" (from `docs/roadmap.md`, feature 008: "visit records and medical notes; doctors access only the records the approved access rules allow. Notes never appear in listings or logs.")

## Context

Feature 007 moves a patient through the day: they check in, wait, go in to the doctor, and the
doctor marks the visit as finished. Nothing yet records **what happened during the visit**. This
feature gives doctors a **medical record for each visit**: why the patient came, what the doctor
found, the diagnosis, the treatment plan, and what was prescribed. It also lets a doctor look back at
earlier visits, so care continues from what is already known.

This is the most sensitive information the clinic holds. Access is limited to doctors under the
rules in this specification, and medical content never appears in listings, error responses, or logs
(Constitution I, XII).

A record belongs to **one visit**, meaning one queue entry from feature 007. Booked patients and
walk-ins are treated the same way, because a walk-in has a visit but no appointment. Patients come
from feature 003, doctors from 004, appointments from 006, visits from 007, and staff roles from
002. Fees and payments belong to "Payments" (009), and a history of who read or changed a record
belongs to "Audit logs". There is no patient-facing access in this feature (Constitution II).

## Clarifications

### Session 2026-11-05

- Q: Which clinical content does a record hold? → A: Free-text chief complaint, examination
  findings, diagnosis, and treatment plan, plus prescription lines of medication, dosage, frequency,
  duration, and instructions (FR-003, FR-004).
- Q: Which other doctors' records may a doctor read? → A: Finalized records only, and only for
  patients on the doctor's own list today who are waiting, with the doctor, or seen (FR-012).
- Q: How is a finalized record corrected? → A: It stays locked. Its author adds dated addenda that
  appear with the record (FR-011, US5).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A doctor records the visit (Priority: P1)

A patient is with the doctor. The doctor starts the visit's medical record, writes what they learn
as the visit goes on, and saves as often as they need. If the clinic is busy, they can finish the
visit and complete the record afterwards.

**Why this priority**: This is the purpose of the feature; nothing else exists until a record can be
written.

**Independent Test**: As a doctor with a patient with them, start a record, save clinical content,
open it again, and confirm the content is there and the record is a draft.

**Acceptance Scenarios**:

1. **Given** a signed-in doctor and a visit of theirs that is with the doctor or seen, **When** they
   start its record, **Then** one draft record is created for that visit, showing the visit's date,
   the doctor, and the patient summary (patient number, full name, sex, date of birth).
2. **Given** a draft record of theirs, **When** the doctor saves clinical content, **Then** the
   content replaces what was saved before and the record stays a draft; a partly filled draft is
   allowed.
3. **Given** a visit that is waiting or left, or a visit of another doctor, **When** a doctor tries
   to start its record, **Then** it is refused and nothing is created.
4. **Given** a visit that already has a record, or two start requests for one visit at the same
   moment, **When** they are processed, **Then** the visit ends up with exactly one record and the
   extra request is refused with a validation message.
5. **Given** a visit with no record, **When** the doctor marks it as finished (feature 007), **Then**
   it finishes as before, and the doctor can still start its record afterwards, including on a later
   day.
6. **Given** a signed-in doctor without a profile, **When** they use any record action, **Then** it
   is reported as not found.
7. **Given** a signed-in receptionist or administrator, **When** they use any record action, **Then**
   it is refused as forbidden.

---

### User Story 2 - A doctor finalizes the record (Priority: P1)

When the record is complete, the doctor finalizes it. From then on it is the clinic's dependable
account of that visit, and it cannot be overwritten.

**Why this priority**: A medical record that can change silently cannot be relied on. Together with
US1 this is the minimum usable slice.

**Independent Test**: Finalize a complete draft, confirm the finalization time is recorded, then try
to save over it and confirm it is refused and the content is unchanged.

**Acceptance Scenarios**:

1. **Given** a draft of theirs with a chief complaint and a diagnosis, **When** the doctor finalizes
   it, **Then** it becomes finalized and the finalization time is recorded.
2. **Given** a draft missing the chief complaint or the diagnosis, **When** the doctor tries to
   finalize it, **Then** it is refused with a validation message naming what is missing, and it
   stays a draft.
3. **Given** a finalized record, **When** anyone tries to save over it or finalize it again, **Then**
   it is refused and the content is unchanged.
4. **Given** a save and a finalize of one record at the same moment, **When** both are processed,
   **Then** the finalized content is exactly what the record held when it was finalized, and no
   later save changes it.
5. **Given** a record whose patient is still with the doctor, **When** it is finalized, **Then** the
   visit stays in progress; finalizing a record and finishing a visit are separate actions.
6. **Given** a draft with a prescription line missing its dosage, frequency, or duration, **When** the
   doctor tries to finalize it, **Then** it is refused with a validation message naming the line, and
   it stays a draft. A record with no prescription lines can be finalized.

---

### User Story 3 - A doctor finds their visits and records (Priority: P2)

At the end of a busy day, the doctor wants to see which visits still lack a record or have an
unfinished draft, and to reopen any record they wrote earlier.

**Why this priority**: Records written after the visit get forgotten without this list, but a doctor
who documents during each visit can manage without it at first.

**Independent Test**: Give a doctor three visits, one with no record, one with a draft, and one
finalized. Filter the list by each record status and confirm the expected visit is returned with no
clinical content.

**Acceptance Scenarios**:

1. **Given** a signed-in doctor, **When** they list their visits for a date range of at most 92 days,
   optionally filtered by patient and record status (`none`, `draft`, `finalized`), **Then** their
   own visits that are with the doctor or seen are returned in date and time order, in pages of a
   bounded size.
2. **Given** that list, **When** it is returned, **Then** each item shows only the visit date and
   times, whether it was a walk-in, the record status, and the patient summary, and never any
   clinical content.
3. **Given** a record they wrote, **When** the doctor opens it, **Then** the full record is returned,
   whatever its status.

---

### User Story 4 - A doctor reads a patient's earlier visits (Priority: P2)

Before or during a visit, the doctor wants to know the patient's history: earlier diagnoses,
treatments, and medications.

**Why this priority**: Continuity of care is a major reason to keep records, but a doctor can still
write new records without it.

**Independent Test**: Have other doctors finalize records for two patients, only one of whom is on
the reading doctor's list today. Confirm the reading doctor gets that patient's finalized records,
that the list carries no clinical content, and that the other patient is reported as not found.

**Acceptance Scenarios**:

1. **Given** a patient who is waiting, with the doctor, or seen on the doctor's own list today,
   **When** the doctor requests that patient's history, **Then** all the patient's finalized records,
   from any doctor, are listed newest first, each with only the visit date, the doctor's name, and
   the doctor's specialties, and no clinical content.
2. **Given** a record in that history, **When** the doctor opens it, **Then** its clinical content and
   addenda are returned.
3. **Given** a patient not on the doctor's list today, **When** the doctor requests that patient's
   history, **Then** only the doctor's own finalized records of that patient are listed, and if there
   are none, the patient is reported as not found.
4. **Given** another doctor's finalized record of a patient not on the doctor's list today, or any
   other doctor's draft, **When** the doctor opens it, **Then** it is reported as not found, exactly
   as if it did not exist.
5. **Given** a doctor who could read a patient's records today, **When** the next clinic day begins,
   or the patient's entry is marked as left or its check-in is undone, **Then** that access has ended
   unless the patient is on the doctor's list again.

---

### User Story 5 - A doctor corrects a finalized record (Priority: P3)

The doctor notices a mistake in a finalized record, or learns something later that matters, such as
a lab result that changes the diagnosis.

**Why this priority**: Corrections are needed for an accurate record, but they are rarer than writing
and reading.

**Independent Test**: Add an addendum to a finalized record as its author; confirm the original
content is unchanged and the addendum appears when the record is opened, and confirm that another
doctor cannot add one.

**Acceptance Scenarios**:

1. **Given** a finalized record of theirs, **When** its author adds an addendum, **Then** the text and
   time are recorded, the original content is unchanged, and the addendum appears with the record,
   oldest first, to everyone who may read the record.
2. **Given** an addendum, **When** anyone tries to change or delete it, **Then** there is no way to do
   so; a further correction is made by adding another addendum.
3. **Given** a draft, **When** its author tries to add an addendum, **Then** it is refused with a
   validation message, because a draft can still be edited directly.
4. **Given** empty addendum text, or text over 10,000 characters, **When** it is submitted, **Then**
   it is refused with a validation message.
5. **Given** a finalized record of another doctor, **When** a doctor tries to add an addendum, **Then**
   it is refused as forbidden if they may read the record, and reported as not found if they may not.
6. **Given** two addenda submitted to one record at the same moment, **When** both are processed,
   **Then** both are kept.

---

### Edge Cases

- **When a record can start**: While the visit is with the doctor or after it is seen. A patient who
  is still waiting has not been seen, and a patient who left was never seen.
- **Visit never finished**: A visit still with the doctor at the end of the day stays that way
  (feature 007), and its record can still be written and finalized.
- **Undoing a check-in**: Feature 007 allows this only for waiting visits, which cannot have a
  record, so no record is ever left without its visit.
- **Late documentation**: There is no deadline for starting or finalizing a record. The record keeps
  both the visit's date and the time it was finalized, so late documentation is visible.
- **Two sessions on one draft**: Only the author can save a draft. If they save from two devices, the
  last save wins; this cannot happen after finalization.
- **Author no longer active**: A deactivated doctor's account loses all access. Their finalized
  records stay readable under the access rule, but no one can add an addendum to them until the
  account is reactivated. Their drafts stay stored and unread by anyone until then; handing a draft
  or a record to another doctor is out of scope.
- **Access follows today's list**: A doctor's access to other doctors' records depends only on the
  list for the clinic's today. It does not depend on the visit having a record, and a visit still with
  the doctor from an earlier day does not extend it.
- **Prescription lines in a draft**: A saved line needs at least the medication; the rest can be
  filled in before finalizing.
- **Doctor profile deactivated**: A doctor whose profile is inactive but whose account is active can
  still complete records of their own visits, as a visit already happened.
- **Patient details change**: The record shows the patient's current summary. Medical content is what
  the doctor wrote and does not change with the patient's details.
- **No deletion**: Records are never deleted, including drafts, because they are medical history.
- **Language of clinical text**: Doctors write in whichever language they choose. Clinical text is
  stored and returned as written and is never translated.
- **Other features' lists**: Queue, appointment, and patient lists stay as they are and never gain
  clinical content.
- **Errors**: Validation and refusal messages never repeat submitted clinical text.
- **Clinic time**: All dates and times are the clinic's local wall-clock time (Egypt), as in features
  005–007.

## Requirements *(mandatory)*

### Functional Requirements

**Recording**

- **FR-001**: A doctor MUST be able to start a medical record for a visit of their own that is with
  the doctor or seen, on the visit's day or any later day.
- **FR-002**: Each visit MUST have at most one record, also under simultaneous requests. Booked and
  walk-in visits MUST be treated the same way.
- **FR-003**: A record MUST hold this clinical content: free-text chief complaint, examination
  findings, diagnosis, and treatment plan, and prescription lines of medication, dosage, frequency,
  duration, and instructions.
- **FR-004**: Each clinical text field MUST allow up to 10,000 characters, each prescription line
  field up to 500 characters, and each record up to 20 prescription lines. Clinical text MUST be
  stored as written, in any language. A saved prescription line MUST have at least a medication.
- **FR-005**: Starting a record, saving a draft, and finalizing MUST NOT change the visit's status or
  the appointment's status; finishing a visit (feature 007) MUST NOT require a record.

**Draft and finalized**

- **FR-006**: A new record MUST be a draft. Its author MUST be able to save partly filled content any
  number of times.
- **FR-007**: The author MUST be able to finalize a draft that has a chief complaint and a diagnosis,
  and whose prescription lines, if any, each have a medication, dosage, frequency, and duration.
  Instructions are optional. The finalization time MUST be recorded.
- **FR-008**: A finalized record's content MUST NOT be overwritten by anyone, also under simultaneous
  requests.
- **FR-009**: Only the author MUST be able to save, finalize, or read a draft.
- **FR-010**: Records MUST NOT be deleted.
- **FR-011**: A finalized record's author MUST be able to correct it only by adding addenda of up to
  10,000 characters, each with its time recorded. Addenda MUST NOT be changed or deleted, MUST NOT
  change the original content, and MUST appear with the record, oldest first, to everyone who may
  read it. Drafts MUST NOT have addenda.

**Access**

- **FR-012**: A doctor MUST be able to read their own records at any time. A doctor MUST be able to
  read another doctor's finalized records only for a patient who, at the time of the request, has an
  entry on the doctor's own list for the clinic's today with status waiting, with the doctor, or seen.
- **FR-013**: Records and patients a doctor may not read MUST be reported as not found, so that a
  doctor cannot learn whether they exist.
- **FR-014**: Receptionists and administrators MUST NOT read, list, or change records, and MUST NOT
  learn whether a visit has one.
- **FR-015**: Doctors MUST NOT gain access to full patient records (feature 003) through this
  feature. They MUST see only the patient summary (patient number, full name, sex, date of birth),
  and never the national ID, address, or emergency contact. This settles the question feature 006
  left to this feature; feature 003, FR-014 is unchanged.

**Listings**

- **FR-016**: A doctor MUST be able to list their own visits that are with the doctor or seen, for a
  date range of at most 92 days, filtered optionally by patient and record status, in date and time
  order, in pages of a bounded size.
- **FR-017**: A doctor MUST be able to list a patient's finalized records that FR-012 allows them to
  read, newest first, in pages of a bounded size.
- **FR-018**: Listings MUST NOT contain clinical content, including prescription lines and addenda.
  Clinical content MUST be returned only when one record is opened.

**Cross-cutting**

- **FR-019**: Every change in this feature MUST be all-or-nothing.
- **FR-020**: All actions MUST require a signed-in, active staff account and MUST be authorized
  through the system's single authorization mechanism (feature 002); refused requests are reported as
  forbidden, distinct from unauthenticated, except where FR-013 requires not found.
- **FR-021**: Clinical content MUST NOT appear in logs or in error responses; logs MUST identify
  patients and records only by internal identifier (Constitution I, XII).
- **FR-022**: Seed data, fixtures, and tests MUST use synthetic data only (Constitution XII).
- **FR-023**: All dates and times MUST be the clinic's local time (Egypt), as in features 005–007.
- **FR-024**: All user-facing messages MUST exist in English and Arabic with identical response field
  names and structure; record statuses are fixed English identifiers, never translated.
- **FR-025**: Encounter capabilities MUST follow the API versioning and module conventions established
  by features 001–007.

### Key Entities *(include if feature involves data)*

- **Medical record (encounter)**: The record of one visit. It belongs to exactly one queue entry
  (feature 007), and through it to one doctor, one patient, and a date. It holds the clinical content
  (FR-003), a status (`draft`, `finalized`), and the time it was started, last saved, and finalized.
- **Prescription line**: One medication prescribed in a record: medication, dosage, frequency,
  duration, and instructions. It belongs to one record and locks with it when the record is finalized.
- **Addendum**: A dated correction or later finding on one finalized record. It holds its text and
  time; its author is the record's author. It cannot be changed or deleted.
- **Visit** (feature 007, queue entry): Unchanged. A visit that is with the doctor or seen can have
  one record. Its appointment, if any, is reached through the visit.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A doctor can start the record of the patient with them in one action, and find every
  visit still missing a finalized record in one action.
- **SC-002**: 0 listing, error, or log outputs contain clinical content, as shown by automated tests
  that plant recognizable text in records and search those outputs for it.
- **SC-003**: 100% of record actions are refused for receptionists, administrators, and
  unauthenticated requests, and doctors reach only the records the access rule allows, as shown by
  automated tests covering every role against every action.
- **SC-004**: 0 visits with more than one record and 0 changes to finalized content, in automated
  tests that submit many simultaneous start, save, and finalize requests.
- **SC-005**: Doctors receive their visit list or a patient's history in under 2 seconds with 500
  doctors and 500,000 records.
- **SC-006**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Visits come from feature 007, patients from 003, doctors from 004, appointments from 006, and roles
  from 002. This feature does not change the behavior of those features.
- Administrators and receptionists have no access to medical content (least privilege). Any need for
  it, such as medical-legal requests or management review, would need an approved scope change.
- Payments (009) and Reports (010) do not need clinical content; any use of encounter data in them
  will be decided in their own specifications.
- Prescription line fields are free text. A drug catalog, interaction checks, allergy alerts, and
  printed prescriptions are out of scope.
- File attachments, lab and imaging orders and results, referrals, and record templates are out of
  scope.
- A history of who read or changed a record belongs to "Audit logs". Each record keeps only its own
  times.
- How long records are retained, and how they must be protected, are among the Egyptian health-data
  obligations that need legal review before production use (Constitution I). Records are kept
  indefinitely until that review; protection at rest will be addressed during planning.
- Feature 007 is not yet merged into `main` when this specification is written; this feature builds
  on it.
- The 2-second and 500,000-record targets follow the scale of features 005–007 and can be tuned
  during planning.
