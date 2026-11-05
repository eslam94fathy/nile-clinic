# Feature Specification: Audit Logs

**Feature Branch**: `011-audit-logs`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Audit logs" (from `docs/roadmap.md`: "who did what and when on sensitive records")

## Context

The clinic now holds patient details, medical records, and money, and each record keeps only its own latest
staff members and times. Nobody can yet ask **who opened this patient's medical record, who gave this
discount, or who changed this staff member's role**. This feature keeps a permanent trail of those actions
and lets administrators search it.

Earlier features left this history here: sign-ins and staff changes (002), who viewed or changed a patient
(003), specialties and doctor profiles (004), appointments (006), visits (007), who read or changed a medical
record (008), and charges and money (009). This feature records actions on all of them, and on schedules
(005), and changes none of their rules or responses.

The trail shows what happened, not what the records say. It identifies a patient only by internal identifier
and **never holds medical content, patient details, or credentials** (Constitution I and XII).

## Clarifications

### Session 2026-11-05

- Q: Which reads are recorded? → A: Opening one patient's details, opening one medical record, and viewing a
  patient's medical history. Listings, searches, and opening a charge are not recorded (FR-004).
- Q: How much detail does an entry keep about a change? → A: The names of the fields changed, plus before and
  after values only for statuses, money, roles, appointment times, and doctor assignments (FR-006).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An administrator finds out who changed a record (Priority: P1)

A discount looks wrong, or an appointment was cancelled and nobody remembers why. An administrator looks up
the record and sees each change made to it, who made it, and when.

**Why this priority**: This is the question the feature exists to answer, and the money and patient records it
protects are the clinic's most disputed. Recording changes and finding them is the minimum usable slice.

**Independent Test**: As different staff members, set a discount on a charge, record a refund, and update a
patient. Search by each record and confirm that exactly one entry exists for each action, with the right staff
member, action, and time.

**Acceptance Scenarios**:

1. **Given** a staff member carries out a change listed in FR-003, **When** it succeeds, **Then** exactly one
   entry is recorded with the time, the staff member, their role at that moment, the action, the record acted
   on, the patient concerned if any, and the network address of the request.
2. **Given** a change that is refused or fails, whether by validation, a business rule, or an error, **When** it
   ends, **Then** no entry is recorded and the record is unchanged.
3. **Given** entries exist for a record, **When** an administrator searches by that record, **Then** they are
   returned newest first, showing the staff member's current name and what changed as set by FR-006.
4. **Given** a record that has since been removed, such as an undone check-in or removed time off, **When** an
   administrator searches by it, **Then** its entries are still returned.
5. **Given** an update that changes nothing, **When** it is saved, **Then** no entry is recorded.
6. **Given** a signed-in receptionist or doctor, **When** they search the trail, **Then** they are refused as
   forbidden.

---

### User Story 2 - An administrator sees who opened a patient's records (Priority: P1)

After a concern about a patient's privacy, an administrator asks who opened that patient's details and medical
records, and whether anyone tried to open a medical record they had no right to.

**Why this priority**: Reading is the privacy risk that leaves no trace on the record itself, so without this the
clinic cannot answer a privacy complaint.

**Independent Test**: As a receptionist, open a patient. As a doctor with access, open that patient's medical record
and medical history. As a doctor without access, try to open them. Search by the patient and confirm each entry and
its outcome, and that the refused doctor received the same response as before this feature.

**Acceptance Scenarios**:

1. **Given** a staff member opens a record listed in FR-004, **When** it is returned, **Then** one entry is recorded
   with the outcome `succeeded`.
2. **Given** a staff member is refused a medical record or a patient's medical history that exists, **When** the
   request ends, **Then** one entry is recorded with the outcome `refused`, and their response is unchanged:
   forbidden or not found exactly as feature 008 requires. It never reveals that the attempt was recorded.
3. **Given** a request for a record that does not exist, **When** it ends, **Then** no entry is recorded.
4. **Given** entries about a patient, **When** an administrator searches by that patient, **Then** all entries
   concerning the patient are returned, across patient details, appointments, visits, medical records, and charges.
5. **Given** a doctor opens a medical record they wrote themselves, **When** it is returned, **Then** it is recorded
   like any other opening.
