# Feature Specification: Patient Registration and Records

**Feature Branch**: `003-patient-records`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "Patient registration and records"

## Context

Every clinical and financial feature that follows (appointments, queues, encounters, payments)
refers to a patient. This feature lets clinic staff register patients, find them again quickly,
view their record, and keep their details up to date. It covers **identity, contact, and
demographic details only**; medical history, visit notes, and diagnoses belong to the later
"Medical encounters" feature.

Patients do not have accounts and never use the system directly (Constitution II). Staff accounts
and the three roles (administrator, receptionist, doctor) come from feature 002.

## Clarifications

### Session 2026-10-28

- Q: Which patients may a doctor search and view before appointments exist? → A: None. Doctors
  have no access to patient records in this feature; a later feature (appointments or medical
  encounters) grants access to the patients linked to them (FR-014).
- Q: Is the national ID required for every patient? → A: No. It is optional, and unique when
  given (FR-004).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receptionist registers a new patient (Priority: P1)

A patient arrives for the first time. The receptionist records their full name, date of birth,
sex, and phone number, plus any optional details they have (national ID, address, emergency
contact). The system assigns a patient number that identifies the patient from then on.

**Why this priority**: No patient can be booked, queued, seen, or billed until they exist in the
system. This is the minimum slice every later feature depends on.

**Independent Test**: As a receptionist, register a patient with only the required details,
receive a patient number, and retrieve the new record by that number.

**Acceptance Scenarios**:

1. **Given** a signed-in receptionist or administrator, **When** they register a patient with a
   full name, date of birth, sex, and phone number, **Then** the patient is created with a new,
   unique patient number and the full record is returned.
2. **Given** a registration that also includes national ID, address, and an emergency contact,
   **When** it is submitted, **Then** all of these are stored and shown on the patient's record.
3. **Given** missing required details, a date of birth in the future, or a malformed phone number
   or national ID, **When** registration is submitted, **Then** it is rejected with a validation
   message per problem field and nothing is created.
4. **Given** a national ID already recorded for another patient, **When** someone registers a new
   patient with it, **Then** it is rejected with a validation message and nothing is created.
5. **Given** a signed-in doctor, **When** they try to register a patient, **Then** it is refused as
   forbidden.

---

### User Story 2 - Staff find a patient and view their record (Priority: P1)

A returning patient gives their name, phone number, patient number, or national ID. Staff search
and get a short list of matching patients showing only what is needed to pick the right person,
then open the full record.

**Why this priority**: Returning patients are the daily norm; without search, staff would create
duplicate records. Registration and search together form the usable minimum.

**Independent Test**: Register three synthetic patients, then search by part of a name, by phone,
by patient number, and by national ID, and confirm the expected patient is found each time and the
list contains only the listing fields.

**Acceptance Scenarios**:

1. **Given** registered patients, **When** staff search by part of a name, **Then** every patient
   whose name contains that text is listed, ignoring letter case and common Arabic letter variants
   (see Edge Cases).
2. **Given** registered patients, **When** staff search by phone number, patient number, or
   national ID, **Then** the matching patient is listed.
3. **Given** any search or list result, **When** it is returned, **Then** each entry shows only
   patient number, full name, sex, date of birth, and phone number; national ID, address, and
   emergency contact never appear in lists.
4. **Given** a search with no matches, **When** it is returned, **Then** the result is an empty
   list, not an error.
5. **Given** a patient number, **When** a receptionist or administrator opens the record, **Then** the full
   record is shown, including national ID, address, and emergency contact.
6. **Given** a patient number that does not exist, **When** someone opens it, **Then** it is
   reported as not found.
7. **Given** a signed-in doctor, **When** they list or search patients or open a patient record,
   **Then** it is refused as forbidden (FR-014).

---

### User Story 3 - Staff update a patient's details (Priority: P2)

A patient's phone number, address, or emergency contact changes, or a mistake was made at
registration. A receptionist or administrator corrects the record.

**Why this priority**: Records drift over time and registration mistakes happen, but the clinic can
start operating with registration and search alone.

**Independent Test**: As a receptionist, change a patient's phone number and address, then open the
record and confirm the new values, with the patient number unchanged.

**Acceptance Scenarios**:

1. **Given** a signed-in receptionist or administrator, **When** they change any editable detail
   with valid values, **Then** the record is updated and the updated record is returned.
2. **Given** an update with invalid values or a national ID belonging to another patient, **When**
   it is submitted, **Then** it is rejected with a validation message and nothing changes.
3. **Given** any update, **When** it completes, **Then** the patient number stays the same.
4. **Given** an optional detail that was recorded, **When** staff clear it, **Then** it is removed
   from the record.
5. **Given** a signed-in doctor, **When** they try to update a patient, **Then** it is refused as
   forbidden and nothing changes.

---

### Edge Cases

- **Arabic and English names**: Names may be written in Arabic or Latin script. Name search treats
  these Arabic letter forms as the same: alef forms (ا أ إ آ), taa marbuta and haa (ة ه), and yaa
  and alef maqsura (ي ى). Searching "احمد" finds "أحمد". Leading, trailing, and repeated spaces in
  names are ignored.
- **Phone numbers**: Several patients may share one phone number (for example a parent and
  children), so phone numbers are not unique. Searching by a phone finds all patients with it.
- **Children**: Children may have no national ID or phone of their own; the phone of a parent or
  guardian is recorded, and the emergency contact can name the guardian.
- **Foreign patients**: Patients without an Egyptian national ID are registered without one.
- **Possible duplicates**: The system blocks only a repeated national ID. Two patients may share a
  name and date of birth (they can be different people); staff are expected to search before
  registering.
