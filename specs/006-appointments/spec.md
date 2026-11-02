# Feature Specification: Appointments

**Feature Branch**: `006-appointments`

**Created**: 2026-11-01

**Status**: Draft

**Input**: User description: "006 appointments" (from `docs/roadmap.md`, feature 006: "book, reschedule, and cancel; prevent conflicts and handle concurrent booking of the same slot safely")

## Context

Feature 005 works out each doctor's **bookable slots** from their weekly hours and time off. This
feature lets clinic staff **book a registered patient into one of those slots**, find appointments
again, move them to another slot, cancel them, and record when a patient did not come. A booked
slot is no longer offered to anyone else, and two staff members trying to book the same slot at the
same moment can never both succeed.

It covers **the appointment itself**. Checking patients in on arrival and the waiting list belong to
"Patient queues" (007); what happens during the visit belongs to "Medical encounters" (008); fees
and payments belong to "Payments" (009). Patients come from feature 003, doctors and their active
status from feature 004, slots from feature 005, and staff roles from feature 002.

## Clarifications

### Session 2026-11-01

- Q: May a doctor open the full patient record (feature 003) of patients booked with them? → A: No.
  In this feature doctors see only the patient summary in their own appointments; full-record
  access is decided by "Medical encounters" (008). Feature 003, FR-014 is unchanged (FR-017).
- Q: What happens to booked appointments when a doctor's hours, appointment length, or time off
  change (feature 005)? → A: They are kept unchanged and shown as outside availability, and staff
  can filter for them to reschedule or cancel; the schedule change itself is never blocked
  (FR-018).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receptionist books a patient into a free slot (Priority: P1)

A patient calls asking to see a doctor next week. The receptionist looks up the doctor's free slots
(feature 005), picks one with the patient, and books it. The appointment is saved with the patient,
the doctor, the date, and the start and end time, and that slot is no longer offered.

**Why this priority**: Booking is the reason this feature exists; everything else (rescheduling,
cancelling, queues, encounters, payments) starts from a booked appointment.

**Independent Test**: Give a doctor Sunday 09:00–10:00 with 20-minute appointments, book a patient
at 09:20 next Sunday, then request that doctor's slots and confirm only 09:00 and 09:40 remain and
the appointment can be read back with the patient, doctor, date, and times.

**Acceptance Scenarios**:

1. **Given** a signed-in receptionist or administrator, a registered patient, and a free slot of an
   active doctor, **When** they book the patient into that slot, **Then** the appointment is saved
   as scheduled with the slot's date, start, and end, and is returned with the patient summary and
   the doctor.
2. **Given** a booked appointment, **When** anyone requests that doctor's slots, **Then** the booked
   slot is not offered.
3. **Given** a requested time that is not the start of a currently bookable slot of that doctor (not
   in working hours, on time off, in the past, beyond the booking horizon, not on the slot grid, or
   for an inactive doctor), **When** booking is submitted, **Then** it is refused with a message
   saying the slot is not available, and nothing is saved.
4. **Given** a slot already taken by a scheduled appointment, **When** another booking for it is
   submitted, **Then** it is refused as a conflict with a message saying the slot is already booked.
5. **Given** two booking requests for the same slot submitted at the same moment, **When** both are
   processed, **Then** exactly one succeeds and the other is refused as a conflict.
6. **Given** a patient who already has a scheduled appointment whose time overlaps the requested
   slot (with any doctor), **When** booking is submitted, **Then** it is refused as a conflict with
   a message saying the patient is already booked at that time.
7. **Given** an unknown patient or unknown doctor, **When** booking is submitted, **Then** it is
   refused with a validation message.
8. **Given** a signed-in doctor, **When** they try to book an appointment, **Then** it is refused as
   forbidden and nothing is saved.

---

### User Story 2 - Staff find and view appointments (Priority: P1)

The receptionist needs to see tomorrow's appointments for a doctor, or every upcoming appointment of
one patient who is on the phone. They list appointments for a date range, filtered by doctor,
patient, or status, and open one to see its full details.

**Why this priority**: Booking is only useful if staff can find appointments again to confirm,
change, or cancel them. Together with US1 this is the minimum usable slice.

