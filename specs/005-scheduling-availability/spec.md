# Feature Specification: Scheduling and Availability

**Feature Branch**: `005-scheduling-availability`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "005 scheduling and availability" (from `docs/roadmap.md`, feature 005: "doctor working hours, breaks, and bookable slots")

## Context

Before patients can be booked (feature 006), the clinic needs to know **when each doctor sees
patients**. This feature lets administrators record each doctor's regular weekly working hours,
the length of that doctor's appointments, and the dates or hours a doctor is away. From these the
system works out the **bookable slots** for any doctor and date range, which staff use to find a
free time and which appointments will later consume.

It covers **when doctors are available only**. Booking, rescheduling, and cancelling appointments,
and marking slots as taken, belong to "Appointments" (006). Doctors and their active status come
from feature 004; staff roles from feature 002.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Administrator sets a doctor's weekly working hours (Priority: P1)

An administrator records, for each day of the week, the periods in which a doctor sees patients —
for example Sunday 09:00–13:00 and 17:00–21:00, Tuesday 10:00–14:00 — and the length of that
doctor's appointments (for example 20 minutes). The gap between two periods on the same day is the
doctor's break.

**Why this priority**: Without working hours there are no slots, and no appointment can be booked.
This is the minimum every later scheduling and booking step depends on.

**Independent Test**: As an administrator, give a doctor two periods on Sunday and one on Tuesday
with 20-minute appointments, then read the doctor's weekly schedule back and confirm the periods,
the break between the Sunday periods, and the appointment length.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator and an active doctor, **When** they set the doctor's weekly
   schedule with an appointment length and one or more periods on some days, **Then** the schedule
   is saved and returned grouped by day, with days that have no period shown as days off.
2. **Given** a doctor who already has a weekly schedule, **When** an administrator sets a new one,
   **Then** it replaces the previous one entirely.
3. **Given** a period whose end is not after its start, two periods on the same day that overlap or
   touch, a time not on a 5-minute boundary, or a period shorter than one appointment, **When** the
   schedule is submitted, **Then** it is rejected with a validation message naming the problem day
   and period, and the previous schedule stays unchanged.
4. **Given** an appointment length outside 5–240 minutes or not a multiple of 5, **When** the
   schedule is submitted, **Then** it is rejected with a validation message.
5. **Given** an administrator, **When** they clear a doctor's weekly schedule, **Then** the doctor
   has no working hours and no slots.
6. **Given** any signed-in staff member, **When** they view a doctor's weekly schedule, **Then** it
   is shown; **Given** an unknown doctor, **Then** it is reported as not found.
7. **Given** a signed-in receptionist or doctor, **When** they try to set or clear a weekly
   schedule, **Then** it is refused as forbidden and nothing changes.

---

### User Story 2 - Staff see a doctor's bookable slots (Priority: P1)

A receptionist needs a free time with a doctor next week. They ask for the doctor's slots between
two dates and get every start time, in order, with the slot's end time, cut from the doctor's
working periods by the appointment length.

**Why this priority**: Slots are what appointments are booked into. Together with US1 this is the
minimum slice feature 006 needs.

**Independent Test**: Give a doctor Sunday 09:00–10:00 with 20-minute appointments, then ask for
slots for a range containing one Sunday and confirm exactly 09:00, 09:20, and 09:40 are returned
for that date.

**Acceptance Scenarios**:

1. **Given** a doctor with working periods, **When** staff request slots for a date range, **Then**
   every slot within the range is returned in date and time order, each with its date, start, and
   end; a period's leftover time shorter than one appointment produces no slot.
2. **Given** a requested range that includes today, **When** slots are returned, **Then** slots
   that have already started are excluded.
3. **Given** a range that starts before today, ends before it starts, is longer than 31 days, or
   reaches beyond the booking horizon, **When** slots are requested, **Then** it is rejected with a
   validation message.
4. **Given** a doctor who is inactive (feature 004), or who has no weekly schedule, **When** slots
   are requested, **Then** the result is an empty list, not an error.
5. **Given** an unknown doctor, **When** slots are requested, **Then** it is reported as not found.
6. **Given** any signed-in staff member, **When** they request slots, **Then** they are allowed.

---

### User Story 3 - Administrator records a doctor's time off (Priority: P2)

A doctor will be at a conference for three days, or leaves early one afternoon. An administrator
records the time off — whole days or part of one day — with an optional reason, and the affected
slots disappear. Time off can be listed and removed if plans change.

**Why this priority**: Clinics run with regular hours most of the time; absences matter as soon as
booking starts, but the weekly schedule and slots are usable without them.

