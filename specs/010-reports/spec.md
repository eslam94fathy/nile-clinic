# Feature Specification: Reports

**Feature Branch**: `010-reports`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Reports" (from `docs/roadmap.md`, feature 010: "operational and financial summaries for administrators")

## Context

The clinic now records appointments, visits, medical records, charges, and money received, but one record
at a time. Nobody can yet ask **how much money came in, what is still owed, or how busy each doctor was**
without adding it up by hand. This feature answers those questions with summaries over a chosen period.

Reports only read. They change nothing, hold no records of their own, and always reflect the records as
they stand at that moment. They are totals and counts, not lists of people: **no report identifies a
patient** or contains medical content (Constitution I).

Money comes from feature 009, appointments from 006, visits from 007, patients from 003, doctors from 004,
and staff roles from 002. Payments (009) left end-of-day totals to this feature, and medical encounters
(008) left the use of encounter data to it. A history of who changed what belongs to "Audit logs".

## Clarifications

### Session 2026-11-05

- Q: Who may see reports? → A: Administrators only. Receptionists and doctors are refused (FR-001).
- Q: May reports use medical encounter data? → A: Counts only: for each doctor, the seen visits with a
  finalized record, a draft, or no record. No clinical content or statistics drawn from it (FR-017, US4).
- Q: Should reports be downloadable as files? → A: No. Reports are returned as structured data only (FR-006).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An administrator sees the money collected (Priority: P1)

At the end of the day or the month, an administrator asks how much money actually came in, in each method,
and through which staff member, so the cash can be checked against the records.

**Why this priority**: This is the clinic's most frequent financial question, and the total that Payments
left to this feature. It is the minimum usable slice.

**Independent Test**: Record payments in several methods by two staff members, refund one on a later day,
then request the period and confirm that the totals and every breakdown match by hand.

**Acceptance Scenarios**:

1. **Given** payments and refunds in a period, **When** an administrator requests the report for that
   period, **Then** it shows the money received, the money refunded, and the net (received less refunded).
2. **Given** the same period, **When** the report is returned, **Then** it breaks those three figures down
   by method (`cash`, `card`, `wallet`), by day, and by staff member within each method, and each breakdown
   adds up exactly to the totals.
3. **Given** a payment recorded on one day and refunded on a later day, **When** each day is requested,
   **Then** the payment counts on the first day and the refund on the later day; the earlier day's total
   does not change.
4. **Given** a doctor, **When** the report is requested for that doctor, **Then** only money on that
   doctor's charges is counted.
5. **Given** a period with no money movement, **When** it is requested, **Then** all totals are zero and the
   breakdowns are empty; it is not an error.
6. **Given** a signed-in receptionist or doctor, **When** they request this or any other report, **Then** it is
   refused as forbidden.

---

### User Story 2 - An administrator sees what was billed and what is owed, by doctor (Priority: P1)

An administrator asks what each doctor's visits were charged, how much was discounted, and how much is still
unpaid, and which finished visits were never charged.

**Why this priority**: Unpaid balances and forgotten charges are lost income, and this is the view that
reveals them. Collected money alone cannot show either.

**Independent Test**: Create charges for two doctors with a discount, a part payment, a cancelled charge, and
a seen visit with no charge. Confirm each doctor's figures and the overall totals.

**Acceptance Scenarios**:

1. **Given** charges for visits in a period, **When** an administrator requests the report, **Then** for
   each doctor and overall it shows the number of active charges, the fees, the discounts, the amount due,
   the amount paid, and the balance.
2. **Given** a cancelled charge in the period, **When** the report is returned, **Then** it is left out of
   all money figures and counted only as the number of cancelled charges.
3. **Given** visits marked `seen` with no active charge, **When** the report is returned, **Then** their
   number is shown for each doctor and overall.
4. **Given** a charge that receives a discount or payment after an earlier request, **When** the same period
   is requested again, **Then** the figures reflect the charge as it stands now.
