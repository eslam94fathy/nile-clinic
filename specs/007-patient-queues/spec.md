# Feature Specification: Patient Queues

**Feature Branch**: `007-patient-queues`

**Created**: 2026-11-02

**Status**: Draft

**Input**: User description: "007 patient queues" (from `docs/roadmap.md`, feature 007: "check-in on arrival and a per-doctor waiting list for the day")

## Context

Feature 006 lets reception book patients into a doctor's slots. On the day itself, patients arrive
at the clinic, wait, and are seen one after another. This feature records **that a patient has
arrived** (check-in) and keeps **each doctor's waiting list for today**: who is waiting, in what
order, who is with the doctor now, and who has been seen. Reception uses it to manage the waiting
area; doctors use it to call their next patient.

It covers **the day's flow from arrival to leaving the doctor**. What happens during the visit
(diagnosis, notes, prescriptions) belongs to "Medical encounters" (008); fees and payments belong
to "Payments" (009). Appointments come from feature 006, patients from 003, doctors and their
active status from 004, and staff roles from 002. There is no patient-facing screen or waiting-room
display in this feature (Constitution II).

## Clarifications

### Session 2026-11-02

- Q: Are walk-ins (patients without an appointment) part of this feature? → A: Yes. A walk-in joins
  a doctor's waiting list for today without taking any slot; the doctor's bookable slots are
  unchanged (US4, FR-006).
- Q: In what order are waiting patients called? → A: By appointment time for booked patients;
  walk-ins are placed among them by their arrival time (FR-008).
- Q: Does this feature finish the visit, or is completion left to "Medical encounters" (008)? → A:
  This feature finishes it: the doctor marks the visit as finished, the entry becomes seen, and the
  appointment becomes completed; feature 008 will attach the visit record to it (US3-5, FR-012).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receptionist checks in an arriving patient (Priority: P1)

A patient with an appointment today walks up to the reception desk. The receptionist finds the
appointment and checks the patient in. The patient gets a queue number for that doctor's list today
and is now waiting.

**Why this priority**: Check-in is the start of everything else in this feature; without it there
is no waiting list.

**Independent Test**: Book a patient for today with a doctor, check them in, and confirm the
appointment shows as checked in and the patient appears in that doctor's waiting list for today
with queue number 1 and status waiting.

**Acceptance Scenarios**:

1. **Given** a signed-in receptionist or administrator and a scheduled appointment for today,
   **When** they check the patient in, **Then** the arrival time and the staff member are recorded,
   the appointment shows as checked in, and the patient joins that doctor's waiting list for today
   as waiting with the next queue number for that doctor and day.
2. **Given** a patient who arrives before or after their appointment time on the same day, **When**
   they are checked in, **Then** the check-in is accepted (early and late arrivals on the day are
   allowed).
3. **Given** an appointment for another day, or one that is cancelled, marked no-show, or already
   checked in, **When** check-in is submitted, **Then** it is refused with a validation message and
   nothing changes.
4. **Given** two check-in requests for the same appointment at the same moment, **When** both are
   processed, **Then** exactly one succeeds and the patient is in the list once.
5. **Given** several patients checked in for one doctor at the same moment, **When** all are
   processed, **Then** each gets a different queue number and no number is skipped or repeated.
6. **Given** a checked-in appointment, **When** someone tries to reschedule, cancel, or mark it as
   no-show (feature 006), **Then** it is refused with a validation message.
7. **Given** a signed-in doctor, **When** they try to check a patient in, **Then** it is refused as
   forbidden.

---

### User Story 2 - Reception sees the waiting lists for today (Priority: P1)

The receptionist wants to see, for one doctor or for the whole clinic, who is waiting, who is with
the doctor, and who has been seen today, in queue order, so they can answer "how long until my
turn?" and spot patients who have not been called.

**Why this priority**: A waiting list is only useful if staff can see it. Together with US1 this is
the minimum usable slice.

**Independent Test**: Check in three patients for two doctors, then list one doctor's waiting list
and the whole clinic's lists for today, confirming each contains exactly the expected patients in
queue order with their status.

**Acceptance Scenarios**:

1. **Given** checked-in patients, **When** a receptionist or administrator requests a doctor's
   waiting list for today, **Then** every entry of that doctor today is returned in queue order,
   each showing the queue number, status, arrival time, the appointment time (if any), and the
   patient summary (patient number, full name, phone).