**Independent Test**: Give a doctor working hours, record a whole-day time off for next Sunday and
a 10:00–12:00 time off for next Monday, then request slots for that week and confirm Sunday has
none and Monday has none between 10:00 and 12:00.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they record time off from one date to another
   (whole days), **Then** it is saved and the doctor has no slots on those dates.
2. **Given** a signed-in administrator, **When** they record time off on one date between two
   times, **Then** no slot that overlaps that window is offered on that date; slots before and
   after it remain.
3. **Given** time off whose end is before its start, partial-day times on more than one date, times
   not on a 5-minute boundary, or a start date in the past, **When** it is submitted, **Then** it
   is rejected with a validation message.
4. **Given** time off that overlaps time off already recorded for the same doctor, **When** it is
   submitted, **Then** it is accepted; overlapping time off simply removes the same slots.
5. **Given** a doctor's time off, **When** any signed-in staff member lists it for a date range,
   **Then** every entry overlapping the range is shown with its dates, times, and reason.
6. **Given** a time off entry that has not ended, **When** an administrator removes it, **Then** the
   future slots it hid are offered again; **Given** one that has ended, **Then** removal is refused
   with a validation message.
7. **Given** a signed-in receptionist or doctor, **When** they try to record or remove time off,
   **Then** it is refused as forbidden and nothing changes.

---

### User Story 4 - A doctor views their own schedule and slots (Priority: P3)

A signed-in doctor checks their own weekly hours, time off, and upcoming slots without needing to
know their doctor id.

**Why this priority**: Convenient for doctors, but staff can already answer the same question
through US1–US3.

**Independent Test**: As a doctor with a profile and weekly schedule, request own schedule and own
slots for next week and receive the same results an administrator sees for that doctor.

**Acceptance Scenarios**:

1. **Given** a signed-in doctor with a profile, **When** they request their own weekly schedule,
   time off, or slots, **Then** they receive the same data staff see for their doctor profile.
2. **Given** a signed-in doctor without a profile, **When** they request any of these, **Then** it
   is reported as not found.
3. **Given** a signed-in administrator or receptionist, **When** they use these own-schedule
   requests, **Then** they are refused as forbidden.

---

### Edge Cases

- **Clinic time**: All dates and times are the clinic's local wall-clock time (Egypt). A period
  09:00–13:00 means 09:00–13:00 on every such day, including across daylight-saving changes. On a
  daylight-saving change day, slots follow the wall clock; a slot whose start time does not exist
  that day is not offered, and a repeated hour is offered once.
- **Breaks**: A break is the gap between two periods on the same day. A day may have up to 4
  periods. Periods are listed in time order whatever order they were entered in.
- **Midnight**: A period cannot run past midnight; the latest end time is 24:00 (end of day).
  Overnight shifts are out of scope.
- **Appointment length change**: Changing the appointment length changes all future slots at once.
  Effects on appointments already booked are handled by feature 006.
- **Schedule change**: A new weekly schedule applies to all future slots immediately; there is no
  "effective from" date in this feature.
- **Inactive doctor**: A doctor who is inactive (account deactivated or no longer a doctor, feature
  004) keeps their schedule and time off but has no slots until active again.
- **No deletion of doctors**: Schedules belong to doctor profiles, which are never deleted (feature
  004). Time off that has ended is kept for the record and cannot be removed.
- **Doctor returns early**: Time off that has started but not ended can be removed; only its
  remaining future slots become bookable again, since past slots are never offered.
- **Languages**: Validation and refusal messages are returned in English or Arabic per the
  request's language with identical response structure; day names in responses are fixed English
  identifiers (`sunday` … `saturday`), never translated. Time off reasons are returned exactly as
  entered.
- **Privacy**: Schedules, time off, and slots contain no patient data. A time off reason is free
  text visible to all staff; the description of the field tells administrators not to enter
  medical or personal details.
- **Week start**: Days are named, not numbered, so no week-start convention is needed.

## Requirements *(mandatory)*

### Functional Requirements

**Weekly schedule**

- **FR-001**: Each doctor MUST have at most one weekly schedule, made of an appointment length and
  zero or more working periods, each on one day of the week with a start and end time.
- **FR-002**: The appointment length MUST be between 5 and 240 minutes and a multiple of 5.
- **FR-003**: Period start and end times MUST be on 5-minute boundaries, the end MUST be after the
  start, the end MUST be no later than 24:00, and each period MUST be at least one appointment
  long.
- **FR-004**: Periods on the same day MUST NOT overlap or touch; a day MUST have at most 4 periods.
- **FR-005**: Setting a weekly schedule MUST replace the previous one entirely and atomically: either
  the whole new schedule is saved or nothing changes.
- **FR-006**: Administrators MUST be able to set and clear any doctor's weekly schedule.
- **FR-007**: All signed-in staff MUST be able to view any doctor's weekly schedule, returned for
  all seven days in a fixed order with periods in time order.