- **No deletion**: Patient records are never deleted in this feature, so later appointments,
  encounters, and payments can keep referring to them. Mistakes are corrected by editing.
- **Privacy**: National ID, address, and emergency contact never appear in lists, search results,
  error messages, or logs. Logs refer to patients only by their internal identifier
  (Constitution XII).
- **Languages**: Validation and refusal messages are returned in English or Arabic per the
  request's language, with identical response structure; stored patient details are returned
  exactly as entered, never translated.
- **Large result sets**: Lists and searches are returned in pages of a bounded size, so a very
  short search text (for example one letter) cannot return every patient at once.

## Requirements *(mandatory)*

### Functional Requirements

**Patient record**

- **FR-001**: The system MUST store for each patient: full name, date of birth, sex (male or
  female), and phone number, all required.
- **FR-002**: The system MUST store these optional details: national ID, address, and an emergency
  contact (name, relationship, and phone number).
- **FR-003**: The system MUST assign each new patient a patient number that is unique, never
  changes, and is never reused. Staff MUST NOT be able to choose or edit it.
- **FR-004**: The national ID is optional. When given, it MUST be unique across all patients and
  MUST be a valid Egyptian national ID format (14 digits).
- **FR-005**: The date of birth MUST NOT be in the future and MUST NOT be more than 130 years ago.
- **FR-006**: Phone numbers (patient and emergency contact) MUST contain 8 to 15 digits, optionally
  starting with `+`; spaces and dashes entered by staff are ignored. Phone numbers are not unique.
- **FR-007**: Each record MUST keep when it was registered and when it was last changed.
- **FR-008**: Patient records MUST NOT be deleted.

**Registration and updates**

- **FR-009**: Receptionists and administrators MUST be able to register patients.
- **FR-010**: Receptionists and administrators MUST be able to update every patient detail except
  the patient number, including clearing optional details.

**Search and viewing**

- **FR-011**: Receptionists and administrators MUST be able to list patients and search them by part of the
  full name, by phone number, by patient number, or by national ID, with results in pages of a
  bounded size.
- **FR-012**: Name search MUST ignore letter case, extra spaces, and the Arabic letter variants
  listed in Edge Cases.
- **FR-013**: List and search results MUST show only patient number, full name, sex, date of birth,
  and phone number. The full record, including national ID, address, and emergency contact, MUST be
  shown only when a single patient is opened.

**Authorization**

- **FR-014**: Doctors MUST NOT register, list, search, view, or update patients in this feature.
  Doctor access to the patients linked to them is granted by a later feature (appointments or
  medical encounters) through an approved access rule (Constitution I).
- **FR-015**: All patient actions MUST require a signed-in, active staff account and MUST be
  authorized through the system's single authorization mechanism (feature 002, FR-012); refused
  requests are reported as forbidden, distinct from unauthenticated.

**Cross-cutting**

- **FR-016**: All user-facing messages from this feature MUST exist in English and Arabic, with
  identical response field names and structure.
- **FR-017**: National ID, address, and emergency contact MUST NOT appear in lists, error messages,
  or logs; logs MUST identify patients only by internal identifier.
- **FR-018**: Seed data, fixtures, and tests MUST use synthetic patients only (Constitution XII).
- **FR-019**: Patient capabilities MUST follow the project's existing API versioning and module
  conventions established by features 001 and 002.

### Key Entities *(include if feature involves data)*

- **Patient**: A person who receives care at the clinic. Patient number, full name, date of birth,
  sex, phone number, optional national ID, optional address, optional emergency contact, and when
  the record was registered and last changed. Never deleted. Later features (appointments,
  encounters, payments) refer to it.
- **Emergency contact**: A person to reach on the patient's behalf (often a guardian for a child).
  Name, relationship to the patient, and phone number. Belongs to one patient; optional.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A receptionist can register a new patient with the required details in under 1
  minute.
- **SC-002**: Staff find a returning patient by name, phone, patient number, or national ID in a
  single search, with results shown in under 2 seconds for a clinic holding 100,000 patients.
- **SC-003**: An Arabic name search finds the patient regardless of alef, taa marbuta, or yaa
  spelling variants in 100% of tested cases.
- **SC-004**: 0 list or search responses contain national ID, address, or emergency contact, as
  shown by automated tests.
- **SC-005**: 100% of patient actions refuse unauthenticated requests, and doctors are refused
  every patient action (register, list, search, view, update), as shown by automated tests covering every role against every
  patient action.
- **SC-006**: 0 duplicate national IDs can be stored, including when two registrations with the
  same national ID are submitted at the same time.
- **SC-007**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Only clinic staff use the system; patients have no accounts (Constitution II).
- Doctors cannot look up patients until a later feature links them to their patients; until then,
  receptionists and administrators handle all patient lookups.
- Staff use the API through a client application built separately; this feature exposes API
  capabilities only.
- Medical history, allergies, diagnoses, visit notes, and attachments belong to the later "Medical
  encounters" feature; this feature stores no medical content.
- A single "full name" field is used rather than separate name parts, because Egyptian names
  commonly have three or four parts and some patients have names in both scripts.
- Merging duplicate records, archiving patients, and recording deaths are out of scope.
- Recording who viewed or changed a patient record belongs to the later "Audit logs" feature;
  records here keep registered and last-changed timestamps only.
- Passport or other foreign identity documents are out of scope; foreign patients are registered
  without an identity number.
- The 130-year date-of-birth limit, phone-number length, and page sizes follow common practice and
  can be tuned during planning.
- Applicable Egyptian health-data and data-protection obligations for patient records must be
  identified for legal review before production use (Constitution I).