**Independent Test**: Book three appointments across two doctors and two days, then list one
doctor's appointments for one day, list one patient's appointments, and open one appointment,
confirming each result contains exactly the expected appointments in time order.

**Acceptance Scenarios**:

1. **Given** booked appointments, **When** a receptionist or administrator lists appointments for a
   date range, **Then** every appointment in the range is returned in date and time order, in pages
   of a bounded size, each showing date, start, end, status, the doctor, and the patient summary
   (patient number, full name, phone).
2. **Given** a list request, **When** it is filtered by doctor, patient, or status, **Then** only
   matching appointments are returned; filters can be combined.
3. **Given** a date range that ends before it starts or is longer than 92 days, **When** appointments
   are listed, **Then** it is refused with a validation message.
4. **Given** an appointment, **When** a receptionist or administrator opens it, **Then** its full
   details are shown, including the booking note, who booked it and when, and, if applicable, when
   it was last rescheduled and when, by whom, and why it was cancelled.
5. **Given** an unknown appointment, **When** it is opened, **Then** it is reported as not found.
6. **Given** listings, **When** they are returned, **Then** they never include the booking note or
   the patient's national ID, address, or emergency contact.
7. **Given** a booked appointment next Sunday at 09:20, **When** an administrator records time off
   for that day or removes Sunday from the doctor's hours, **Then** the change is saved, the
   appointment keeps its date and times, and it is shown as outside availability in listings and
   when opened.
8. **Given** appointments outside availability, **When** staff list appointments filtered to outside
   availability, **Then** only those scheduled, not-yet-started appointments are returned; **When**
   one is rescheduled or cancelled, or the hours are restored, **Then** it no longer appears there.
9. **Given** a doctor whose appointment length changes from 20 to 30 minutes after a 09:20–09:40
   booking, **When** slots are requested, **Then** no slot overlapping 09:20–09:40 is offered and
   the appointment is not shown as outside availability.

---

### User Story 3 - Receptionist reschedules or cancels an appointment (Priority: P2)

The patient cannot come on Sunday. The receptionist moves the appointment to a free slot on Tuesday
with the same doctor; the Sunday slot becomes free again. Another patient calls to cancel; the
receptionist cancels with an optional reason and the slot is offered again.

**Why this priority**: Changes and cancellations are daily work, but a clinic can start booking with
US1–US2 and handle changes by cancelling and rebooking.

**Independent Test**: Book a patient at 09:20 Sunday, reschedule to 10:00 Tuesday, and confirm the
Sunday 09:20 slot is offered again and Tuesday 10:00 is not; then cancel the appointment and confirm
Tuesday 10:00 is offered again and the appointment shows as cancelled with its reason.

**Acceptance Scenarios**:

1. **Given** a scheduled appointment that has not started, **When** a receptionist or administrator
   reschedules it to another free slot of the same doctor, **Then** the appointment keeps its
   identity, patient, and doctor, takes the new date and times, records when and by whom it was
   rescheduled, and the old slot is offered again.
2. **Given** a reschedule to a slot that is not bookable, already booked, or overlaps another
   scheduled appointment of the same patient, **When** it is submitted, **Then** it is refused with
   the same messages as booking, and the appointment is unchanged.
3. **Given** two requests at the same moment that would move or book different appointments into
   the same slot, **When** both are processed, **Then** exactly one succeeds.
4. **Given** a scheduled appointment that has not started, **When** a receptionist or administrator
   cancels it with an optional reason, **Then** its status becomes cancelled, the cancellation time,
   staff member, and reason are recorded, and the slot is offered again.
5. **Given** an appointment that is cancelled, marked as no-show, or has already started, **When**
   someone tries to reschedule or cancel it, **Then** it is refused with a validation message and
   nothing changes.
6. **Given** a cancelled appointment, **When** its slot is booked again for any patient, **Then** the
   new booking succeeds and the cancelled appointment is kept unchanged for the record.
7. **Given** a signed-in doctor, **When** they try to reschedule or cancel, **Then** it is refused as
   forbidden.

---

### User Story 4 - Staff record that a patient did not come (Priority: P3)

