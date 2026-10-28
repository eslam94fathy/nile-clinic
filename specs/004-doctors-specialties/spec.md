# Feature Specification: Doctors and Specialties

**Feature Branch**: `004-doctors-specialties`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "Doctors & specialties: specialty catalog; a doctor profile linked to a staff user with the doctor role" (from `docs/roadmap.md`, feature 004)

## Context

Scheduling, appointments, queues, and encounters all need to know **which doctors work at the
clinic and what they specialize in**. This feature gives administrators a catalog of medical
specialties and lets them attach a doctor profile to a staff account that has the doctor role
(feature 002). All staff can then browse doctors and filter them by specialty, for example when a
receptionist chooses a doctor for a patient.

It covers **who the doctors are and what they practice only**. Working hours and bookable slots
belong to "Scheduling & availability" (005); consultation fees belong to "Payments" (009).

## Clarifications

### Session 2026-10-28

- Q: Should the API return both specialty names or only the one in the request language? → A:
  Always both, as separate English and Arabic name fields, for every role and request language
  (FR-004a).
- Q: What happens when a staff account with a doctor profile loses the doctor role or is
  deactivated? → A: Allowed; feature 002 rules are unchanged. The profile is kept and the doctor
  counts as inactive until the account is active with the doctor role again (FR-012).
- Q: Can doctors edit their own profile? → A: No. Only administrators create and update
  profiles; doctors can view them only (FR-010).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Administrator maintains the specialty catalog (Priority: P1)

An administrator adds the specialties the clinic offers (for example Pediatrics, Cardiology), each
with an English and an Arabic name, corrects names when needed, and deactivates specialties the
clinic no longer offers.

**Why this priority**: Doctor profiles must be assigned at least one specialty, so the catalog has
to exist first.

**Independent Test**: As an administrator, add two specialties, rename one, deactivate the other,
and list the catalog, confirming names in both languages and the active status of each.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they add a specialty with an English and an Arabic
   name, **Then** it is created as active and returned with both names.
2. **Given** a specialty whose English or Arabic name is already used by another specialty (active
   or not, ignoring letter case and extra spaces), **When** an administrator adds or renames a
   specialty to that name, **Then** it is rejected with a validation message and nothing changes.
3. **Given** a missing or over-long name, **When** a specialty is added or renamed, **Then** it is
   rejected with a validation message per problem field.
4. **Given** an active specialty, **When** an administrator deactivates it, **Then** it can no
   longer be assigned to doctors, while doctors already assigned to it keep it; **When** they
   reactivate it, **Then** it can be assigned again.
5. **Given** any signed-in staff member, **When** they list specialties, **Then** they see every
   specialty with both names and its active status, and can filter to active ones only.
6. **Given** a signed-in receptionist or doctor, **When** they try to add, rename, deactivate, or
   reactivate a specialty, **Then** it is refused as forbidden.

---

### User Story 2 - Administrator creates and maintains doctor profiles (Priority: P1)

An administrator creates a doctor profile for a staff member whose account has the doctor role:
they choose one or more specialties and record the doctor's professional title and, optionally,
their medical syndicate registration number. Later they update the specialties or details.

**Why this priority**: Without doctor profiles, nothing in scheduling or appointments has a doctor
to refer to. Together with US1 this is the minimum slice later features depend on.

**Independent Test**: Create a staff account with the doctor role, create a profile for it with two
specialties, then replace one specialty and change the title, and confirm the stored profile.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator and an active staff account with the doctor role and no
   profile yet, **When** they create a profile with a professional title and at least one active
   specialty, **Then** the profile is created and returned with the doctor's name, title,
   specialties, and registration number if given.
2. **Given** a staff account whose role is not doctor, **When** an administrator tries to create a
   profile for it, **Then** it is rejected with a validation message.
3. **Given** a staff account that already has a doctor profile, **When** an administrator tries to
   create a second one for it, **Then** it is rejected with a validation message.
4. **Given** no specialties, an unknown specialty, or a deactivated specialty not already assigned
   to that doctor, **When** a profile is created or updated, **Then** it is rejected with a
   validation message and nothing changes.
5. **Given** a registration number already recorded for another doctor, **When** a profile is
   created or updated with it, **Then** it is rejected with a validation message.
6. **Given** an existing profile, **When** an administrator changes the title, registration number,
   or set of specialties with valid values, **Then** the profile is updated and returned; the
   doctor's name and email continue to come from their staff account.