2. **Given** checked-in patients for several doctors, **When** the clinic's lists for today are
   requested, optionally filtered by status, **Then** the entries of every doctor are returned,
   grouped by doctor and in queue order within each doctor.
3. **Given** waiting lists, **When** they are returned, **Then** they never include the booking
   note or the patient's national ID, address, or emergency contact.
4. **Given** a doctor with no check-ins today, **When** their list is requested, **Then** an empty
   list is returned; **Given** an unknown doctor, **Then** it is reported as not found.
5. **Given** a signed-in doctor, **When** they request the staff waiting lists, **Then** it is
   refused as forbidden.

---

### User Story 3 - A doctor calls the next patient and finishes the visit (Priority: P2)

The doctor finishes with one patient and wants the next. They see their own waiting list, call the
next patient, and when the visit ends, mark it as finished so the next one can be called.

**Why this priority**: This is how the waiting list moves; without it reception has to tell the
doctor who is next by hand. A clinic can start with US1–US2 and call patients verbally.

**Independent Test**: As a doctor with two waiting patients, call next and confirm the first in
queue order is now with the doctor; finish the visit and confirm it is marked as seen; call next
again and confirm the second patient is now with the doctor.

**Acceptance Scenarios**:

1. **Given** a signed-in doctor with a profile, **When** they request their own waiting list for
   today, **Then** only their entries are returned in queue order, each with the queue number,
   status, arrival time, appointment time, and the patient summary (patient number, full name, sex,
   date of birth).
2. **Given** waiting patients and nobody with the doctor, **When** the doctor calls the next
   patient, **Then** the first waiting entry in queue order becomes "with doctor" and the time it
   was called is recorded.
3. **Given** a patient already with the doctor, **When** the doctor calls the next patient, **Then**
   it is refused with a message that the current visit must be finished first.
4. **Given** no waiting patients, **When** the doctor calls the next patient, **Then** it is
   reported that nobody is waiting, and nothing changes.
5. **Given** a patient with the doctor, **When** the doctor marks the visit as finished, **Then**
   the entry becomes "seen" with the time it finished, the appointment (if any) becomes completed,
   and the doctor can call the next patient.
6. **Given** two "call next" requests from the same doctor at the same moment, **When** both are
   processed, **Then** exactly one patient is called.
7. **Given** a signed-in doctor without a profile, **When** they request their own list or call
   next, **Then** it is reported as not found; **Given** a receptionist or administrator, **When**
   they use the doctor's own-list actions, **Then** it is refused as forbidden.

---

### User Story 4 - Reception adds a walk-in patient (Priority: P2)

A registered patient arrives without an appointment and asks to see a doctor today. The receptionist
adds them to that doctor's waiting list.

**Why this priority**: Walk-ins are common in clinics, but a clinic can work with booked patients
first.

**Independent Test**: Add a registered patient without an appointment to a doctor's list for today
and confirm they appear as waiting with the next queue number, marked as a walk-in.

**Acceptance Scenarios**:

1. **Given** a registered patient and an active doctor, **When** a receptionist or administrator
   adds the patient as a walk-in to that doctor's list today, **Then** an entry is created as
   waiting with the next queue number, marked as a walk-in, with the arrival time and staff member
   recorded; no slot is taken, so the doctor's bookable slots are unchanged.
2. **Given** a patient who is already waiting or with this doctor today, **When** they are added
   again, **Then** it is refused with a validation message.
3. **Given** an unknown patient or an unknown or inactive doctor, **When** a walk-in is submitted,
   **Then** it is refused with a validation message.

---

### User Story 5 - Reception corrects the waiting list (Priority: P3)

A patient gets tired of waiting and leaves, or reception checked in the wrong appointment. The
receptionist marks the patient as left, or undoes the check-in.

**Why this priority**: Corrections keep the list accurate, but they are rarer than the main flow.

**Independent Test**: Check in two patients, mark one as left and undo the other's check-in;
confirm the first shows as left and is skipped by "call next", and the second's appointment is
scheduled again and no longer in the list.

**Acceptance Scenarios**:

1. **Given** a waiting entry, **When** a receptionist or administrator marks it as left, **Then** it
   becomes "left" with the time and staff member recorded, stays in the list for the record, and is
   never called.