**Bookable slots**

- **FR-008**: The system MUST derive slots for a doctor and date range by cutting each working
  period, from its start, into consecutive pieces of the appointment length; leftover time shorter
  than one appointment MUST produce no slot.
- **FR-009**: Slots MUST NOT be offered on time off (FR-013–FR-014), in the past, for an inactive
  doctor, or for a doctor without a weekly schedule.
- **FR-010**: A slots request MUST give a start and end date; the range MUST NOT start before today,
  MUST NOT be longer than 31 days, and MUST NOT end more than 90 days after today (the booking
  horizon).
- **FR-011**: Slots MUST be returned in date and time order, each with its date, start time, and
  end time.
- **FR-012**: All signed-in staff MUST be able to request any doctor's slots.

**Time off**

- **FR-013**: Administrators MUST be able to record a doctor's time off as either whole days (a
  start date and end date, inclusive) or part of a single day (one date with a start and end time),
  with an optional reason of at most 255 characters.
- **FR-014**: A partial-day time off MUST use times on 5-minute boundaries with the end after the
  start; any slot overlapping its window MUST NOT be offered.
- **FR-015**: Time off MUST NOT start in the past; overlapping time off for the same doctor MUST be
  accepted.
- **FR-016**: All signed-in staff MUST be able to list a doctor's time off overlapping a date range.
- **FR-017**: Administrators MUST be able to remove a time off entry that has not ended yet;
  removing one that has ended MUST be refused with a validation message.

**Own schedule**

- **FR-018**: A signed-in doctor MUST be able to view their own weekly schedule, time off, and slots
  without giving a doctor id; other roles MUST be refused.

**Cross-cutting**

- **FR-019**: All actions MUST require a signed-in, active staff account and MUST be authorized
  through the system's single authorization mechanism (feature 002, FR-012); refused requests are
  reported as forbidden, distinct from unauthenticated.
- **FR-020**: All dates and times MUST be interpreted and returned in the clinic's local time zone
  (Egypt).
- **FR-021**: All user-facing messages from this feature MUST exist in English and Arabic, with
  identical response field names and structure.
- **FR-022**: Seed data, fixtures, and tests MUST use synthetic data only (Constitution XII).
- **FR-023**: Scheduling capabilities MUST follow the project's existing API versioning and module
  conventions established by features 001–004.

### Key Entities *(include if feature involves data)*

- **Weekly schedule**: A doctor's regular working pattern. Belongs to one doctor profile (feature
  004); holds the appointment length and when it was last changed.
- **Working period**: One block of working time in the weekly schedule: day of the week, start
  time, end time. A day may have several; gaps between them are breaks.
- **Time off**: A doctor's absence: start date, end date, optional start and end time (for part of
  one day), optional reason, and when it was recorded. Belongs to one doctor profile.
- **Slot**: A bookable time for a doctor: date, start time, end time. Derived from the weekly
  schedule and time off when requested; not stored in this feature. Feature 006 books appointments
  into slots.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can set a doctor's full weekly schedule in a single request in under
  2 minutes of work.
- **SC-002**: Staff receive a doctor's slots for a 31-day range in under 2 seconds, for a clinic
  with 500 doctors.
- **SC-003**: 100% of tested schedules and time off produce exactly the expected slots: no slot
  outside working periods, during time off, in the past, or shorter than the appointment length,
  as shown by automated tests.
- **SC-004**: 0 invalid schedules (overlapping periods, end before start, period shorter than an
  appointment) can be stored, and a rejected schedule leaves the previous one unchanged, as shown
  by automated tests.
- **SC-005**: 100% of schedule and time off changes are refused for receptionists and doctors, and
  all actions refuse unauthenticated requests, as shown by automated tests covering every role
  against every action.
- **SC-006**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Doctor profiles and their active status come from feature 004; staff roles from feature 002.
  This feature does not change either.
- Only administrators manage schedules and time off, matching who manages doctor profiles in
  feature 004; receptionists and doctors read them.
- Each doctor has one appointment length for all their periods; different lengths per visit type
  belong to a later feature if needed.
- Breaks are expressed as gaps between periods rather than as separate records.
- Clinic-wide closures (public holidays) are recorded as time off for each affected doctor; a
  clinic-wide holiday calendar is out of scope.
- Slots are computed on request, not stored; feature 006 decides how booked slots are excluded and
  how concurrent booking of one slot is prevented (Constitution XI).
- One clinic, one location, one time zone (Egypt); rooms and resources are out of scope.
- The 31-day range, 90-day booking horizon, 4 periods per day, and 5–240-minute appointment length
  follow common practice and can be tuned during planning.