7. **Given** a signed-in receptionist or doctor, **When** they try to create or update a doctor
   profile, including their own, **Then** it is refused as forbidden and nothing changes.

---

### User Story 3 - Staff browse doctors by specialty (Priority: P2)

A receptionist needs a cardiologist for a patient. They list doctors, filter by specialty or search
by name, and open a doctor's profile. A doctor can also view colleagues' profiles and their own.

**Why this priority**: Day-to-day use of the doctor list starts with appointments (006), but the
listing is useful as soon as profiles exist and lets the clinic verify its data.

**Independent Test**: Create three doctor profiles across two specialties, then list doctors
filtered by one specialty and search by part of a name, confirming only the expected doctors
appear.

**Acceptance Scenarios**:

1. **Given** existing doctor profiles, **When** any signed-in staff member lists doctors, **Then**
   each entry shows the doctor's name, title, specialties, and whether the doctor is active, in
   pages of a bounded size.
2. **Given** existing doctor profiles, **When** staff filter by a specialty, **Then** only doctors
   assigned that specialty are listed.
3. **Given** existing doctor profiles, **When** staff search by part of a doctor's name, **Then**
   matching doctors are listed, ignoring letter case, extra spaces, and the Arabic letter variants
   used by patient search (feature 003).
4. **Given** a doctor whose staff account is deactivated or no longer has the doctor role, **When**
   staff list doctors filtered to active ones, **Then** that doctor is not listed; without the
   filter the doctor is listed as inactive.
5. **Given** a doctor profile, **When** any signed-in staff member opens it, **Then** the full
   profile is shown; **Given** an unknown doctor, **Then** it is reported as not found.
6. **Given** a signed-in doctor, **When** they request their own profile, **Then** it is returned,
   or reported as not found if no profile has been created for them yet.

---

### Edge Cases

- **Role changes**: If an administrator changes a doctor's staff role to receptionist or
  administrator, or deactivates the account, the profile is kept (later appointments may refer to
  it) but the doctor counts as inactive. Restoring the doctor role and active status makes the
  doctor active again with the same profile.
- **No deletion**: Specialties and doctor profiles are never deleted, so later schedules,
  appointments, and encounters can keep referring to them. Specialties are deactivated instead.
- **Deactivated specialty on a doctor**: A doctor keeps a specialty that is deactivated after it
  was assigned; it is shown on the profile marked inactive. It can be removed from the doctor but
  not newly added.
- **Last specialty**: A doctor profile always keeps at least one specialty; an update that would
  leave none is rejected.
- **Names**: The doctor's name is the staff account's name (feature 002); it is not stored twice.
  Renaming the staff account renames the doctor everywhere.
- **Languages**: Specialty names are catalog data and are always returned in both English and
  Arabic, with identical response structure in either request language. Validation and refusal
  messages are returned in the request's language. Doctor titles and other entered details are
  returned exactly as entered.
- **Privacy**: Doctor profiles hold no patient data. Staff email addresses and account details
  beyond the name are not shown in doctor listings.
- **Concurrent edits**: Two administrators adding the same specialty name at the same moment
  results in one specialty, not two.

## Requirements *(mandatory)*

### Functional Requirements

**Specialty catalog**

- **FR-001**: The system MUST store for each specialty an English name and an Arabic name (both
  required, each at most 100 characters) and an active status.
- **FR-002**: English names MUST be unique among specialties, and Arabic names MUST be unique among
  specialties, ignoring letter case and leading, trailing, and repeated spaces; this holds for
  active and deactivated specialties and under concurrent requests.
- **FR-003**: Only administrators MAY add specialties, rename them, and deactivate or reactivate
  them. Specialties MUST NOT be deleted.
- **FR-004**: All signed-in staff MUST be able to list specialties with both names and active
  status, optionally filtered to active only.
- **FR-004a**: Wherever a specialty appears (catalog, doctor lists, doctor profiles), the system
  MUST return its English and Arabic names as two separate fields, regardless of the request
  language or the requester's role.

**Doctor profiles**

- **FR-005**: A doctor profile MUST belong to exactly one staff account, and a staff account MUST
  have at most one doctor profile.
- **FR-006**: A doctor profile MAY be created only for a staff account that is active and has the
  doctor role at the time of creation.
- **FR-007**: A doctor profile MUST store a professional title (required, at most 100 characters,
  for example "Consultant" or "Specialist"), an optional medical syndicate registration number
  (unique among doctors when given, letters and digits only, at most 30 characters), and one or
  more specialties.