5. **Given** more doctors than fit on one page, **When** the report is requested, **Then** doctors are
   returned in pages of a bounded size, and the overall totals still cover all of them.

---

### User Story 3 - An administrator sees how busy the clinic was (Priority: P2)

An administrator asks how many appointments were kept, cancelled, or missed, how many patients walked in,
and how many new patients were registered, to plan doctors' time.

**Why this priority**: It guides staffing and spots high no-show rates, but the clinic can run for a while
without it.

**Independent Test**: Create appointments in each status and visits of each kind for two doctors, then confirm
the counts and the no-show rate.

**Acceptance Scenarios**:

1. **Given** appointments in a period, **When** an administrator requests the report, **Then** for each doctor
   and overall it shows the number in each status (`scheduled`, `cancelled`, `no_show`, `checked_in`,
   `completed`).
2. **Given** those appointments, **When** the report is returned, **Then** it shows a no-show rate: no-shows
   divided by appointments that were no-shows, checked in, or completed, to one decimal place. With none of
   those, the rate is empty rather than zero.
3. **Given** visits in a period, **When** the report is returned, **Then** for each doctor and overall it shows
   the number in each status (`waiting`, `with_doctor`, `seen`, `left`), and how many were booked and how many
   were walk-ins.
4. **Given** patients registered in a period, **When** the report is returned, **Then** it shows their number
   overall.

---

### User Story 4 - An administrator sees visit times and unfinished records (Priority: P3)

An administrator asks how long patients waited, how long consultations took with each doctor, and whether
each doctor's seen visits have a finished medical record.

**Why this priority**: It helps improve the service, but it is an improvement rather than a daily need.

**Independent Test**: Create visits with known arrival, call, and finish times, and seen visits with a
finalized record, a draft, and no record. Confirm the averages, that unfinished visits are left out of the
consultation average, and the three record counts.

**Acceptance Scenarios**:

1. **Given** visits that were called, **When** an administrator requests the report, **Then** for each doctor
   and overall it shows the average wait from arrival to being called, in whole minutes, and how many visits
   the average covers.
2. **Given** visits that were finished, **When** the report is returned, **Then** for each doctor and overall it
   shows the average consultation from being called to being finished, in whole minutes, and how many visits
   the average covers.
3. **Given** visits that were never called or never finished, **When** the averages are calculated, **Then**
   they are left out of the average that needs the missing time.
4. **Given** seen visits in a period, **When** the report is returned, **Then** for each doctor and overall it
   shows how many have a finalized record, a draft, and no record, and these add up to the number of seen
   visits. It contains no clinical content.

---

### Edge Cases

- **Which day money counts on**: Money collected counts on the clinic day it was recorded, whichever visit it
  was for. Billing and activity count on the visit's or appointment's own date, whenever the charge was made.
- **Clinic days**: A day runs midnight to midnight in the clinic's local time (Egypt). A payment recorded just
  after midnight clinic time belongs to the new day, including across daylight-saving changes.
- **Negative net**: A day, method, or staff member can show a negative net when refunds exceed money received,
  because a refund may fall on a later day or use a different method from its payment. It is reported as is.
- **Cancelled charges**: Cancellation requires all money to have been returned, so a cancelled charge's payments
  and refunds still appear in money collected and net to zero, possibly across different days.
- **Full discounts**: A fully discounted charge counts in billing with its fee and discount, and adds nothing to
  money collected.
- **Later changes**: Billing shows charges as they stand now, not as they stood on a past date. Money collected
  for a past period never changes, because payments and refunds are never edited or deleted.
- **Undone check-ins**: An undone check-in leaves no visit, so it is not counted; its appointment counts under
  its current status.
- **Unresolved appointments**: A past appointment nobody checked in or marked as a no-show stays `scheduled` and
  is counted as such, not as a no-show.