6. **Given** any entry about a medical record, **When** it is returned, **Then** it contains no clinical content.

---

### User Story 3 - An administrator reviews a staff member's activity (Priority: P2)

When a staff member leaves, or an account looks misused, an administrator reviews what that account did in a period,
along with its sign-ins and any changes to its role, password, or status.

**Why this priority**: It supports investigations and account safety, but it builds on the entries that US1 and US2
already record.

**Independent Test**: Sign in as a staff member, fail a sign-in with the wrong password, carry out several actions,
have an administrator change their role and deactivate them, then confirm that filtering by staff member and period
returns exactly those entries.

**Acceptance Scenarios**:

1. **Given** a successful sign-in or sign-out, **When** it ends, **Then** an entry is recorded for the staff member.
2. **Given** a failed sign-in for an existing account, **When** it ends, **Then** an entry with the outcome `refused`
   names that account. The password tried is never recorded.
3. **Given** a failed sign-in for an email that matches no account, **When** it ends, **Then** an entry with the
   outcome `refused` is recorded with no staff member, and the text entered is not recorded.
4. **Given** an administrator changes a staff member's role or status or resets their password, **When** it succeeds,
   **Then** the entry names both the administrator who acted and the staff member acted on.
5. **Given** the search filters in FR-009, **When** an administrator combines them, **Then** only entries that match
   all of them are returned.
6. **Given** a deactivated staff member, **When** the trail is searched, **Then** their entries are still returned
   and they can still be used as a filter.

---

### User Story 4 - The clinic can rely on the trail (Priority: P2)

If the trail could be edited, or could miss an action during a failure, it would be worthless in a dispute. The
clinic needs to know that every entry stands as written and no recorded action goes missing.

**Why this priority**: It is what makes the trail credible as evidence, but it is verified across the other stories
rather than used on its own.

**Independent Test**: Attempt to change or delete an entry as every role and confirm no way exists. Force an entry to
fail to save and confirm that the change or the opening does not go ahead.

**Acceptance Scenarios**:

1. **Given** any role, including administrator, **When** they try to change or delete an entry, **Then** no such
   capability exists.
2. **Given** a change whose entry cannot be saved, **When** it ends, **Then** the change is not applied and the staff
   member receives a general error.
3. **Given** the opening of a record whose entry cannot be saved, **When** it ends, **Then** the record is not returned
   and the staff member receives a general error.
4. **Given** an administrator searches the trail, **When** the results are returned, **Then** that search is itself
   recorded, with the filters used.

---

### Edge Cases