- **FR-008**: Only active specialties MAY be newly assigned to a doctor; a specialty already
  assigned stays assigned if it is later deactivated, until an administrator removes it.
- **FR-009**: A doctor profile MUST always have at least one specialty.
- **FR-010**: Only administrators MAY create and update doctor profiles, including a doctor's own
  profile; doctors can view profiles but not change any part of them. Doctor profiles MUST NOT be
  deleted.
- **FR-011**: The doctor's name MUST come from the linked staff account and MUST NOT be stored
  separately.
- **FR-012**: A doctor MUST count as active only while their staff account is active and has the
  doctor role. Changing the role or deactivating the account is not blocked by an existing
  profile; the profile is kept unchanged and becomes active again when both conditions hold.

**Browsing**

- **FR-013**: All signed-in staff MUST be able to list doctors, filtered by specialty and/or active
  status and searched by part of the name, in pages of a bounded size.
- **FR-014**: Name search MUST ignore letter case, extra spaces, and the Arabic letter variants
  defined by feature 003 (alef forms, taa marbuta/haa, yaa/alef maqsura).
- **FR-015**: Doctor list entries MUST show the doctor's name, title, specialties (both names), and
  active status; they MUST NOT show the staff account's email or other account details.
- **FR-016**: All signed-in staff MUST be able to view a single doctor profile, which adds the
  registration number to the list fields.
- **FR-017**: A signed-in doctor MUST be able to retrieve their own doctor profile directly.

**Cross-cutting**

- **FR-018**: All actions MUST require a signed-in, active staff account and MUST be authorized
  through the system's single authorization mechanism (feature 002, FR-012); refused requests are
  reported as forbidden, distinct from unauthenticated.
- **FR-019**: All user-facing messages from this feature MUST exist in English and Arabic, with
  identical response field names and structure.
- **FR-020**: Seed data, fixtures, and tests MUST use synthetic doctors and specialties only
  (Constitution XII).
- **FR-021**: Doctor and specialty capabilities MUST follow the project's existing API versioning
  and module conventions established by features 001–003.

### Key Entities *(include if feature involves data)*

- **Specialty**: A field of medicine the clinic offers. English name, Arabic name, active status,
  and when it was created and last changed. Never deleted. Assigned to many doctors.
- **Doctor profile**: The clinical identity of a staff member with the doctor role. Linked to one
  staff account (which supplies name and active status), professional title, optional syndicate
  registration number, one or more specialties, and when it was created and last changed. Never
  deleted. Later features (schedules, appointments, encounters) refer to it.
- **Doctor specialty assignment**: Links a doctor profile to a specialty; a doctor has one or more,
  a specialty may have many doctors.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can set up a new doctor (profile with specialties) in under 1
  minute once the staff account exists.
- **SC-002**: Staff find the doctors for a given specialty in a single request, with results shown
  in under 2 seconds for a clinic with 500 doctors and 100 specialties.
- **SC-003**: 0 duplicate specialty names (per language) and 0 duplicate registration numbers can
  be stored, including under simultaneous submissions, as shown by automated tests.
- **SC-004**: 100% of specialty and doctor-profile changes are refused for receptionists and
  doctors, and all actions refuse unauthenticated requests, as shown by automated tests covering
  every role against every action.
- **SC-005**: 100% of doctors whose account is deactivated or no longer has the doctor role are
  excluded from the active-doctor list, as shown by automated tests.
- **SC-006**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Staff accounts, roles, and deactivation come from feature 002; this feature does not change how
  accounts are created, edited, or deactivated.
- Specialty names are entered by administrators in both languages because the clinic serves staff
  and documents in English and Arabic; the API returns both names rather than picking one by
  request language, keeping responses identical across languages.
- A flat list of specialties is enough; sub-specialties and specialty hierarchies are out of scope.
- Consultation fees and pricing belong to "Payments" (009); working hours, breaks, and slots
  belong to "Scheduling & availability" (005).
- Doctors cannot edit their own profiles in this feature; administrators keep the catalog and
  profiles consistent.
- Doctor photos, biographies, qualifications lists, and public-facing doctor pages are out of
  scope (no patient portal, Constitution II).
- Recording who changed a specialty or profile belongs to the later "Audit logs" feature; records
  here keep created and last-changed timestamps only.
- Field length limits and page sizes follow common practice and can be tuned during planning.