- **Rescheduled appointments**: Counted once, on their current date.
- **Records on other visits**: Record counts cover seen visits only. A draft on a visit still `with_doctor` is
  not counted.
- **Unfinished days**: Visits still `waiting` or `with_doctor` on a past day count under that status. They are
  not "seen", so they are not reported as uncharged.
- **Visits marked as left**: They are not reported as uncharged, because they cannot be newly charged (feature
  009). An existing charge on one still counts in billing.
- **Inactive doctors and staff**: Deactivated doctors and staff members appear when they have activity in the
  period, so that totals stay complete.
- **Doctors with no activity**: They are left out of the per-doctor breakdowns. A doctor filter on such a doctor
  returns zeros.
- **Future dates**: A period may include future dates, which show upcoming appointments and no money.
- **Exact money**: Every amount and total is exact to two decimal places, in Egyptian pounds, with no rounding
  errors, however many records it covers.
- **Languages**: Messages are returned in English or Arabic with identical structure. Statuses and methods are
  fixed English identifiers, never translated; names appear as stored.

## Requirements *(mandatory)*

### Functional Requirements

**Common to all reports**

- **FR-001**: Reports MUST be available to administrators only. Receptionists and doctors MUST be refused as
  forbidden.
- **FR-002**: Every report MUST require a period with a start and end date, both included, of at most 366 days.
  A missing or malformed date, an end before the start, or a longer period MUST be refused with a validation
  message.
- **FR-003**: Every report MUST accept an optional doctor filter. An unknown doctor MUST be reported as not found.
- **FR-004**: Reports MUST NOT contain patient names, patient numbers, phone numbers, national IDs, addresses,
  emergency contacts, any other identifier of a patient, or medical content.
- **FR-005**: Reports MUST only read: requesting one MUST NOT change or create any record, and it MUST reflect the
  records as they stand at that moment.
- **FR-006**: Reports MUST be returned as structured data only, with no downloadable files.

**Money collected**

- **FR-007**: The report MUST show money received, money refunded, and net for the period, by method, by day, and
  by staff member within each method. Only days with money movement MUST be listed.
- **FR-008**: Payments and refunds MUST count on the clinic day they were recorded. The doctor filter MUST apply to
  the doctor on the charge.

**Billing**

- **FR-009**: The report MUST show, for each doctor and overall, the number of active charges, fees, discounts,
  amount due, amount paid, and balance, for charges dated in the period, as they stand now.
- **FR-010**: Cancelled charges MUST be left out of the money figures and shown only as a count.
- **FR-011**: The report MUST show the number of visits marked `seen` with no active charge, for each doctor and
  overall.

**Activity**

- **FR-012**: The report MUST show the number of appointments in each status and the no-show rate as defined in US3,
  for each doctor and overall, for appointments dated in the period.
- **FR-013**: The report MUST show the number of visits in each status, and of booked and walk-in visits, for each
  doctor and overall, for visits dated in the period.
- **FR-014**: The report MUST show the number of patients registered in the period, overall. The doctor filter does
  not apply to this figure, and the report MUST leave it out when the filter is used.

**Times and records**

- **FR-015**: The report MUST show the average wait and the average consultation in whole minutes, each with the
  number of visits it covers, for each doctor and overall. An average with no visits MUST be empty rather than zero.
- **FR-016**: An average MUST leave out visits missing either of its two times.
- **FR-017**: The report MUST show the number of seen visits with a finalized record, a draft, and no record, for each
  doctor and overall. Encounter data MUST be used for these counts only: clinical content MUST NOT be read, and no
  figure may be derived from it.

**Cross-cutting**

- **FR-018**: Per-doctor breakdowns MUST be returned in pages of a bounded size, in a stable order, and overall totals
  MUST cover every doctor whichever page is returned.