At the end of the day the receptionist marks the appointments of patients who never arrived as
no-show, so the clinic can see who missed their visit.

**Why this priority**: Useful for follow-up and later reports, but booking works without it.

**Independent Test**: Book an appointment, let its start time pass, mark it as no-show, and confirm
its status; then confirm marking a future appointment as no-show is refused.

**Acceptance Scenarios**:

1. **Given** a scheduled appointment whose start time has passed, **When** a receptionist or
   administrator marks it as no-show, **Then** its status becomes no-show and when and by whom it
   was marked is recorded.
2. **Given** an appointment that has not started yet, or is not scheduled, **When** someone marks it
   as no-show, **Then** it is refused with a validation message.
3. **Given** a signed-in doctor, **When** they try to mark a no-show, **Then** it is refused as
   forbidden.

---

### User Story 5 - A doctor sees their own appointments (Priority: P2)

A doctor opens their day to see which patients are booked, at what times, without needing to know
their doctor id.

**Why this priority**: Doctors need to know who is coming, and this is the first feature that links
a doctor to specific patients (feature 003, FR-014).

**Independent Test**: As a doctor with a profile, book two appointments with them and one with
another doctor, then request own appointments for that day and confirm only the two are returned
with the patient summary.

**Acceptance Scenarios**:

1. **Given** a signed-in doctor with a profile, **When** they list their own appointments for a date
   range (optionally by status), **Then** only appointments with them are returned, each with date,
   times, status, and the patient summary (patient number, full name, sex, date of birth).
2. **Given** a signed-in doctor, **When** they open one of their own appointments, **Then** its
   details are shown; **When** they open another doctor's appointment through their own
   appointments, **Then** it is reported as not found, so doctors cannot learn which appointments
   exist; **When** they open any appointment through the staff view, **Then** it is refused as
   forbidden.
3. **Given** a signed-in doctor without a profile, **When** they request their own appointments,
   **Then** it is reported as not found.
4. **Given** a signed-in doctor, **When** they list all appointments (the staff listing), **Then** it
   is refused as forbidden.
5. **Given** a signed-in doctor with an appointment, **When** they try to open that patient's full
   record (feature 003), **Then** it is refused as forbidden, as before this feature.

---

### Edge Cases

- **Slot grid**: An appointment always matches exactly one slot of the doctor at booking time: its
  start is a slot start and its length is that doctor's appointment length then. One patient per
  slot; overbooking is not allowed.
- **Schedule or time off changes after booking**: When an administrator changes a doctor's weekly
  hours or appointment length, or records time off (feature 005), appointments already booked are
  kept with their own date, start, and end, and the change is never blocked. A scheduled
  appointment that has not started is shown as outside availability when its time is no longer
  fully inside one of the doctor's working periods that day, overlaps the doctor's time off, or the
  doctor is inactive. A change of appointment length alone does not make it outside availability.
  Once fixed (rescheduled, cancelled, or the hours restored), it is no longer shown as outside
  availability.
- **Overlap after a length change**: After a doctor's appointment length changes, the new slot grid
  may cut across appointments booked under the old length. A slot that overlaps any scheduled
  appointment of the doctor is not offered, so no doctor is ever double-booked.
- **Doctor becomes inactive**: Existing appointments are kept and can still be viewed, cancelled, or
  marked as no-show; no new booking or reschedule into that doctor's slots is possible while the
  doctor is inactive (feature 005 offers no slots).
- **Past appointments**: Booking, rescheduling, and cancelling in the past are not possible. An
  appointment counts as started once its start time has passed in clinic time.
- **Same patient, same day**: A patient may have several appointments on one day (with the same or
  different doctors) as long as their times do not overlap.
- **Rebooking a freed slot**: Cancelling or rescheduling frees the slot immediately; cancelled and
  no-show appointments never block a slot.
- **Concurrency**: Two staff members booking or moving appointments into the same slot, or booking
  the same patient into overlapping times, at the same moment: exactly one succeeds; the other gets
  a conflict and nothing partial is saved.
- **No deletion**: Appointments are never deleted; cancelled and no-show appointments stay for the
  record and for later queues, encounters, payments, and reports.