- **Several changes in one action**: An action that changes several fields is one entry listing them. An action that
  triggers another (a check-in that changes the appointment's status) is recorded as the action the staff member took.
- **Failures inside an action**: A change undone by a failure leaves no entry, because the change and its entry stand or
  fall together.
- **Refused attempts on other records**: Only refused attempts to open a medical record or medical history, and failed
  sign-ins, are recorded. Other refusals and validation failures are not.
- **Sign-in limit**: Attempts stopped by the sign-in limit (feature 002) are not recorded, because no password was checked.
  A failed sign-in to a deactivated account is recorded like any other.
- **Actions that repeat**: Setting the same discount again is still recorded, because it records a new staff member and
  reason. Only an update that leaves every field as it was creates no entry.
- **Patient updates that change nothing**: These create no update entry, but they return the whole record, so they count as
  opening it. Otherwise sending one known field would read the rest of a patient's details without a trace.
- **Listings**: Lists and searches, such as the patient search, today's queue, and the charges list, are not recorded,
  nor is opening a single charge. Text typed into a search is never recorded.
- **Patient changes**: An entry shows that a patient's phone number changed, but not the old or new number. A discount
  shows its old and new amounts.
- **Reports**: Reports hold no patient identifiers and feature 010 requires that requesting one creates no record, so
  they are not recorded.
- **Command-line actions**: The first administrator is created outside the API, so that entry shows the system instead of
  a staff member, and no network address.
- **Removed records**: Entries outlive the record they describe and keep its internal identifier.
- **Role changes**: An entry keeps the role held at the time, even if it later changes.
- **Name changes**: An entry shows the staff member's current name. Their internal identifier ties all entries together.
- **Same moment**: Entries recorded at the same moment keep a stable order between pages.
- **Clinic days**: Date filters use midnight-to-midnight clinic time (Egypt), including across daylight-saving changes.
  Times are stored exactly, so they stay correct whichever day they are viewed from.
- **Earlier actions**: Actions before this feature was deployed have no entries and are not reconstructed.
- **Empty searches**: A search that matches nothing returns an empty page, not an error. Filtering by an unknown staff
  member or patient returns the same, so that entries about removed records stay searchable and the search reveals nothing
  about what exists.
- **Languages**: Messages are returned in English or Arabic with identical structure. Actions and outcomes are fixed
  English identifiers, never translated; names appear as stored.

## Requirements *(mandatory)*

### Functional Requirements

**Recording**

- **FR-001**: The system MUST record one entry for each action listed in FR-003 and FR-004 and each refused attempt in FR-005,
  whichever role carried it out.
- **FR-002**: Each entry MUST hold the time, the staff member (or the system, or none for an unknown sign-in), their role at
  that moment, the action, the type and internal identifier of the record acted on, the patient's internal identifier when the
  action concerns a patient, the outcome (`succeeded` or `refused`), and the network address of the request.
- **FR-003**: These changes MUST be recorded when they succeed:
  - **Accounts (002)**: sign-in, sign-out, changing one's own password, creating and updating a staff member, deactivating
    and reactivating them, an administrator resetting their password, and creating the first administrator.
  - **Patients (003)**: registering and updating.
  - **Doctors (004)**: creating, updating, deactivating, and reactivating a specialty; creating and updating a doctor profile.
  - **Schedules (005)**: setting and clearing a weekly schedule; adding and removing time off.
  - **Appointments (006)**: booking, rescheduling, cancelling, and marking a no-show.
  - **Visits (007)**: checking in, adding a walk-in, marking as left, undoing a check-in, calling the next patient, and
    finishing a visit.
  - **Medical records (008)**: starting a record, saving a draft, finalizing, and adding an addendum.
  - **Money (009)**: setting a fee, charging a visit, recording a payment or a refund, setting a discount, and cancelling a
    charge.
- **FR-004**: These openings MUST be recorded when the record is returned: opening one patient's details, opening one medical
  record, and viewing a patient's medical history. Listings, searches, and opening a charge MUST NOT be recorded. An update to a
  patient that changes nothing still returns the whole record, so it MUST be recorded as an opening.
- **FR-005**: These refused attempts MUST be recorded: a failed sign-in, and a staff member being refused when opening a medical
  record or patient's medical history that exists. Recording an attempt MUST NOT change the response that feature 002 or 008 requires.
- **FR-006**: An entry for a change MUST list the names of the fields changed. It MUST also hold before and after values for
  statuses, money, roles, appointment times, and doctor assignments, and MUST NOT hold values of any other field, including patient
  details and clinical content.
- **FR-007**: A change and its entry MUST succeed or fail together. An update that changes nothing MUST NOT create an entry. If
  the entry for an opening cannot be saved, the record MUST NOT be returned.
- **FR-008**: Entries MUST NOT contain medical content, patient names, numbers, phone numbers, national IDs, addresses, emergency
  contacts, passwords, access credentials, text typed into a search, or an email entered at a failed sign-in that matches no
  account. Patients MUST be identified only by internal identifier.

**Searching**

- **FR-009**: Administrators MUST be able to search entries by any combination of period, staff member, patient, record type and
  identifier, action, and outcome. All filters MUST be optional, and a period MAY be of any length. An end date before the start or
  a malformed filter MUST be refused with a validation message.
- **FR-010**: Results MUST be returned newest first, in pages of a bounded size, in a stable order.
- **FR-011**: A search MUST itself be recorded as an entry with the filters used, by internal identifier only.
- **FR-012**: Results MUST NOT reveal patient details or clinical content, and MUST show the staff member's current name and the
  role held at the time.

**Protection**

- **FR-013**: The trail MUST be available to administrators only. Receptionists and doctors MUST be refused as forbidden.
- **FR-014**: No role MUST be able to change or delete an entry through the system. Entries MUST remain when the record or the staff
  account they refer to is removed or deactivated.

**Cross-cutting**

- **FR-015**: This feature MUST NOT change the rules, responses, or permissions of features 002–010, other than the general error
  in FR-007 when an entry cannot be saved. Unexpected errors in every feature now return this message in the request's language
  (Constitution VII).
- **FR-016**: All actions on the trail MUST require a signed-in, active staff account and MUST be authorized through the system's
  single authorization mechanism (feature 002); refused requests are reported as forbidden, distinct from unauthenticated.
- **FR-017**: Application logs MUST identify patients only by internal identifier; seed data, fixtures, and tests MUST use synthetic
  data only (Constitution XII).
- **FR-018**: All dates and day boundaries MUST be the clinic's local time (Egypt), as in features 005–010.
- **FR-019**: All user-facing messages MUST exist in English and Arabic with identical response field names and structure; actions and
  outcomes are fixed English identifiers, never translated.
- **FR-020**: Audit capabilities MUST follow the API versioning and module conventions established by features 001–010.

### Key Entities *(include if feature involves data)*

- **Audit entry**: One recorded action, never changed once written. Holds the time, staff member, role at that moment, action, record
  type and identifier, patient identifier if any, outcome, network address, and what changed as set by FR-006. Its references are kept
  even after the record they point to is removed.
- **Staff member** (feature 002): Who acted, and possibly who was acted on. Accounts are never deleted, so entries can always refer to them.
- **Patient** (feature 003): Referred to by internal identifier only, for finding all entries about one patient.
- **Records acted on** (features 002–009): Referred to by type and internal identifier only. Their content is not copied.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of actions listed in FR-003, FR-004, and FR-005 create exactly one correct entry, as shown by automated tests covering
  every action.
- **SC-002**: An administrator can get all entries about one patient or one record with one request, in under 2 seconds with 5,000,000
  entries.
- **SC-003**: 0 entries or search results contain planted synthetic patient details, clinical content, passwords, or credentials, as shown
  by automated tests across every recorded action.
- **SC-004**: 100% of refused or failed changes leave 0 entries, and 100% of changes whose entry cannot be saved leave the record unchanged,
  as shown by automated tests.
- **SC-005**: 100% of attempts to search the trail by unauthenticated requests, receptionists, and doctors are refused, and no role has any
  way to change or delete an entry, as shown by automated tests covering every role.
- **SC-006**: Responses to refused attempts are identical with and without this feature, as shown by the existing tests of features 002 and
  008 continuing to pass unchanged.
- **SC-007**: Staff notice no difference in speed: a recorded action takes no more than 100 milliseconds longer than it did before.
- **SC-008**: Every user-facing message added by this feature exists in both English and Arabic, as verified by automated tests in both
  languages.

## Assumptions

- Staff roles come from feature 002, and the recorded actions from features 002–009. Those features keep their behavior apart from FR-007.
- Administrators only (least privilege), as with Reports (010). A doctor seeing who opened their own patients' records would need an approved
  scope change, as would a patient-facing access history.
- Administrators can see each other's actions and their own, and cannot remove any of them. Separate auditor roles are out of scope, as additional
  roles require an approved specification (Constitution I).
- If the trail cannot be written, the action does not go ahead, because a medical record opened with no trace defeats the feature. The trail shares
  the clinic's database, so this should only happen when the clinic's other records are unavailable too.
- Entries are kept indefinitely, with no deletion or archiving. How long they must be kept is among the Egyptian health-data and data-protection
  obligations needing legal review before production use, along with whether network addresses count as personal data (Constitution I).
- Protection against someone with direct database access, such as tamper-evident entries or copies held elsewhere, is out of scope and left to how
  production is operated.
- File downloads, alerts on suspicious activity, dashboards, and reporting over the trail are out of scope.
- Repeated sign-in attempts are already limited by feature 002 (FR-007). Attempts stopped by that limit never reach a password check and are not
  recorded, so the limit also bounds how quickly failed sign-ins can grow the trail.
- Device and browser details are not recorded; the network address is enough to place a request.
- The 5,000,000-entry, 2-second, and 100-millisecond targets are estimates and can be tuned during planning.