2. **Given** a waiting entry for an appointment, **When** a receptionist or administrator undoes the
   check-in, **Then** the entry is removed from the list, the appointment is scheduled again as
   before check-in, and its queue number is not reused.
3. **Given** an entry that is with the doctor, seen, or left, **When** someone marks it as left or
   undoes its check-in, **Then** it is refused with a validation message.
4. **Given** a signed-in doctor, **When** they try to mark a patient as left or undo a check-in,
   **Then** it is refused as forbidden.

---

### Edge Cases

- **Today only**: Check-in, walk-ins, and calling happen only for the clinic's today. Lists can be
  requested for today; past days' lists remain stored for the record but are not changed by this
  feature.
- **End of day**: Nothing changes automatically at midnight. Entries still waiting or with the
  doctor at the end of the day keep their status; reception can mark waiting patients as left.
- **Appointment interplay**: A checked-in appointment cannot be rescheduled, cancelled, or marked
  no-show (feature 006). Undoing the check-in makes it scheduled again, with all feature 006 rules.
- **Late arrival after the slot**: A patient who arrives after their slot has ended the same day can
  still be checked in; feature 006 would otherwise let reception mark the appointment as no-show.
  Because booked patients are ordered by appointment time, a late patient goes ahead of patients
  booked later; reception can mark entries as left or undo a check-in when needed.
- **Early arrival**: A patient who arrives early waits in their appointment-time position; the
  doctor may still call them first if nobody is ahead of them in the list.
- **Walk-ins and slots**: Walk-ins never take or hide slots; on a busy day the doctor's list can be
  longer than the booked slots.
- **Doctor inactive or outside availability**: Patients with an appointment can still be checked
  in (the patient is physically here); reception decides what to do. Walk-ins need an active doctor.
- **Several appointments one day**: A patient checked in for two doctors on the same day appears in
  both lists; they can be with only one doctor at a time.
- **Queue numbers**: Start at 1 for each doctor each day, increase by one per check-in or walk-in,
  and are never reused that day, even after an undo.
- **Concurrency**: Simultaneous check-ins, walk-ins, and calls never produce duplicate entries,
  duplicate or skipped queue numbers, or two patients with one doctor at once.
- **No deletion**: Queue entries are never deleted, except that undoing a check-in removes its entry
  (it was a mistake); the queue number stays used.
- **Clinic time**: All dates and times are the clinic's local wall-clock time (Egypt), as in
  features 005 and 006.
- **Languages**: Messages are returned in English or Arabic per the request's language with
  identical structure; statuses are fixed English identifiers, never translated.
- **Privacy**: Waiting lists carry only the patient summary; logs refer to patients by internal
  identifier only (Constitution XII).

## Requirements *(mandatory)*

### Functional Requirements

**Check-in**

- **FR-001**: Receptionists and administrators MUST be able to check in a patient for a scheduled
  appointment whose date is the clinic's today, at any time that day.
- **FR-002**: Check-in MUST record the arrival time and staff member, show the appointment as
  checked in, and add the patient to that appointment's doctor's waiting list for today as waiting.
- **FR-003**: Each new entry in a doctor's list for a day MUST get the next queue number for that
  doctor and day, starting at 1, unique and without gaps from concurrent requests; numbers MUST NOT
  be reused that day.
- **FR-004**: An appointment MUST be checked in at most once, even under simultaneous requests;
  cancelled, no-show, already checked-in, and other-day appointments MUST be refused.
- **FR-005**: A checked-in appointment MUST NOT be rescheduled, cancelled, or marked no-show.
- **FR-006**: Walk-in patients without an appointment MUST be addable to an active doctor's list for
  today as waiting entries marked as walk-ins. A walk-in MUST NOT take or hide any slot.

**Waiting lists**

- **FR-007**: Receptionists and administrators MUST be able to see one doctor's waiting list for
  today, and all doctors' lists for today optionally filtered by status, in queue order.
- **FR-008**: Waiting lists MUST be in queue order: each booked patient by their appointment start
  time, each walk-in by their arrival time, merged into one order by that time; equal times by
  queue number. "Call next" MUST pick the first waiting entry in this order.