- **Clinic time**: All dates and times are the clinic's local wall-clock time (Egypt), as in feature
  005.
- **Booking note**: The optional note (for example "first visit" or "bring previous X-ray") is
  administrative text, at most 500 characters, shown only when one appointment is opened. Its
  description tells staff not to enter medical details; clinical notes belong to feature 008.
- **Languages**: Validation, conflict, and refusal messages are returned in English or Arabic per
  the request's language with identical response structure; statuses are fixed English identifiers
  (`scheduled`, `cancelled`, `no_show`), never translated. Notes and reasons are returned exactly as
  entered.
- **Privacy**: Logs refer to patients and appointments by internal identifier only (Constitution
  XII). Listings carry only the patient summary.

## Requirements *(mandatory)*

### Functional Requirements

**Booking**

- **FR-001**: Receptionists and administrators MUST be able to book a registered patient (feature
  003) with a doctor at a given date and start time, with an optional booking note of at most 500
  characters.
- **FR-002**: A booking MUST be accepted only if the requested start is the start of a slot the
  doctor currently offers (feature 005); the appointment's end MUST be that slot's end.
- **FR-003**: A doctor MUST NOT have two scheduled appointments whose times overlap, so a slot holds
  at most one scheduled appointment; this MUST hold even when bookings or reschedules for the same
  slot are submitted at the same moment (Constitution XI).
- **FR-004**: A patient MUST NOT have two scheduled appointments whose times overlap, with any
  doctors; this MUST also hold under simultaneous requests.
- **FR-005**: Booking, rescheduling, cancelling, and marking no-show MUST each be all-or-nothing:
  either the whole change is saved or nothing changes.
- **FR-006**: Slots offered by feature 005 MUST exclude every slot that overlaps a scheduled
  appointment of that doctor, both for staff and for a doctor's own slots.
- **FR-007**: A refused booking or reschedule MUST say why: slot not available, slot already booked,
  or patient already booked at that time; the last two MUST be reported as conflicts.

**Viewing**

- **FR-008**: Receptionists and administrators MUST be able to list appointments for a date range of
  at most 92 days, filtered optionally by doctor, patient, status, and outside availability
  (FR-018), in date and time order, in pages of a bounded size.
- **FR-009**: Listings MUST show only date, start, end, status, whether the appointment is outside
  availability, the doctor (id and name), and the patient summary; the booking note and the
  patient's sensitive fields (feature 003, FR-013) MUST NOT appear in listings.
- **FR-010**: Opening one appointment MUST show its full details: listing fields plus booking note,
  who booked it and when, last reschedule time and staff member, and cancellation or no-show time,
  staff member, and reason.

**Changing**

- **FR-011**: Receptionists and administrators MUST be able to reschedule a scheduled appointment
  that has not started to another currently bookable slot of the same doctor, under the same rules
  as booking (FR-002–FR-004); the appointment keeps its identity, and the old slot is freed.
- **FR-012**: Receptionists and administrators MUST be able to cancel a scheduled appointment that
  has not started, with an optional reason of at most 255 characters; the slot is freed.
- **FR-013**: Receptionists and administrators MUST be able to mark a scheduled appointment whose
  start has passed as no-show.
- **FR-014**: Cancelled and no-show appointments MUST NOT be rescheduled, cancelled, or changed
  again; appointments MUST never be deleted.

**Doctors**

- **FR-015**: A signed-in doctor MUST be able to list (for a date range of at most 92 days,
  optionally by status) and open their own appointments without giving a doctor id; their listing
  shows the patient summary of patient number, full name, sex, and date of birth.
- **FR-016**: Doctors MUST NOT book, reschedule, cancel, mark no-show, use the staff listing, or open
  other doctors' appointments.
- **FR-017**: Doctors MUST NOT gain access to full patient records (feature 003) through
  appointments; feature 003, FR-014 is unchanged, and full-record access is decided by feature 008.

**Schedule changes**