- **FR-019**: All amounts MUST be exact to two decimal places, and each breakdown MUST add up exactly to its total.
- **FR-020**: All actions MUST require a signed-in, active staff account and MUST be authorized through the system's
  single authorization mechanism (feature 002); refused requests are reported as forbidden, distinct from
  unauthenticated.
- **FR-021**: Logs MUST identify patients only by internal identifier; seed data, fixtures, and tests MUST use
  synthetic data only (Constitution XII).
- **FR-022**: All dates and day boundaries MUST be the clinic's local time (Egypt), as in features 005–009.
- **FR-023**: All user-facing messages MUST exist in English and Arabic with identical response field names and
  structure; statuses and methods are fixed English identifiers, never translated.
- **FR-024**: Report capabilities MUST follow the API versioning and module conventions established by features
  001–009.

### Key Entities *(include if feature involves data)*

This feature stores no data of its own. It reads:

- **Payment and refund** (feature 009): Amount, method, staff member, and time recorded. The source of money
  collected.
- **Charge** (feature 009): Fee, discount, amount paid, status, doctor, and date. The source of billing.
- **Visit** (feature 007, queue entry): Doctor, date, status, whether it was booked, and its arrival, call, and finish
  times. The source of visit counts, times, and uncharged visits.
- **Medical encounter** (feature 008): Only whether a seen visit has one and whether it is draft or finalized. Its
  clinical content is never read.
- **Appointment** (feature 006): Doctor, date, and status. The source of appointment counts and the no-show rate.
- **Patient** (feature 003): Only the date registered, for the count of new patients.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can get any report for any period with one request, and a day's money collected in
  under 2 seconds.
- **SC-002**: A report for the longest period, 366 days, returns in under 5 seconds with 500 doctors and 500,000 charges,
  and with visits and appointments in proportion.
- **SC-003**: Every money breakdown adds up exactly to its total, to the piastre, including over 1,000 small payments
  and refunds, as shown by automated tests.
- **SC-004**: Money recorded within a minute either side of midnight clinic time counts on the correct day in 100% of
  automated test cases, including across a daylight-saving change.
- **SC-005**: 0 report responses contain a patient identifier or medical content, as shown by automated tests that
  check every report for planted synthetic values.
- **SC-006**: 100% of reports refuse unauthenticated requests, receptionists, and doctors, as shown by automated tests
  covering every role against every report.
- **SC-007**: Requesting any report leaves 0 records changed or created, as shown by automated tests.
- **SC-008**: Every user-facing message added by this feature exists in both English and Arabic, as verified by automated
  tests in both languages.

## Assumptions

- Patients come from feature 003, doctors from 004, appointments from 006, visits from 007, encounters from 008, money from
  009, and roles from 002. This feature does not change the behavior of any of them.
- Receptionists and doctors have no access to reports (least privilege). Reception checks a day's cash through an
  administrator, and a doctor's view of their own figures would need an approved scope change.
- File downloads (CSV, PDF) are out of scope. A later feature could add them over the same figures.
- "Revenue" is not one figure: money collected follows the day money moved, and billing follows the visit's date. Both are
  given, and the two legitimately differ for a period.
- Wait time starts when the patient arrives, even if they arrived well before their appointment time.
- The 366-day limit allows a full year's report while bounding the work of one request. Yearly and monthly groupings are out
  of scope: a period's totals can be requested month by month.
- Reports always show current records. Saved or scheduled reports, emailed reports, and dashboards are out of scope.
- A printed end-of-day sheet and a formal cash drawer process (opening float, counted cash, sign-off) are out of scope; the
  report gives the expected figures to check against.
- Breakdowns by specialty, patient age or sex, or staff productivity beyond money handled are out of scope, as are lists of
  individual debtors; finding a patient's charges is already available in Payments (009).
- Tax reports and accounting exports are out of scope. Applicable Egyptian tax obligations, like health-data obligations, need
  legal review before production use.
- The 2-second, 5-second, and 500,000-charge targets follow the scale of features 005–009 and can be tuned during planning.