- **FR-009**: Staff waiting lists MUST show only queue number, status, arrival time, call and finish
  times, whether the entry is a walk-in, the appointment time (if any), and the patient summary
  (patient number, full name, phone); the booking note and sensitive patient fields MUST NOT appear.

**Doctors**

- **FR-010**: A signed-in doctor MUST be able to see their own waiting list for today without giving
  a doctor id, with the patient summary of patient number, full name, sex, and date of birth.
- **FR-011**: A doctor MUST be able to call the next waiting patient in queue order; a doctor MUST
  have at most one patient with them at a time, also under simultaneous requests.
- **FR-012**: A doctor MUST be able to mark the visit of the patient with them as finished: the
  entry becomes seen and its appointment, if any, becomes completed. Completed appointments, like
  checked-in ones, MUST NOT be rescheduled, cancelled, or marked no-show.
- **FR-013**: Doctors MUST NOT check in, add walk-ins, mark patients as left, undo check-ins, see
  other doctors' lists, or gain access to full patient records (feature 003, FR-014 unchanged).

**Corrections**

- **FR-014**: Receptionists and administrators MUST be able to mark a waiting entry as left, and
  undo the check-in of a waiting entry for an appointment, which makes the appointment scheduled
  again.
- **FR-015**: Entries that are with the doctor, seen, or left MUST NOT be marked as left or undone.

**Cross-cutting**

- **FR-016**: Every change in this feature MUST be all-or-nothing.
- **FR-017**: All actions MUST require a signed-in, active staff account and MUST be authorized
  through the system's single authorization mechanism (feature 002); refused requests are reported
  as forbidden, distinct from unauthenticated.
- **FR-018**: All dates and times MUST be the clinic's local time (Egypt), as in features 005–006.
- **FR-019**: All user-facing messages MUST exist in English and Arabic with identical response
  field names and structure.
- **FR-020**: Logs MUST identify patients only by internal identifier; seed data, fixtures, and
  tests MUST use synthetic data only (Constitution XII).
- **FR-021**: Queue capabilities MUST follow the API versioning and module conventions established
  by features 001–006.

### Key Entities *(include if feature involves data)*

- **Queue entry**: One patient in one doctor's waiting list for one clinic day. Doctor, patient,
  date, queue number, status (`waiting`, `with_doctor`, `seen`, `left`), the appointment it came
  from (none for a walk-in), arrival time and staff member, call time, finish time, and when and by
  whom it was marked as left.
- **Appointment** (feature 006): Gains two states: checked in (its patient is in the list) and
  completed (the doctor finished the visit). Neither can be rescheduled, cancelled, or marked
  no-show; undoing a check-in makes it scheduled again. Feature 008 will attach the visit record.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A receptionist can find today's appointment of an arriving patient and check them in
  in under 30 seconds of work.
- **SC-002**: 0 duplicate check-ins, 0 duplicate or skipped queue numbers, and never two patients
  with one doctor at once: in automated tests that submit many simultaneous check-ins and calls,
  every result is consistent every time.
- **SC-003**: A doctor can call their next patient in one action, and the patient called is the
  first waiting entry in queue order in 100% of automated tests.
- **SC-004**: Staff receive a doctor's or the whole clinic's waiting lists for today in under 2
  seconds with 500 doctors and 5,000 entries in the day.
- **SC-005**: 100% of reception actions are refused for doctors, doctors see only their own list,
  and all actions refuse unauthenticated requests, as shown by automated tests covering every role
  against every action.
- **SC-006**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Appointments come from feature 006, patients from 003, doctors and active status from 004, roles
  from 002. This feature changes feature 006 only by adding the checked-in and completed
  appointment states: neither can be rescheduled, cancelled, or marked no-show, and undoing a
  check-in makes the appointment scheduled again.
- Receptionists and administrators have the same queue permissions; doctors act only on their own
  list.
- There is no waiting-room display, patient notification, or patient self check-in; these would
  need an approved scope change (Constitution II).
- Estimated waiting times are out of scope; staff see the order and statuses.
- Moving a waiting patient to another doctor is done by undoing the check-in and handling the
  appointment under feature 006.
- A detailed change history belongs to "Audit logs"; each entry keeps its latest times and staff
  members.
- The 2-second and 5,000-entry targets follow the scale of features 005–006 and can be tuned during
  planning.