- **FR-018**: When a doctor's weekly schedule, appointment length, or time off changes (feature
  005), already booked appointments MUST be kept unchanged and the change MUST NOT be blocked. A
  scheduled appointment that has not started MUST be shown as outside availability whenever its
  time is not fully inside one of the doctor's working periods that day, overlaps the doctor's time
  off, or the doctor is inactive; a change of appointment length alone MUST NOT make it so. This
  MUST be reflected immediately in listings, the single appointment view, and the doctor's own
  appointments.

**Cross-cutting**

- **FR-019**: All actions MUST require a signed-in, active staff account and MUST be authorized
  through the system's single authorization mechanism (feature 002, FR-012); refused requests are
  reported as forbidden, distinct from unauthenticated.
- **FR-020**: All dates and times MUST be interpreted and returned in the clinic's local time zone
  (Egypt), as in feature 005.
- **FR-021**: All user-facing messages from this feature MUST exist in English and Arabic, with
  identical response field names and structure.
- **FR-022**: Logs MUST identify patients and appointments only by internal identifier; seed data,
  fixtures, and tests MUST use synthetic data only (Constitution XII).
- **FR-023**: Appointment capabilities MUST follow the project's existing API versioning and module
  conventions established by features 001–005.

### Key Entities *(include if feature involves data)*

- **Appointment**: A patient booked with a doctor for one slot. Patient (feature 003), doctor
  profile (feature 004), date, start time, end time, status (`scheduled`, `cancelled`, `no_show`),
  optional booking note, who booked it and when, when and by whom it was last rescheduled, when and
  by whom it was cancelled or marked no-show, and an optional cancellation reason. Never deleted.
  Later features (queues, encounters, payments, reports) refer to it.
- **Slot** (feature 005): Derived, not stored. A slot is free when no scheduled appointment of that
  doctor holds it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A receptionist can find a free slot and book a patient into it in under 1 minute of
  work.
- **SC-002**: 0 double bookings: in automated tests that submit many simultaneous bookings for the
  same slot, exactly one succeeds every time, and the same holds for overlapping bookings of one
  patient.
- **SC-003**: 100% of tested bookings and reschedules into unavailable slots (outside hours, on time
  off, in the past, off the slot grid, inactive doctor) are refused and save nothing, as shown by
  automated tests.
- **SC-004**: After every booking, reschedule, and cancellation in automated tests, the doctor's
  offered slots are exactly the slots that overlap no scheduled appointment.
- **SC-005**: Staff receive one day's appointments for a doctor, or a patient's appointment history,
  in under 2 seconds for a clinic with 100,000 appointments.
- **SC-006**: 100% of booking, changing, and staff listing actions are refused for doctors, doctors
  see only their own appointments, and all actions refuse unauthenticated requests, as shown by
  automated tests covering every role against every action.
- **SC-007**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.
- **SC-008**: 100% of tested schedule, appointment-length, time off, and doctor-status changes leave
  booked appointments unchanged and mark exactly the affected scheduled, not-yet-started
  appointments as outside availability, as shown by automated tests.

## Assumptions

- Patients come from feature 003, doctor profiles and active status from feature 004, slots from
  feature 005, roles from feature 002; this feature changes feature 005 only so that slots
  overlapping booked appointments are no longer offered. Schedule and time off changes in feature
  005 are never blocked by appointments.
- Outside availability is a view of each appointment against the doctor's current hours, not a
  separate status; staff decide whether to reschedule or cancel. Patients are not notified
  automatically.
- Receptionists and administrators have the same appointment permissions; doctors only read their
  own.
- Rescheduling stays with the same doctor; moving a patient to another doctor is done by cancelling
  and booking again.
- One patient per slot; overbooking and walk-in squeezing belong to "Patient queues" (007) if
  needed.
- Statuses in this feature are scheduled, cancelled, and no-show; check-in and completion are added
  by features 007 and 008. A no-show cannot be undone in this feature.
- No limit on how many upcoming appointments a patient may hold, beyond the no-overlap rule.
- Patient notifications (SMS, reminders) are out of scope.
- A detailed change history of each appointment belongs to "Audit logs"; this feature keeps the
  latest booking, reschedule, and cancellation details on the appointment.
- The 92-day listing range and the 500/255-character limits follow common practice and can be tuned
  during planning; the booking horizon is feature 005's 90 days.
